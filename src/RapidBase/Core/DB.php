<?php

namespace RapidBase\Core;

use RapidBase\Core\Conn;
use RapidBase\Core\SQL;
use RapidBase\Core\Cache\CacheService;

use \PDO;
use \Generator;

class DB implements DBInterface {
	
	public static function setup(string $dsn, string $user, string $pass, string $name = 'main'): void {
		 Conn::setup($dsn, $user,$pass, $name);
		 SQL::detectDriverFromPDO(Conn::get());
	}
	
	/**
     * Obtiene la instancia de la conexión PDO actual.
     * @return \PDO|null
     */
    public static function getConnection(): ?\PDO
    {
        return Conn::get();
    }
	/**
	 * Ejecuta una sentencia SQL directa y devuelve el resultado de Executor::action.
	 * @param string $sql
	 * @param array $params
	 * @return array
	 */
	public static function exec(string $sql, array $params = []): array {
        return Executor::action($sql, $params, Conn::get());
    }
	
	/**
     * Ejecuta una consulta SQL de lectura y retorna el PDOStatement.
     * @param string $sql
     * @param array $params
     * @return \PDOStatement|false
     */
    public static function query(string $sql, array $params = []): \PDOStatement|false
    {
       return Executor::query($sql, $params, Conn::get());
    }

    // ========== ESTADO Y METADATOS ==========

    /**
     * Retorna el estado de la última operación.
     * @return array
     */
    public static function status(): array {
        return Gateway::status();
    }

    /**
     * Retorna el último mensaje de error, si existe.
     * @return string|null
     */
    public static function getLastError(): ?string {
        return self::status()['error'] ?? null;
    }

    /**
     * Retorna el número de filas afectadas por la última operación.
     * @return int
     */
    public static function getAffectedRows(): int {
        return self::status()['rows'] ?? 0;
    }

    /**
     * Retorna el último ID insertado.
     * @return string|int
     */
    public static function lastInsertId(): string|int {
        return self::status()['id'] ?? 0;
    }


  /**
     * Establece el mapa de relaciones que usará SQL para construir JOINs.
     * El mapa debe tener el formato: 
     * [
     *   'from' => [ 'tabla_origen' => [ 'tabla_destino' => ['local_key' => 'col', 'foreign_key' => 'col'] ] ],
     *   'to'   => [ ... ]
     * ]
     *
     * @param array $map
     */
    public static function setRelationsMap(array $map): void
    {
        SQL::setRelationsMap($map);
    }

