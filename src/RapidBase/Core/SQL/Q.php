<?php

declare(strict_types=1);

namespace RapidBase\Core\SQL;

use RapidBase\Core\SchemaMap;

class Q
{
    private const T = 0;      // Table
    private const F = 1;      // Filter / Where
    private const ORIGIN = 2; // 'from' o 'into'

    private array $state;
    private string $connectionId;
    private array $compiledParams = [];

    /** @var array<string, array> Cache de proyección para '*' */
    private static array $starProjectionCache = [];

    private function __construct(string $connectionId = 'main')
    {
        $this->connectionId = $connectionId;
        $this->state = [
            self::T      => '',
            self::F      => [],
            self::ORIGIN => 'from',
        ];
    }

    public static function from($table, array $filter = []): self
    {
        $instance = new self();
        if ($table instanceof CompiledQuery) {
            $instance->state[self::T] = (string) $table;
            $instance->compiledParams = $table->getParams();
        } else {
            $instance->state[self::T] = $table;
            $instance->compiledParams = [];
        }
        $instance->state[self::F]      = $filter;
        $instance->state[self::ORIGIN] = 'from';
        return $instance;
    }

    public static function into(string $table): self
    {
        $instance = new self();
        $instance->state[self::T]      = $table;
        $instance->state[self::ORIGIN] = 'into';
        return $instance;
    }

    // ========== Terminal methods ==========

    public function select(
        $fields = null,
        $pagination = null,
        $sort = [],
        $groupBy = null,
        array $having = [],
        bool $withTotal = false
    ): CompiledQuery {
        $selectFields = $fields ?? '*';
        
        if ($withTotal && empty($groupBy)) {
            $totalFunc = 'COUNT(*) OVER() AS ' . ConditionMatrix::quote('_total');
            if (is_array($selectFields)) {
                array_unshift($selectFields, $totalFunc);
            } else {
                $selectFields = $totalFunc . ', ' . $selectFields;
            }
        }

        if ($groupBy === null && empty($having) && empty($this->compiledParams) && $this->isSimpleTable()) {
            return $this->compileSimpleSelect($selectFields, $pagination, $sort);
        }

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
            : ConditionMatrix::parse(
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

        [$sql, $params, $projectionMap] = SqlCompiler::compileSelect($compiledState);

        $sourceTables = [];
        foreach ($tablesInfo as $info) {
            if (!empty($info['real']) && is_string($info['real']) && !str_starts_with($info['real'], '(')) {
                $sourceTables[] = $info['real'];
            }
        }

        return new CompiledQuery($sql, $params, CompiledQuery::SELECT, $projectionMap, $sourceTables);
    }

    public function insert(array|CompiledQuery $rows, ?callable $transformer = null): CompiledQuery
    {
        $this->ensureSingleTable();

        // Normalizar objetos a arrays si es necesario
        if (is_array($rows) && !isset($rows['columns'], $rows['values']) && !($rows instanceof CompiledQuery)) {
            $first = reset($rows);
            if (is_object($first)) {
                $rows = array_map(function ($obj) { return (array) $obj; }, $rows);
            }
        }

        if ($rows instanceof CompiledQuery) {
            return $this->insertSelect($rows);
        }

        if (isset($rows['columns'], $rows['values'])) {
            $columns   = $rows['columns'];
            $valuesStr = $rows['values'];

            if (empty($columns) || empty($valuesStr)) {
                return new CompiledQuery('SELECT 1 WHERE 1=0', [], CompiledQuery::SELECT);
            }

            $quotedCols = array_map([ConditionMatrix::class, 'quote'], $columns);
            $table = ConditionMatrix::quote($this->resolveSingleTableName());
            $sql = "INSERT INTO $table (" . implode(', ', $quotedCols) . ") VALUES $valuesStr";

            return new CompiledQuery($sql, [], CompiledQuery::INSERT);
        }

        if ($transformer !== null) {
            $mapped = [];
            foreach ($rows as $row) {
                $newRow = $transformer($row);
                if ($newRow !== false) {
                    $mapped[] = $newRow;
                }
            }
            $rows = $mapped;
        }

        if (empty($rows)) {
            return new CompiledQuery('SELECT 1 WHERE 1=0', [], CompiledQuery::SELECT);
        }

        $compiledState = [
            SqlCompiler::FROM   => ConditionMatrix::quote($this->resolveSingleTableName()),
            SqlCompiler::PARAMS => [],
        ];
        [$sql, $params] = SqlCompiler::compileInsert($compiledState, $rows);
        return new CompiledQuery($sql, $params, CompiledQuery::INSERT);
    }

    public function insertFrom(CompiledQuery $source, array $columns = []): CompiledQuery
    {
        return $this->insertSelect($source, $columns);
    }

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
            $columns = array_map(function ($key) {
                return strpos($key, '.') !== false
                    ? substr($key, strrpos($key, '.') + 1)
                    : $key;
            }, array_keys($map));
            $columns = array_unique($columns);
        }

