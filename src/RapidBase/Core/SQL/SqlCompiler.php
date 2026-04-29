<?php

declare(strict_types=1);

namespace RapidBase\Core\SQL;

use RapidBase\Core\SchemaMap;

/**
 * SqlCompiler - Genera SQL usando plantillas sprintf.
 * 
 * Recibe un array numérico indexado por constantes de clase.
 * Máxima eficiencia: sin claves de cadena, sin objetos adicionales.
 */
class SqlCompiler
{
    // Índices del array de estado compilado
    public const SEL    = 0; // Campos SELECT
    public const FROM   = 1; // Cláusula FROM/JOIN completa (incluye "FROM")
    public const WHERE  = 2; // Condiciones WHERE (sin "WHERE")
    public const GROUP  = 3; // Columnas GROUP BY (sin "GROUP BY")
    public const HAVING = 4; // Condiciones HAVING (sin "HAVING")
    public const ORDER  = 5; // Columnas ORDER BY (sin "ORDER BY")
    public const LIMIT  = 6; // LIMIT/OFFSET con placeholders (sin "LIMIT")
    public const PARAMS = 7; // Array de parámetros finales

    // Plantillas sprintf (minúsculas, sin lógica)
    private const TPL_SELECT = 'SELECT %s %s %s %s %s %s %s';
    //                          SEL, FROM, WHERE (con "WHERE"), GROUP, HAVING, ORDER, LIMIT
    private const TPL_DELETE = 'DELETE FROM %s%s';
    private const TPL_COUNT  = 'SELECT COUNT(*) FROM %s%s';
    private const TPL_EXISTS = 'SELECT EXISTS(SELECT 1 FROM %s%s)';
    private const TPL_UPDATE = 'UPDATE %s SET %s%s';
    private const TPL_INSERT = 'INSERT INTO %s (%s) VALUES %s';

    /**
     * Compila un SELECT.
     *
     * @param array $state Array con índices numéricos según constantes.
     * @return array [sql, params, projectionMap]
     */
    public function compileSelect(array $state): array
    {
        $sel    = $this->normalizeField($state[self::SEL] ?? '*');
        $from   = $state[self::FROM]   ?? '';
        $where  = $state[self::WHERE]  ? ' WHERE ' . $state[self::WHERE] : '';
        $group  = ($g = $this->normalizeField($state[self::GROUP] ?? '')) ? ' GROUP BY ' . $g : '';
        $having = $state[self::HAVING] ? ' HAVING ' . $state[self::HAVING] : '';
        $order  = ($o = $this->normalizeField($state[self::ORDER] ?? '')) ? ' ORDER BY ' . $o : '';
        $limit  = $state[self::LIMIT]  ? ' LIMIT ' . $state[self::LIMIT] : '';
        $params = $state[self::PARAMS] ?? [];

        $sql = sprintf(
            self::TPL_SELECT,
            $sel,
            $from,
            $where,
            $group,
            $having,
            $order,
            $limit
        );

        // Build projection map for fetch_num compatibility
        $projectionMap = $this->buildProjectionMap($state[self::SEL] ?? '*', $from);

        return [$sql, $params, $projectionMap];
    }

    /**
     * Builds a projection map from field names to numeric indices.
     * This is needed when using PDO::FETCH_NUM to map indices back to column names.
     *
     * @param mixed $fields Fields from SELECT (string, array, or '*')
     * @param string $fromClause FROM clause (used to extract table info if needed)
     * @return array Map of index => column name
     */
    private function buildProjectionMap($fields, string $fromClause): array
    {
        $map = [];
        $index = 0;

        // Extract table aliases from FROM clause
        $tablesInfo = $this->extractTablesFromFromClause($fromClause);

        if ($fields === '*') {
            // Expand with schema for each table
            $schema = SchemaMap::getMap();
            foreach ($tablesInfo as $info) {
                $realTable = $info['real'];
                $alias = $info['alias'];
                if (isset($schema['tables'][$realTable])) {
                    foreach ($schema['tables'][$realTable] as $col => $def) {
                        $map[$index] = $alias . '.' . $col;
                        $index++;
                    }
                }
            }
            return $map;
        }

        if (is_string($fields)) {
            // Parse comma-separated fields
            $parts = explode(',', $fields);
        } else if (is_array($fields)) {
            $parts = $fields;
        } else {
            return [];
        }

        foreach ($parts as $field) {
            $field = trim($field);
            
            // Handle aliases (e.g., "COUNT(*) as total" -> "total")
            if (preg_match('/\s+as\s+(\w+)/i', $field, $matches)) {
                $map[$index] = $matches[1];
            } 
            // Handle table.* - expand with schema
            else if (preg_match('/(\w+)\.\*/', $field, $matches)) {
                $tableAlias = $matches[1];
                $schema = SchemaMap::getMap();
                // Find real table name from alias
                foreach ($tablesInfo as $info) {
                    if ($info['alias'] === $tableAlias) {
                        $realTable = $info['real'];
                        if (isset($schema['tables'][$realTable])) {
                            foreach ($schema['tables'][$realTable] as $col => $def) {
                                $map[$index] = $tableAlias . '.' . $col;
                                $index++;
                            }
                        }
                        break;
                    }
                }
            } 
            else {
                // Simple column, function, or aliased column
                // Remove table prefix for display but keep it in the map
                if (strpos($field, '.') !== false && !preg_match('/^\w+\(/', $field)) {
                    // It's table.column format
                    $map[$index] = $field;
                } else {
                    // Function or simple column
                    $map[$index] = $field;
                }
                $index++;
            }
        }

        return $map;
    }

