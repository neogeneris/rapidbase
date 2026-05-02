<?php

namespace RapidBase\Core;

use RapidBase\Core\SQL\CompiledQuery;

/**
 * Class Executor - Atomic SQL statement executor.
 * Centralizes execution, error handling and transactions.
 *
 * Now features an intelligent execute() method that acts based on the
 * CompiledQuery type, and accepts an optional connection name.
 */
class Executor
{
    /**
     * Executes a compiled query intelligently depending on its type.
     *
     * @param CompiledQuery $cq             The compiled query object.
     * @param int           $fetchMode      Fetch mode for SELECT (default FETCH_NUM).
     * @param string|null   $class          Class name for FETCH_CLASS.
     * @param string|null   $connectionName Connection name in Conn pool (null = default).
     * @return mixed   - SELECT: array of rows (associative if projection map present, or per fetch mode)
     *                 - COUNT: int
     *                 - EXISTS: bool
     *                 - INSERT: string|int (last insert ID)
     *                 - UPDATE/DELETE: int (affected rows)
     */
    public static function execute(
        CompiledQuery $cq,
        int $fetchMode = \PDO::FETCH_NUM,
        ?string $class = null,
        ?string $connectionName = null
    ): mixed {
        switch ($cq->getType()) {
            case CompiledQuery::SELECT:
                $stmt = self::query($cq->getSql(), $cq->getParams(), $connectionName);

                if ($fetchMode === \PDO::FETCH_CLASS && $class !== null) {
                    return $stmt->fetchAll($fetchMode, $class);
                }

                if ($fetchMode !== \PDO::FETCH_NUM) {
                    return $stmt->fetchAll($fetchMode);
                }

                // FETCH_NUM + projection map conversion
                $rows = $stmt->fetchAll(\PDO::FETCH_NUM);
                $map = $cq->getProjectionMap();
                if (empty($map)) {
                    return $rows;
                }

                return array_map(function ($row) use ($map) {
                    $assoc = [];
                    foreach ($map as $alias => $idx) {
                        $assoc[$alias] = $row[$idx] ?? null;
                    }
                    return $assoc;
                }, $rows);

            case CompiledQuery::COUNT:
                $stmt = self::query($cq->getSql(), $cq->getParams(), $connectionName);
                return (int) $stmt->fetchColumn();

            case CompiledQuery::EXISTS:
                $stmt = self::query($cq->getSql(), $cq->getParams(), $connectionName);
                return (bool) $stmt->fetchColumn();

            case CompiledQuery::INSERT:
                $result = self::action($cq->getSql(), $cq->getParams(), $connectionName);
                return $result['lastId'] ?? false;

            case CompiledQuery::UPDATE:
            case CompiledQuery::DELETE:
                $result = self::action($cq->getSql(), $cq->getParams(), $connectionName);
                return $result['count'] ?? 0;

            default:
                throw new \RuntimeException("Unknown compiled query type: {$cq->getType()}");
        }
    }

    // ========== Legacy methods (string $sql, array $params) ==========

    /**
     * Executes a SELECT statement and returns the PDOStatement.
     *
     * @param string      $sql
     * @param array       $params
     * @param string|null $connectionName
     * @return \PDOStatement
     */
    public static function query(string $sql, array $params = [], ?string $connectionName = null): \PDOStatement
    {
        $pdo = Conn::get($connectionName);
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (\PDOException $e) {
            throw new \RuntimeException("Query error: " . $e->getMessage() . " | SQL: $sql");
        }
    }

    /**
     * Executes write statements (INSERT, UPDATE, DELETE).
     *
     * @param string      $sql
     * @param array       $params
     * @param string|null $connectionName
     * @return array
     */
    public static function action(string $sql, array $params = [], ?string $connectionName = null): array
    {
        $pdo = Conn::get($connectionName);
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            return [
                'count'   => $stmt->rowCount(),
                'lastId'  => $pdo->lastInsertId(),
                'success' => true
            ];
        } catch (\PDOException $e) {
            throw new \RuntimeException("Write error (Action): " . $e->getMessage() . " | SQL: $sql");
        }
    }

    /**
     * Creates a Generator (Cursor) for iterating massive results without RAM exhaustion.
     */
    public static function stream(string $sql, array $params = [], ?string $connectionName = null): \Generator
    {
        $stmt = self::query($sql, $params, $connectionName);
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            yield $row;
        }
    }

    /**
     * Executes a series of operations inside an atomic transaction.
     */
    public static function transaction(callable $callback, ?string $connectionName = null): mixed
    {
        $pdo = Conn::get($connectionName);
        try {
            $pdo->beginTransaction();
            $result = $callback($pdo);
            $pdo->commit();
            return $result;
        } catch (\Exception $e) {
            if ($pdo && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new \RuntimeException("Transaction failed: " . $e->getMessage());
        }
    }

    /**
     * Executes the same SQL statement for multiple parameter sets.
     */
    public static function batch(string $sql, array $params_list, ?string $connectionName = null): int
    {
        $pdo = Conn::get($connectionName);
        $totalAffected = 0;
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare($sql);
            foreach ($params_list as $params) {
                $stmt->execute($params);
                $totalAffected += $stmt->rowCount();
            }
            $pdo->commit();
            return $totalAffected;
        } catch (\Exception $e) {
            if ($pdo && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new \RuntimeException("Batch error: " . $e->getMessage());
        }
    }
}