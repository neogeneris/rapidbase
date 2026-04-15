<?php

namespace Core;

use \Exception;
use \PDO;
use \PDOStatement;

/**
 * Clase Gateway - El punto de control y despacho del Framework.
 * Une la Fundición (SQL), el Pool (Conn), el Obrero (Executor) y el Almacén (Cache).
 * 
 * Soporta consultas con JOINs automáticos pasando un array de tablas en $table,
 * por ejemplo: ['drivers', 'users'].
 * Para que los JOINs se construyan correctamente, se debe cargar previamente el
 * mapa de relaciones mediante DB::loadRelationsMap() o SQL::setRelationsMap().
 */
class Gateway {

    /** @var array Rastro de la última operación */
    private static array $lastStatus = [];

    /**
     * CAPA 1 (Núcleo): Consulta Pura a la DB.
     *
     * @param mixed $fields Columnas a seleccionar (string o array).
     * @param mixed $table Nombre de la tabla (string) o array de tablas para JOIN.
     * @param array $where Condiciones.
     * @param array $sort Ordenamiento [columna => ASC|DESC].
     * @param int $page Número de página.
     * @param int $perPage Registros por página.
     * @param bool $withTotal Si es true, incluye el total de registros (sin paginación).
     * @return array Con claves: data, total, page, limit, source, timestamp.
     */
    public static function select(
        mixed $fields   = '*', 
        mixed $table    = '', 
        array $where    = [], 
        array $sort     = [], 
        int $page       = 1, 
        int $perPage    = 10,
        bool $withTotal = false
    ): array {
        
        // Construir SQL de datos
        [$sql, $params] = SQL::buildSelect($fields, $table, $where, [], [], $sort, $page, $perPage);

        $total = 0;
        if ($withTotal) {
            // Consulta de conteo (sin LIMIT/OFFSET)
            [$countSql, $countParams] = SQL::buildSelect('COUNT(*) as total', $table, $where);
            $countStmt = Executor::query($countSql, $countParams, Conn::get());
            $total = (int) $countStmt->fetchColumn();
        }

        try {
            $stmt = Executor::query($sql, $params, Conn::get());
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            self::logStatus(true, $sql, $params);

            return [
                'data'      => $data,
                'total'     => $withTotal ? $total : count($data),
                'page'      => $page,
                'limit'     => $perPage,
                'source'    => 'database',
                'timestamp' => microtime(true)
            ];
        } catch (Exception $e) {
            self::logError($e, $sql, $params);
            throw $e;
        }
    }

    /**
     * CAPA 2 (Caché): Consulta con persistencia en L1/L2.
     * Soporta tablas simples (string) o relaciones/joins (array).
     *
     * @param mixed $fields
     * @param mixed $table (string o array de tablas)
     * @param array $where
     * @param array $sort
     * @param int $page
     * @param int $perPage
     * @param bool $withTotal
     * @param int $ttl Segundos de vida de la caché.
     * @return array Mismo formato que select().
     */
    public static function selectCached(
        mixed $fields   = '*', 
        mixed $table    = '', 
        array $where    = [], 
        array $sort     = [], 
        int $page       = 1, 
        int $perPage    = 10,
        bool $withTotal = false,
        int $ttl        = 3600
    ): array {
        
        // Normalización: si es array, convertir a string para la clave de caché
        $tableName = is_array($table) ? implode('_', $table) : (string)$table;
        
        // Clave única basada en todos los argumentos
        $queryHash = md5(json_encode([$fields, $where, $sort, $page, $perPage, $withTotal]));
        $cacheKey  = "db_select_{$tableName}_{$queryHash}";

        // Intentar recuperar de caché
        $cached = Cache\CacheService::get($cacheKey);
        if ($cached !== null) {
            $cached['source'] = 'cache';
            self::logStatus(true, "CACHE GET: $cacheKey", [], null, ['source' => 'cache']);
            return $cached;
        }

        // No está en caché: consultar a la base de datos
        $result = self::select($fields, $table, $where, $sort, $page, $perPage, $withTotal);
        
        // Guardar en caché (solo si hay datos)
        if ($result && !empty($result['data'])) {
            Cache\CacheService::set($cacheKey, $result, $ttl);
        }
        
        return $result;
    }

