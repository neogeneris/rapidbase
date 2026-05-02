<?php

namespace RapidBase\Core;

use \Exception;
use \PDO;
use RapidBase\Core\Cache\CacheService;
use RapidBase\Core\SQL\Q;
use RapidBase\Core\SQL\CompiledQuery;

/**
 * Class Gateway - Control and dispatch point of the Framework.
 */
class Gateway
{
    private static array $lastStatus = [];
    private static ?bool $hasEvents = null;
    private static ?bool $hasCacheService = null;

    // ========== Core SELECT ==========

    public static function select(
        mixed $fields   = '*',
        mixed $table    = '',
        array $where    = [],
        array $groupBy  = [],
        array $having   = [],
        array $sort     = [],
        mixed $page     = 1,
        bool $withTotal = false,
        int $fetchMode  = \PDO::FETCH_ASSOC,
        ?string $class  = null
    ): array {
        $tableName = self::tableNameFromMixed($table);

        $pagination = null;
        $returnedPage = 0;
        $returnedLimit = 0;
        if ($page !== 0 && $page !== null) {
            if (is_array($page)) {
                $p = max(1, (int)$page[0]);
                $perPage = (int)($page[1] ?? 10);
                $pagination = Q::page($p, $perPage);
                $returnedPage = $p;
                $returnedLimit = $perPage;
            } else {
                $p = max(1, (int)$page);
                $perPage = 10;
                $pagination = Q::page($p, $perPage);
                $returnedPage = $p;
                $returnedLimit = $perPage;
            }
        }

        $query = Q::from($table, $where ?? []);
        $compiled = $query->select($fields, $pagination, $sort, !empty($groupBy) ? $groupBy : null, $having);

        $total = 0;
        if ($withTotal) {
            $total = Q::from($table, $where ?? [])->count()->run();
        }

        $start = microtime(true);
        try {
            $data = $compiled->run($fetchMode, $class);
            $duration = (microtime(true) - $start) * 1000;
            self::logStatus(true, $compiled->getSql(), $compiled->getParams(), null, [], 'select', $tableName, $duration);

            return [
                'data'          => $data,
                'total'         => $withTotal ? $total : count($data),
                'page'          => $returnedPage,
                'limit'         => $returnedLimit,
                'source'        => 'database',
                'timestamp'     => microtime(true),
                'projectionMap' => $compiled->getProjectionMap(),
                'fetchMode'     => $fetchMode,
                'class'         => $class,
            ];
        } catch (Exception $e) {
            $duration = (microtime(true) - $start) * 1000;
            self::logError($e, $compiled->getSql(), $compiled->getParams(), 'select', $tableName, $duration);
            throw $e;
        }
    }

    public static function selectCached(
        mixed $fields   = '*',
        mixed $table    = '',
        array $where    = [],
        array $groupBy  = [],
        array $having   = [],
        array $sort     = [],
        mixed $page     = 1,
        bool $withTotal = false,
        int $ttl        = 3600,
        int $fetchMode  = \PDO::FETCH_ASSOC,
        ?string $class  = null
    ): array {
        $tableName = self::tableNameFromMixed($table);

        $queryData = [$fields, $where, $groupBy, $having, $sort, $page, $withTotal, $fetchMode, $class];
        $jsonEncoded = json_encode($queryData);
        $queryHash = function_exists('xxh128') ? xxh128($jsonEncoded) : md5($jsonEncoded);
        $cacheKey  = "db_select_{$tableName}_{$queryHash}";

        if (self::$hasCacheService ??= class_exists('\\RapidBase\\Core\\Cache\\CacheService')) {
            $cached = CacheService::get($cacheKey);
            if ($cached !== null) {
                $cached['source'] = 'cache';
                $duration = CacheService::getLastReadDuration();
                self::logStatus(true, "CACHE GET: $cacheKey", [], null, [], 'select', $tableName, $duration);
                return $cached;
            }
        }

        $result = self::select($fields, $table, $where, $groupBy, $having, $sort, $page, $withTotal, $fetchMode, $class);

        if ($result && (self::$hasCacheService ?? true)) {
            CacheService::set($cacheKey, $result, $ttl);
        }

        return $result;
    }

