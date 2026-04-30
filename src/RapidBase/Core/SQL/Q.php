<?php

declare(strict_types=1);

namespace RapidBase\Core\SQL;

use RapidBase\Core\SchemaMap;

/**
 * Q - Fluent SQL query builder.
 *
 * Single entry point, maximum performance.
 *
 * Usage:
 *   Q::from('users', ['status' => 'active'])->select('*', Q::page(1,20), ['-created_at']);
 *   Q::from(['users', 'posts'], ['u.status' => 'active'])->select('*');
 *   Q::from('users', ['id' => 5])->update(['name' => 'John']);
 *   Q::from('users')->insert(['name' => 'Ana', 'email' => 'ana@test.com']);
 *   Q::from('users', ['id' => 5])->delete();
 *   Q::from('users', ['status' => 'active'])->count();
 *   Q::from('users', ['id' => 5])->exists();
 *   // Subquery support:
 *   Q::from('(SELECT * FROM users WHERE active = 1) AS active_users')->select('*');
 *   Q::from('SELECT * FROM users')->count();   // auto alias
 */
class Q
{
    private const T = 0; // Table
    private const F = 1; // Filter / Where
    private const O = 2; // Order
    private const L = 3; // Limit
    private const G = 4; // Group
    private const H = 5; // Having
    private const S = 6; // Select fields

    private array $state;
    private string $connectionId;

    private function __construct(string $connectionId = 'default')
    {
        $this->connectionId = $connectionId;
        $this->state = [
            self::T => '',
            self::F => [],
            self::O => null,
            self::L => null,
            self::G => null,
            self::H => [],
            self::S => null,
        ];
    }

    /**
     * Starts a query.
     *
     * @param string|array $table  Table or array of tables (activates auto-join).
     * @param array        $filter Initial WHERE conditions.
     * @return self
     */
    public static function from($table, array $filter = []): self
    {
        $instance = new self();
        $instance->state[self::T] = $table;
        $instance->state[self::F] = $filter;
        return $instance;
    }

    // ========== Optional fluent methods ==========

    public function fields($fields): self
    {
        $this->state[self::S] = $fields;
        return $this;
    }

    public function orderBy($order): self
    {
        $this->state[self::O] = $order;
        return $this;
    }

    public function limit(int $limit): self
    {
        $this->state[self::L] = $limit;
        return $this;
    }

    public function groupBy($fields): self
    {
        $this->state[self::G] = $fields;
        return $this;
    }

    public function having(array $filter): self
    {
        $this->state[self::H] = $filter;
        return $this;
    }

    // ========== Terminal methods ==========

    /**
     * Compiles a SELECT query.
     *
     * @param string|array|null $fields     Columns to select.
     * @param mixed             $pagination [offset, limit] array or int as limit.
     * @param string|array      $sort       Ordering (prefix - for DESC).
     * @return array [sql, params, projectionMap]
     */
    public function select($fields = null, $pagination = null, $sort = []): array
    {
        $base = $this->buildBaseState();
        $fromClause = $base['fromClause'];
        $tablesInfo = $base['tablesInfo'];
        $whereData = ['sql' => $base['whereSql'], 'params' => $base['whereParams']];

        $groupSql = '';
        if ($this->state[self::G]) {
            $groupSql = is_array($this->state[self::G])
                ? implode(', ', $this->state[self::G])
                : $this->state[self::G];
        }

        $havingData = empty($this->state[self::H])
            ? ['sql' => '', 'params' => []]
            : (new ConditionMatrix())->parse(
                $this->state[self::H],
                $base['context'] ?? [],
                $base['defaultAlias'] ?? '',
                SchemaMap::getMap($this->connectionId)
              );

        $order = $sort ?: $this->state[self::O];
        $orderSql = $order ? $this->buildOrderClause($order) : '';

        $limit = $pagination ?? $this->state[self::L];
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

        $selectFields = $fields ?? $this->state[self::S] ?? '*';

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

        // Generate projection map before compiling SQL
        $projectionMap = $this->buildProjectionMap($selectFields, $tablesInfo);

        $compiler = new SqlCompiler();
        [$sql, $params] = $compiler->compileSelect($compiledState);

        return [$sql, $params, $projectionMap];
    }

