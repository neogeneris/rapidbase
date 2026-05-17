<?php

declare(strict_types=1);

namespace RapidBase\Core\SQL;

use RapidBase\Core\SchemaMap;

/**
 * SqlCompiler - Genera SQL usando plantillas sprintf.
 * 
 * Máxima eficiencia: sin claves de cadena, sin objetos adicionales.
 * Todos los métodos son estáticos, no requiere instanciación.
 */
class SqlCompiler
{
    // Índices del array de estado compilado
    public const SEL    = 0;
    public const FROM   = 1;
    public const WHERE  = 2;
    public const GROUP  = 3;
    public const HAVING = 4;
    public const ORDER  = 5;
    public const LIMIT  = 6;
    public const PARAMS = 7;

    // Plantillas sprintf
    // La plantilla de SELECT ya NO incluye "FROM" porque $from ya lo contiene.
    private const TPL_SELECT = "SELECT\n    %s\n%s%s%s%s%s%s";
    private const TPL_DELETE = 'DELETE FROM %s%s';
    private const TPL_COUNT  = 'SELECT COUNT(*) FROM %s%s';
    private const TPL_EXISTS = 'SELECT EXISTS(SELECT 1 FROM %s%s)';
    private const TPL_UPDATE = 'UPDATE %s SET %s%s';
    private const TPL_INSERT = 'INSERT INTO %s (%s) VALUES %s';

    /**
     * Compila un SELECT con formato legible.
     */
	public static function compileSelect(array $state): array
	{
		$sel    = self::normalizeField($state[self::SEL] ?? '*');
		
		// Agrupar columnas de a 3 por línea
		if ($sel !== '*') {
			$columns = array_map('trim', explode(',', $sel));
			$groups = array_chunk($columns, 3);
			$sel = implode(",\n    ", array_map(fn($group) => implode(', ', $group), $groups));
		}

		$from   = $state[self::FROM]   ?? '';
		$where  = $state[self::WHERE]  ? "\nWHERE\n    " . $state[self::WHERE] : '';
		$group  = ($g = self::normalizeField($state[self::GROUP] ?? '')) ? "\nGROUP BY\n    " . $g : '';
		$having = $state[self::HAVING] ? "\nHAVING\n    " . $state[self::HAVING] : '';
		$order  = ($o = self::normalizeField($state[self::ORDER] ?? '')) ? "\nORDER BY\n    " . $o : '';
		$limit  = $state[self::LIMIT]  ? "\n" . trim($state[self::LIMIT]) : '';
		$params = $state[self::PARAMS] ?? [];

		// Cada JOIN en su propia línea
		$from = preg_replace_callback(
			'/\s+((?:LEFT|RIGHT|INNER|OUTER|CROSS)\s+)?JOIN\s+/i',
			function($matches) {
				return "\n    " . $matches[0];
			},
			$from
		);

		$sql = sprintf(
			self::TPL_SELECT,   // "SELECT\n    %s\n%s%s%s%s%s%s"
			$sel,
			$from,
			$where,
			$group,
			$having,
			$order,
			$limit
		);

		$projectionMap = self::buildProjectionMap($state[self::SEL] ?? '*', $from);
		return [$sql, $params, $projectionMap];
	}
    public static function compileDelete(array $state): array
    {
        $from   = $state[self::FROM] ?? '';
        $where  = $state[self::WHERE] ? ' WHERE ' . $state[self::WHERE] : '';
        $params = $state[self::PARAMS] ?? [];
        $sql = sprintf(self::TPL_DELETE, $from, $where);
        return [$sql, $params];
    }

    public static function compileCount(array $state): array
    {
        $from   = $state[self::FROM] ?? '';
        $where  = $state[self::WHERE] ? ' WHERE ' . $state[self::WHERE] : '';
        $params = $state[self::PARAMS] ?? [];
        $sql = sprintf(self::TPL_COUNT, $from, $where);
        return [$sql, $params];
    }

    public static function compileExists(array $state): array
    {
        $from   = $state[self::FROM] ?? '';
        $where  = $state[self::WHERE] ? ' WHERE ' . $state[self::WHERE] : '';
        $params = $state[self::PARAMS] ?? [];
        $sql = sprintf(self::TPL_EXISTS, $from, $where);
        return [$sql, $params];
    }

