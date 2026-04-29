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
                $context,
                $defaultAlias,
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

        $compiledState = [
            SqlCompiler::SEL    => $fields ?? $this->state[self::S] ?? '*',
            SqlCompiler::FROM   => $fromClause,
            SqlCompiler::WHERE  => $whereData['sql'],
            SqlCompiler::GROUP  => $groupSql,
            SqlCompiler::HAVING => $havingData['sql'],
            SqlCompiler::ORDER  => $orderSql,
            SqlCompiler::LIMIT  => $limitSql,
            SqlCompiler::PARAMS => $params,
        ];

        $compiler = new SqlCompiler();
        return $compiler->compileSelect($compiledState);
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

    public function count(): array
    {
        $this->ensureSingleTable();
        $whereData = $this->compileWhereSimple();
        $compiledState = [
            SqlCompiler::FROM   => ConditionMatrix::quote($this->resolveSingleTableName()),
            SqlCompiler::WHERE  => $whereData['sql'],
            SqlCompiler::PARAMS => $whereData['params'],
        ];
        $compiler = new SqlCompiler();
        return $compiler->compileCount($compiledState);
    }

    public function exists(): array
    {
        $this->ensureSingleTable();
        $whereData = $this->compileWhereSimple();
        $compiledState = [
            SqlCompiler::FROM   => ConditionMatrix::quote($this->resolveSingleTableName()),
            SqlCompiler::WHERE  => $whereData['sql'],
            SqlCompiler::PARAMS => $whereData['params'],
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