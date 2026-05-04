<?php

declare(strict_types=1);

namespace RapidBase\Core\SQL;

class ConditionMatrix
{
    private static string $driver = 'sqlite';
    private static string $quoteChar = '"';

    /** @var array<string, array{sql:string, params:array}> Cache por petición */
    private static array $parseCache = [];

    public static function setDriver(string $driver): void
    {
        self::$driver = strtolower($driver);
        self::$quoteChar = (self::$driver === 'mysql') ? '`' : '"';
    }

    public static function getDriver(): string
    {
        return self::$driver;
    }

    private static array $quoteCache = [];

    public static function quote(string $identifier): string
    {
        if (isset(self::$quoteCache[$identifier])) {
            return self::$quoteCache[$identifier];
        }

        $q = self::$quoteChar;
        $id = trim($identifier);

        if ($id === '*' || str_starts_with($id, $q)) {
            return self::$quoteCache[$identifier] = $id;
        }

        if (strpos($id, '.') === false) {
            return self::$quoteCache[$identifier] = $q . trim($id, $q) . $q;
        }

        $parts = explode('.', $id);
        $quotedParts = [];
        foreach ($parts as $part) {
            $quotedParts[] = $part === '*' ? '*' : $q . trim($part, $q) . $q;
        }

        return self::$quoteCache[$identifier] = implode('.', $quotedParts);
    }

    /**
     * Parses a conditions array, with in‑memory cache based on structure.
     */
    public static function parse(
        array $conditions,
        array $context = [],
        string $defaultAlias = '',
        array $tablesSchema = []
    ): array {
        $cacheKey = self::getCacheKey($conditions, $context, $tablesSchema);
        if (isset(self::$parseCache[$cacheKey])) {
            return self::$parseCache[$cacheKey];
        }

        $result = self::doParse($conditions, $context, $defaultAlias, $tablesSchema);
        self::$parseCache[$cacheKey] = $result;
        return $result;
    }

    private static function doParse(
        array $conditions,
        array $context,
        string $defaultAlias,
        array $tablesSchema
    ): array {
        if (empty($conditions)) {
            return ['sql' => '1', 'params' => []];
        }

        // --- OR groups ---
        if (array_is_list($conditions)) {
            $groupSql = [];
            $allParams = [];
            foreach ($conditions as $group) {
                if (!is_array($group)) {
                    $group = [$group];
                }
                $sub = self::parse($group, $context, $defaultAlias, $tablesSchema);
                $groupSql[] = '(' . $sub['sql'] . ')';
                $allParams = array_merge($allParams, $sub['params']);
            }
            $sql = count($groupSql) > 1 ? implode(' OR ', $groupSql) : $groupSql[0];
            return ['sql' => $sql, 'params' => $allParams];
        }

        // --- AND between conditions ---
        $sqlParts = [];
        $params = [];
        $schemaTables = self::normalizeTablesSchema($tablesSchema);

        foreach ($conditions as $column => $value) {
            // Manejo de grupos lógicos explícitos (ej. ['OR' => [...]]) o sub-condiciones numéricas
            if ($column === 'OR' && is_array($value)) {
                $sub = self::parse(array_values($value), $context, $defaultAlias, $tablesSchema);
                if (!empty($sub['sql']) && $sub['sql'] !== '1') {
                    $sqlParts[] = '(' . $sub['sql'] . ')';
                    array_push($params, ...$sub['params']);
                }
                continue;
            }
            if (is_int($column) && is_array($value)) {
                $sub = self::parse($value, $context, $defaultAlias, $tablesSchema);
                if (!empty($sub['sql']) && $sub['sql'] !== '1') {
                    $sqlParts[] = '(' . $sub['sql'] . ')';
                    array_push($params, ...$sub['params']);
                }
                continue;
            }

            $rawColumn = trim((string)$column);

            if (!str_contains($rawColumn, '.') && !preg_match('/[^\w]/', $rawColumn)) {
                $rawColumn = self::qualifyColumnName($rawColumn, $context, $defaultAlias, $schemaTables);
            }

            $safeColumn = self::quote($rawColumn);

            if ($value === null) {
                $sqlParts[] = "$safeColumn IS NULL";
                continue;
            }

            if (is_array($value)) {
                if (array_is_list($value)) {
                    if (empty($value)) {
                        $sqlParts[] = '0';
                    } else {
                        $placeholders = implode(', ', array_fill(0, count($value), '?'));
                        $sqlParts[] = "$safeColumn IN ($placeholders)";
                        array_push($params, ...array_values($value));
                    }
                } else {
                    foreach ($value as $operator => $operand) {
                        $op = strtoupper(trim($operator));
                        if ($operand === null && in_array($op, ['!=', '<>'], true)) {
                            $sqlParts[] = "$safeColumn IS NOT NULL";
                        } else {
                            $sqlParts[] = "$safeColumn $op ?";
                            $params[] = $operand;
                        }
                    }
                }
                continue;
            }

            $sqlParts[] = "$safeColumn = ?";
            $params[] = $value;
        }

        return [
            'sql' => implode(' AND ', $sqlParts),
            'params' => $params
        ];
    }

    private static function qualifyColumnName(
        string $column,
        array $context,
        string $defaultAlias,
        array $schemaTables
    ): string {
        if (empty($schemaTables)) {
            return $defaultAlias !== '' ? "$defaultAlias.$column" : $column;
        }

        $foundAliases = [];
        foreach ($context as $alias => $realTable) {
            if (isset($schemaTables[$realTable][$column])) {
                $foundAliases[] = $alias;
            }
        }

        if (count($foundAliases) > 1) {
            throw new \RuntimeException(
                "Column '$column' is ambiguous in tables: " . implode(', ', $foundAliases)
            );
        }

        if (!empty($foundAliases)) {
            return $foundAliases[0] . '.' . $column;
        }

        return $defaultAlias !== '' ? "$defaultAlias.$column" : $column;
    }

    private static function normalizeTablesSchema(array $tablesSchema): array
    {
        return $tablesSchema['tables'] ?? $tablesSchema;
    }

    /**
     * Generates a cache key based on the structure of conditions, context, and schema.
     * Uses crc32(json_encode(...)) for maximum speed.
     */
    private static function getCacheKey(
        array $conditions,
        array $context,
        array $tablesSchema
    ): string {
        $base = crc32(serialize($conditions));

        $ctx = !empty($context) ? '|ctx:' . implode(',', array_keys($context)) : '';
        $sch = !empty($tablesSchema) ? '|sch:' . crc32(serialize($tablesSchema)) : '';

        return $base . $ctx . $sch;
    }

    private static function extractStructure(array $conditions): array
    {
        if (array_is_list($conditions)) {
            $parts = [];
            foreach ($conditions as $group) {
                $parts[] = self::extractStructure(is_array($group) ? $group : [$group]);
            }
            sort($parts);
            return $parts;
        }

        $keys = [];
        foreach ($conditions as $col => $val) {
            if ($val === null) {
                $keys[] = "$col:IS_NULL";
            } elseif (is_array($val)) {
                if (array_is_list($val)) {
                    $keys[] = "$col:IN:" . count($val);
                } else {
                    $ops = [];
                    foreach ($val as $op => $operand) {
                        $ops[] = $operand === null ? "IS_NOT_NULL" : strtoupper($op);
                    }
                    sort($ops);
                    $keys[] = "$col:" . implode('.', $ops);
                }
            } else {
                $keys[] = "$col:EQ";
            }
        }
        sort($keys);
        return $keys;
    }
}