    public function insert(array $rows): array
    {
        $this->ensureSingleTable();
        $compiler = new SqlCompiler();
        $compiledState = [
            SqlCompiler::FROM   => ConditionMatrix::quote($this->resolveSingleTableName()),
            SqlCompiler::PARAMS => [],
        ];
        return $compiler->compileInsert($compiledState, $rows);
    }

    public function update(array $data): array
    {
        $this->ensureSingleTable();
        $whereData = $this->compileWhereSimple();
        $compiledState = [
            SqlCompiler::FROM   => ConditionMatrix::quote($this->resolveSingleTableName()),
            SqlCompiler::WHERE  => $whereData['sql'],
            SqlCompiler::PARAMS => $whereData['params'],
        ];
        $compiler = new SqlCompiler();
        return $compiler->compileUpdate($compiledState, $data);
    }

    public function delete(): array
    {
        $this->ensureSingleTable();
        $whereData = $this->compileWhereSimple();
        $compiledState = [
            SqlCompiler::FROM   => ConditionMatrix::quote($this->resolveSingleTableName()),
            SqlCompiler::WHERE  => $whereData['sql'],
            SqlCompiler::PARAMS => $whereData['params'],
        ];
        $compiler = new SqlCompiler();
        return $compiler->compileDelete($compiledState);
    }

    /**
     * Compiles a COUNT query. Accepts multiple tables (JOINs) and subqueries.
     */
    public function count(): array
    {
        $base = $this->buildBaseState();
        // Remove leading "FROM " because SqlCompiler adds its own FROM
        $fromClause = preg_replace('/^FROM\s+/i', '', $base['fromClause']);
        $compiledState = [
            SqlCompiler::FROM   => $fromClause,
            SqlCompiler::WHERE  => $base['whereSql'],
            SqlCompiler::PARAMS => $base['whereParams'],
        ];
        $compiler = new SqlCompiler();
        return $compiler->compileCount($compiledState);
    }

    /**
     * Compiles an EXISTS query. Accepts multiple tables (JOINs) and subqueries.
     */
    public function exists(): array
    {
        $base = $this->buildBaseState();
        $fromClause = preg_replace('/^FROM\s+/i', '', $base['fromClause']);
        $compiledState = [
            SqlCompiler::FROM   => $fromClause,
            SqlCompiler::WHERE  => $base['whereSql'],
            SqlCompiler::PARAMS => $base['whereParams'],
        ];
        $compiler = new SqlCompiler();
        return $compiler->compileExists($compiledState);
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

    /**
     * Builds the base state for queries that support multiple tables:
     * FROM/JOIN clause, WHERE conditions, tables information.
     */
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

        return [
            'fromClause'   => $fromClause,
            'tablesInfo'   => $tablesInfo,
            'context'      => $context,
            'defaultAlias' => $defaultAlias,
            'whereSql'     => $whereData['sql'],
            'whereParams'  => $whereData['params'],
        ];
    }

    /**
     * Generates a projection map (column alias => numeric index)
     * for FETCH_NUM mode. For '*' it expands columns using SchemaMap.
     */
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
            return ['sql' => '1', 'params' => []];
        }
        return (new ConditionMatrix())->parse($this->state[self::F]);
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
        throw new \RuntimeException('INSERT, UPDATE, DELETE, COUNT and EXISTS require a single table.');
    }

    private function ensureSingleTable(): void
    {
        if (is_array($this->state[self::T])) {
            throw new \RuntimeException('INSERT, UPDATE, DELETE, COUNT and EXISTS only support a single table.');
        }
    }
}