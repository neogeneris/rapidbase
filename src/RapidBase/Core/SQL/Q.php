<?php

declare(strict_types=1);

namespace RapidBase\Core\SQL;

use RapidBase\Core\SchemaMap;

/**
 * Q - Fluent SQL query builder (strict B->F pattern).
 *
 * Maximum performance through a two-link chain:
 *   Q::from(...)  →  terminal method (select, insert, update, delete, count, exists)
 *
 * Usage:
 *   Q::from('users', ['status' => 'active'])->select('*', Q::page(1,20), ['-created_at']);
 *   Q::from('users')->insert(['name' => 'Ana', 'email' => 'ana@test.com']);
 *   Q::from('users', ['id' => 5])->update(['name' => 'John']);
 *   Q::from('users', ['id' => 5])->delete();
 *   Q::from('users', ['status' => 'active'])->count();
 *   Q::from('users', ['id' => 5])->exists();
 *   Q::from('(SELECT * FROM users WHERE active=1) AS u')->select('*');
 *
 *   // Nested subqueries
 *   $sub = Q::from('users', ['active' => 1])->select('id, name');
 *   $count = Q::from($sub)->count();
 *
 *   // INSERT SELECT
 *   $source = Q::from('users', ['active' => 1])->select('id, name');
 *   $insert = Q::into('active_users')->insertSelect($source, ['id', 'name']);
 */
class Q
{
    private const T = 0; // Table
    private const F = 1; // Filter / Where

    private array $state;
    private string $connectionId;
    private array $compiledParams = [];

    private function __construct(string $connectionId = 'default')
    {
        $this->connectionId = $connectionId;
        $this->state = [
            self::T => '',
            self::F => [],
        ];
    }

    // ----- Entry points -----

    /**
     * Starts a query from a table, array of tables, subquery string, or CompiledQuery.
     */
    public static function from($table, array $filter = []): self
    {
        $instance = new self();

        if ($table instanceof CompiledQuery) {
            $instance->state[self::T] = (string) $table; // __toString returns (sql)
            $instance->compiledParams = $table->getParams();
        } else {
            $instance->state[self::T] = $table;
            $instance->compiledParams = [];
        }

        $instance->state[self::F] = $filter;
        return $instance;
    }

    /**
     * Starts an INSERT SELECT operation. `into` sets the target table.
     * Use ->insertSelect() to complete the chain.
     */
    public static function into(string $table): self
    {
        $instance = new self();
        $instance->state[self::T] = $table;
        // No filter
        return $instance;
    }

    // ========== Terminal methods ==========

    // --- Standard operations (unchanged except insertSelect) ---

    public function select(
        $fields = null,
        $pagination = null,
        $sort = [],
        $groupBy = null,
        array $having = []
    ): CompiledQuery {
        $base = $this->buildBaseState();
        $fromClause = $base['fromClause'];
        $tablesInfo = $base['tablesInfo'];
        $whereData  = ['sql' => $base['whereSql'], 'params' => $base['whereParams']];

        $groupSql = '';
        if ($groupBy) {
            $groupSql = is_array($groupBy) ? implode(', ', $groupBy) : $groupBy;
        }

        $havingData = empty($having)
            ? ['sql' => '', 'params' => []]
            : (new ConditionMatrix())->parse(
                $having,
                $base['context'] ?? [],
                $base['defaultAlias'] ?? '',
                SchemaMap::getMap($this->connectionId)
              );

        $orderSql = $sort ? $this->buildOrderClause($sort) : '';

        $limit = $pagination;
        $limitSql = '';
        $limitParams = [];
        if ($limit !== null) {
            if (is_array($limit)) {
                $limitSql = '? OFFSET ?';
                $limitParams = [(int)$limit[1], (int)$limit[0]];
            } else {
                $limitSql = '?';
                $limitParams = [(int)$limit];
            }
        }

        $params = array_merge(
            $whereData['params'],
            $havingData['params'],
            $limitParams
        );

        $selectFields = $fields ?? '*';

        $compiledState = [
            SqlCompiler::SEL    => $selectFields,
            SqlCompiler::FROM   => $fromClause,
            SqlCompiler::WHERE  => $whereData['sql'],
            SqlCompiler::GROUP  => $groupSql,
            SqlCompiler::HAVING => $havingData['sql'],
            SqlCompiler::ORDER  => $orderSql,
            SqlCompiler::LIMIT  => $limitSql,
            SqlCompiler::PARAMS => $params,
        ];

        $projectionMap = $this->buildProjectionMap($selectFields, $tablesInfo);

        $compiler = new SqlCompiler();
        [$sql, $params] = $compiler->compileSelect($compiledState);

        return new CompiledQuery($sql, $params, CompiledQuery::SELECT, $projectionMap);
    }

