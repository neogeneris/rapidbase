<?php

declare(strict_types=1);

namespace RapidBase\Core\SQL;

use RapidBase\Core\SchemaMap;

/**
 * JoinResolver - Automatically resolves FROM and JOINs from an array of tables
 * using the relationship map and schema.
 *
 * Exact behavior of buildFromWithMap() from the original SQL class.
 *
 * Supports:
 * - Simple string: 'users'
 * - Array of strings: ['users', 'posts'] → auto-join via graph
 * - Pivot format: ['users', ['posts', 'comments']]
 * - Nested linear format: ['users', ['posts' => [... definition ...]]]
 * - Weakness ordering (outDegree)
 * - BFS tree construction from the first table
 * - Automatic ON conditions based on relationship type
 * - Internal join tree cache for maximum performance
 */
class JoinResolver
{
    private array $relMap;
    private array $schema;
    private string $driver;
    private string $quoteChar;

    /** @var array Cache for join trees indexed by normalized table list */
    private static array $joinTreeCache = [];
    private static int $joinTreeCacheSize = 0;
    private static int $joinTreeCacheMaxSize = 500;

    public function __construct(string $connectionId = 'default')
    {
        $map = SchemaMap::getMap($connectionId);
        $this->relMap = $map['relationships'] ?? ['from' => [], 'to' => []];
        $this->schema = $map['tables'] ?? [];

        $this->driver = 'sqlite';
        $this->quoteChar = '"';
    }

    public function resolve(mixed $tables): array
    {
        $result = $this->buildFromWithMap($tables);
        return [
            'from'       => $result[0],
            'tablesInfo' => $result[1] ?? [],
        ];
    }

    private function buildFromWithMap(mixed $table): array
    {
        $tablesInfo = [];

        if (is_string($table)) {
            $tablesInfo[] = ['real' => $table, 'alias' => $table];
            return ["FROM " . $this->quote($table), $tablesInfo];
        }

        if (!is_array($table)) {
            return ["", $tablesInfo];
        }

        if (count($table) === 0) {
            return ["", $tablesInfo];
        }

        if (count($table) === 1 && isset($table[0]) && is_string($table[0])) {
            $single = $table[0];
            $tablesInfo[] = ['real' => $single, 'alias' => $single];
            return ["FROM " . $this->quote($single), $tablesInfo];
        }

        // Pivot format: [t1, [t2, t3, ...]]
        if (count($table) >= 2 &&
            isset($table[0]) && is_string($table[0]) &&
            isset($table[1]) && is_array($table[1]) &&
            array_is_list($table[1])
        ) {
            return $this->buildFromPivot($table[0], $table[1]);
        }

        $hasComplex = false;
        foreach ($table as $item) {
            if (is_array($item) && !array_is_list($item)) {
                $hasComplex = true;
                break;
            }
        }

        if ($hasComplex) {
            $flat = [];
            foreach ($table as $item) {
                if (is_array($item) && array_is_list($item)) {
                    array_push($flat, ...$item);
                } else {
                    $flat[] = $item;
                }
            }
            return $this->buildFromLinear($flat);
        }

        $realNames = [];
        $aliases = [];
        foreach ($table as $t) {
            $parsed = $this->parseTable($t);
            $realNames[] = $parsed['real'];
            $aliases[$parsed['real']] = $parsed['alias'];
            $tablesInfo[] = $parsed;
        }

        if (count($realNames) !== count(array_unique($realNames))) {
            return $this->buildFromLinear($table);
        }

        if ($this->hasRelations()) {
            return $this->buildFromGraph($realNames, $aliases, $tablesInfo);
        }

        return $this->buildFromLinear($table);
    }

    private function parseTable(string|array $table): array
    {
        if (is_string($table)) {
            $parts = preg_split('/\s+AS\s+/i', trim($table));
            return [
                'real'  => trim($parts[0]),
                'alias' => isset($parts[1]) ? trim($parts[1]) : trim($parts[0])
            ];
        }
        $keys = array_keys($table);
        $real = $keys[0];
        $val = $table[$real];
        $alias = $real;
        if (is_string($val)) {
            $alias = $val;
        } elseif (is_array($val) && isset($val['as'])) {
            $alias = $val['as'];
        }
        return ['real' => $real, 'alias' => $alias];
    }