        $colsSql = implode(', ', array_map([ConditionMatrix::class, 'quote'], $columns));
        $sql = "INSERT INTO $table ($colsSql) " . $source->getSql();
        return new CompiledQuery($sql, $source->getParams(), CompiledQuery::INSERT);
    }

    public function values(?int $limit = null, int $offset = 0): array
    {
        if ($limit !== null) {
            $compiled = $this->select('*', [$offset, $limit]);
        } else {
            $compiled = $this->select('*');
        }

        $result = $compiled->run(\PDO::FETCH_NUM);

        $cols = $result['cols'] ?? [];
        $rows = $result['rows'] ?? [];

        $valuesStr = '';
        if (!empty($rows)) {
            $quotedRows = [];
            foreach ($rows as $row) {
                $quoted = array_map(function ($val) {
                    if (is_null($val)) return 'NULL';
                    if (is_int($val) || is_float($val)) return (string)$val;
                    return "'" . addslashes((string)$val) . "'";
                }, $row);
                $quotedRows[] = '(' . implode(',', $quoted) . ')';
            }
            $valuesStr = implode(',', $quotedRows);
        }

        return [
            'columns' => $cols,
            'values'  => $valuesStr,
        ];
    }

    public function upsert(array $data, array $conflictColumns = []): CompiledQuery
    {
        $table = ConditionMatrix::quote($this->state[self::T]);
        $columns = array_keys($data);
        $quotedCols = array_map([ConditionMatrix::class, 'quote'], $columns);

        $params = [];
        $placeholders = [];
        foreach ($columns as $col) {
            $placeholders[] = '?';
            $params[] = $data[$col];
        }

        $driver = ConditionMatrix::getDriver();
        switch ($driver) {
            case 'mysql':
                $insert = sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, implode(', ', $quotedCols), implode(', ', $placeholders));
                $updates = [];
                foreach ($columns as $col) {
                    if (!in_array($col, $conflictColumns, true)) {
                        $qcol = ConditionMatrix::quote($col);
                        $updates[] = "$qcol = VALUES($qcol)";
                    }
                }
                $sql = !empty($updates)
                    ? $insert . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates)
                    : str_replace('INSERT INTO', 'INSERT IGNORE INTO', $insert);
                break;
            case 'sqlite':
            case 'pgsql':
            default:
                $conflictCols = array_map([ConditionMatrix::class, 'quote'], $conflictColumns);
                $conflictStr = implode(', ', $conflictCols);
                $insert = sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, implode(', ', $quotedCols), implode(', ', $placeholders));
                if (!empty($conflictColumns)) {
                    $updates = [];
                    foreach ($columns as $col) {
                        if (!in_array($col, $conflictColumns, true)) {
                            $qcol = ConditionMatrix::quote($col);
                            $updates[] = "$qcol = excluded.$qcol";
                        }
                    }
                    $sql = !empty($updates)
                        ? $insert . ' ON CONFLICT (' . $conflictStr . ') DO UPDATE SET ' . implode(', ', $updates)
                        : $insert . ' ON CONFLICT (' . $conflictStr . ') DO NOTHING';
                } else {
                    $sql = $insert;
                }
        }
        return new CompiledQuery($sql, $params, CompiledQuery::UPSERT);
    }

    /**
     * UPDATE table SET col=?,... WHERE ... [LIMIT n]
     *
     * @param array|object $data  Datos a actualizar (array asociativo u objeto)
     * @param int|null     $limit Máximo de filas a afectar (null = sin límite)
     */
    public function update(array|object $data, ?int $limit = null): CompiledQuery
    {
        $this->ensureSingleTable();

        if (is_object($data)) {
            $data = (array) $data;
        }

        $whereData = $this->compileWhereSimple();
        $table     = $this->resolveSingleTableName();
        $quotedTable = ConditionMatrix::quote($table);
        $driver    = ConditionMatrix::getDriver();

        $setParts = [];
        $setParams = [];
        foreach ($data as $col => $val) {
            $setParts[] = ConditionMatrix::quote($col) . ' = ?';
            $setParams[] = $val;
        }
        $setSql = implode(', ', $setParts);
        $params = array_merge($setParams, $whereData['params']);

        $whereSql = $whereData['sql'] !== '1' ? ' WHERE ' . $whereData['sql'] : '';

        if ($limit !== null) {
            if ($driver === 'mysql') {
                $sql = "UPDATE $quotedTable SET $setSql$whereSql LIMIT " . (int)$limit;
                return new CompiledQuery($sql, $params, CompiledQuery::UPDATE);
            } else {
                $idCol = $this->resolveLimitIdentifier($driver, $table);
                $quotedId = ConditionMatrix::quote($idCol);
                $sql = "UPDATE $quotedTable SET $setSql WHERE $quotedId IN (SELECT $quotedId FROM $quotedTable$whereSql LIMIT " . (int)$limit . ")";
                return new CompiledQuery($sql, $params, CompiledQuery::UPDATE);
            }
        }

        // Sin límite: delegar en SqlCompiler
        $compiledState = [
            SqlCompiler::FROM   => ConditionMatrix::quote($table),
            SqlCompiler::WHERE  => $whereData['sql'],
            SqlCompiler::PARAMS => $params,
        ];
        [$sql, $params] = SqlCompiler::compileUpdate($compiledState, $data);
        return new CompiledQuery($sql, $params, CompiledQuery::UPDATE);
    }

    public function updateFrom(CompiledQuery $source, array $data, ?string $joinCondition = null): CompiledQuery
    {
        $targetTable = ConditionMatrix::quote($this->state[self::T]);
        $driver = ConditionMatrix::getDriver();
        if ($joinCondition === null) {
            $sourceTables = $source->getSourceTables();
            $joinCondition = $this->inferJoinCondition($this->resolveSingleTableName(), $sourceTables);
        }

        $setParts = [];
        $setParams = [];
        foreach ($data as $col => $val) {
            $setParts[] = ConditionMatrix::quote($col) . ' = ?';
            $setParams[] = $val;
        }
        $setSql = implode(', ', $setParts);
        $params = array_merge($setParams, $source->getParams());

        if ($driver === 'mysql') {
            $sql = "UPDATE $targetTable INNER JOIN ({$source->getSql()}) AS _src $joinCondition SET $setSql";
        } else {
            $sql = "UPDATE $targetTable SET $setSql FROM ({$source->getSql()}) AS _src WHERE $joinCondition";
        }
        return new CompiledQuery($sql, $params, CompiledQuery::UPDATE);
    }

    /**
     * DELETE FROM table WHERE ... [LIMIT n]
     *
     * @param int|null $limit Máximo de filas a borrar (null = sin límite)
     */
    public function delete(?int $limit = null): CompiledQuery
    {
        $this->ensureSingleTable();

        $whereData = $this->compileWhereSimple();
        $table     = $this->resolveSingleTableName();
        $quotedTable = ConditionMatrix::quote($table);
        $driver    = ConditionMatrix::getDriver();
        $params    = $whereData['params'];

        $whereSql = $whereData['sql'] !== '1' ? ' WHERE ' . $whereData['sql'] : '';

        if ($limit !== null) {
            if ($driver === 'mysql') {
                $sql = "DELETE FROM $quotedTable$whereSql LIMIT " . (int)$limit;
                return new CompiledQuery($sql, $params, CompiledQuery::DELETE);
            } else {
                $idCol = $this->resolveLimitIdentifier($driver, $table);
                $quotedId = ConditionMatrix::quote($idCol);
                $sql = "DELETE FROM $quotedTable WHERE $quotedId IN (SELECT $quotedId FROM $quotedTable$whereSql LIMIT " . (int)$limit . ")";
                return new CompiledQuery($sql, $params, CompiledQuery::DELETE);
            }
        }

        // Sin límite: delegar en SqlCompiler
        $compiledState = [
            SqlCompiler::FROM   => ConditionMatrix::quote($table),
            SqlCompiler::WHERE  => $whereData['sql'],
            SqlCompiler::PARAMS => $params,
        ];
        [$sql, $params] = SqlCompiler::compileDelete($compiledState);
        return new CompiledQuery($sql, $params, CompiledQuery::DELETE);
    }

    public function count(): CompiledQuery
    {
        if ($this->isSimpleTable() && empty($this->compiledParams)) {
            return $this->compileSimpleCount();
        }

        $base = $this->buildBaseState();
        $fromClause = preg_replace('/^FROM\s+/i', '', $base['fromClause']);
        $compiledState = [
            SqlCompiler::FROM   => $fromClause,
            SqlCompiler::WHERE  => $base['whereSql'],
            SqlCompiler::PARAMS => $base['whereParams'],
        ];
        [$sql, $params] = SqlCompiler::compileCount($compiledState);
        return new CompiledQuery($sql, $params, CompiledQuery::COUNT);
    }

    public function exists(): CompiledQuery
    {
        if ($this->isSimpleTable() && empty($this->compiledParams)) {
            return $this->compileSimpleExists();
        }

        $base = $this->buildBaseState();
        $fromClause = preg_replace('/^FROM\s+/i', '', $base['fromClause']);
        $compiledState = [
            SqlCompiler::FROM   => $fromClause,
            SqlCompiler::WHERE  => $base['whereSql'],
            SqlCompiler::PARAMS => $base['whereParams'],
        ];
        [$sql, $params] = SqlCompiler::compileExists($compiledState);
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

    private function resolveLimitIdentifier(string $driver, string $table): string
    {
        return match ($driver) {
            'pgsql'  => 'ctid',
            'sqlite' => 'rowid',
            default  => $this->getTablePrimaryKey($table),
        };
    }

    private function getTablePrimaryKey(string $table): string
    {
        $map = SchemaMap::getMap($this->connectionId);
        $columns = $map['tables'][$table] ?? [];
        foreach ($columns as $colName => $def) {
            if (!empty($def['primary'])) {
                return $colName;
            }
        }
        return 'id'; // fallback absoluto
    }

    private function isSimpleTable(): bool
    {
        $table = $this->state[self::T];
        if (!is_string($table)) return false;
        if (str_contains($table, '(') || str_contains($table, ' ')) return false;
        return $this->isSimpleWhere();
    }

    private function isSimpleWhere(): bool
    {
        foreach ($this->state[self::F] as $val) {
            if (is_array($val)) return false;
        }
        return true;
    }

    private function compileSimpleSelect($fields, $pagination, $sort): CompiledQuery
    {
        $table = ConditionMatrix::quote($this->state[self::T]);
        $params = [];

        $whereSql = '';
        if (!empty($this->state[self::F]) && $this->isSimpleWhere()) {
            $parts = [];
            foreach ($this->state[self::F] as $col => $val) {
                $parts[] = ConditionMatrix::quote($col) . ' = ?';
                $params[] = $val;
            }
            $whereSql = ' WHERE ' . implode(' AND ', $parts);
        }

        $orderSql = $sort ? ' ORDER BY ' . $this->buildOrderClause($sort) : '';

        $limitSql = '';
        if ($pagination !== null) {
            if (is_array($pagination)) {
                $limitSql = ' LIMIT ? OFFSET ?';
                array_push($params, (int)$pagination[1], (int)$pagination[0]);
            } else {
                $limitSql = ' LIMIT ?';
                $params[] = (int)$pagination;
            }
        }

        $selectFields = $fields ?? '*';
        if (is_array($selectFields)) {
            $selectFields = implode(', ', $selectFields);
        }
        $sql = "SELECT $selectFields FROM $table$whereSql$orderSql$limitSql";

        $projectionMap = $this->getSimpleProjection($fields ?? '*');
        $sourceTables = [$this->state[self::T]];
        return new CompiledQuery($sql, $params, CompiledQuery::SELECT, $projectionMap, $sourceTables);
    }

    private function compileSimpleCount(): CompiledQuery
    {
        $table = ConditionMatrix::quote($this->state[self::T]);
        $params = [];
        $whereSql = '';
        if (!empty($this->state[self::F]) && $this->isSimpleWhere()) {
            $parts = [];
            foreach ($this->state[self::F] as $col => $val) {
                $parts[] = ConditionMatrix::quote($col) . ' = ?';
                $params[] = $val;
            }
            $whereSql = ' WHERE ' . implode(' AND ', $parts);
        }
        $sql = "SELECT COUNT(*) FROM $table$whereSql";
        return new CompiledQuery($sql, $params, CompiledQuery::COUNT);
    }

    private function compileSimpleExists(): CompiledQuery
    {
        $table = ConditionMatrix::quote($this->state[self::T]);
        $params = [];
        $whereSql = '';
        if (!empty($this->state[self::F]) && $this->isSimpleWhere()) {
            $parts = [];
            foreach ($this->state[self::F] as $col => $val) {
                $parts[] = ConditionMatrix::quote($col) . ' = ?';
                $params[] = $val;
            }
            $whereSql = ' WHERE ' . implode(' AND ', $parts);
        }
        $sql = "SELECT EXISTS(SELECT 1 FROM $table$whereSql)";
        return new CompiledQuery($sql, $params, CompiledQuery::EXISTS);
    }

    private function getSimpleProjection($fields): array
    {
        $realTable = $this->state[self::T];

        if ($fields === '*') {
            if (!isset(self::$starProjectionCache[$realTable])) {
                $schemaMap = SchemaMap::getMap($this->connectionId);
                $schemaTables = $schemaMap['tables'] ?? [];
                if (isset($schemaTables[$realTable])) {
                    $cols = array_keys($schemaTables[$realTable]);
                    $map = [];
                    foreach ($cols as $i => $col) {
                        $map[$col] = $i;
                    }
                    self::$starProjectionCache[$realTable] = $map;
                } else {
                    self::$starProjectionCache[$realTable] = null;
                }
            }
            return self::$starProjectionCache[$realTable] ?? [];
        }

        if (is_array($fields)) {
            $map = [];
            foreach ($fields as $i => $f) {
                $map[is_string($f) ? $f : "col_$i"] = $i;
            }
            return $map;
        }

        if (is_string($fields)) {
            $parts = explode(',', $fields);
            $map = [];
            $index = 0;
            foreach ($parts as $field) {
                $field = trim($field);
                if (preg_match('/\s+as\s+(\w+)/i', $field, $matches)) {
                    $map[$matches[1]] = $index;
                } elseif (strpos($field, '.') !== false && !preg_match('/^\w+\(/', $field)) {
                    $map[$field] = $index;
                } else {
                    $cleanField = preg_replace('/^\w+\((.*?)\)$/', '$1', $field);
                    $cleanField = preg_replace('/\s+/', '', $cleanField);
                    $map[$cleanField] = $index;
                }
                $index++;
            }
            return $map;
        }

        return [];
    }

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
            : ConditionMatrix::parse(
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

    private function compileWhereSimple(): array
    {
        if (empty($this->state[self::F])) {
            return ['sql' => '1', 'params' => $this->compiledParams];
        }
        $whereData = ConditionMatrix::parse($this->state[self::F]);
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
            throw new \RuntimeException('INSERT, UPDATE, and DELETE only support a single table.');
        }
    }

    private function inferJoinCondition(string $targetTable, array $sourceTables): string
    {
        $map = SchemaMap::getMap($this->connectionId);
        $fromRels = $map['relationships']['from'] ?? [];
        $toRels   = $map['relationships']['to']   ?? [];

        foreach ($sourceTables as $sourceTable) {
            if (isset($fromRels[$targetTable][$sourceTable])) {
                $rel = $fromRels[$targetTable][$sourceTable];
                return sprintf(
                    '%s.%s = _src.%s',
                    ConditionMatrix::quote($targetTable),
                    ConditionMatrix::quote($rel['local_key']),
                    ConditionMatrix::quote($rel['foreign_key'])
                );
            }
            if (isset($fromRels[$sourceTable][$targetTable])) {
                $rel = $fromRels[$sourceTable][$targetTable];
                return sprintf(
                    '%s.%s = _src.%s',
                    ConditionMatrix::quote($targetTable),
                    ConditionMatrix::quote($rel['foreign_key']),
                    ConditionMatrix::quote($rel['local_key'])
                );
            }
            if (isset($toRels[$targetTable][$sourceTable])) {
                $rel = $toRels[$targetTable][$sourceTable];
                return sprintf(
                    '%s.%s = _src.%s',
                    ConditionMatrix::quote($targetTable),
                    ConditionMatrix::quote($rel['local_key']),
                    ConditionMatrix::quote($rel['foreign_key'])
                );
            }
            if (isset($toRels[$sourceTable][$targetTable])) {
                $rel = $toRels[$sourceTable][$targetTable];
                return sprintf(
                    '%s.%s = _src.%s',
                    ConditionMatrix::quote($targetTable),
                    ConditionMatrix::quote($rel['foreign_key']),
                    ConditionMatrix::quote($rel['local_key'])
                );
            }
        }

        throw new \RuntimeException(
            "Could not infer join condition between '$targetTable' and any of the source tables: "
            . implode(', ', $sourceTables) . '. Please provide it explicitly.'
        );
    }
}