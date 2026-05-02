<?php

declare(strict_types=1);

namespace RapidBase\Core\SQL;

use RapidBase\Core\Executor;

/**
 * Immutable DTO that holds a fully compiled SQL string, its parameters,
 * the type of query, an optional projection map, and the source tables.
 *
 * Provides type‑safe constants for query types and a convenience
 * `run()` method that delegates execution to the central Executor.
 */
class CompiledQuery
{
    // ── Query type constants (numeric for fast comparison) ──
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
    private array $sourceTables;

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

    /** Returns the real table names used in the query (for auto‑join inference). */
    public function getSourceTables(): array
    {
        return $this->sourceTables;
    }

    /** Wrap the SQL as a subquery with the given alias. */
    public function asTable(string $alias): string
    {
        return '(' . $this->sql . ') AS ' . ConditionMatrix::quote($alias);
    }

    /** Magic method: returns the SQL wrapped in parentheses (used for subqueries). */
    public function __toString(): string
    {
        return '(' . $this->sql . ')';
    }

    /**
     * Convenience method that executes the compiled query through the central Executor.
     *
     * @param int|null    $fetchMode      PDO fetch mode (default FETCH_NUM)
     * @param string|null $class          Class name for FETCH_CLASS
     * @param string|null $connectionName Connection name in the Conn pool (null = default)
     * @return mixed
     */
    public function run(
        ?int $fetchMode = null,
        ?string $class = null,
        ?string $connectionName = null
    ): mixed {
        $fetchMode = $fetchMode ?? \PDO::FETCH_NUM;

        return Executor::execute($this, $fetchMode, $class, $connectionName);
    }
}