<?php

declare(strict_types=1);

namespace RapidBase\Core\SQL;

use RapidBase\Core\Executor;
use RapidBase\Core\Conn;

class CompiledQuery
{
    public const SELECT = 1;
    public const COUNT  = 2;
    public const EXISTS = 3;
    public const INSERT = 4;
    public const UPDATE = 5;
    public const DELETE = 6;
    public const UPSERT = 7;

    private string $sql;
    private array $params;
    private array $projectionMap;
    private int $type;
    private array $sourceTables;
    private bool $isSimple;

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

    public function getSql(): string { return $this->sql; }
    public function getParams(): array { return $this->params; }
    public function getType(): int { return $this->type; }
    public function getProjectionMap(): array { return $this->projectionMap; }
    public function setProjectionMap(array $map): void{$this->projectionMap = $map;}
	public function getSourceTables(): array { return $this->sourceTables; }

    public function asTable(string $alias): string {
        return '(' . $this->sql . ') AS ' . ConditionMatrix::quote($alias);
    }

    public function __toString(): string {
        return '(' . $this->sql . ')';
    }

    /**
     * Intelligent execution depending on query type.
     *
     * @param int         $fetchMode      Fetch mode for SELECT (default FETCH_NUM for max speed)
     * @param string|null $class          Class name for FETCH_CLASS
     * @param string|null $connectionName Name of the connection in Conn (null = default)
     *
     * @return mixed
     */
    public function run(?int $fetchMode = null, ?string $class = null, ?string $connectionName = null): array
	{
		$fetchMode = $fetchMode ?? \PDO::FETCH_NUM;
		return Executor::execute($this, $fetchMode, $class, $connectionName);
	}
}