    public function insert(array $rows): CompiledQuery
    {
        $this->ensureSingleTable();
        $compiler = new SqlCompiler();
        $compiledState = [
            SqlCompiler::FROM   => ConditionMatrix::quote($this->resolveSingleTableName()),
            SqlCompiler::PARAMS => [],
        ];
        [$sql, $params] = $compiler->compileInsert($compiledState, $rows);
        return new CompiledQuery($sql, $params, CompiledQuery::INSERT);
    }

    /**
     * INSERT INTO ... SELECT ...
     *
     * @param CompiledQuery $source   The compiled SELECT query to use as source.
     * @param string[]      $columns  Columns in the target table (if empty, inferred from source projection map).
     * @return CompiledQuery
     */
    public function insertSelect(CompiledQuery $source, array $columns = []): CompiledQuery
    {
        $table = ConditionMatrix::quote($this->state[self::T]);

        if (empty($columns)) {
            $map = $source->getProjectionMap();
            if (empty($map)) {
                throw new \InvalidArgumentException(
                    'Columns must be specified or the source CompiledQuery must have a projection map.'
                );
            }
            // Extract column names from projection map keys (handles "table.column" vs "column")
            $columns = array_map(function ($key) {
                return strpos($key, '.') !== false ? substr($key, strrpos($key, '.') + 1) : $key;
            }, array_keys($map));
            $columns = array_unique($columns);
        }

        $colsSql = implode(', ', array_map([ConditionMatrix::class, 'quote'], $columns));
        $sql = "INSERT INTO $table ($colsSql) " . $source->getSql();

        return new CompiledQuery($sql, $source->getParams(), CompiledQuery::INSERT);
    }

    public function update(array $data): CompiledQuery
    {
        $this->ensureSingleTable();
        $whereData = $this->compileWhereSimple();
        $compiledState = [
            SqlCompiler::FROM   => ConditionMatrix::quote($this->resolveSingleTableName()),
            SqlCompiler::WHERE  => $whereData['sql'],
            SqlCompiler::PARAMS => $whereData['params'],
        ];
        $compiler = new SqlCompiler();
        [$sql, $params] = $compiler->compileUpdate($compiledState, $data);
        return new CompiledQuery($sql, $params, CompiledQuery::UPDATE);
    }

    public function delete(): CompiledQuery
    {
        $this->ensureSingleTable();
        $whereData = $this->compileWhereSimple();
        $compiledState = [
            SqlCompiler::FROM   => ConditionMatrix::quote($this->resolveSingleTableName()),
            SqlCompiler::WHERE  => $whereData['sql'],
            SqlCompiler::PARAMS => $whereData['params'],
        ];
        $compiler = new SqlCompiler();
        [$sql, $params] = $compiler->compileDelete($compiledState);
        return new CompiledQuery($sql, $params, CompiledQuery::DELETE);
    }

    public function count(): CompiledQuery
    {
        $base = $this->buildBaseState();
        $fromClause = preg_replace('/^FROM\s+/i', '', $base['fromClause']);
        $compiledState = [
            SqlCompiler::FROM   => $fromClause,
            SqlCompiler::WHERE  => $base['whereSql'],
            SqlCompiler::PARAMS => $base['whereParams'],
        ];
        $compiler = new SqlCompiler();
        [$sql, $params] = $compiler->compileCount($compiledState);
        return new CompiledQuery($sql, $params, CompiledQuery::COUNT);
    }

    public function exists(): CompiledQuery
    {
        $base = $this->buildBaseState();
        $fromClause = preg_replace('/^FROM\s+/i', '', $base['fromClause']);
        $compiledState = [
            SqlCompiler::FROM   => $fromClause,
            SqlCompiler::WHERE  => $base['whereSql'],
            SqlCompiler::PARAMS => $base['whereParams'],
        ];
        $compiler = new SqlCompiler();
        [$sql, $params] = $compiler->compileExists($compiledState);
        return new CompiledQuery($sql, $params, CompiledQuery::EXISTS);
    }

    // ========== Static helpers ==========

    public static function page(int $page, int $perPage = 10): array
    {
        $page   = max(1, $page);
        $offset = ($page - 1) * $perPage;
        return [$offset, $perPage];
    }