    // ========== Actions ==========

    public static function action(string $type, ...$args): array
    {
        $table = $args[0] ?? 'unknown';
        $tableName = self::tableNameFromMixed($table);

        $query = Q::from($table);
        switch ($type) {
            case 'insert':
                $compiled = $query->insert($args[1] ?? []);
                break;
            case 'update':
                $where = $args[2] ?? [];
                if (empty($where)) {
                    throw new \RuntimeException("DANGER: Mass update not allowed. You must specify WHERE conditions.");
                }
                $query = Q::from($table, $where);
                $compiled = $query->update($args[1] ?? []);
                break;
            case 'delete':
                $where = $args[1] ?? [];
                if (empty($where)) {
                    throw new \RuntimeException("DANGER: Mass delete not allowed. You must specify WHERE conditions.");
                }
                $query = Q::from($table, $where);
                $compiled = $query->delete();
                break;
            default:
                throw new \InvalidArgumentException("Invalid action type: $type");
        }

        $start = microtime(true);
        try {
            $res = \RapidBase\Core\Executor::action($compiled->getSql(), $compiled->getParams());
            $duration = (microtime(true) - $start) * 1000;

            if ($res['success']) {
                self::clearCacheForTable($tableName);
            }

            self::logStatus(true, $compiled->getSql(), $compiled->getParams(), null, [
                'id'   => $res['lastId'],
                'rows' => $res['count'],
            ], $type, $tableName, $duration);

            return $res;
        } catch (Exception $e) {
            $duration = (microtime(true) - $start) * 1000;
            self::logError($e, $compiled->getSql(), $compiled->getParams(), $type, $tableName, $duration);
            throw $e;
        }
    }

    // ========== UPSERT ==========

    /**
     * UPSERT universal (INSERT ON CONFLICT / ON DUPLICATE KEY UPDATE)
     *
     * @param string $table           Target table
     * @param array  $data            Column => value to insert/update
     * @param array  $conflictColumns Columns that define the conflict (PK / unique)
     * @return int                    Affected rows
     */
    public static function upsert(string $table, array $data, array $conflictColumns = []): int|bool
	{
		$start = microtime(true);
		$tableName = self::tableNameFromMixed($table);
		$compiled = Q::into($table)->upsert($data, $conflictColumns);

		try {
			$result = \RapidBase\Core\Executor::action($compiled->getSql(), $compiled->getParams());
			$duration = (microtime(true) - $start) * 1000;

			if ($result['success']) {
				self::clearCacheForTable($tableName);
			}

			self::logStatus(true, $compiled->getSql(), $compiled->getParams(), null, [
				'id'   => $result['lastId'],
				'rows' => $result['count']
			], 'upsert', $tableName, $duration);

			// Si hay un ID generado (inserción), lo devolvemos; si no, true (actualización)
			$lastId = $result['lastId'];
			return ($lastId && $lastId !== '0') ? $lastId : true;
		} catch (Exception $e) {
			$duration = (microtime(true) - $start) * 1000;
			self::logError($e, $compiled->getSql(), $compiled->getParams(), 'upsert', $tableName, $duration);
			return false;
		}
	}
    // ========== Convenience methods ==========

    public static function exists(string $table, array $where): bool
    {
        $start = microtime(true);
        $tableName = self::tableNameFromMixed($table);
        $compiled = Q::from($table, $where)->exists();

        try {
            $exists = $compiled->run();
            $duration = (microtime(true) - $start) * 1000;
            self::logStatus(true, $compiled->getSql(), $compiled->getParams(), null, ['rows' => $exists ? 1 : 0], 'exists', $tableName, $duration);
            return $exists;
        } catch (Exception $e) {
            $duration = (microtime(true) - $start) * 1000;
            self::logError($e, $compiled->getSql(), $compiled->getParams(), 'exists', $tableName, $duration);
            return false;
        }
    }

