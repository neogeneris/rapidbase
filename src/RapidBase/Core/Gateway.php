<?php

namespace RapidBase\Core;

use \Exception;
use \PDO;
use \PDOStatement;
use RapidBase\Core\Cache\CacheService;
use RapidBase\Core\SQL\Q;
use RapidBase\Core\SQL\SqlCompiler; // Solo necesario si quieres las constantes, no imprescindible

/**
 * Class Gateway - Control and dispatch point of the Framework.
 *
 * Now uses the Q fluent builder instead of the old SQL class.
 * Supports automatic JOINs by passing an array of tables.
 */
class Gateway
{
    private static array $lastStatus = [];

    private static ?bool $hasEvents = null;
    private static ?bool $hasCacheService = null;

    // ========== CORE: Pure DB Query ==========

    /**
     * Executes a SELECT query with optional JOINs, grouping, sorting and pagination.
     *
     * @param mixed $fields    Columns to select (string or array).
     * @param mixed $table     Table name (string) or array of tables for automatic JOINs.
     * @param array $where     Conditions.
     * @param array $groupBy   GROUP BY columns.
     * @param array $having    HAVING conditions.
     * @param array $sort      Ordering (array or string, prefix '-' for DESC).
     * @param mixed $page      Page number: 0 = no limit, int = page, [page, perPage].
     * @param bool  $withTotal If true, adds a total count (without pagination).
     * @param int   $fetchMode PDO fetch mode.
     * @param string|null $class Class name for FETCH_CLASS.
     * @return array Keys: data, total, page, limit, source, timestamp, projectionMap.
     */
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
        // Convert pagination to Q format [offset, limit]
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
                $perPage = 10; // default
                $pagination = Q::page($p, $perPage);
                $returnedPage = $p;
                $returnedLimit = $perPage;
            }
        }

        // Build SELECT query using Q
        $query = Q::from($table, $where);
        if (!empty($groupBy)) {
            $query->groupBy($groupBy);
        }
        if (!empty($having)) {
            $query->having($having);
        }
        // Note: for sort we pass it directly to select() terminal method
        [$sql, $params, $projectionMap] = $query->select($fields, $pagination, $sort);

        // Separate total count if requested
        $total = 0;
        if ($withTotal) {
            $countQuery = Q::from($table, $where);
            [$countSql, $countParams] = $countQuery->count();
            $countStmt = Executor::query($countSql, $countParams);
            $total = (int) $countStmt->fetchColumn();
        }

        $start = microtime(true);
        try {
            $stmt = Executor::query($sql, $params);

            if ($fetchMode === \PDO::FETCH_CLASS && $class !== null) {
                $data = $stmt->fetchAll($fetchMode, $class);
            } else {
                $data = $stmt->fetchAll($fetchMode);
            }

            $duration = (microtime(true) - $start) * 1000;
            $tableName = is_array($table) ? implode('_', $table) : (string)$table;
            self::logStatus(true, $sql, $params, null, [], 'select', $tableName, $duration);

            return [
                'data'          => $data,
                'total'         => $withTotal ? $total : count($data),
                'page'          => $returnedPage,
                'limit'         => $returnedLimit,
                'source'        => 'database',
                'timestamp'     => microtime(true),
                'projectionMap' => $projectionMap,
                'fetchMode'     => $fetchMode,
                'class'         => $class
            ];
        } catch (Exception $e) {
            $duration = (microtime(true) - $start) * 1000;
            $tableName = is_array($table) ? implode('_', $table) : (string)$table;
            self::logError($e, $sql, $params, 'select', $tableName, $duration);
            throw $e;
        }
    }

    /**
     * Cached SELECT. L1/L2 cache with automatic invalidation.
     */
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
        $tableName = is_array($table) ? implode('_', $table) : (string)$table;

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

    // ========== ACTIONS: INSERT, UPDATE, DELETE ==========

    /**
     * Executes INSERT, UPDATE, DELETE and invalidates cache for the affected table.
     *
     * @param string $type 'insert', 'update', 'delete'
     * @param mixed ...$args Variable arguments depending on type.
     * @return array with 'success', 'lastId', 'count'.
     */
    public static function action(string $type, ...$args): array
    {
        $table = $args[0] ?? 'unknown';

        // Build the query using Q
        $query = Q::from($table);
        switch ($type) {
            case 'insert':
                [$sql, $params] = $query->insert($args[1] ?? []);
                break;
            case 'update':
                [$sql, $params] = $query->update($args[1] ?? [], $args[2] ?? []);
                break;
            case 'delete':
                [$sql, $params] = $query->delete();
                break;
            default:
                throw new \InvalidArgumentException("Invalid action type: $type");
        }

        $start = microtime(true);
        try {
            $res = Executor::action($sql, $params);
            $duration = (microtime(true) - $start) * 1000;

            if ($res['success']) {
                self::clearCacheForTable($table);
            }

            self::logStatus(true, $sql, $params, null, [
                'id'   => $res['lastId'],
                'rows' => $res['count']
            ], $type, $table, $duration);

            return $res;
        } catch (Exception $e) {
            $duration = (microtime(true) - $start) * 1000;
            self::logError($e, $sql, $params, $type, $table, $duration);
            throw $e;
        }
    }

    /**
     * Batch insert.
     */
    public static function batch(string $table, array $data): bool
    {
        // Q doesn't have batch directly, so we simulate with multiple insert calls
        // For now, we assume Executor::batch still expects a single INSERT with many rows.
        // We'll build a multi-row insert using Q's insert method which already supports multiple rows.
        $query = Q::from($table);
        [$sql, $params] = $query->insert($data); // $data can be a single row or array of rows

        // Note: The old batch() used buildInsert which returned a list of params per row.
        // Now Q::insert() returns a flattened params array already.
        // Executor::batch() will need to be adapted if it expects a list of params per row.
        // For compatibility, we assume Executor::batch() uses the old interface.
        // If not, we'll just use Executor::action instead.
        $start = microtime(true);
        try {
            // Fallback: use single query if batch is not compatible
            $res = Executor::action($sql, $params);
            $duration = (microtime(true) - $start) * 1000;

            if ($res['success']) {
                self::clearCacheForTable($table);
            }

            self::logStatus(true, $sql . " [BATCH]", $params, null, ['count' => $res['count']], 'batch', $table, $duration);
            return true;
        } catch (Exception $e) {
            $duration = (microtime(true) - $start) * 1000;
            self::logError($e, $sql, $params, 'batch', $table, $duration);
            return false;
        }
    }

    /**
     * Invalidate all cache associated with a table.
     * (Q no longer has query cache, only result cache.)
     */
    protected static function clearCacheForTable(string $table): void
    {
        if (self::$hasCacheService ??= class_exists('\\RapidBase\\Core\\Cache\\CacheService')) {
            $prefix = "db_select_{$table}_";
            CacheService::clearByPrefix($prefix);
        }
        // SQL::clearQueryCache() is no longer needed; removed.
    }

    // ========== CONVENIENCE METHODS ==========

    public static function exists(string $table, array $where): bool
    {
        $start = microtime(true);
        [$sql, $params] = Q::from($table, $where)->exists();

        try {
            $stmt = Executor::query($sql, $params);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            $exists = (bool)($row['check'] ?? false);

            $duration = (microtime(true) - $start) * 1000;
            self::logStatus(true, $sql, $params, null, ['rows' => $exists ? 1 : 0], 'exists', $table, $duration);
            return $exists;
        } catch (Exception $e) {
            $duration = (microtime(true) - $start) * 1000;
            self::logError($e, $sql, $params, 'exists', $table, $duration);
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

        try {
            // Use select with LIMIT 1 (page=1, perPage=1)
            $result = self::select($fields, $table, $where, [], [], [], [1, 1], false, \PDO::FETCH_ASSOC, $class);
            $row = $result['data'][0] ?? null;

            $duration = (microtime(true) - $start) * 1000;
            $tableName = is_array($table) ? implode('_', $table) : (string)$table;

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
            $tableName = is_array($table) ? implode('_', $table) : (string)$table;
            self::logError($e, "SELECT ONE", [], 'one', $tableName, $duration);
            throw $e;
        }
    }

    public static function count(string|array $table, array $where = []): int
    {
        $start = microtime(true);
        [$sql, $params] = Q::from($table, $where)->count();

        try {
            $stmt = Executor::query($sql, $params);
            $count = (int)($stmt->fetchColumn() ?: 0);

            $duration = (microtime(true) - $start) * 1000;
            $tableName = is_array($table) ? implode('_', $table) : (string)$table;
            self::logStatus(true, $sql, $params, null, ['rows' => $count], 'count', $tableName, $duration);
            return $count;
        } catch (Exception $e) {
            $duration = (microtime(true) - $start) * 1000;
            $tableName = is_array($table) ? implode('_', $table) : (string)$table;
            self::logError($e, $sql, $params, 'count', $tableName, $duration);
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

    // ========== LOGGING & EVENTS ==========

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
}