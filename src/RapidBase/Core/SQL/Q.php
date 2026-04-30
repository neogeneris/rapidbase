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
 */
class Q
{
    // Internal state indices (numeric for speed)
    private const T = 0; // Table (string or array)
    private const F = 1; // Filter / Where conditions (array)

    private array $state;
    private string $connectionId;

    private function __construct(string $connectionId = 'default')
    {
        $this->connectionId = $connectionId;
        $this->state = [
            self::T => '',
            self::F => [],
        ];
    }

    /**
     * Starts a query (first link of the chain).
     *
     * @param string|array $table  Table name / array of tables / subquery string.
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

    // ========== Terminal methods (second link) ==========

    /**
     * Compiles a SELECT query.
     *
     * @param string|array|null $fields     Columns to select (null = '*', or state from fields() removed).
     * @param mixed             $pagination [offset, limit] array or int as limit.
     * @param string|array      $sort       Ordering (prefix - for DESC).
     * @param string|array|null $groupBy    GROUP BY columns.
     * @param array             $having     HAVING conditions.
     * @return array [sql, params, projectionMap]
     */
    public function select(
        $fields = null,
        $pagination = null,
        $sort = [],
        $groupBy = null,
        array $having = []
    ): array {
        $base = $this->buildBaseState();
        $fromClause = $base['fromClause'];
        $tablesInfo = $base['tablesInfo'];
        $whereData  = ['sql' => $base['whereSql'], 'params' => $base['whereParams']];

        // GROUP BY
        $groupSql = '';
        if ($groupBy) {
            $groupSql = is_array($groupBy) ? implode(', ', $groupBy) : $groupBy;
        }

        // HAVING
        $havingData = empty($having)
            ? ['sql' => '', 'params' => []]
            : (new ConditionMatrix())->parse(
                $having,
                $base['context'] ?? [],
                $base['defaultAlias'] ?? '',
                SchemaMap::getMap($this->connectionId)
              );

        // ORDER BY
        $orderSql = $sort ? $this->buildOrderClause($sort) : '';

        // LIMIT / OFFSET
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

        // Merge parameters in order: WHERE, HAVING, LIMIT
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

    public function count(): array
    {
        $base = $this->buildBaseState();
        $fromClause = preg_replace('/^FROM\s+/i', '', $base['fromClause']);
        $compiledState = [
            SqlCompiler::FROM   => $fromClause,
            SqlCompiler::WHERE  => $base['whereSql'],
            SqlCompiler::PARAMS => $base['whereParams'],
        ];
        $compiler = new SqlCompiler();
        return $compiler->compileCount($compiledState);
    }

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