    /**
     * Carga el mapa de relaciones desde un archivo PHP que retorna un array.
     * El archivo debe contener la clave 'relationships' con la estructura esperada.
     *
     * @param string $filePath
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public static function loadRelationsMap(string $filePath): void
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("Relations map file not found: $filePath");
        }
        $map = include $filePath;
        if (!is_array($map) || !isset($map['relationships'])) {
            throw new \RuntimeException("Invalid relations map format: missing 'relationships' key");
        }
        self::setRelationsMap($map['relationships']);
    }





    // ========== CONSULTAS EXPRESIVAS (SQL Crudo) ==========

    /**
     * Obtiene una única fila como array asociativo.
     * @param string $sql
     * @param array $params
     * @return array|false
     */
    public static function one(string $sql, array $params = []): array|false {
        $stmt = Executor::query($sql, $params, Conn::get());
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Obtiene múltiples filas como array de arrays asociativos.
     * @param string $sql
     * @param array $params
     * @return array
     */
    public static function many(string $sql, array $params = []): array {
        $stmt = Executor::query($sql, $params, Conn::get());
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Obtiene un valor escalar (ej. COUNT, SUM, una columna).
     * @param string $sql
     * @param array $params
     * @return mixed
     */
    public static function value(string $sql, array $params = []): mixed 
    {
        $stmt = self::query($sql, $params);
        return $stmt ? $stmt->fetchColumn() : null;
    }

    // ========== ABSTRACCIÓN FLUIDA (Basada en Gateway) ==========

    /**
     * Encuentra un único registro por condiciones.
     * @param string $table
     * @param array $conditions
     * @return array|false
     */
    public static function find(string $table, array $conditions): array|false {
        $result = Gateway::select('*', $table, $conditions, [],[],[], 1, 1, false);
        $data = $result['data'] ?? [];
        return $data[0] ?? false;
    }

    /**
     * Cuenta registros de una tabla.
     * @param string|array $table
     * @param array $conditions
     * @return int
     */
    public static function count(string|array $table, array $conditions = []): int {
        return Gateway::count($table, $conditions);
    }

    /**
     * Verifica si existe un registro que cumpla las condiciones.
     * @param string $table
     * @param array $conditions
     * @return bool
     */
    public static function exists(string $table, array $conditions): bool {
        return Gateway::exists($table, $conditions);
    }

    // ========== OPERACIONES CRUD (Escritura Segura) ==========

    /**
     * Alias de find().
     * @param string|array $table
     * @param array $where
     * @param array $sort (no utilizado en este alias)
     * @return array|false
     */
    public static function read(string|array $table, array $where = [], array $sort = []): array|false 
    {
        return self::find($table, $where);
    }

    /**
     * Lee un registro y lo mapea a una instancia de la clase dada.
     * La clase debe tener un método estático getTable() o se debe pasar el nombre de la tabla.
     * @param string $class
     * @param array $where
     * @param string|null $table (opcional)
     * @return object|false
     * @throws \InvalidArgumentException
     */
    public static function readAs(string $class, array $where, ?string $table = null): object|false 
    {
        if ($table === null) {
            if (!method_exists($class, 'getTable')) {
                throw new \InvalidArgumentException("La clase $class debe tener un método estático getTable() o proporcionar explícitamente el nombre de la tabla.");
            }
            $table = $class::getTable();
        }

        $result = Gateway::select('*', $table, $where, [],[],[], 1, 1, false);
        $row = $result['data'][0] ?? null;
        if (!$row) {
            return false;
        }
        // Hidratar objeto
        $object = new $class();
        foreach ($row as $key => $value) {
            if (property_exists($object, $key)) {
                $object->$key = $value;
            }
        }
        return $object;
    }

    /**
     * Inserta un registro y retorna el último ID insertado (o 0 si falla).
     * @param string $table
     * @param array $data
     * @return int|string
     */
    public static function insert(string $table, array $data): int|string {
        $res = Gateway::action('insert', $table, $data);
        return $res['lastId'] ?? 0;
    }

    /**
     * Inserta un registro y retorna el último ID, o false si falla.
     * @param string $table
     * @param array $data
     * @return string|int|false
     */
    public static function create(string $table, array $data): string|int|false {
        $res = Gateway::action('insert', $table, $data);
        return $res['success'] ? $res['lastId'] : false;
    }

    /**
     * Actualiza registros que cumplan las condiciones.
     * @param string $table
     * @param array $data
     * @param array $conditions
     * @return bool
     */
    public static function update(string $table, array $data, array $conditions): bool {
        $res = Gateway::action('update', $table, $data, $conditions);
        return $res['success'];
    }

    /**
     * Elimina registros que cumplan las condiciones.
     * @param string $table
     * @param array $conditions
     * @return bool
     */
    public static function delete(string $table, array $conditions): bool {
        $res = Gateway::action('delete', $table, $conditions);
        return $res['success'];
    }

    /**
     * Lógica de "Insertar o Actualizar" (Upsert)
     * @param string $table
     * @param array $data
     * @param array $identifier
     * @return int|string|bool - Si inserta, retorna lastId; si actualiza, retorna true; si falla, false.
     */
    public static function upsert(string $table, array $data, array $identifier): int|string|bool 
    {
        if (Gateway::exists($table, $identifier)) {
            $res = Gateway::action('update', $table, $data, $identifier);
            return $res['success'] ? true : false;
        }
        $res = Gateway::action('insert', $table, $data);
        return $res['lastId'] ?? false;
    }

    // ========== MOTOR PARA GRIDS (DHTMLX) ==========

    /**
     * Versión simplificada de SELECT para obtener arrays rápidos.
     * @param string $sql
     * @param array $params
     * @return array
     */
    public static function fetch(string $sql, array $params = []): array {
        $stmt = self::query($sql, $params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ========== ABSTRACCIÓN FLUIDA (Optimizado para Caché) ==========

    /**
     * Obtiene todos los registros de una tabla (sin paginación).
     * @param string|array $table
     * @param array $conditions
     * @param array $sort
     * @return array
     */
    public static function all(string|array $table, array $conditions = [], array $sort = []): array {
        $res = Gateway::selectCached('*', $table, $conditions,[],[], $sort,1,5000);
        return $res['data'];
    }

    /**
     * Versión paginada y cacheada.
     * @param string|array $table
     * @param array $where
     * @param array $sort
     * @param int $page
     * @param int $perPage
     * @return array
     */
    /**
     * Obtiene una lista plana o asociativa de registros.
     * Si hay >=2 columnas, retorna [col1 => col2]. Si hay 1 columna, retorna [col1].
     * @return array|false Retorna false si no hay datos, o el array transformado.
     */
    public static function list(
        string|array $table, 
        array $where = [], 
        array $sort = [], 
        int $page = 1, 
        int $perPage = 50
    ): array {
        $res = Gateway::selectCached(['*'], $table, $where, [],[], $sort, $page, $perPage);
        
        $data = $res['data'] ?? [];
        if (empty($data)) return [];

        // Lógica de transformación automática a Pares (Llave => Valor)
        $sample = $data[0];
        $columns = array_keys($sample);

        if (count($columns) >= 2) {
            // La primera columna es la Llave, la segunda es el Valor
            return array_column($data, $columns[1], $columns[0]);
        }

        // Si solo hay una columna, devolvemos lista plana
        return array_column($data, $columns[0]);
    }

    /**
     * Motor para GRIDs que retorna un objeto QueryResponse optimizado.
     * Usa FETCH_NUM por defecto para máximo rendimiento.
     * 
     * Estructura de respuesta esperada:
     * {
     *   "head": { "columns": [...], "titles": [...] },
     *   "data": [[...], [...]],
     *   "page": { "current": 1, "total": 6, "limit": 10, "records": 51, ... },
     *   "stats": { "exec_ms": 0.08, "cache": true, ... }
     * }
     * 
     * ## Firma:
     * DB::grid($table, $conditions, $page = 0, $sort = [])
     * 
     * @param string|array $table Tabla, Model, array de tablas o SQL.
     * @param array $conditions Where matricial.
     * @param int $page Página (0=sin paginación, 1=primera página). Default: 0.
     * @param string|array $sort Campo(s) de ordenamiento. Default: [].
     * @param int $perPage Registros por página. Default: 10.
     * @return QueryResponse
     */
    public static function grid(string|array $table, array $conditions = [], int $page = 0, string|array $sort = [], int $perPage = 10): QueryResponse {
        // Normalizar $sort a array si es string
        if (is_string($sort)) {
            $sort = [$sort];
        }
        
        // CRÍTICO: useFetchNum = true para obtener arrays numéricos (FETCH_NUM)
        // Firma: selectCached(fields, table, where, groupBy, having, sort, page, perPage, withTotal, ttl, useFetchNum)
        $res = Gateway::selectCached('*', $table, $conditions, [], [], $sort, $page, $perPage, true, 3600, true);
        
        // Obtener nombre de la tabla (si es array, tomar la primera)
        $tableName = is_array($table) ? key($table) : $table;
        
        // Extraer metadata desde SchemaMap (usando connectionId 'main' por defecto)
        $schemaMeta = SchemaMap::getTable($tableName, 'main');
        $columns = $schemaMeta['columns'] ?? [];
        
        // Construir arrays de columnas y títulos
        $columnNames = [];
        $columnTitles = [];
        
        if (!empty($columns)) {
            foreach ($columns as $colName => $colProps) {
                $columnNames[] = $colName;
                // Usar descripción si existe, sino usar el nombre formateado
                $columnTitles[] = $colProps['description'] ?? self::formatTitle($colName);
            }
        } else {
            // Fallback: intentar inferir desde projectionMap o data
            $projectionMap = $res['projectionMap'] ?? [];
            if (!empty($projectionMap) && isset($res['data'][0])) {
                foreach ($projectionMap as $tblKey => $cols) {
                    if (is_array($cols)) {
                        foreach ($cols as $cName => $index) {
                            $columnNames[$index] = $cName;
                            $columnTitles[$index] = self::formatTitle($cName);
                        }
                    }
                }
                ksort($columnNames);
                ksort($columnTitles);
                $columnNames = array_values($columnNames);
                $columnTitles = array_values($columnTitles);
            } elseif (!empty($res['data'])) {
                // Último fallback: usar las claves del primer resultado (aunque debería ser FETCH_NUM)
                $firstRow = $res['data'][0];
                if (is_array($firstRow)) {
                    $columnNames = array_keys($firstRow);
                    $columnTitles = array_map([self::class, 'formatTitle'], $columnNames);
                }
            }
        }
        
        // Calcular información de paginación
        $lastPage = $perPage > 0 ? (int) ceil($res['total'] / $perPage) : 1;
        
        return new QueryResponse(
            data: $res['data'],
            total: $res['total'],
            count: count($res['data']),
            metadata: [
                'columns' => $columnNames,
                'titles' => $columnTitles,
                'projection_map' => $res['projectionMap'] ?? [],
                'execution_time' => $res['metadata']['execution_time'] ?? 0,
                'sort_status' => $res['metadata']['sort_status'] ?? null,
                'cache_info' => [
                    'used' => $res['source'] === 'cache',
                    'type' => $res['source'] === 'cache' ? 'L2' : null,
                ]
            ],
            state: [
                'page'     => $res['page'], 
                'per_page' => $res['limit'],
                'last_page' => $lastPage,
                'offset'   => ($res['page'] - 1) * $res['limit'],
                'source'   => $res['source']
            ]
        );
    }
    
    /**
     * Formatea un nombre de columna en un título legible.
     * Ej: created_at -> Created At, user_id -> User ID
     */
    private static function formatTitle(string $name): string {
        // Reemplazar underscores con espacios
        $title = str_replace('_', ' ', $name);
        // Capitalizar cada palabra
        $title = ucwords($title);
        return $title;
    }

    // ========== STREAMING (Telemetría de alto volumen) ==========

    /**
     * Retorna un generador para iterar sobre filas de una consulta.
     * @param string $sql
     * @param array $params
     * @return Generator
     */
    public static function stream(string $sql, array $params = []): Generator {
        $stmt = Executor::query($sql, $params, Conn::get());
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            yield $row;
        }
    }

    // ========== TRANSACCIONES Y UTILIDADES ==========

    /**
     * Envuelve una serie de operaciones en una transacción atómica.
     * @param callable $callback
     * @return mixed
     */
    public static function transaction(callable $callback): mixed {
        return Executor::transaction($callback, Conn::get());
    }

    /**
     * Crea un objeto de expresión SQL cruda para evitar el escape de caracteres.
     * Útil para funciones SQL o nombres de columnas reservados.
     * @param string $value
     * @return string
     */
    public static function raw(string $value): string {
        return $value;
    }
}