    private function buildFromGraph(array $realNames, array $aliases, array &$tablesInfo): array
    {
        $orderedRealNames = $this->orderTablesByWeakness($realNames);
        $aliasesOrdered = [];
        foreach ($orderedRealNames as $real) {
            $aliasesOrdered[$real] = $aliases[$real];
        }
        $aliases = $aliasesOrdered;

        $tree = $this->buildJoinTree($orderedRealNames);
        $rootReal = $tree['root'];
        $rootAlias = $aliases[$rootReal];

        $parts = ["FROM " . $this->quote($rootReal)];
        if ($rootAlias !== $rootReal) {
            $parts[] = "AS " . $this->quote($rootAlias);
        }

        foreach ($tree['edges'] as $edge) {
            $parentReal = $edge['parent'];
            $childReal  = $edge['child'];
            $rel        = $edge['rel'];
            $parentAlias = $aliases[$parentReal];
            $childAlias  = $aliases[$childReal];

            $onClause = $this->buildJoinCondition($parentReal, $parentAlias, $childReal, $childAlias, $rel);
            $joinPart = "LEFT JOIN " . $this->quote($childReal);
            if ($childAlias !== $childReal) {
                $joinPart .= " AS " . $this->quote($childAlias);
            }
            $joinPart .= " " . $onClause;
            $parts[] = $joinPart;
        }

        return [implode(' ', $parts), $tablesInfo];
    }

    private function orderTablesByWeakness(array $tableNames): array
    {
        if (!$this->hasRelations()) {
            return $tableNames;
        }

        $degrees = [];
        $relMapFrom = $this->relMap['from'] ?? [];
        $relMapTo   = $this->relMap['to']   ?? [];

        foreach ($tableNames as $t) {
            $out = isset($relMapFrom[$t]) ? count($relMapFrom[$t]) : 0;
            $in  = isset($relMapTo[$t])   ? count($relMapTo[$t])   : 0;
            $degrees[$t] = ['out' => $out, 'in' => $in];
        }

        uasort($degrees, static function ($a, $b) {
            if ($a['out'] !== $b['out']) {
                return $b['out'] <=> $a['out'];
            }
            if ($a['in'] !== $b['in']) {
                return $a['in'] <=> $b['in'];
            }
            return 0;
        });

        return array_keys($degrees);
    }

    private function buildJoinTree(array $tableNames): array
    {
        // Normalize cache key (order independent)
        sort($tableNames);
        $cacheKey = crc32(implode(',', $tableNames));

        if (isset(self::$joinTreeCache[$cacheKey])) {
            return self::$joinTreeCache[$cacheKey];
        }

        // Build graph
        $graph = [];
        foreach ($tableNames as $t) {
            $graph[$t] = [];
        }

        $relMapFrom = $this->relMap['from'] ?? [];
        $relMapTo   = $this->relMap['to']   ?? [];

        foreach ($relMapFrom as $from => $rels) {
            foreach ($rels as $to => $rel) {
                if (in_array($from, $tableNames, true) && in_array($to, $tableNames, true)) {
                    $graph[$from][$to] = $rel;
                    $graph[$to][$from] = $rel;
                }
            }
        }

        foreach ($relMapTo as $definingTable => $rels) {
            foreach ($rels as $referencedTable => $rel) {
                if (in_array($definingTable, $tableNames, true) && in_array($referencedTable, $tableNames, true)) {
                    $rel['_direction'] = 'to';
                    $rel['_defining_table'] = $definingTable;
                    $rel['_referenced_table'] = $referencedTable;
                    $graph[$definingTable][$referencedTable] = $rel;
                    $graph[$referencedTable][$definingTable] = $rel;
                }
            }
        }

        // BFS connectivity check
        $root = $tableNames[0];
        $visited = [];
        $queue = [$root];
        $visited[$root] = true;
        while (!empty($queue)) {
            $current = array_shift($queue);
            foreach ($graph[$current] as $neighbor => $rel) {
                if (!isset($visited[$neighbor])) {
                    $visited[$neighbor] = true;
                    $queue[] = $neighbor;
                }
            }
        }

        if (count($visited) !== count($tableNames)) {
            throw new \RuntimeException("Cannot connect all tables: " . implode(',', $tableNames));
        }

        // Build tree
        $parent = [];
        $queue = [$root];
        $visited = [$root => true];
        while (!empty($queue)) {
            $current = array_shift($queue);
            foreach ($graph[$current] as $neighbor => $rel) {
                if (!isset($visited[$neighbor])) {
                    $visited[$neighbor] = true;
                    $parent[$neighbor] = ['parent' => $current, 'rel' => $rel];
                    $queue[] = $neighbor;
                }
            }
        }

        $edges = [];
        foreach ($parent as $child => $info) {
            $edges[] = [
                'parent' => $info['parent'],
                'child'  => $child,
                'rel'    => $info['rel']
            ];
        }

        $tree = ['root' => $root, 'edges' => $edges];

        // Store in cache with size limit
        if (self::$joinTreeCacheSize >= self::$joinTreeCacheMaxSize) {
            array_shift(self::$joinTreeCache);
            self::$joinTreeCacheSize--;
        }
        self::$joinTreeCache[$cacheKey] = $tree;
        self::$joinTreeCacheSize++;

        return $tree;
    }

