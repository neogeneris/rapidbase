<?php

declare(strict_types=1);

namespace RapidBase\Core\SQL;

/**
 * ConditionMatrix - Parses multidimensional condition arrays into SQL with placeholders and ordered parameters.
 *
 * Exact functional replica of buildWhere() from the original SQL class.
 *
 * Supports:
 * - Implicit AND (associative array)
 * - Explicit OR (indexed array of groups)
 * - Multiple operators per column: '>', '<', '>=', '<=', '!=', '<>', 'LIKE', etc.
 * - IN (indexed array of values)
 * - IS NULL / IS NOT NULL (via null value or ['!=' => null])
 * - Table alias resolution using context and schema
 * - Ambiguity prevention
 * - Positional placeholders ? for maximum speed
 * - Driver-dependent quoting (MySQL → backticks, others → double quotes)
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

    /**
     * Parses a conditions array.
     *
     * @param array  $conditions   Associative (AND) or list of groups (OR).
     * @param array  $context      Alias => real table name mapping.
     * @param string $defaultAlias Default alias for unprefixed columns.
     * @param array  $tablesSchema Optional table schema for ambiguity checks.
     * @return array ['sql' => string, 'params' => array]
     */
    public function parse(
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
                $sub = $this->parse($group, $context, $defaultAlias, $tablesSchema);
                $groupSql[] = '(' . $sub['sql'] . ')';
                $allParams = array_merge($allParams, $sub['params']);
            }
            $sql = count($groupSql) > 1 ? implode(' OR ', $groupSql) : $groupSql[0];
            return ['sql' => $sql, 'params' => $allParams];
        }

        // --- AND between conditions (associative array) ---
        $sqlParts = [];
        $params = [];
        $schemaTables = $this->normalizeTablesSchema($tablesSchema);

        foreach ($conditions as $column => $value) {
            $rawColumn = trim($column);

            // Qualify column if no dot and not an expression
            if (!str_contains($rawColumn, '.') && !preg_match('/[^\w]/', $rawColumn)) {
                $rawColumn = $this->qualifyColumnName($rawColumn, $context, $defaultAlias, $schemaTables);
            }

            $safeColumn = $this->quoteIdentifier($rawColumn);

            // 1. NULL -> IS NULL
            if ($value === null) {
                $sqlParts[] = "$safeColumn IS NULL";
                continue;
            }

            // 2. Array
            if (is_array($value)) {
                if (array_is_list($value)) {
                    // Flat list -> IN (...)
                    if (empty($value)) {
                        $sqlParts[] = '0'; // empty IN never matches
                    } else {
                        $placeholders = implode(', ', array_fill(0, count($value), '?'));
                        $sqlParts[] = "$safeColumn IN ($placeholders)";
                        array_push($params, ...array_values($value));
                    }
                } else {
                    // Operator map
                    foreach ($value as $operator => $operand) {
                        $op = strtoupper(trim($operator));
                        // IS NOT NULL (operator != or <> with null value)
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

    private function qualifyColumnName(
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

    private function normalizeTablesSchema(array $tablesSchema): array
    {
        return $tablesSchema['tables'] ?? $tablesSchema;
    }

    public function quoteIdentifier(string $identifier): string
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

    public static function quote(string $identifier): string
    {
        $instance = new self();
        return $instance->quoteIdentifier($identifier);
    }
}