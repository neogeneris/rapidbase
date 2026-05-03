<?php

namespace RapidBase\Core;

use RapidBase\Core\Conn;
use RapidBase\Core\Cache\CacheService;
use RapidBase\Core\SchemaMap;
use RapidBase\Core\SQL\ConditionMatrix;

use \PDO;
use \Generator;

class DB implements DBInterface {

    public static function setup(string $dsn, string $user, string $pass, string $name = 'main'): void {
        Conn::setup($dsn, $user, $pass, $name);
        $pdo = Conn::get($name);
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        ConditionMatrix::setDriver($driver);

        $schemaMapPath = __DIR__ . "/../../schema_map_{$name}.php";
        if (file_exists($schemaMapPath)) {
            SchemaMap::loadFromFile($schemaMapPath, $name);
        } else {
            try {
                \RapidBase\Meta\SchemaMapper::setOutputFile($schemaMapPath);
                $dbName = Conn::getDatabaseName($name);
                \RapidBase\Meta\SchemaMapper::generate($pdo, $dbName, null, $name);
                SchemaMap::loadFromFile($schemaMapPath, $name);
            } catch (\Exception $e) {
                error_log("SchemaMap auto-generation failed: " . $e->getMessage());
            }
        }
    }

    public static function getConnection(): ?\PDO { return Conn::get(); }
    public static function exec(string $sql, array $params = []): array { return Executor::action($sql, $params); }
    public static function query(string $sql, array $params = []): \PDOStatement|false { return Executor::query($sql, $params); }
    public static function status(): array { return Gateway::status(); }
    public static function getLastError(): ?string { return self::status()['error'] ?? null; }
    public static function getAffectedRows(): int { return self::status()['count'] ?? 0; }
    public static function lastInsertId(): string|int { return self::status()['lastId'] ?? 0; }

    public static function setRelationsMap(array $map): void { SchemaMap::setMap($map, 'main'); }
    public static function loadRelationsMap(string $filePath): void {
        if (!file_exists($filePath)) { throw new \InvalidArgumentException("Relations map file not found: $filePath"); }
        $map = include $filePath;
        if (!is_array($map) || !isset($map['relationships'])) { throw new \RuntimeException("Invalid relations map format: missing 'relationships' key"); }
        SchemaMap::setMap($map, 'main');
    }

    private static function normalizeRow(array $row): array {
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

    public static function many(string $sql, array $params = []): array {
        $stmt = Executor::query($sql, $params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function value(string $sql, array $params = []): mixed {
        $stmt = self::query($sql, $params);
        return $stmt ? $stmt->fetchColumn() : null;
    }

    public static function find(string $table, array $conditions): array|false {
        $result = Gateway::select('*', $table, $conditions, [],[],[], [1, 1], false, \PDO::FETCH_ASSOC);
        $row = $result['data'][0] ?? false;
        return $row ? self::normalizeRow($row) : false;
    }

    public static function count(string|array $table, array $conditions = []): int {
        return Gateway::count($table, $conditions);
    }

    public static function exists(string $table, array $conditions): bool {
        return Gateway::exists($table, $conditions);
    }

    public static function read(string|array $table, array $where = [], array $sort = []): array|false {
        return self::find($table, $where);
    }

    public static function readAs(string $class, array $where, ?string $table = null): object|false {
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

    public static function insert(string $table, array $data): int|string {
        $res = Gateway::action('insert', $table, $data);
        return $res['lastId'] ?? 0;
    }

    public static function create(string $table, array $data): string|int|false {
        $res = Gateway::action('insert', $table, $data);
        return $res['success'] ? $res['lastId'] : false;
    }

    public static function update(string $table, array $data, array $conditions): bool {
        $res = Gateway::action('update', $table, $data, $conditions);
        return $res['success'];
    }

    public static function delete(string $table, array $conditions): bool {
        $res = Gateway::action('delete', $table, $conditions);
        return $res['success'];
    }

    public static function upsert(string $table, array $data, array $conflictColumns = []): array {
        return Gateway::upsert($table, $data, $conflictColumns);
    }

    public static function fetch(string $sql, array $params = []): array {
        $stmt = self::query($sql, $params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function all(string|array $table, array $conditions = [], array $sort = []): array {
        $res = Gateway::selectCached('*', $table, $conditions,[],[], $sort,1,false,3600,\PDO::FETCH_ASSOC);
        return array_map([self::class, 'normalizeRow'], $res['data']);
    }

    public static function list(
        string|array $table, array $where = [], array $sort = [], mixed $page = 0
    ): array {
        $res = Gateway::selectCached(['*'], $table, $where, [],[], $sort, $page, false, 3600, \PDO::FETCH_ASSOC);
        $data = $res['data'] ?? [];
        if (empty($data)) return [];
        $columns = array_keys($data[0]);
        if (count($columns) >= 2) return array_column($data, $columns[1], $columns[0]);
        return array_column($data, $columns[0]);
    }

    public static function grid(
        string|array|object $table, array $conditions = [], mixed $page = 0,
        mixed $sort = [], ?string $class = null
    ): QueryResponse {
        $actualPage = $page;
        $fetchMode = match($class) {
            null => \PDO::FETCH_NUM, 'StdClass' => \PDO::FETCH_OBJ, default => \PDO::FETCH_CLASS,
        };

        $res = Gateway::selectCached('*', $table, $conditions, [], [],
            is_string($sort) ? [$sort] : (is_array($sort) ? $sort : []),
            $actualPage, true, 3600, $fetchMode,
            $class !== null && $class !== 'StdClass' ? $class : null
        );

        $tableName = is_array($table) ? key($table) : $table;
        $columns = SchemaMap::getColumns($tableName, 'main');
        $columnNames = [];
        $columnTitles = [];

        if (!empty($columns)) {
            foreach ($columns as $colName => $colProps) {
                $columnNames[] = $colName;
                $columnTitles[] = $colProps['description'] ?? self::formatTitle($colName);
            }
        } else {
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
                ksort($columnNames); $columnNames = array_values($columnNames);
                ksort($columnTitles); $columnTitles = array_values($columnTitles);
            } elseif (!empty($res['data'])) {
                $firstRow = $res['data'][0];
                if (is_array($firstRow)) {
                    $columnNames = array_keys($firstRow);
                    $columnTitles = array_map([self::class, 'formatTitle'], $columnNames);
                }
            }
        }

        $limit = $res['limit'] ?? 10;
        $lastPage = $limit > 0 ? (int) ceil($res['total'] / $limit) : 1;

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
                'cache_info' => ['used' => $res['source'] === 'cache', 'type' => $res['source'] === 'cache' ? 'L2' : null]
            ],
            state: [
                'page' => $res['page'], 'per_page' => $res['limit'],
                'last_page' => $lastPage, 'offset' => ($res['page'] - 1) * $res['limit'],
                'source' => $res['source']
            ]
        );
    }

    private static function formatTitle(string $name): string { return ucwords(str_replace('_', ' ', $name)); }

    public static function stream(string $sql, array $params = []): Generator {
        $stmt = Executor::query($sql, $params);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) yield $row;
    }

    public static function transaction(callable $callback): mixed { return Executor::transaction($callback); }
    public static function raw(string $value): string { return $value; }
}