<?php

namespace RapidBase\Core;

use RapidBase\Core\Conn;
use RapidBase\Core\Cache\CacheService;
use RapidBase\Core\SchemaMap;
use RapidBase\Core\SQL\ConditionMatrix;
use RapidBase\Core\SQL\Q;

use \PDO;
use \Generator;

class DB implements DBInterface
{
	
	
	public static function setup(string $dsn, string $user, string $pass, string $name = 'main'): void
	{
		// 1. Registrar la conexión
		Conn::setup($dsn, $user, $pass, $name);
		$driver = Conn::getDriver($name);
		ConditionMatrix::setDriver($driver);
		SchemaMap::setDefaultConnection(Conn::getCurrentConnectionId());

		// 2. Si ya hay un mapa cargado (p.ej. por la app), no lo sobrescribimos
		if (SchemaMap::getMap()) {
			return;
		}

		// 3. Intentar recuperar de CacheService
		$cacheKey = 'schema_' . CacheService::hash($dsn . $user . $name);
		if (class_exists(CacheService::class) && $cached = CacheService::get($cacheKey)) {
			SchemaMap::setMap($cached, $name);
			return;
		}

		// 4. Auto-descubrimiento y cacheo
		try {
			$dbName = Conn::getDatabaseName($name);
			$pdo = Conn::get($name);
			$discovery = \RapidBase\Meta\Discovery\DiscoveryFactory::create($pdo);
			$allTables = $discovery->getTables($dbName);

			$tablesMetadata = [];
			foreach ($allTables as $table) {
				$tablesMetadata[$table] = $discovery->discoverColumns($table, $dbName);
			}

			$map = [
				'tables'        => $tablesMetadata,
				'relationships' => $discovery->discoverRelationships($dbName),
				'driver'        => $driver,
				'features'      => (new \RapidBase\Meta\Discovery\FeatureDetector($pdo))->detect(),
				'checksum'      => '', // podría calcularse, no esencial
			];

			SchemaMap::setMap($map, $name);
			if (class_exists(CacheService::class)) {
				CacheService::set($cacheKey, $map, 0); // TTL 0 = no expira
			}
		} catch (\Exception $e) {
			error_log("RapidBase auto-schema discovery failed for '$name': " . $e->getMessage());
			// No detener la app
		}
	}
    public static function getConnection(): ?\PDO { return Conn::get(); }
    public static function exec(string $sql, array $params = []): array { return Executor::action($sql, $params); }
    public static function query(string $sql, array $params = []): \PDOStatement|false { return Executor::query($sql, $params); }
    public static function status(): array { return Gateway::status(); }
    public static function getLastError(): ?string { return self::status()['error'] ?? null; }
    public static function getAffectedRows(): int { return self::status()['count'] ?? 0; }
    public static function lastInsertId(): string|int { return self::status()['lastId'] ?? 0; }

    public static function setRelationsMap(array $map): void { SchemaMap::setMap($map); }
    public static function loadRelationsMap(string $filePath): void
    {
        if (!file_exists($filePath)) { throw new \InvalidArgumentException("Relations map file not found: $filePath"); }
        $map = include $filePath;
        if (!is_array($map) || !isset($map['relationships'])) { throw new \RuntimeException("Invalid relations map format: missing 'relationships' key"); }
        SchemaMap::setMap($map);
    }

    private static function normalizeRow(array $row): array
    {
        $normalized = [];
        foreach ($row as $key => $value) {
            if (strpos($key, '.') !== false) {
                $parts = explode('.', $key);
                $normalized[end($parts)] = $value;
            } else {
                $normalized[$key] = $value;
            }
        }
        return $normalized;
    }

    public static function one(
        string|array $table, array $where, string|array $fields = '*',
        ?string $class = null, bool $fail = false
    ): array|object|null {
        $result = Gateway::one($table, $where, $fields, $class, $fail);
        if (is_array($result) && $class === null) {
            return self::normalizeRow($result);
        }
        return $result;
    }

    public static function many(string $sql, array $params = []): array
    {
        $stmt = Executor::query($sql, $params);
        return $stmt->fetchAll(PDO::FETCH_NUM);
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $stmt = self::query($sql, $params);
        return $stmt ? $stmt->fetchColumn() : null;
    }

    public static function find(string $table, array $conditions): array|false
    {
        $result = Gateway::select('*', $table, $conditions, [],[],[], [1, 1], \PDO::FETCH_OBJ);
        $row = $result['data'][0] ?? false;
        return $row ? self::normalizeRow((array)$row) : false;
    }

    public static function count(string|array $table, array $conditions = []): int
    {
        return Gateway::count($table, $conditions);
    }

    public static function exists(string $table, array $conditions): bool
    {
        return Gateway::exists($table, $conditions);
    }

    public static function read(string|array $table, array $where = [], array $sort = []): array|false
    {
        return self::find($table, $where);
    }

    public static function readAs(string $class, array $where, ?string $table = null): object|false
    {
        if ($table === null) {
            if (!method_exists($class, 'getTable')) {
                throw new \InvalidArgumentException("La clase $class debe tener un método estático getTable()");
            }
            $table = $class::getTable();
        }
        $row = Gateway::one($table, $where, '*', null, false);
        if (!$row) return false;
        $row = self::normalizeRow($row);
        $object = new $class();
        foreach ($row as $key => $value) {
            if (property_exists($object, $key)) {
                $object->$key = $value;
            }
        }
        return $object;
    }

    public static function insert(string $table, array $data): int|string
    {
        $res = Gateway::action('insert', $table, $data);
        return $res['lastId'] ?? 0;
    }