    public static function compileUpdate(array $state, array $data): array
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

        $params = array_merge($setParams, $params);
        $sql = sprintf(self::TPL_UPDATE, $from, $setSql, $where);
        return [$sql, $params];
    }

    public static function compileInsert(array $state, array $rows): array
    {
        $from = $state[self::FROM] ?? '';

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

    // ========== Private helpers ==========

    private static function normalizeField($field): string
    {
        if (is_array($field)) {
            return implode(', ', $field);
        }
        return (string) $field;
    }

    private static array $projectionCache = [];

    private static function buildProjectionMap($fields, string $fromClause): array
    {
        $fieldsKey = is_array($fields) ? implode(',', $fields) : (string)$fields;
        $cacheKey = $fieldsKey . '|' . $fromClause;
        if (isset(self::$projectionCache[$cacheKey])) {
            return self::$projectionCache[$cacheKey];
        }

        $map = [];
        $index = 0;

        $tablesInfo = self::extractTablesFromFromClause($fromClause);

        if ($fields === '*') {
            $schema = SchemaMap::getMap();
            foreach ($tablesInfo as $info) {
                $realTable = $info['real'];
                $alias = $info['alias'];
                if (isset($schema['tables'][$realTable])) {
                    foreach ($schema['tables'][$realTable] as $col => $def) {
                        $map[$alias . '.' . $col] = $index;
                        $index++;
                    }
                }
            }
            return $map;
        }

        if (is_string($fields)) {
            $parts = explode(',', $fields);
        } elseif (is_array($fields)) {
            $parts = $fields;
        } else {
            return [];
        }

        foreach ($parts as $field) {
            $field = trim($field);
            
            // Check for alias with AS keyword (supports quoted aliases)
            if (preg_match('/\s+as\s+["`]?(\w+)["`]?/i', $field, $matches)) {
                $map[$matches[1]] = $index;
                $index++;
            } 
            // Check for table.* expansion (supports quoted table names)
            elseif (preg_match('/["`]?(\w+)["`]?\.\*/', $field, $matches)) {
                $tableAlias = $matches[1];
                $schema = SchemaMap::getMap();
                foreach ($tablesInfo as $info) {
                    if ($info['alias'] === $tableAlias) {
                        $realTable = $info['real'];
                        if (isset($schema['tables'][$realTable])) {
                            foreach ($schema['tables'][$realTable] as $col => $def) {
                                $map[$tableAlias . '.' . $col] = $index;
                                $index++;
                            }
                        }
                        break;
                    }
                }
            } 
            // Regular field or function
            else {
                // Extract simple column name or use the whole expression as alias
                if (strpos($field, '.') !== false && !preg_match('/^\w+\(/', $field)) {
                    // table.column format - extract base name
                    $parts = explode('.', $field);
                    $colName = end($parts);
                    $map[$colName] = $index;
                } else {
                    // Simple column or function - extract base name or use as-is
                    $cleanField = preg_replace('/^\w+\((.*?)\)$/', '$1', $field);
                    $cleanField = preg_replace('/\s+/', '', $cleanField);
                    $map[$cleanField] = $index;
                }
                $index++;
            }
        }
        return self::$projectionCache[$cacheKey] = $map;
    }

    private static function extractTablesFromFromClause(string $fromClause): array
    {
        $tablesInfo = [];
        
        $clause = preg_replace('/^FROM\s+/i', '', $fromClause);
        $parts = preg_split('/\s+(?:LEFT\s+|RIGHT\s+|INNER\s+|OUTER\s+|CROSS\s+)?JOIN\s+/i', $clause);
        
        foreach ($parts as $part) {
            $part = trim($part);
            $part = preg_replace('/\s+ON\s+.*$/i', '', $part);
            $part = trim($part);
            
            // Soporta identificadores con comillas dobles (Postgres), backticks (MySQL) o simples
            if (preg_match('/^(?:["`]?([\w.-]+)["`]?)(?:\s+(?:AS\s+)?(?:["`]?([\w.-]+)["`]?))?/i', $part, $matches)) {
                $realTable = $matches[1];
                $alias = $matches[2] ?? $realTable;
                $tablesInfo[] = ['real' => $realTable, 'alias' => $alias];
            }
        }
        
        return $tablesInfo;
    }
}