    /**
     * ACCIÓN: Ejecuta INSERT, UPDATE o DELETE e invalida la caché de la tabla afectada.
     *
     * @param string $type 'insert', 'update', 'delete'
     * @param mixed ...$args Argumentos variables según el tipo.
     * @return array Resultado con claves 'success', 'lastId', 'count'.
     * @throws Exception
     */
    public static function action(string $type, ...$args): array {
        $method = 'build' . ucfirst(strtolower($type));
        
        if (!method_exists(SQL::class, $method)) {
            throw new Exception("El método de construcción [$method] no existe en la clase SQL.");
        }

        // Extraer nombre de la tabla (primer argumento)
        $table = $args[0] ?? 'unknown';

        [$sql, $params] = SQL::$method(...$args);

        try {
            $res = Executor::action($sql, $params, Conn::get());
            
            if ($res['success']) {
                self::clearCacheForTable($table);
            }
            
            self::logStatus(true, $sql, $params, null, [
                'id'    => $res['lastId'], 
                'rows'  => $res['count']
            ]);
            
            return $res;
        } catch (Exception $e) {
            self::logError($e, $sql, $params);
            throw $e;
        }
    }

    /**
     * BATCH: Inserciones masivas.
     *
     * @param string $table
     * @param array $data Lista de arrays asociativos.
     * @return bool
     */
    public static function batch(string $table, array $data): bool {
        [$sql, $paramsList] = SQL::buildInsert($table, $data);
        
        try {
            $count = Executor::batch($sql, $paramsList, Conn::get());
            
            if ($count > 0) {
                self::clearCacheForTable($table);
            }
            
            self::logStatus(true, $sql . " [BATCH]", $paramsList[0] ?? [], null, ['count' => $count]);
            return true;
        } catch (Exception $e) {
            self::logError($e, $sql, []);
            return false;
        }
    }

    /**
     * Invalida toda la caché asociada a una tabla (L1 + L2).
     * El prefijo usado es "db_select_{table}_".
     * Nota: Para consultas con JOIN (array de tablas), la invalidación solo afecta
     * a las claves que comiencen con la concatenación exacta de las tablas.
     * Si se actualiza una tabla, es posible que necesites limpiar manualmente
     * las claves de JOIN que la involucren (o extender este método).
     *
     * @param string $table
     */
    protected static function clearCacheForTable(string $table): void {
        $prefix = "db_select_{$table}_";
        Cache\CacheService::clearByPrefix($prefix);
    }

    /**
     * Verifica si existe un registro que cumpla las condiciones.
     *
     * @param string $table
     * @param array $where
     * @return bool
     */
    public static function exists(string $table, array $where): bool {
        [$sql, $params] = SQL::buildExists($table, $where);
        try {
            $stmt = Executor::query($sql, $params, Conn::get());
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $exists = (bool) ($row['check'] ?? false);
            self::logStatus(true, $sql, $params);
            return $exists;
        } catch (Exception $e) {
            self::logError($e, $sql, $params);
            return false;
        }
    }

    /**
     * Cuenta registros de una tabla o de una consulta con JOIN.
     *
     * @param mixed $table (string o array de tablas)
     * @param array $where
     * @param array $groupBy
     * @return int
     */
    public static function count(mixed $table, array $where = [], array $groupBy = []): int {
        [$sql, $params] = SQL::buildCount($table, $where, $groupBy);
        try {
            $stmt = Executor::query($sql, $params, Conn::get());
            $total = (int) $stmt->fetchColumn();
            self::logStatus(true, $sql, $params);
            return $total;
        } catch (Exception $e) {
            self::logError($e, $sql, $params);
            return 0;
        }
    }

    /**
     * Retorna el estado de la última operación.
     *
     * @return array
     */
    public static function status(): array {
        return self::$lastStatus;
    }

    /**
     * Registra el estado de la operación en el log interno y dispara eventos.
     *
     * @param bool $success
     * @param string $sql
     * @param array $params
     * @param string|null $error
     * @param array $extra
     */
    private static function logStatus(bool $success, string $sql, array $params, ?string $error = null, array $extra = []): void {
        self::$lastStatus = array_merge([
            'success'   => $success,
            'sql'       => $sql,
            'params'    => $params,
            'error'     => $error,
            'timestamp' => microtime(true)
        ], $extra);

        if (class_exists(__NAMESPACE__ . '\Event')) {
            $eventName = $success ? 'db.success' : 'db.error';
            Event::fire($eventName, self::$lastStatus);
            Event::fire('db.log', self::$lastStatus);
        }
    }

    /**
     * Registra un error.
     *
     * @param Exception $e
     * @param string $sql
     * @param array $params
     */
    private static function logError(Exception $e, string $sql, array $params): void {
        self::logStatus(false, $sql, $params, $e->getMessage(), ['code' => $e->getCode()]);
    }
}