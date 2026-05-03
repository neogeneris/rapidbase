<?php

declare(strict_types=1);

namespace RapidBase\Core\SQL;

use RapidBase\Core\SchemaMap;

class JoinResolver
{
    private array $relMap;
    private array $schema;
    private string $driver;
    private string $quoteChar;

    /** @var array Caches for join trees and FROM clauses */
    private static array $joinTreeCache = [];
    private static int $joinTreeCacheSize = 0;
    private static int $joinTreeCacheMaxSize = 500;

    private static array $fromClauseCache = [];
    private static int $fromClauseCacheSize = 0;
    private static int $fromClauseCacheMaxSize = 200;

    private static int $subqueryAliasCounter = 0;

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
        $key = $this->getCacheKey($tables);
        if ($key !== null && isset(self::$fromClauseCache[$key])) {
            return self::$fromClauseCache[$key];
        }

        $result = $this->buildFromWithMap($tables);
        $data = [
            'from'       => $result[0],
            'tablesInfo' => $result[1] ?? [],
        ];

        if ($key !== null) {
            if (self::$fromClauseCacheSize >= self::$fromClauseCacheMaxSize) {
                array_shift(self::$fromClauseCache);
                self::$fromClauseCacheSize--;
            }
            self::$fromClauseCache[$key] = $data;
            self::$fromClauseCacheSize++;
        }