    public static function setDriver(string $driver): void
    {
        ConditionMatrix::setDriver($driver);
    }

    public static function quote(string $identifier): string
    {
        return ConditionMatrix::quote($identifier);
    }

    // ========== Private helpers ==========

    private function buildBaseState(): array
    {
        $joinResolver = new JoinResolver($this->connectionId);
        $joinResult   = $joinResolver->resolve($this->state[self::T]);
        $fromClause   = $joinResult['from'];
        $tablesInfo   = $joinResult['tablesInfo'];

        $context = [];
        foreach ($tablesInfo as $info) {
            $context[$info['alias']] = $info['real'];
        }
        $defaultAlias = $tablesInfo[0]['alias'] ?? '';

        $whereData = empty($this->state[self::F])
            ? ['sql' => '', 'params' => []]
            : (new ConditionMatrix())->parse(
                $this->state[self::F],
                $context,
                $defaultAlias,
                SchemaMap::getMap($this->connectionId)
              );

        $whereData['params'] = array_merge($this->compiledParams, $whereData['params']);

        return [
            'fromClause'   => $fromClause,
            'tablesInfo'   => $tablesInfo,
            'context'      => $context,
            'defaultAlias' => $defaultAlias,
            'whereSql'     => $whereData['sql'],
            'whereParams'  => $whereData['params'],
        ];
    }

    private function buildProjectionMap($fields, array $tablesInfo): array
    {
        $map = [];
        $index = 0;

        if ($fields === '*') {
            $schemaMap = SchemaMap::getMap($this->connectionId);
            $schemaTables = $schemaMap['tables'] ?? [];
            foreach ($tablesInfo as $info) {
                $alias = $info['alias'];
                $real = $info['real'];
                if (isset($schemaTables[$real])) {
                    $columns = array_keys($schemaTables[$real]);
                    foreach ($columns as $col) {
                        $map[$alias . '.' . $col] = $index;
                        $index++;
                    }
                }
            }
        } elseif (is_array($fields)) {
            foreach ($fields as $key => $val) {
                if (is_string($key)) {
                    $map[$key] = $index;
                } elseif (is_string($val)) {
                    $this->parseFieldAlias($val, $map, $index);
                } elseif (is_array($val) && count($val) === 2) {
                    $map[$val[1]] = $index;
                }
                $index++;
            }
        } else {
            $this->parseFieldAlias((string)$fields, $map, $index);
        }

        return $map;
    }

    private function parseFieldAlias(string $expression, array &$map, int $index): void
    {
        if (preg_match('/\s+AS\s+([^\s,]+)$/i', $expression, $matches)) {
            $map[trim($matches[1])] = $index;
        } elseif (strpos($expression, '.') !== false) {
            $parts = explode('.', $expression);
            $map[trim(end($parts))] = $index;
        } else {
            $map[trim($expression)] = $index;
        }
    }

    private function compileWhereSimple(): array
    {
        if (empty($this->state[self::F])) {
            return ['sql' => '1', 'params' => $this->compiledParams];
        }
        $whereData = (new ConditionMatrix())->parse($this->state[self::F]);
        $whereData['params'] = array_merge($this->compiledParams, $whereData['params']);
        return $whereData;
    }

    private function buildOrderClause($order): string
    {
        if (is_array($order)) {
            $parts = [];
            foreach ($order as $field) {
                $field = trim($field);
                $dir = 'ASC';
                if (str_starts_with($field, '-')) {
                    $dir = 'DESC';
                    $field = substr($field, 1);
                }
                $parts[] = ConditionMatrix::quote($field) . ' ' . $dir;
            }
            return implode(', ', $parts);
        }

        $fields = explode(',', $order);
        $parts = [];
        foreach ($fields as $field) {
            $field = trim($field);
            $dir = 'ASC';
            if (str_starts_with($field, '-')) {
                $dir = 'DESC';
                $field = substr($field, 1);
            }
            $parts[] = ConditionMatrix::quote($field) . ' ' . $dir;
        }
        return implode(', ', $parts);
    }

    private function resolveSingleTableName(): string
    {
        $table = $this->state[self::T];
        if (is_string($table)) {
            $parts = preg_split('/\s+AS\s+/i', trim($table));
            return trim($parts[0]);
        }
        throw new \RuntimeException('INSERT, UPDATE, and DELETE require a single table.');
    }

    private function ensureSingleTable(): void
    {
        if (is_array($this->state[self::T])) {
            throw new \RuntimeException('INSERT, UPDATE, and DELETE, only support a single table.');
        }
    }
}