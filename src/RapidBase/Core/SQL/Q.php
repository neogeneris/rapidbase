<?php

declare(strict_types=1);

namespace RapidBase\Core\SQL;

use RapidBase\Core\SchemaMap;

class Q
{
    private const T = 0;
    private const F = 1;
    private const ORIGIN = 2;

    private array $state;
    private array $compiledParams = [];
    private static array $starProjectionCache = [];

    private function __construct()
    {
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
        $fields = null, $pagination = null, $sort = [],
        $groupBy = null, array $having = [], bool $withTotal = false
    ): CompiledQuery {
        $originalFields = $fields ?? '*';
        $selectFields = $originalFields;

        // Normalización inicial del sort
        $sort = $sort ?? [];
        if (is_string($sort)) {
            $sort = str_contains($sort, ',')
                ? array_map('trim', explode(',', $sort))
                : [trim($sort)];
        }

        // Obtener estado de tablas y Joins
        $base = $this->buildBaseState();
        $tablesInfo = $base['tablesInfo'];
        $mainAlias = $tablesInfo[0]['alias'] ?? '';

        // Manejo de múltiples tablas (JOIN)
        if (count($tablesInfo) > 1) {
            $allQualifiedColumns = [];
            $map = SchemaMap::getMap();
            foreach ($tablesInfo as $info) {
                $alias = $info['alias'];
                $realTable = $info['real'];
                $cols = $map['tables'][$realTable] ?? [];
                foreach (array_keys($cols) as $col) {
                    $allQualifiedColumns[] = $alias . '.' . $col;
                }
            }
            if ($originalFields === '*') {
                $selectFields = $allQualifiedColumns;
            } else {
                $selectFields = $this->qualify($originalFields, $mainAlias);
            }
            $sort = $this->qualifySort($sort, $mainAlias);
        } else {
            $selectFields = $this->qualify($originalFields, $mainAlias);
            $sort = $this->qualifySort($sort, $mainAlias);
        }

        if ($withTotal && empty($groupBy)) {
            $totalFunc = 'COUNT(*) OVER() AS ' . ConditionMatrix::quote('_total');
            if (is_array($selectFields)) {
                $selectFields[] = $totalFunc;
            } elseif ($selectFields === '*') {
                $selectFields = "*, $totalFunc";
            } else {
                $selectFields = is_array($selectFields) ? array_merge($selectFields, [$totalFunc]) : "$selectFields, $totalFunc";
            }
        }

        if ($groupBy === null && empty($having) && empty($this->compiledParams) && $this->isSimpleTable()) {
            return $this->compileSimpleSelect($selectFields, $pagination, $sort);
        }

        $fromClause = $base['fromClause'];
        $whereData  = ['sql' => $base['whereSql'], 'params' => $base['whereParams']];

        $groupSql = $groupBy ? (is_array($groupBy) ? implode(', ', $groupBy) : $groupBy) : '';

        $havingData = empty($having)
            ? ['sql' => '', 'params' => []]
            : ConditionMatrix::parse($having, $base['context'] ?? [], $base['defaultAlias'] ?? '', SchemaMap::getMap());

        $orderSql = $sort ? $this->buildOrderClause($sort) : '';
        $limitSql = $this->buildLimitClause($pagination);

        $params = array_merge($whereData['params'], $havingData['params']);

        $selectSql = is_array($selectFields) ? implode(', ', $selectFields) : $selectFields;

        $compiledState = [
            SqlCompiler::SEL    => $selectSql,
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

    // ========== Métodos auxiliares de calificación ==========

    private function qualify(mixed $fields, string $tableAlias): mixed
    {
        if ($fields === '*' || empty($fields)) return $fields;

        $process = function($field) use ($tableAlias) {
            $field = trim((string)$field);
            if (str_contains($field, '.') || str_contains($field, '(') || $field === '*') {
                return $field;
            }
            return $tableAlias . '.' . $field;
        };

        if (is_string($fields)) {
            $parts = explode(',', $fields);
            return implode(', ', array_map($process, $parts));
        }

        if (is_array($fields)) {
            return array_map($process, $fields);
        }

        return $fields;
    }

    private function qualifySort(mixed $sort, string $tableAlias): mixed
    {
        if (empty($sort)) return $sort;

        // 1. Array asociativo (columna => dirección) – ya viene normalizado, no modificar
        if (is_array($sort) && !array_is_list($sort)) {
            return $sort;
        }

        // 2. String con comas
        if (is_string($sort)) {
            $sort = array_map('trim', explode(',', $sort));
        }

        // 3. Array indexado (lista de strings)
        $process = function($field) use ($tableAlias) {
            $field = trim((string)$field);
            $isDesc = str_starts_with($field, '-');
            $clean  = $isDesc ? substr($field, 1) : $field;
            if (str_contains($clean, '.') || str_contains($clean, '(')) {
                return $field;
            }
            return ($isDesc ? '-' : '') . $tableAlias . '.' . $clean;
        };

        return array_map($process, (array)$sort);
    }

    public function insert(array|CompiledQuery $rows, ?callable $transformer = null): CompiledQuery
    {
        $this->ensureSingleTable();
        if (is_array($rows) && !isset($rows['columns'], $rows['values']) && !($rows instanceof CompiledQuery)) {
            $first = reset($rows);
            if (is_object($first)) $rows = array_map(fn($obj) => (array) $obj, $rows);
        }
        if ($rows instanceof CompiledQuery) return $this->insertSelect($rows);
        if (isset($rows['columns'], $rows['values'])) {
            $columns = $rows['columns']; $valuesStr = $rows['values'];
            if (empty($columns) || empty($valuesStr)) return new CompiledQuery('SELECT 1 WHERE 1=0', [], CompiledQuery::SELECT);
            $quotedCols = array_map([ConditionMatrix::class, 'quote'], $columns);
            $table = ConditionMatrix::quote($this->resolveSingleTableName());
            return new CompiledQuery("INSERT INTO $table (" . implode(', ', $quotedCols) . ") VALUES $valuesStr", [], CompiledQuery::INSERT);
        }
        if ($transformer !== null) {
            $mapped = [];
            foreach ($rows as $row) { $newRow = $transformer($row); if ($newRow !== false) $mapped[] = $newRow; }
            $rows = $mapped;
        }
        if (empty($rows)) return new CompiledQuery('SELECT 1 WHERE 1=0', [], CompiledQuery::SELECT);
        $compiledState = [SqlCompiler::FROM => ConditionMatrix::quote($this->resolveSingleTableName()), SqlCompiler::PARAMS => []];
        [$sql, $params] = SqlCompiler::compileInsert($compiledState, $rows);
        return new CompiledQuery($sql, $params, CompiledQuery::INSERT);
    }

    public function insertFrom(CompiledQuery $source, array $columns = []): CompiledQuery { return $this->insertSelect($source, $columns); }

    public function insertSelect(CompiledQuery $source, array $columns = []): CompiledQuery
    {
        $table = ConditionMatrix::quote($this->state[self::T]);
        if (empty($columns)) {
            $map = $source->getProjectionMap();
            if (empty($map)) throw new \InvalidArgumentException('Columns must be specified or the source CompiledQuery must have a projection map.');
            $columns = array_unique(array_map(fn($key) => strpos($key, '.') !== false ? substr($key, strrpos($key, '.') + 1) : $key, array_keys($map)));
        }
        $colsSql = implode(', ', array_map([ConditionMatrix::class, 'quote'], $columns));
        return new CompiledQuery("INSERT INTO $table ($colsSql) " . $source->getSql(), $source->getParams(), CompiledQuery::INSERT);
    }

    public function values(?int $limit = null, int $offset = 0): array
    {
        $compiled = $limit !== null ? $this->select('*', [$offset, $limit]) : $this->select('*');
        $result = $compiled->run(\PDO::FETCH_NUM);
        $cols = $result['cols'] ?? []; $rows = $result['rows'] ?? [];
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
        return ['columns' => $cols, 'values' => $valuesStr];
    }

    public function upsert(array $data, array $conflictColumns = []): CompiledQuery
    {
        $table = ConditionMatrix::quote($this->state[self::T]);
        $columns = array_keys($data);
        $quotedCols = array_map([ConditionMatrix::class, 'quote'], $columns);
        $params = []; $placeholders = [];
        foreach ($columns as $col) { $placeholders[] = '?'; $params[] = $data[$col]; }
        $driver = ConditionMatrix::getDriver();
        switch ($driver) {
            case 'mysql':
                $insert = sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, implode(', ', $quotedCols), implode(', ', $placeholders));
                $updates = [];
                foreach ($columns as $col) { if (!in_array($col, $conflictColumns, true)) { $qcol = ConditionMatrix::quote($col); $updates[] = "$qcol = VALUES($qcol)"; } }
                $sql = !empty($updates) ? $insert . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates) : str_replace('INSERT INTO', 'INSERT IGNORE INTO', $insert);
                break;
            default:
                $conflictCols = array_map([ConditionMatrix::class, 'quote'], $conflictColumns);
                $conflictStr = implode(', ', $conflictCols);
                $insert = sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, implode(', ', $quotedCols), implode(', ', $placeholders));
                if (!empty($conflictColumns)) {
                    $updates = [];
                    foreach ($columns as $col) { if (!in_array($col, $conflictColumns, true)) { $qcol = ConditionMatrix::quote($col); $updates[] = "$qcol = excluded.$qcol"; } }
                    $sql = !empty($updates) ? $insert . ' ON CONFLICT (' . $conflictStr . ') DO UPDATE SET ' . implode(', ', $updates) : $insert . ' ON CONFLICT (' . $conflictStr . ') DO NOTHING';
                } else { $sql = $insert; }
        }
        return new CompiledQuery($sql, $params, CompiledQuery::UPSERT);
    }

    public function update(array|object $data, ?int $limit = null): CompiledQuery
    {
        $this->ensureSingleTable();
        if (is_object($data)) $data = (array) $data;
        $whereData = $this->compileWhereSimple();
        $table = $this->resolveSingleTableName();
        $quotedTable = ConditionMatrix::quote($table);
        $driver = ConditionMatrix::getDriver();
        $setParts = []; $setParams = [];
        foreach ($data as $col => $val) { $setParts[] = ConditionMatrix::quote($col) . ' = ?'; $setParams[] = $val; }
        $setSql = implode(', ', $setParts);
        $params = array_merge($setParams, $whereData['params']);
        $whereSql = $whereData['sql'] !== '1' ? ' WHERE ' . $whereData['sql'] : '';
        if ($limit !== null) {
            if ($driver === 'mysql') return new CompiledQuery("UPDATE $quotedTable SET $setSql$whereSql LIMIT " . (int)$limit, $params, CompiledQuery::UPDATE);
            $idCol = $this->resolveLimitIdentifier($driver, $table);
            $quotedId = ConditionMatrix::quote($idCol);
            return new CompiledQuery("UPDATE $quotedTable SET $setSql WHERE $quotedId IN (SELECT $quotedId FROM $quotedTable$whereSql LIMIT " . (int)$limit . ")", $params, CompiledQuery::UPDATE);
        }
        [$sql, $params] = SqlCompiler::compileUpdate([SqlCompiler::FROM => ConditionMatrix::quote($table), SqlCompiler::WHERE => $whereData['sql'], SqlCompiler::PARAMS => $params], $data);
        return new CompiledQuery($sql, $params, CompiledQuery::UPDATE);
    }

    public function updateFrom(CompiledQuery $source, array $data, ?string $joinCondition = null): CompiledQuery
    {
        $targetTable = ConditionMatrix::quote($this->state[self::T]);
        $driver = ConditionMatrix::getDriver();
        if ($joinCondition === null) { $sourceTables = $source->getSourceTables(); $joinCondition = $this->inferJoinCondition($this->resolveSingleTableName(), $sourceTables); }
        $setParts = []; $setParams = [];
        foreach ($data as $col => $val) { $setParts[] = ConditionMatrix::quote($col) . ' = ?'; $setParams[] = $val; }
        $setSql = implode(', ', $setParts);
        $params = array_merge($setParams, $source->getParams());
        $sql = $driver === 'mysql' ? "UPDATE $targetTable INNER JOIN ({$source->getSql()}) AS _src $joinCondition SET $setSql" : "UPDATE $targetTable SET $setSql FROM ({$source->getSql()}) AS _src WHERE $joinCondition";
        return new CompiledQuery($sql, $params, CompiledQuery::UPDATE);
    }

    public function delete(?int $limit = null): CompiledQuery
    {
        $this->ensureSingleTable();
        $whereData = $this->compileWhereSimple();
        $table = $this->resolveSingleTableName();
        $quotedTable = ConditionMatrix::quote($table);
        $driver = ConditionMatrix::getDriver();
        $params = $whereData['params'];
        $whereSql = $whereData['sql'] !== '1' ? ' WHERE ' . $whereData['sql'] : '';
        if ($limit !== null) {
            if ($driver === 'mysql') return new CompiledQuery("DELETE FROM $quotedTable$whereSql LIMIT " . (int)$limit, $params, CompiledQuery::DELETE);
            $idCol = $this->resolveLimitIdentifier($driver, $table);
            $quotedId = ConditionMatrix::quote($idCol);
            return new CompiledQuery("DELETE FROM $quotedTable WHERE $quotedId IN (SELECT $quotedId FROM $quotedTable$whereSql LIMIT " . (int)$limit . ")", $params, CompiledQuery::DELETE);
        }
        [$sql, $params] = SqlCompiler::compileDelete([SqlCompiler::FROM => ConditionMatrix::quote($table), SqlCompiler::WHERE => $whereData['sql'], SqlCompiler::PARAMS => $params]);
        return new CompiledQuery($sql, $params, CompiledQuery::DELETE);
    }

    public function count(): CompiledQuery
    {
        if ($this->isSimpleTable() && empty($this->compiledParams)) return $this->compileSimpleCount();
        $base = $this->buildBaseState();
        $fromClause = preg_replace('/^FROM\s+/i', '', $base['fromClause']);
        [$sql, $params] = SqlCompiler::compileCount([SqlCompiler::FROM => $fromClause, SqlCompiler::WHERE => $base['whereSql'], SqlCompiler::PARAMS => $base['whereParams']]);
        return new CompiledQuery($sql, $params, CompiledQuery::COUNT);
    }

    public function exists(): CompiledQuery
    {
        if ($this->isSimpleTable() && empty($this->compiledParams)) return $this->compileSimpleExists();
        $base = $this->buildBaseState();
        $fromClause = preg_replace('/^FROM\s+/i', '', $base['fromClause']);
        [$sql, $params] = SqlCompiler::compileExists([SqlCompiler::FROM => $fromClause, SqlCompiler::WHERE => $base['whereSql'], SqlCompiler::PARAMS => $base['whereParams']]);
        return new CompiledQuery($sql, $params, CompiledQuery::EXISTS);
    }

    public static function page(mixed $page, int $perPage = 10): array 
    {
        if (is_array($page)) {
            $perPage = $page[1] ?? $perPage;
            $page = $page[0] ?? 1;
        }

        $page = max(1, (int)$page);
        $perPage = max(1, $perPage);

        return [($page - 1) * $perPage, $perPage];
    }

    public static function setDriver(string $driver): void { ConditionMatrix::setDriver($driver); }
    public static function quote(string $identifier): string { return ConditionMatrix::quote($identifier); }

    /**
     * Normalizes any sort representation into a clean [column => direction] array.
     *
     * Accepted formats:
     *   - string:                "id", "-name", "created_at DESC", "id, -name"
     *   - associative array:     ['id' => 'asc', 'name' => 'desc']
     *   - indexed array:         ['id', '-name']
     *   - frontend object array: [['field' => 'id', 'order' => 'desc']]
     *   - numeric directions:    ['id' => -1, 'name' => 1]
     *
     * @param mixed $sort
     * @return array  e.g. ['id' => 'DESC', 'name' => 'ASC']
     */
    public static function sort(mixed $sort): array
    {
        if (empty($sort)) return [];

        // Already an associative array with numeric or string directions
        if (is_array($sort) && !array_is_list($sort)) {
            $normalized = [];
            foreach ($sort as $col => $dir) {
                if (is_int($dir)) {
                    $normalized[$col] = $dir <= 0 ? 'DESC' : 'ASC';
                } else {
                    $normalized[$col] = (strtoupper((string)$dir) === 'DESC') ? 'DESC' : 'ASC';
                }
            }
            return $normalized;
        }

        // String with commas: "id, -name, created_at ASC"
        if (is_string($sort)) {
            $sort = array_map('trim', explode(',', $sort));
        }

        // Indexed array (list)
        if (is_array($sort)) {
            $normalized = [];
            foreach ($sort as $item) {
                if (is_array($item)) {
                    // Object-like array: ['field' => 'id', 'order' => 'desc'] or ['column' => 'id', 'direction' => 'DESC']
                    $col = $item['field'] ?? $item['column'] ?? $item[0] ?? '';
                    $dir = $item['order'] ?? $item['direction'] ?? $item[1] ?? 'ASC';
                    $normalized[$col] = (strtoupper((string)$dir) === 'DESC') ? 'DESC' : 'ASC';
                } elseif (is_string($item)) {
                    if (str_starts_with($item, '-')) {
                        $col = ltrim($item, '-');
                        $dir = 'DESC';
                    } else {
                        $col = $item;
                        $dir = 'ASC';
                    }
                    $normalized[$col] = $dir;
                }
            }
            return $normalized;
        }

        return [];
    }

    // ========== Private helpers ==========

    private function buildLimitClause(?array $pagination): string
    {
        if ($pagination === null) return '';
        if (is_array($pagination)) {
            $limit = max(1, (int)$pagination[1]);
            $offset = max(0, (int)$pagination[0]);
            return " LIMIT $limit OFFSET $offset";
        }
        return " LIMIT " . max(1, (int)$pagination);
    }

    private function resolveLimitIdentifier(string $driver, string $table): string
    {
        return match ($driver) { 'pgsql' => 'ctid', 'sqlite' => 'rowid', default => $this->getTablePrimaryKey($table) };
    }

    private function getTablePrimaryKey(string $table): string
    {
        $columns = SchemaMap::getMap()['tables'][$table] ?? [];
        foreach ($columns as $colName => $def) { if (!empty($def['primary'])) return $colName; }
        return 'id';
    }

    private function isSimpleTable(): bool
    {
        $table = $this->state[self::T];
        if (!is_string($table)) return false;
        if (str_contains($table, '(') || str_contains($table, ' ')) return false;
        return $this->isSimpleWhere();
    }

    private function isSimpleWhere(): bool { foreach ($this->state[self::F] as $val) { if (is_array($val)) return false; } return true; }

    private function compileSimpleSelect($fields, $pagination, $sort): CompiledQuery
    {
        $table = ConditionMatrix::quote($this->state[self::T]);
        $params = [];
        $whereSql = '';
        if (!empty($this->state[self::F]) && $this->isSimpleWhere()) {
            $parts = [];
            foreach ($this->state[self::F] as $col => $val) { $parts[] = ConditionMatrix::quote($col) . ' = ?'; $params[] = $val; }
            $whereSql = ' WHERE ' . implode(' AND ', $parts);
        }
        $sort = $sort ?? [];
        $orderSql = !empty($sort) ? ' ORDER BY ' . $this->buildOrderClause($sort) : '';
        $limitSql = $this->buildLimitClause($pagination);
        $selectFields = $fields ?? '*';
        if (is_array($selectFields)) $selectFields = implode(', ', $selectFields);
        $sql = "SELECT $selectFields FROM $table$whereSql$orderSql$limitSql";
        return new CompiledQuery($sql, $params, CompiledQuery::SELECT, $this->getSimpleProjection($fields ?? '*'), [$this->state[self::T]]);
    }

    private function compileSimpleCount(): CompiledQuery
    {
        $table = ConditionMatrix::quote($this->state[self::T]);
        $params = []; $whereSql = '';
        if (!empty($this->state[self::F]) && $this->isSimpleWhere()) {
            $parts = [];
            foreach ($this->state[self::F] as $col => $val) { $parts[] = ConditionMatrix::quote($col) . ' = ?'; $params[] = $val; }
            $whereSql = ' WHERE ' . implode(' AND ', $parts);
        }
        return new CompiledQuery("SELECT COUNT(*) FROM $table$whereSql", $params, CompiledQuery::COUNT);
    }

    private function compileSimpleExists(): CompiledQuery
    {
        $table = ConditionMatrix::quote($this->state[self::T]);
        $params = []; $whereSql = '';
        if (!empty($this->state[self::F]) && $this->isSimpleWhere()) {
            $parts = [];
            foreach ($this->state[self::F] as $col => $val) { $parts[] = ConditionMatrix::quote($col) . ' = ?'; $params[] = $val; }
            $whereSql = ' WHERE ' . implode(' AND ', $parts);
        }
        return new CompiledQuery("SELECT EXISTS(SELECT 1 FROM $table$whereSql)", $params, CompiledQuery::EXISTS);
    }

    private function getSimpleProjection($fields): array
    {
        $realTable = $this->state[self::T];
        if ($fields === '*') {
            if (!isset(self::$starProjectionCache[$realTable])) {
                $schemaTables = SchemaMap::getMap()['tables'] ?? [];
                if (isset($schemaTables[$realTable])) {
                    $cols = array_keys($schemaTables[$realTable]); $map = [];
                    foreach ($cols as $i => $col) $map[$col] = $i;
                    self::$starProjectionCache[$realTable] = $map;
                } else { self::$starProjectionCache[$realTable] = null; }
            }
            return self::$starProjectionCache[$realTable] ?? [];
        }
        if (is_array($fields)) { $map = []; foreach ($fields as $i => $f) $map[is_string($f) ? $f : "col_$i"] = $i; return $map; }
        if (is_string($fields)) {
            $parts = explode(',', $fields); $map = []; $index = 0;
            foreach ($parts as $field) {
                $field = trim($field);
                if (preg_match('/\s+as\s+(\w+)/i', $field, $m)) $map[$m[1]] = $index;
                elseif (strpos($field, '.') !== false && !preg_match('/^\w+\(/', $field)) $map[$field] = $index;
                else { $c = preg_replace('/^\w+\((.*?)\)$/', '$1', $field); $map[preg_replace('/\s+/', '', $c)] = $index; }
                $index++;
            }
            return $map;
        }
        return [];
    }

    private function buildBaseState(): array
    {
        $jr = new JoinResolver(); $j = $jr->resolve($this->state[self::T]);
        $fromClause = $j['from']; $tablesInfo = $j['tablesInfo'];
        $context = []; foreach ($tablesInfo as $info) $context[$info['alias']] = $info['real'];
        $defaultAlias = $tablesInfo[0]['alias'] ?? '';
        $whereData = empty($this->state[self::F]) ? ['sql' => '', 'params' => []] : ConditionMatrix::parse($this->state[self::F], $context, $defaultAlias, SchemaMap::getMap());
        $whereData['params'] = array_merge($this->compiledParams, $whereData['params']);
        return ['fromClause' => $fromClause, 'tablesInfo' => $tablesInfo, 'context' => $context, 'defaultAlias' => $defaultAlias, 'whereSql' => $whereData['sql'], 'whereParams' => $whereData['params']];
    }

    private function compileWhereSimple(): array
    {
        if (empty($this->state[self::F])) return ['sql' => '1', 'params' => $this->compiledParams];
        $w = ConditionMatrix::parse($this->state[self::F]); $w['params'] = array_merge($this->compiledParams, $w['params']); return $w;
    }

    /**
     * Builds the ORDER BY clause from a normalized sort array.
     * Handles associative arrays (col => dir), indexed arrays and strings.
     */
    private function buildOrderClause($order): string
    {   
        if (empty($order)) return '';

        // 1. Associative array (col => direction) – produced by Q::sort()
        if (is_array($order) && !array_is_list($order)) {
            $p = [];
            foreach ($order as $col => $dir) {
                $dir = strtoupper((string)$dir) === 'DESC' ? 'DESC' : 'ASC';
                $p[] = ConditionMatrix::quote($col) . ' ' . $dir;
            }
            return implode(', ', $p);
        }

        // 2. String with commas
        if (is_string($order)) {
            $order = array_map('trim', explode(',', $order));
        }

        // 3. Indexed array (list of strings or object arrays)
        if (is_array($order)) {
            $p = [];
            foreach ($order as $item) {
                if (is_string($item)) {
                    $item = trim($item);
                    $d = 'ASC';
                    if (str_starts_with($item, '-')) {
                        $d = 'DESC';
                        $item = substr($item, 1);
                    }
                    $p[] = ConditionMatrix::quote($item) . ' ' . $d;
                } elseif (is_array($item)) {
                    $col = $item['field'] ?? $item[0] ?? '';
                    $dir = $item['order'] ?? $item[1] ?? 'ASC';
                    $dir = strtoupper((string)$dir) === 'DESC' ? 'DESC' : 'ASC';
                    $p[] = ConditionMatrix::quote($col) . ' ' . $dir;
                }
            }
            return implode(', ', $p);
        }

        return '';
    }

    private function resolveSingleTableName(): string
    {
        $t = $this->state[self::T];
        if (is_string($t)) { $p = preg_split('/\s+AS\s+/i', trim($t)); return trim($p[0]); }
        throw new \RuntimeException('INSERT, UPDATE, and DELETE require a single table.');
    }

    private function ensureSingleTable(): void { if (is_array($this->state[self::T])) throw new \RuntimeException('INSERT, UPDATE, and DELETE only support a single table.'); }

    private function inferJoinCondition(string $targetTable, array $sourceTables): string
    {
        $map = SchemaMap::getMap();
        $from = $map['relationships']['from'] ?? []; $to = $map['relationships']['to'] ?? [];
        foreach ($sourceTables as $s) {
            if (isset($from[$targetTable][$s])) { $r = $from[$targetTable][$s]; return sprintf('%s.%s = _src.%s', ConditionMatrix::quote($targetTable), ConditionMatrix::quote($r['local_key']), ConditionMatrix::quote($r['foreign_key'])); }
            if (isset($from[$s][$targetTable])) { $r = $from[$s][$targetTable]; return sprintf('%s.%s = _src.%s', ConditionMatrix::quote($targetTable), ConditionMatrix::quote($r['foreign_key']), ConditionMatrix::quote($r['local_key'])); }
            if (isset($to[$targetTable][$s])) { $r = $to[$targetTable][$s]; return sprintf('%s.%s = _src.%s', ConditionMatrix::quote($targetTable), ConditionMatrix::quote($r['local_key']), ConditionMatrix::quote($r['foreign_key'])); }
            if (isset($to[$s][$targetTable])) { $r = $to[$s][$targetTable]; return sprintf('%s.%s = _src.%s', ConditionMatrix::quote($targetTable), ConditionMatrix::quote($r['foreign_key']), ConditionMatrix::quote($r['local_key'])); }
        }
        throw new \RuntimeException("Could not infer join condition between '$targetTable' and any of the source tables: " . implode(', ', $sourceTables) . '. Please provide it explicitly.');
    }
}