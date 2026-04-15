<?php

namespace RapidBase\Back;

class SQL {
    private static array $relMap = [];
    private static string $quoteChar = "`";
    
    // El ÚNICO contador para toda la clase
    protected static int $parameterCount = 0; 

    public static function setRelationsMap(array $map): void {
        self::$relMap = $map;
    }

    /**
     * Vital para tests: Reinicia el estado global de la fundición.
     */
    public static function reset(): void {
        self::$parameterCount = 0; 
    }

    /**
     * Generador único de placeholders para toda la clase.
     */
    protected static function nextToken(): string {
        return "p" . (self::$parameterCount++);
    }

    public static function quote(string $identifier): string {
        $q = self::$quoteChar;
        $identifier = trim($identifier);
        if ($identifier === '*' || str_starts_with($identifier, $q)) return $identifier;
        
        $parts = explode('.', $identifier);
        $quotedParts = array_map(function($part) use ($q) {
            return $part === '*' ? '*' : $q . trim($part, $q) . $q;
        }, $parts);
        
        return implode('.', $quotedParts);
    }

    // ========== SELECT ==========

    public static function buildSelect($fields = '*', $table = '', array $where = [], array $groupBy = [], array $having = [], array $sort = [], int $page = 1, int $perPage = 10): array {
        $selectSql = is_array($fields) ? implode(', ', array_map([self::class, 'quote'], $fields)) : (string)$fields;
        $fromClause = self::buildFromWithMap($table);
        $whereData = self::buildWhere($where);
        $params = $whereData['params'];

        $groupSql = !empty($groupBy) ? " GROUP BY " . implode(', ', array_map([self::class, 'quote'], (array)$groupBy)) : "";

        $havingSql = "";
        if (!empty($having)) {
            $havingData = self::buildWhere($having);
            $havingSql = " HAVING " . $havingData['sql'];
            $params = array_merge($params, $havingData['params']);
        }

        $orderSql = self::buildOrderBy($sort);
        $offset = ($page - 1) * $perPage;
        $limitSql = " LIMIT $perPage OFFSET $offset";

        $sql = "SELECT $selectSql $fromClause WHERE {$whereData['sql']}$groupSql$havingSql$orderSql$limitSql";
        return [trim($sql), $params];
    }

    // ========== INSERT ==========

    public static function buildInsert(string $table, array $rows): array {
        if (empty($rows)) throw new \InvalidArgumentException("No se pueden insertar registros vacíos.");

        $isSingle = !isset($rows[0]) || !is_array($rows[0]);
        $data = $isSingle ? [$rows] : $rows;
        if (empty($data[0])) throw new \InvalidArgumentException("El registro de datos está vacío.");

        $columns = array_keys($data[0]);
        $quotedCols = implode(', ', array_map([self::class, 'quote'], $columns));
        
        $placeholders = [];
        $params = [];

        // Solo generamos tokens para la primera fila (o single insert)
        foreach ($columns as $col) {
            $token = self::nextToken();
            $placeholders[] = ":$token";
            $params[$token] = $data[0][$col]; 
        }

        $sql = "INSERT INTO " . self::quote($table) . " ($quotedCols) VALUES (" . implode(', ', $placeholders) . ")";

        if ($isSingle) return [$sql, $params];

        // Lógica batch simplificada para mantener compatibilidad
        $batchParams = [$params]; 
        for ($i = 1; $i < count($data); $i++) {
            $p = [];
            foreach ($columns as $col) {
                $token = self::nextToken();
                $p[$token] = $data[$i][$col];
            }
            $batchParams[] = $p;
        }
        return [$sql, $batchParams];
    }

    // ========== UPDATE ==========