    private function buildJoinCondition(
        string $parentReal,
        string $parentAlias,
        string $childReal,
        string $childAlias,
        array $relation
    ): string {
        $localKey   = $relation['local_key']   ?? '';
        $foreignKey = $relation['foreign_key'] ?? '';

        $fromToMap = isset($relation['_direction']) && $relation['_direction'] === 'to';

        if ($fromToMap) {
            $definingTable   = $relation['_defining_table']   ?? '';
            $referencedTable = $relation['_referenced_table'] ?? '';

            if ($parentReal === $definingTable) {
                return "ON " . $this->quote($parentAlias) . "." . $this->quote($localKey)
                     . " = " . $this->quote($childAlias) . "." . $this->quote($foreignKey);
            } else {
                return "ON " . $this->quote($childAlias) . "." . $this->quote($localKey)
                     . " = " . $this->quote($parentAlias) . "." . $this->quote($foreignKey);
            }
        }

        $type = $relation['type'] ?? 'hasMany';

        if ($type === 'belongsTo') {
            return "ON " . $this->quote($childAlias) . "." . $this->quote($localKey)
                 . " = " . $this->quote($parentAlias) . "." . $this->quote($foreignKey);
        }

        // hasMany / hasOne
        return "ON " . $this->quote($parentAlias) . "." . $this->quote($localKey)
             . " = " . $this->quote($childAlias) . "." . $this->quote($foreignKey);
    }

    // Linear construction for nested arrays or when no relations are defined
    private function buildFromLinear(array $tables): array
    {
        $tablesInfo = [];
        if (empty($tables)) {
            return ["", $tablesInfo];
        }

        $first = $tables[0];
        if (is_array($first)) {
            throw new \InvalidArgumentException("The first table cannot be an inline relationship definition.");
        }

        $firstParsed = $this->parseTable($first);
        $firstReal   = $firstParsed['real'];
        $firstAlias  = $firstParsed['alias'];
        $tablesInfo[] = $firstParsed;

        $parts = ["FROM " . $this->quote($firstReal)];
        if ($firstAlias !== $firstReal) {
            $parts[] = "AS " . $this->quote($firstAlias);
        }

        $currentReal  = $firstReal;
        $currentAlias = $firstAlias;

        for ($i = 1, $len = count($tables); $i < $len; $i++) {
            $item = $tables[$i];
            $nextReal   = null;
            $nextAlias  = null;
            $relationDef = null;

            if (is_string($item)) {
                $parsed = $this->parseTable($item);
                $nextReal  = $parsed['real'];
                $nextAlias = $parsed['alias'];
                $relationDef = $this->relMap['from'][$currentReal][$nextReal]
                    ?? $this->relMap['to'][$currentReal][$nextReal]
                    ?? $this->relMap['from'][$nextReal][$currentReal]
                    ?? $this->relMap['to'][$nextReal][$currentReal]
                    ?? null;
            } elseif (is_array($item)) {
                $keys = array_keys($item);
                if (count($keys) !== 1) {
                    throw new \InvalidArgumentException("Inline relationship must have exactly one key.");
                }
                $nextReal = $keys[0];
                $relationDef = $item[$nextReal];
                $nextAlias = $relationDef['as'] ?? $nextReal;
            } else {
                throw new \InvalidArgumentException("Invalid table element.");
            }

            $tablesInfo[] = ['real' => $nextReal, 'alias' => $nextAlias];
            $joinPart = "LEFT JOIN " . $this->quote($nextReal);
            if ($nextAlias !== $nextReal) {
                $joinPart .= " AS " . $this->quote($nextAlias);
            }

            if (is_array($relationDef) && isset($relationDef['local_key'], $relationDef['foreign_key'])) {
                $onClause = $this->buildJoinConditionFromDef($currentReal, $currentAlias, $nextReal, $nextAlias, $relationDef);
                $joinPart .= " " . $onClause;
            }

            $parts[] = $joinPart;
            $currentReal  = $nextReal;
            $currentAlias = $nextAlias;
        }

        return [implode(' ', $parts), $tablesInfo];
    }