    public static function one(
        string|array $table,
        array $where,
        string|array $fields = '*',
        ?string $class = null,
        bool $fail = false
    ): array|object|null {
        $start = microtime(true);
        $tableName = self::tableNameFromMixed($table);

        try {
            $result = self::select($fields, $table, $where, [], [], [], [1, 1], false, \PDO::FETCH_ASSOC, $class);
            $row = $result['data'][0] ?? null;
            $duration = (microtime(true) - $start) * 1000;

            if ($row === null && $fail) {
                $whereStr = json_encode($where);
                throw new \RuntimeException("No record found in '$tableName' with conditions: $whereStr");
            }

            self::logStatus(true, "SELECT ONE", [], null, ['rows' => $row ? 1 : 0], 'one', $tableName, $duration);
            return $row;
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (Exception $e) {
            $duration = (microtime(true) - $start) * 1000;
            self::logError($e, "SELECT ONE", [], 'one', $tableName, $duration);
            throw $e;
        }
    }

    public static function count(string|array $table, array $where = []): int
    {
        $start = microtime(true);
        $tableName = self::tableNameFromMixed($table);
        $compiled = Q::from($table, $where)->count();

        try {
            $count = $compiled->run();
            $duration = (microtime(true) - $start) * 1000;
            self::logStatus(true, $compiled->getSql(), $compiled->getParams(), null, ['rows' => $count], 'count', $tableName, $duration);
            return $count;
        } catch (Exception $e) {
            $duration = (microtime(true) - $start) * 1000;
            self::logError($e, $compiled->getSql(), $compiled->getParams(), 'count', $tableName, $duration);
            return 0;
        }
    }

    public static function insert(string $table, array $data)
    {
        $result = self::action('insert', $table, $data);
        return $result['success'] ? $result['lastId'] : false;
    }

    public static function update(string $table, array $data, array $where = []): int
    {
        $result = self::action('update', $table, $data, $where);
        return $result['count'];
    }

    public static function delete(string $table, array $where = []): int
    {
        $result = self::action('delete', $table, $where);
        return $result['count'];
    }

    public static function status(): array
    {
        return self::$lastStatus;
    }

    // ========== Logging & Events ==========

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
        self::$lastStatus = $extra;
        self::$lastStatus['success']   = $success;
        self::$lastStatus['sql']       = $sql;
        self::$lastStatus['params']    = $params;
        self::$lastStatus['error']     = $error;
        self::$lastStatus['timestamp'] = microtime(true);
        self::$lastStatus['type']      = $type;
        self::$lastStatus['table']     = $table;
        self::$lastStatus['duration']  = $duration;

        if (self::$hasEvents ??= class_exists(__NAMESPACE__ . '\Event')) {
            $eventName = $success ? 'db.success' : 'db.error';
            Event::fire($eventName, self::$lastStatus);
            Event::fire('db.log', self::$lastStatus);
        }
    }

    private static function logError(Exception $e, string $sql, array $params, ?string $type = null, ?string $table = null, ?float $duration = null): void
    {
        self::logStatus(false, $sql, $params, $e->getMessage(), ['code' => $e->getCode()], $type, $table, $duration);
    }

    private static function clearCacheForTable(string $table): void
    {
        if (self::$hasCacheService ??= class_exists('\\RapidBase\\Core\\Cache\\CacheService')) {
            $prefix = "db_select_{$table}_";
            CacheService::clearByPrefix($prefix);
        }
    }

    private static function tableNameFromMixed(mixed $table): string
    {
        if (is_string($table)) {
            return $table;
        }
        $names = [];
        array_walk_recursive($table, function ($value) use (&$names) {
            if (is_string($value)) {
                $names[] = $value;
            }
        });
        return implode('_', $names);
    }
}