    public static function create(string $table, array $data): string|int|false
    {
        $res = Gateway::action('insert', $table, $data);
        return $res['success'] ? $res['lastId'] : false;
    }

    public static function update(string $table, array $data, array $conditions): bool
    {
        $res = Gateway::action('update', $table, $data, $conditions);
        return $res['success'];
    }

    public static function delete(string $table, array $conditions): bool
    {
        $res = Gateway::action('delete', $table, $conditions);
        return $res['success'];
    }

    public static function upsert(string $table, array $data, array $conflictColumns = []): array
    {
        return Gateway::upsert($table, $data, $conflictColumns);
    }

    public static function fetch(string $sql, array $params = []): array
    {
        $stmt = self::query($sql, $params);
        return $stmt->fetchAll(PDO::FETCH_NUM);
    }

    public static function all(string|array $table, array $conditions = [], array $sort = []): array
    {
        $res = Gateway::selectCached('*', $table, $conditions,[],[], $sort, 1, 3600, \PDO::FETCH_OBJ);
        return array_map(function($row) { return self::normalizeRow((array)$row); }, $res['data']);
    }

    public static function list(
        string|array $table, array $where = [], array $sort = [], mixed $page = 0
    ): array {
        $res = Gateway::selectCached('*', $table, $where, [],[], $sort, $page, 3600, \PDO::FETCH_OBJ);
        $data = $res['data'] ?? [];
        if (empty($data)) return [];
        $firstRow = (array)$data[0];
        $columns = array_keys($firstRow);
        if (count($columns) >= 2) {
            $col1 = $columns[0]; $col2 = $columns[1];
            $list = [];
            foreach ($data as $row) { $r = (array)$row; $list[$r[$col1]] = $r[$col2]; }
            return $list;
        }
        $col0 = $columns[0];
        return array_map(function($row) { return ((array)$row)[$col0] ?? null; }, $data);
    }

    /**
     * Motor para GRIDs que retorna un objeto QueryResponse optimizado.
     * 
     * @param string|array|object $table      Tabla(s)
     * @param array               $conditions Condiciones WHERE
     * @param mixed               $page       [page, perPage] para UI, o número de página, o [offset, limit]
     * @param mixed               $sort       Ordenamiento
     * @param string|null         $class      Modo fetch
     * @return QueryResponse
     */
    public static function grid(
        string|array|object $table,
        array $conditions = [],
        mixed $page = 0,
        mixed $sort = [],
        ?string $class = null
    ): QueryResponse {
        $fetchMode = match($class) {
            null       => \PDO::FETCH_NUM,
            'StdClass' => \PDO::FETCH_OBJ,
            default    => \PDO::FETCH_CLASS,
        };

        // Normalizar paginación: acepta [page, perPage] (formato UI) o número de página
        if (is_array($page) && count($page) === 2) {
            $displayPage = max(1, (int)$page[0]);
            $displayLimit = max(1, (int)$page[1]);
            $gatewayPage = Q::page($displayPage, $displayLimit);
        } elseif (is_numeric($page) && (int)$page > 1) {
            $displayPage = max(1, (int)$page);
            $displayLimit = 10;
            $gatewayPage = Q::page($displayPage, $displayLimit);
        } else {
            $displayPage = 1;
            $displayLimit = 10;
            $gatewayPage = Q::page(1, 10);
        }

		// Obtener el total real con COUNT (sin paginación)
        $realTotal = Gateway::count($table, $conditions);


        $res = Gateway::selectCached(
            '*', $table, $conditions, [], [],
            is_string($sort) ? [$sort] : (is_array($sort) ? $sort : []),
            $gatewayPage, 3600, $fetchMode,
            ($class !== null && $class !== 'StdClass') ? $class : null
        );

        

        $columnNames = $res['metadata']['cols'] ?? [];
        if (empty($columnNames) && !empty($res['data'])) {
            $firstRow = $res['data'][0] ?? [];
            if (is_array($firstRow)) {
                $columnNames = array_keys($firstRow);
            } elseif (is_object($firstRow)) {
                $columnNames = array_keys(get_object_vars($firstRow));
            }
        }

        $columnTitles = array_map([self::class, 'formatTitle'], $columnNames);
        $lastPage = ($displayLimit > 0) ? (int) ceil($realTotal / $displayLimit) : 1;

        return new QueryResponse(
            data: $res['data'],
            total: $realTotal,
            count: count($res['data']),
            metadata: [
                'columns'        => $columnNames,
                'titles'         => $columnTitles,
                'projection_map' => $res['projectionMap'] ?? [],
                'execution_time' => $res['metadata']['execution_time'] ?? 0,
                'sql'            => $res['metadata']['sql'] ?? null,
                'sort_status'    => $res['metadata']['sort_status'] ?? null,
                'cache_info'     => [
                    'used' => $res['source'] === 'cache',
                    'type' => $res['source'] === 'cache' ? 'L2' : null,
                ],
            ],
            state: [
                'page'      => $displayPage,
                'per_page'  => $displayLimit,
                'last_page' => $lastPage,
                'offset'    => ($displayPage - 1) * $displayLimit,
                'source'    => $res['source'],
            ]
        );
    }

    private static function formatTitle(string $name): string { return ucwords(str_replace('_', ' ', $name)); }

    public static function stream(string $sql, array $params = []): Generator
    {
        $stmt = Executor::query($sql, $params);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) yield $row;
    }

    public static function transaction(callable $callback): mixed { return Executor::transaction($callback); }
    public static function raw(string $value): string { return $value; }
}