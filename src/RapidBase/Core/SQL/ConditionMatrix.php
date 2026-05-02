<?php

declare(strict_types=1);

namespace RapidBase\Core\SQL;

/**
 * ConditionMatrix - Parses multidimensional condition arrays into SQL
 * with placeholders and ordered parameters.
 *
 * All methods are static for maximum performance and simplicity.
 */
class ConditionMatrix
{
    private static string $driver = 'sqlite';
    private static string $quoteChar = '"';

    public static function setDriver(string $driver): void
    {
        self::$driver = strtolower($driver);
        self::$quoteChar = (self::$driver === 'mysql') ? '`' : '"';
    }

    public static function getDriver(): string
    {
        return self::$driver;
    }

    /**
     * Fast, fully static quoting – no instance creation.
     */
    public static function quote(string $identifier): string
    {
        $q = self::$quoteChar;
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

    // ===================== Core parsing (static) =====================

    /**
     * Parses a conditions array into SQL and parameters.
     *
     * @param array  $conditions   Associative (AND) or list of groups (OR).
     * @param array  $context      Alias => real table name mapping.
     * @param string $defaultAlias Default alias for unprefixed columns.
     * @param array  $tablesSchema Optional table schema for ambiguity checks.
     * @return array ['sql' => string, 'params' => array]
     */
    public static function parse(
        array $conditions,
        array $context = [],
        string $defaultAlias = '',
        array $tablesSchema = []
    ): array {
        if (empty($conditions)) {
            return ['sql' => '1', 'params' => []];
        }

        // --- OR groups (indexed array) ---
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

        // --- AND between conditions (associative array) ---
        $sqlParts = [];
        $params = [];
        $schemaTables = self::normalizeTablesSchema($tablesSchema);

        foreach ($conditions as $column => $value) {
            $rawColumn = trim($column);

            if (!str_contains($rawColumn, '.') && !preg_match('/[^\w]/', $rawColumn)) {
                $rawColumn = self::qualifyColumnName($rawColumn, $context, $defaultAlias, $schemaTables);
            }

            $safeColumn = self::quote($rawColumn);

            // 1. NULL -> IS NULL
            if ($value === null) {
                $sqlParts[] = "$safeColumn IS NULL";
                continue;
            }

            // 2. Array
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

            // 3. Scalar value -> equality
            $sqlParts[] = "$safeColumn = ?";
            $params[] = $value;
        }

        return [
            'sql' => implode(' AND ', $sqlParts),
            'params' => $params
        ];
    }

    // ===================== Private static helpers =====================

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
}