    public static function buildUpdate(string $table, array $data, array $where, bool $force = false): array {
        if (empty($where) && !$force) {
            throw new \RuntimeException("PELIGRO: UPDATE masivo sin WHERE en [$table].");
        }

        $setParts = [];
        $params = [];

        foreach ($data as $col => $val) {
            $quotedCol = self::quote($col);
            $token = self::nextToken(); // Ahora usa :p0, :p1...
            $setParts[] = "$quotedCol = :$token";
            $params[$token] = ($val === '') ? null : $val;
        }

        $whereData = self::buildWhere($where);
        $sql = "UPDATE " . self::quote($table) . " SET " . implode(', ', $setParts) . " WHERE " . $whereData['sql'];

        return [$sql, array_merge($params, $whereData['params'])];
    }

    // ========== DELETE, EXISTS, COUNT ==========

    public static function buildDelete(string $table, array $where, bool $force = false): array {
        if (empty($where) && !$force) throw new \RuntimeException("PELIGRO: DELETE masivo sin WHERE.");
        $whereData = self::buildWhere($where);
        return ["DELETE FROM " . self::quote($table) . " WHERE " . $whereData['sql'], $whereData['params']];
    }

    public static function buildExists(string $table, array $where): array {
        $whereData = self::buildWhere($where);
        return ["SELECT EXISTS(SELECT 1 FROM " . self::quote($table) . " WHERE " . $whereData['sql'] . ") AS `check` ", $whereData['params']];
    }

    public static function buildCount(mixed $table, array $where = [], array $groupBy = []): array {
        if (empty($groupBy)) {
            $from = self::buildFromWithMap($table); 
            $whereData = self::buildWhere($where);
            return ["SELECT COUNT(*)$from WHERE {$whereData['sql']}", $whereData['params']];
        }
        [$subSql, $params] = self::buildSelect('1', $table, $where, $groupBy);
        return ["SELECT COUNT(*) FROM ($subSql) AS q", $params];
    }

    // ========== MOTOR WHERE ==========

    public static function buildWhere(array $where): array {
        if (empty($where)) return ['sql' => "1", 'params' => []];

        $sqlParts = [];
        $params = [];

        foreach ($where as $column => $value) {
            $safeColumn = self::quote($column);

            if (is_null($value)) {
                $sqlParts[] = "$safeColumn IS NULL";
            } elseif (is_array($value)) {
                foreach ($value as $operator => $val) {
                    $token = self::nextToken();
                    $sqlParts[] = "$safeColumn $operator :$token";
                    $params[$token] = $val;
                }
            } else {
                $token = self::nextToken();
                $sqlParts[] = "$safeColumn = :$token";
                $params[$token] = ($value === '') ? null : $value;
            }
        }
        return ['sql' => implode(' AND ', $sqlParts), 'params' => $params];
    }

    // ========== AUXILIARES ==========

    public static function buildFromWithMap(mixed $table): string {
        if (is_string($table)) return " FROM " . self::quote($table) . " ";
        if (is_array($table)) {
            $baseTable = array_shift($table);
            $from = " FROM " . self::quote($baseTable) . " ";
            $currentTable = $baseTable;
            foreach ($table as $relatedTable) {
                $rel = self::$relMap['from'][$currentTable][$relatedTable] ?? self::$relMap['to'][$currentTable][$relatedTable] ?? null;
                $from .= " LEFT JOIN " . self::quote($relatedTable) . ($rel ? " ON " . self::quote($currentTable) . "." . self::quote($rel['local_key']) . " = " . self::quote($relatedTable) . "." . self::quote($rel['foreign_key']) . " " : " ");
                $currentTable = $relatedTable;
            }
            return $from;
        }
        return "";
    }

    public static function buildOrderBy(array $sort): string {
        if (empty($sort)) return "";
        $parts = [];
        foreach ($sort as $col => $dir) {
            if ($dir === '' || $dir === false || $dir === null || $dir === 0 || $dir === '0') continue;
            $direction = (in_array($dir, [-1, '-1', 'DESC', 'desc'], true)) ? 'DESC' : 'ASC';
            $parts[] = self::quote($col) . " $direction";
        }
        return empty($parts) ? "" : " ORDER BY " . implode(', ', $parts);
    }
}