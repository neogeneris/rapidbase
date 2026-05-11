<?php

namespace RapidBase\Search;

use RapidBase\Core\SchemaMap;
use RapidBase\Core\Cache\CacheService;

class Search
{
    private array $tables = [];
    private array $tableAliases = [];
    private array $columns = [];
    private string $term = '';
    private string $wildcard = 'both';
    private bool $caseSensitive = false;

    private function __construct(string|array $tables, array $aliases = [])
    {
        $this->tables = is_array($tables) ? $tables : [$tables];
        $this->tableAliases = $aliases;
    }

    public static function on(string $table): self
    {
        return new self($table);
    }

    public static function onTables(array $tables, array $aliases = []): self
    {
        return new self($tables, $aliases);
    }

    public function columns(array $columns): self
    {
        $this->columns = $columns;
        return $this;
    }

    public function like(string $term, string $wildcard = 'both', bool $caseSensitive = false): self
    {
        $this->term = $term;
        $this->wildcard = $wildcard;
        $this->caseSensitive = $caseSensitive;
        return $this;
    }

    /**
     * Devuelve el array de condiciones listo para usar en Q::from() o X::from().
     */
    public function get(): array
    {
        if ($this->term === '') {
            return [];
        }

        $columns = $this->resolveColumns();
        if (empty($columns)) {
            return [];
        }

        $pattern = $this->buildPattern();
        $operator = $this->caseSensitive ? 'LIKE BINARY' : '~';

        $conditions = [];
        foreach ($columns as $col) {
            $conditions[] = [$col => [$operator => $pattern]];
        }

        return ['|' => $conditions];
    }

    private function resolveColumns(): array
    {
        if (!empty($this->columns)) {
            return $this->columns;
        }

        $map = SchemaMap::getMap();
        $allColumns = [];

        foreach ($this->tables as $idx => $table) {
            $tableSchema = $map['tables'][$table] ?? null;
            if (!$tableSchema) {
                continue;
            }

            $alias = $this->tableAliases[$idx] ?? $table;

            $textTypes = ['text', 'varchar', 'char', 'tinytext', 'mediumtext', 'longtext', 'string'];
            foreach ($tableSchema as $colName => $def) {
                $type = strtolower($def['type'] ?? '');
                if (in_array($type, $textTypes) || str_contains($type, 'char') || str_contains($type, 'text')) {
                    $allColumns[] = $alias . '.' . $colName;
                }
            }
        }

        return $allColumns;
    }

    private function buildPattern(): string
    {
        $term = addcslashes($this->term, '%_');
        return match ($this->wildcard) {
            'prefix' => $term . '%',
            'suffix' => '%' . $term,
            'none'   => $term,
            default  => '%' . $term . '%',
        };
    }
}