        return $data;
    }

    private function getCacheKey(mixed $tables): ?string
    {
        if (is_string($tables)) {
            return 'str:' . $tables;
        }
        if (is_array($tables)) {
            $flat = [];
            array_walk_recursive($tables, function ($item) use (&$flat) {
                if (is_string($item)) {
                    $flat[] = $item;
                }
            });
            sort($flat);
            return 'arr:' . implode(',', $flat);
        }
        return null;
    }

    private function buildFromWithMap(mixed $table): array
    {
        $tablesInfo = [];

        if (is_string($table)) {
            $parsed = $this->parseTable($table);
            $tablesInfo[] = $parsed;
            if ($parsed['isSubquery'] ?? false) {
                return ["FROM " . $parsed['real'] . " AS " . $this->quote($parsed['alias']), $tablesInfo];
            }
            return ["FROM " . $this->quote($table), $tablesInfo];
        }

        if (!is_array($table)) {
            return ["", $tablesInfo];
        }
        if (count($table) === 0) {
            return ["", $tablesInfo];
        }
        if (count($table) === 1 && isset($table[0]) && is_string($table[0])) {
            $parsed = $this->parseTable($table[0]);
            $tablesInfo[] = $parsed;
            if ($parsed['isSubquery'] ?? false) {
                return ["FROM " . $parsed['real'] . " AS " . $this->quote($parsed['alias']), $tablesInfo];
            }
            return ["FROM " . $this->quote($table[0]), $tablesInfo];
        }

        foreach ($table as $item) {
            if (is_string($item) && self::isSubquery($item)) {
                return $this->buildFromLinear($table);
            }
        }

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

    private static function isSubquery(string $text): bool
    {
        $text = trim($text);
        return str_starts_with($text, '(') || stripos($text, 'SELECT') !== false;
    }

    private function extractSubqueryAlias(string $text): string
    {
        if (preg_match('/\s+AS\s+([^\s]+)$/i', $text, $matches)) {
            return trim($matches[1]);
        }
        return '_sub' . (++self::$subqueryAliasCounter);
    }

    private function parseSubquery(string $text): array
    {
        $text = trim($text);
        $alias = '';
        if (preg_match('/\s+AS\s+([^\s]+)$/i', $text, $matches)) {
            $alias = trim($matches[1]);
            $text = preg_replace('/\s+AS\s+[^\s]+$/i', '', $text);
        }
        if (!str_starts_with($text, '(')) {
            $text = '(' . $text . ')';
        }
        return ['subquery' => $text, 'alias' => $alias];
    }

    private function parseTable(string|array $table): array
    {
        if (is_string($table)) {
            if (self::isSubquery($table)) {
                $parsed = $this->parseSubquery($table);
                $alias = $parsed['alias'] ?: $this->extractSubqueryAlias($table);
                return [
                    'real'      => $parsed['subquery'],
                    'alias'     => $alias,
                    'isSubquery'=> true
                ];
            }
            $parts = preg_split('/\s+AS\s+/i', trim($table));
            return [
                'real'  => trim($parts[0]),
                'alias' => isset($parts[1]) ? trim($parts[1]) : trim($parts[0]),
                'isSubquery' => false
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
        return ['real' => $real, 'alias' => $alias, 'isSubquery' => false];
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
            $parts[] = "LEFT JOIN " . $this->quote($childReal) .
                       ($childAlias !== $childReal ? " AS " . $this->quote($childAlias) : "") .
                       " " . $onClause;
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
            if ($a['out'] !== $b['out']) return $b['out'] <=> $a['out'];
            if ($a['in'] !== $b['in']) return $a['in'] <=> $b['in'];
            return 0;
        });

        return array_keys($degrees);
    }

    private function buildJoinTree(array $tableNames): array
    {
        // Cache key from sorted names (original order preserved)
        $sorted = $tableNames;
        sort($sorted);
        $cacheKey = crc32(implode(',', $sorted));

        if (isset(self::$joinTreeCache[$cacheKey])) {
            return self::$joinTreeCache[$cacheKey];
        }

        // Construir grafo no dirigido con las relaciones
        $graph = [];
        foreach ($tableNames as $t) {
            $graph[$t] = [];
        }

        $relMapFrom = $this->relMap['from'] ?? [];
        $relMapTo   = $this->relMap['to']   ?? [];

        // Función interna para añadir una arista en ambos sentidos
        $addEdge = function (string $a, string $b, array $relData) use (&$graph) {
            $graph[$a][$b] = $relData;
            $graph[$b][$a] = $relData; // para BFS necesitamos que sea simétrico
        };

        // Añadir relaciones 'from'
        foreach ($relMapFrom as $from => $rels) {
            foreach ($rels as $to => $relData) {
                if (in_array($from, $tableNames, true) && in_array($to, $tableNames, true)) {
                    $addEdge($from, $to, $relData);
                }
            }
        }

        // Añadir relaciones 'to' teniendo cuidado de no sobrescribir las existentes
        foreach ($relMapTo as $definingTable => $rels) {
            foreach ($rels as $referencedTable => $relData) {
                if (in_array($definingTable, $tableNames, true) && in_array($referencedTable, $tableNames, true)) {
                    // Solo añadir si no existe una arista en esa dirección (las de 'from' tienen prioridad)
                    if (!isset($graph[$definingTable][$referencedTable])) {
                        $relData['_direction'] = 'to';
                        $relData['_defining_table'] = $definingTable;
                        $relData['_referenced_table'] = $referencedTable;
                        $addEdge($definingTable, $referencedTable, $relData);
                    } elseif (!isset($graph[$referencedTable][$definingTable])) {
                        $relData['_direction'] = 'to';
                        $relData['_defining_table'] = $definingTable;
                        $relData['_referenced_table'] = $referencedTable;
                        $addEdge($definingTable, $referencedTable, $relData);
                    }
                }
            }
        }

        // Verificar conectividad (BFS)
        $root = $tableNames[0];
        $visited = [$root => true];
        $queue = [$root];
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

        // Construir árbol de expansión (BFS)
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
            $edges[] = ['parent' => $info['parent'], 'child' => $child, 'rel' => $info['rel']];
        }

        $tree = ['root' => $root, 'edges' => $edges];

        // Guardar en caché
        if (self::$joinTreeCacheSize >= self::$joinTreeCacheMaxSize) {
            array_shift(self::$joinTreeCache);
            self::$joinTreeCacheSize--;
        }
        self::$joinTreeCache[$cacheKey] = $tree;
        self::$joinTreeCacheSize++;

        return $tree;
    }

    private function buildJoinCondition(
        string $parentReal, string $parentAlias,
        string $childReal, string $childAlias,
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
        return "ON " . $this->quote($parentAlias) . "." . $this->quote($localKey)
             . " = " . $this->quote($childAlias) . "." . $this->quote($foreignKey);
    }

    private function buildFromLinear(array $tables): array
    {
        $tablesInfo = [];
        if (empty($tables)) return ["", $tablesInfo];

        $first = $tables[0];
        if (is_array($first)) {
            throw new \InvalidArgumentException("The first table cannot be an inline relationship definition.");
        }

        $firstParsed = $this->parseTable($first);
        $firstReal   = $firstParsed['real'];
        $firstAlias  = $firstParsed['alias'];
        $tablesInfo[] = $firstParsed;

        if ($firstParsed['isSubquery'] ?? false) {
            $parts = ["FROM " . $firstReal . " AS " . $this->quote($firstAlias)];
        } else {
            $parts = ["FROM " . $this->quote($firstReal)];
            if ($firstAlias !== $firstReal) {
                $parts[] = "AS " . $this->quote($firstAlias);
            }
        }

        $currentReal  = $firstReal;
        $currentAlias = $firstAlias;

        for ($i = 1, $len = count($tables); $i < $len; $i++) {
            $item = $tables[$i];
            $parsedNext = $this->parseTable($item);
            $nextReal   = $parsedNext['real'];
            $nextAlias  = $parsedNext['alias'];
            $isSubquery = $parsedNext['isSubquery'] ?? false;

            $tablesInfo[] = $parsedNext;

            $joinPart = $isSubquery
                ? "LEFT JOIN " . $nextReal
                : "LEFT JOIN " . $this->quote($nextReal);

            if ($nextAlias !== $nextReal || $isSubquery) {
                $joinPart .= " AS " . $this->quote($nextAlias);
            }

            $relationDef = null;
            if (is_array($item)) {
                $keys = array_keys($item);
                $relationDef = $item[$keys[0]] ?? null;
            } elseif (is_string($item)) {
                $relationDef = $this->relMap['from'][$currentReal][$nextReal]
                    ?? $this->relMap['to'][$currentReal][$nextReal]
                    ?? $this->relMap['from'][$nextReal][$currentReal]
                    ?? $this->relMap['to'][$nextReal][$currentReal]
                    ?? null;
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
        string $parentReal, string $parentAlias,
        string $childReal, string $childAlias,
        array $def
    ): string {
        return "ON " . $this->quote($parentAlias) . "." . $this->quote($def['local_key'])
             . " = " . $this->quote($childAlias) . "." . $this->quote($def['foreign_key']);
    }

    private function buildFromPivot(string $pivot, array $connectedTables): array
    {
        $tablesInfo = [];
        $pivotParsed = $this->parseTable($pivot);
        $pivotReal   = $pivotParsed['real'];
        $pivotAlias  = $pivotParsed['alias'];
        $tablesInfo[] = $pivotParsed;

        if ($pivotParsed['isSubquery'] ?? false) {
            $parts = ["FROM " . $pivotReal . " AS " . $this->quote($pivotAlias)];
        } else {
            $parts = ["FROM " . $this->quote($pivotReal)];
            if ($pivotAlias !== $pivotReal) {
                $parts[] = "AS " . $this->quote($pivotAlias);
            }
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