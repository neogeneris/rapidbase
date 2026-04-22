<?php

namespace RapidBase\Core;

use \Exception;
use \PDO;
use \PDOStatement;
use RapidBase\Core\Cache\CacheService;

/**
 * Clase Gateway - El punto de control y despacho del Framework.
 * Une la Fundación (SQL), el Pool (Conn), el Obrero (Executor) y el Almacén (Cache).
 * 
 * Soporta consultas con JOINs automáticos pasando un array de tablas en $table,
 * por ejemplo: ['drivers', 'users'].
 * Para que los JOINs se construyan correctamente, se debe cargar previamente el
 * mapa de relaciones mediante DB::loadRelationsMap() o SQL::setRelationsMap().
 */
class Gateway {

    /** @var array Rastro de la última operación */
    private static array $lastStatus = [];
    
    // ========== OPTIMIZACIÓN: Cachear existencia de clases ==========
    // Evita llamar a class_exists() repetidamente en cada llamada
    private static ?bool $hasEvents = null;
    private static ?bool $hasCacheService = null;
    private static ?bool $hasSQL = null;
    
	private static function setStatus(string $sql, array $params, int $rows = 0, $id = null, ?string $error = null): void {
        self::$lastStatus = [
            'sql'    => $sql,
            'params' => $params,
            'rows'   => $rows,
            'id'     => $id,
            'error'  => $error
        ];
    }
    /**
     * CAPA 1 (Núcleo): Consulta Pura a la DB.
     * Ahora soporta FETCH_NUM con mapa de proyección para máxima eficiencia.
     *
     * @param mixed $fields Columnas a seleccionar (string o array).
     * @param mixed $table Nombre de la tabla (string) o array de tablas para JOIN.
     * @param array $where Condiciones.
     * @param array $sort Ordenamiento [columna => ASC|DESC].
     * @param int $page Número de página.
     * @param int $perPage Registros por página.
     * @param bool $withTotal Si es true, incluye el total de registros (sin paginación).
     * @param bool $useFetchNum Si es true, usa PDO::FETCH_NUM con mapa de proyección (más rápido).
     * @return array Con claves: data, total, page, limit, source, timestamp, projectionMap.
     */
    public static function select(
        mixed $fields   = '*', 
        mixed $table    = '', 
        array $where    = [],
		array $groupBy  = [],
        array $having   = [],		
        array $sort     = [], 
        int $page       = 1, 
        int $perPage    = 10,
        bool $withTotal = false,
        bool $useFetchNum = false
    ): array {
        
        // Construir SQL de datos
        [$sql, $params] = SQL::buildSelect($fields, $table, $where, $groupBy,$having , $sort, $page, $perPage);

        $total = 0;
        if ($withTotal) {
            // Consulta de conteo (sin LIMIT/OFFSET)
            [$countSql, $countParams] = SQL::buildSelect('COUNT(*) as total', $table, $where);
            $countStmt = Executor::query($countSql, $countParams, Conn::get());
            $total = (int) $countStmt->fetchColumn();
        }

        $start = microtime(true);
        try {
            $stmt = Executor::query($sql, $params, Conn::get());
            
            // Usar FETCH_NUM si está habilitado, de lo contrario FETCH_ASSOC
            if ($useFetchNum) {
                // Obtener el mapa de proyección desde SQL
                $projectionMap = SQL::getLastProjectionMap();
                $data = $stmt->fetchAll(\PDO::FETCH_NUM);
            } else {
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $projectionMap = [];
            }
            
            $duration = (microtime(true) - $start) * 1000; // milisegundos
            
            $tableName = is_array($table) ? implode('_', $table) : (string)$table;
            self::logStatus(true, $sql, $params, null, [], 'select', $tableName, $duration);

            return [
                'data'           => $data,
                'total'          => $withTotal ? $total : count($data),
                'page'           => $page,
                'limit'          => $perPage,
                'source'         => 'database',
                'timestamp'      => microtime(true),
                'projectionMap'  => $projectionMap,
                'useFetchNum'    => $useFetchNum
            ];
        } catch (Exception $e) {
            $duration = (microtime(true) - $start) * 1000;
            $tableName = is_array($table) ? implode('_', $table) : (string)$table;
            self::logError($e, $sql, $params, 'select', $tableName, $duration);
            throw $e;
        }
    }

/**
     * CAPA 2 (Caché): Consulta con persistencia en L1/L2.
     * Ahora soporta agrupamiento dinámico para reportes y listas.
     */
    public static function selectCached(
        mixed $fields   = '*', 
        mixed $table    = '', 
        array $where    = [], 
        array $groupBy  = [], 
		array $having   = [],
        array $sort     = [], 
        int $page       = 1, 
        int $perPage    = 10,
        bool $withTotal = false,
        int $ttl        = 3600,
        bool $useFetchNum = false
    ): array {
        
        $tableName = is_array($table) ? implode('_', $table) : (string)$table;
        
        // CRÍTICO: Incluir $groupBy en el hash para evitar colisiones de caché
        $queryHash = md5(json_encode([$fields, $where, $groupBy,$having, $sort, $page, $perPage, $withTotal, $useFetchNum]));
        $cacheKey  = "db_select_{$tableName}_{$queryHash}";

        // Intentar recuperar de caché
        if (self::$hasCacheService ??= class_exists('Core\Cache\CacheService')) {
            $cached = CacheService::get($cacheKey);
            if ($cached !== null) {
                $cached['source'] = 'cache';
                self::logStatus(true, "CACHE GET: $cacheKey", [], null, [], 'select', $tableName, 0.0);
                return $cached;
            }
        }

        // Si no está en caché, llamamos a select() pasando el nuevo parámetro
        $result = self::select($fields, $table, $where, $groupBy,$having, $sort, $page, $perPage, $withTotal, $useFetchNum);
        
        // Guardar en caché
        if ($result && !empty($result['data']) && (self::$hasCacheService ?? true)) {
            CacheService::set($cacheKey, $result, $ttl);
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

        $start = microtime(true);
        try {
            $res = Executor::action($sql, $params, Conn::get());
            $duration = (microtime(true) - $start) * 1000;
            
            if ($res['success']) {
                self::clearCacheForTable($table);
            }
            
            self::logStatus(true, $sql, $params, null, [
                'id'    => $res['lastId'], 
                'rows'  => $res['count']
            ], $type, $table, $duration);
            
            return $res;
        } catch (Exception $e) {
            $duration = (microtime(true) - $start) * 1000;
            self::logError($e, $sql, $params, $type, $table, $duration);
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
        
        $start = microtime(true);
        try {
            $count = Executor::batch($sql, $paramsList, Conn::get());
            $duration = (microtime(true) - $start) * 1000;
            
            if ($count > 0) {
                self::clearCacheForTable($table);
            }
            
            self::logStatus(true, $sql . " [BATCH]", $paramsList[0] ?? [], null, ['count' => $count], 'batch', $table, $duration);
            return true;
        } catch (Exception $e) {
            $duration = (microtime(true) - $start) * 1000;
            self::logError($e, $sql, [], 'batch', $table, $duration);
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
        if (self::$hasCacheService ??= class_exists('Core\Cache\CacheService')) {
            $prefix = "db_select_{$table}_";
            CacheService::clearByPrefix($prefix);
        }
        // También limpiar el caché de consultas SQL si existe
        if (self::$hasSQL ??= class_exists('RapidBase\Core\SQL')) {
            \RapidBase\Core\SQL::clearQueryCache();
        }
    }

    /**
     * Habilita o deshabilita el caché de consultas SQL en la capa SQL.
     * Útil para reducir la CPU en consultas complejas con múltiples JOINs.
     * 
     * @param bool $enabled
     */
    public static function setSqlQueryCacheEnabled(bool $enabled): void {
        if (self::$hasSQL ??= class_exists('RapidBase\Core\SQL')) {
            \RapidBase\Core\SQL::setQueryCacheEnabled($enabled);
        }
    }

    /**
     * Obtiene estadísticas combinadas de los cachés (L1, L2 y caché de consultas SQL).
     * 
     * @return array Con estadísticas de todos los niveles de caché.
     */
    public static function getCacheStats(): array {
        $stats = [
            'sql_query_cache' => null,
            'result_cache' => null
        ];
        
        if (self::$hasSQL ??= class_exists('RapidBase\Core\SQL')) {
            $stats['sql_query_cache'] = \RapidBase\Core\SQL::getQueryCacheStats();
        }
        
        // Aquí podríamos agregar estadísticas del caché de resultados si están disponibles
        if (self::$hasCacheService ??= class_exists('Core\Cache\CacheService')) {
            $stats['result_cache'] = [
                'available' => true,
                'note' => 'Use CacheService::getStats() si está disponible'
            ];
        }
        
        return $stats;
    }

    /**
     * Verifica si existe un registro que cumpla las condiciones.
     *
     * @param string $table
     * @param array $where
     * @return bool
     */
/**
     * Sincroniza el estado global antes de la ejecución.
     */
    private static function record(string $sql, array $params): void {
        self::$lastStatus = [
            'sql'    => $sql,
            'params' => $params, // Esto arregla el FAIL de integridad
            'rows'   => 0,
            'error'  => null
        ];
    }

	public static function exists(string $table, array $where): bool {
        $start = microtime(true);
        [$sql, $params] = SQL::buildExists($table, $where);

        try {
            $stmt = Executor::query($sql, $params, Conn::get());
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $exists = (bool)($row['check'] ?? false);
            
            $duration = (microtime(true) - $start) * 1000;

            // ESTO ES LO QUE EL TEST NECESITA:
            self::logStatus(true, $sql, $params, null, ['rows' => $exists ? 1 : 0], 'exists', $table, $duration);
            
            return $exists;
        } catch (Exception $e) {
            $duration = (microtime(true) - $start) * 1000;
            self::logError($e, $sql, $params, 'exists', $table, $duration);
            return false;
        }
    }

    public static function count(string|array $table, array $where = []): int {
        $start = microtime(true);
        [$sql, $params] = SQL::buildCount($table, $where);
		
        try {
            $stmt = Executor::query($sql, $params, Conn::get());
            $count = (int)($stmt->fetchColumn() ?: 0);
            
            $duration = (microtime(true) - $start) * 1000;
            $tableName = is_array($table) ? implode('_', $table) : (string)$table;

            // ACTUALIZAR EL ESTADO ANTES DEL RETURN:
            self::logStatus(true, $sql, $params, null, ['rows' => $count], 'count', $tableName, $duration);
            
            return $count;
        } catch (Exception $e) {
            $duration = (microtime(true) - $start) * 1000;
            $tableName = is_array($table) ? implode('_', $table) : (string)$table;
            self::logError($e, $sql, $params, 'count', $tableName, $duration);
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
     * @param string|null $type
     * @param string|null $table
     * @param float|null $duration (en milisegundos)
     */
	private static function logStatus(
		bool $success,
		string $sql,
		array $params,
		?string $error = null,
		array $extra = [],
		?string $type = null,
		?string $table = null,
		?float $duration = null
	): void {
		// OPTIMIZACIÓN 1: Evitar array_merge que duplica memoria
		// Asignación directa es mucho más rápida
		self::$lastStatus = $extra;
		self::$lastStatus['success']   = $success;
		self::$lastStatus['sql']       = $sql;
		self::$lastStatus['params']    = $params;
		self::$lastStatus['error']     = $error;
		self::$lastStatus['timestamp'] = microtime(true);
		self::$lastStatus['type']      = $type;
		self::$lastStatus['table']     = $table;
		self::$lastStatus['duration']  = $duration;

		// OPTIMIZACIÓN 2: Cachear la existencia de la clase Event
		if (self::$hasEvents ??= class_exists(__NAMESPACE__ . '\Event')) {
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
     * @param string|null $type
     * @param string|null $table
     * @param float|null $duration
     */
    private static function logError(Exception $e, string $sql, array $params, ?string $type = null, ?string $table = null, ?float $duration = null): void {
        self::logStatus(false, $sql, $params, $e->getMessage(), ['code' => $e->getCode()], $type, $table, $duration);
    }
}