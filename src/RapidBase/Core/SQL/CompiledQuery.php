<?php

declare(strict_types=1);

namespace RapidBase\Core\SQL;

use RapidBase\Core\Executor;
use RapidBase\Core\Conn;

/**
 * CompiledQuery - Immutable DTO that holds a fully compiled SQL string and its parameters.
 *
 * Also provides:
 * - Type-safe constants for query types.
 * - `run()` method for intelligent execution depending on type.
 * - `asTable()` / `__toString()` for subquery nesting.
 * - `sourceTables` to enable automatic join inference in updateFrom().
 */
class CompiledQuery
{
    // Query type constants (numeric for fast comparison)
    public const SELECT = 1;
    public const COUNT  = 2;
    public const EXISTS = 3;
    public const INSERT = 4;
    public const UPDATE = 5;
    public const DELETE = 6;

    private string $sql;
    private array $params;
    private array $projectionMap;
    private int $type;
    private array $sourceTables;   // real table names used in the query (empty for non‑select)

    public function __construct(
        string $sql,
        array $params,
        int $type,
        array $projectionMap = [],
        array $sourceTables = []
    ) {
        $this->sql = $sql;
        $this->params = $params;
        $this->type = $type;
        $this->projectionMap = $projectionMap;
        $this->sourceTables = $sourceTables;
    }

    public function getSql(): string
    {
        return $this->sql;
    }

    public function getParams(): array
    {
        return $this->params;
    }

    public function getType(): int
    {
        return $this->type;
    }

    public function getProjectionMap(): array
    {
        return $this->projectionMap;
    }

    /**
     * Returns the real table names involved in the query.
     * Used by updateFrom() to auto‑infer join conditions.
     */
    public function getSourceTables(): array
    {
        return $this->sourceTables;
    }

    /**
     * Wrap the SQL as a subquery with the given alias (for use in FROM / JOIN).
     */
    public function asTable(string $alias): string
    {
        return '(' . $this->sql . ') AS ' . ConditionMatrix::quote($alias);
    }

    /**
     * Magic method: returns the SQL wrapped in parentheses, suitable for use as a subquery.
     */
    public function __toString(): string
    {
        return '(' . $this->sql . ')';
    }

    /**
     * Intelligent execution depending on query type.
     *
     * @param string|null $connectionName Name of the connection in Conn (null = default)
     * @param int         $fetchMode      Fetch mode for SELECT (default FETCH_NUM for max speed)
     * @param string|null $class          Class name for FETCH_CLASS
     *
     * @return mixed   - SELECT: array of associative rows (or objects/classes)
     *                 - COUNT: int
     *                 - EXISTS: bool
     *                 - INSERT: string|int (last insert ID)
     *                 - UPDATE/DELETE: int (affected rows)
     *
     * @throws \RuntimeException
     */
    public function run(?string $connectionName = null, int $fetchMode = \PDO::FETCH_NUM, ?string $class = null): mixed
    {
        $pdo = $connectionName ? Conn::get($connectionName) : null;

        switch ($this->type) {
            case self::SELECT:
                $stmt = Executor::query($this->sql, $this->params, $pdo);

                if ($fetchMode === \PDO::FETCH_CLASS && $class !== null) {
                    return $stmt->fetchAll($fetchMode, $class);
                }

                if ($fetchMode !== \PDO::FETCH_NUM) {
                    return $stmt->fetchAll($fetchMode);
                }

                // FETCH_NUM + projection map conversion
                $rows = $stmt->fetchAll(\PDO::FETCH_NUM);
                if (empty($this->projectionMap)) {
                    return $rows;
                }

                return array_map(function ($row) {
                    $assoc = [];
                    foreach ($this->projectionMap as $alias => $idx) {
                        $assoc[$alias] = $row[$idx] ?? null;
                    }
                    return $assoc;
                }, $rows);

            case self::COUNT:
                $stmt = Executor::query($this->sql, $this->params, $pdo);
                return (int) $stmt->fetchColumn();

            case self::EXISTS:
                $stmt = Executor::query($this->sql, $this->params, $pdo);
                return (bool) $stmt->fetchColumn();

            case self::INSERT:
                $result = Executor::action($this->sql, $this->params, $pdo);
                // action() returns ['success' => bool, 'lastId' => ..., 'count' => ...]
                return $result['lastId'] ?? false;

            case self::UPDATE:
            case self::DELETE:
                $result = Executor::action($this->sql, $this->params, $pdo);
                return $result['count'] ?? 0;

            default:
                throw new \RuntimeException("Unknown compiled query type: {$this->type}");
        }
    }
}