    private function buildJoinConditionFromDef(
        string $parentReal,
        string $parentAlias,
        string $childReal,
        string $childAlias,
        array $def
    ): string {
        $localKey   = $def['local_key'];
        $foreignKey = $def['foreign_key'];
        return "ON " . $this->quote($parentAlias) . "." . $this->quote($localKey)
             . " = " . $this->quote($childAlias) . "." . $this->quote($foreignKey);
    }

    private function buildFromPivot(string $pivot, array $connectedTables): array
    {
        $tablesInfo = [];
        $pivotParsed = $this->parseTable($pivot);
        $pivotReal   = $pivotParsed['real'];
        $pivotAlias  = $pivotParsed['alias'];
        $tablesInfo[] = $pivotParsed;

        $parts = ["FROM " . $this->quote($pivotReal)];
        if ($pivotAlias !== $pivotReal) {
            $parts[] = "AS " . $this->quote($pivotAlias);
        }

        if (empty($connectedTables)) {
            return [implode(' ', $parts), $tablesInfo];
        }

        $allTables = array_merge([$pivotReal], $connectedTables);
        $realNames = [$pivotReal];
        $aliases = [$pivotReal => $pivotAlias];
        foreach ($connectedTables as $t) {
            $parsed = $this->parseTable($t);
            $real = $parsed['real'];
            $alias = $parsed['alias'];
            $realNames[] = $real;
            $aliases[$real] = $alias;
        }

        $tree = $this->buildJoinTree($realNames);
        $usedTables = [$pivotReal => true];
        $tablesToConnect = array_slice($realNames, 1);

        foreach ($tablesToConnect as $nextReal) {
            if (isset($usedTables[$nextReal])) continue;

            $nextAlias = $aliases[$nextReal];
            $tablesInfo[] = ['real' => $nextReal, 'alias' => $nextAlias];

            $edgeFound = false;
            foreach ($tree['edges'] as $edge) {
                if ($edge['child'] === $nextReal && isset($usedTables[$edge['parent']])) {
                    $parentReal  = $edge['parent'];
                    $parentAlias = $aliases[$parentReal];
                    $rel = $edge['rel'];
                    $joinPart = "LEFT JOIN " . $this->quote($nextReal);
                    if ($nextAlias !== $nextReal) {
                        $joinPart .= " AS " . $this->quote($nextAlias);
                    }
                    $onClause = $this->buildJoinCondition($parentReal, $parentAlias, $nextReal, $nextAlias, $rel);
                    $joinPart .= " " . $onClause;
                    $parts[] = $joinPart;
                    $usedTables[$nextReal] = true;
                    $edgeFound = true;
                    break;
                }
            }
            if (!$edgeFound) {
                $parts[] = "LEFT JOIN " . $this->quote($nextReal)
                         . ($nextAlias !== $nextReal ? " AS " . $this->quote($nextAlias) : "");
                $usedTables[$nextReal] = true;
            }
        }

        return [implode(' ', $parts), $tablesInfo];
    }

    private function quote(string $identifier): string
    {
        if (class_exists(ConditionMatrix::class)) {
            return ConditionMatrix::quote($identifier);
        }
        $q = $this->quoteChar;
        $identifier = trim($identifier);
        if ($identifier === '*' || str_starts_with($identifier, $q)) {
            return $identifier;
        }
        $parts = explode('.', $identifier);
        $quotedParts = array_map(function ($part) use ($q) {
            return $part === '*' ? '*' : $q . trim($part, $q) . $q;
        }, $parts);
        return implode('.', $quotedParts);
    }

    private function hasRelations(): bool
    {
        return !empty($this->relMap['from']) || !empty($this->relMap['to']);
    }
}