    /**
     * Extracts table information from a FROM clause.
     * 
     * @param string $fromClause The FROM clause (e.g., "FROM users u LEFT JOIN posts p ON...")
     * @return array Array of ['alias' => ..., 'real' => ...] for each table
     */
    private function extractTablesFromFromClause(string $fromClause): array
    {
        $tablesInfo = [];
        
        // Remove "FROM " prefix
        $clause = preg_replace('/^FROM\s+/i', '', $fromClause);
        
        // Split by JOIN keywords to get individual table parts
        $parts = preg_split('/\s+(?:LEFT\s+|RIGHT\s+|INNER\s+|OUTER\s+|CROSS\s+)?JOIN\s+/i', $clause);
        
        foreach ($parts as $part) {
            $part = trim($part);
            // Remove ON clause and everything after
            $part = preg_replace('/\s+ON\s+.*$/i', '', $part);
            $part = trim($part);
            
            // Extract table name and alias
            if (preg_match('/^(\w+)(?:\s+(?:AS\s+)?(\w+))?/', $part, $matches)) {
                $realTable = $matches[1];
                $alias = $matches[2] ?? $realTable;
                $tablesInfo[] = ['real' => $realTable, 'alias' => $alias];
            }
        }
        
        return $tablesInfo;
    }

    /**
     * Converts an array to a comma-separated string, leaves strings unchanged.
     */
    private function normalizeField($field): string
    {
        if (is_array($field)) {
            return implode(', ', $field);
        }
        return (string) $field;
    }

    /**
     * Compila DELETE.
     */
    public function compileDelete(array $state): array
    {
        $from   = $state[self::FROM] ?? '';
        $where  = $state[self::WHERE] ? ' WHERE ' . $state[self::WHERE] : '';
        $params = $state[self::PARAMS] ?? [];
        $sql = sprintf(self::TPL_DELETE, $from, $where);
        return [$sql, $params];
    }

    /**
     * Compila COUNT.
     */
    public function compileCount(array $state): array
    {
        $from   = $state[self::FROM] ?? '';
        $where  = $state[self::WHERE] ? ' WHERE ' . $state[self::WHERE] : '';
        $params = $state[self::PARAMS] ?? [];
        $sql = sprintf(self::TPL_COUNT, $from, $where);
        return [$sql, $params];
    }

    /**
     * Compila EXISTS.
     */
    public function compileExists(array $state): array
    {
        $from   = $state[self::FROM] ?? '';
        $where  = $state[self::WHERE] ? ' WHERE ' . $state[self::WHERE] : '';
        $params = $state[self::PARAMS] ?? [];
        $sql = sprintf(self::TPL_EXISTS, $from, $where);
        return [$sql, $params];
    }

    /**
     * Compila UPDATE.
     *
     * @param array $data Datos a actualizar [columna => valor]
     */
    public function compileUpdate(array $state, array $data): array
    {
        $from   = $state[self::FROM] ?? '';
        $where  = $state[self::WHERE] ? ' WHERE ' . $state[self::WHERE] : '';
        $params = $state[self::PARAMS] ?? [];

        $setParts = [];
        $setParams = [];
        foreach ($data as $col => $val) {
            $setParts[] = ConditionMatrix::quote($col) . ' = ?';
            $setParams[] = $val;
        }
        $setSql = implode(', ', $setParts);

        // Los parámetros del SET van antes que los del WHERE
        $params = array_merge($setParams, $params);
        $sql = sprintf(self::TPL_UPDATE, $from, $setSql, $where);
        return [$sql, $params];
    }

    /**
     * Compila INSERT (simple o múltiple).
     *
     * @param array $rows Una fila asociativa o array de filas
     */
    public function compileInsert(array $state, array $rows): array
    {
        $from   = $state[self::FROM] ?? '';

        $data = isset($rows[0]) && is_array($rows[0]) ? $rows : [$rows];
        if (empty($data)) {
            return ['', []];
        }

        $columns = array_keys($data[0]);
        $colsSql = implode(', ', array_map([ConditionMatrix::class, 'quote'], $columns));

        $rowPlaceholder = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $valuesSql = implode(', ', array_fill(0, count($data), $rowPlaceholder));

        $params = [];
        foreach ($data as $row) {
            foreach ($columns as $c) {
                $params[] = $row[$c] ?? null;
            }
        }

        $sql = sprintf(self::TPL_INSERT, $from, $colsSql, $valuesSql);
        return [$sql, $params];
    }
}