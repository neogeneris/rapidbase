<?php

namespace Core;

class SQL {
    private static array $relMap = [];
    private static array $schema = [];
    private static string $quoteChar = "`";
    protected static int $parameterCount = 0;
    private static array $joinTreeCache = [];

    // ========== CARGA DEL MAPA Y ESQUEMA ==========

    public static function setRelationsMap(array $map): void {
        if (isset($map['relationships'])) {
            self::$relMap = $map['relationships'];
        } else {
            self::$relMap = $map;
        }
        if (isset($map['tables'])) {
            self::$schema = $map['tables'];
        }
    }

    private static function hasSchema(): bool {
        return !empty(self::$schema);
    }

    private static function hasRelations(): bool {
        return !empty(self::$relMap);
    }

    private static function getTableColumns(string $tableName): array {
        if (!isset(self::$schema[$tableName])) {
            throw new \RuntimeException("Table '$tableName' not found in schema map.");
        }
        return array_keys(self::$schema[$tableName]);
    }

    // ========== MÉTODOS BASE ==========

    public static function reset(): void {
        self::$parameterCount = 0;
    }

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

    private static function quoteField(string $field): string {
        $field = trim($field);
        if (preg_match('/^(.*?)\s+AS\s+(.*)$/i', $field, $matches)) {
            $left = trim($matches[1]);
            $right = trim($matches[2]);
            return self::quote($left) . ' AS ' . self::quote($right);
        }
        return self::quote($field);
    }

    // ========== CONSTRUCCIÓN DE SELECT ==========

    public static function buildSelect($fields = '*', $table = '', array $where = [], array $groupBy = [], array $having = [], array $sort = [], int $page = 1, int $perPage = 10): array {
        $tables = is_array($table) ? $table : [$table];

        $realTableNames = [];
        foreach ($tables as $t) {
            if (preg_match('/^\s*(\S+)\s+as\s+\S+\s*$/i', $t, $matches)) {
                $real = $matches[1];
            } else {
                $real = $t;
            }
            $realTableNames[] = $real;
        }
        $isSelfReferencing = count(array_unique($realTableNames)) < count($realTableNames);

        if (!self::hasSchema()) {
            if ($fields === '*' && $isSelfReferencing) {
                throw new \RuntimeException(
                    "Auto-referencing (same table with alias) requires the full schema map. " .
                    "Load the map using SQL::setRelationsMap() with 'tables' key or use explicit column aliases in \$fields."
                );
            }

            if ($fields === '*') {
                $selectSql = '*';
            } else {
                $fieldArray = is_array($fields) ? $fields : array_map('trim', explode(',', $fields));
                $quotedFields = [];
                foreach ($fieldArray as $field) {
                    if (preg_match('/^[0-9]+$/', $field) || str_contains($field, '(')) {
                        $quotedFields[] = $field;
                    } else {
                        $quotedFields[] = self::quoteField($field);
                    }
                }
                $selectSql = implode(', ', $quotedFields);
            }

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

        // --- MODO CON ESQUEMA (alias automáticos) ---
        $tableAliases = [];
        foreach ($tables as $t) {
            if (preg_match('/^\s*(\S+)\s+as\s+(\S+)\s*$/i', $t, $matches)) {
                $realName = $matches[1];
                $alias = $matches[2];
            } else {
                $realName = $t;
                $alias = $t;
            }
            $tableAliases[$alias] = $realName;
        }

        $selectColumns = [];
        if ($fields === '*') {
            foreach ($tableAliases as $alias => $realName) {
                $cols = self::getTableColumns($realName);
                foreach ($cols as $col) {
                    $selectColumns[] = self::quote($alias) . '.' . self::quote($col) . ' AS ' . self::quote($alias . '_' . $col);
                }
            }
        } else {
            $fieldArray = is_array($fields) ? $fields : [$fields];
            foreach ($fieldArray as $field) {
                if (str_contains($field, '(')) {
                    $selectColumns[] = $field;
                } else {
                    $selectColumns[] = self::quoteField($field);
                }
            }
        }

        $selectSql = implode(', ', $selectColumns);
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

    // ========== CONSTRUCCIÓN DE FROM CON JOINS ==========

    /**
     * Ordena las tablas de más débil (mayor outDegree) a más fuerte.
     * @param array $tableNames Nombres reales de las tablas
     * @return array Tablas ordenadas
     */
    private static function orderTablesByWeakness(array $tableNames): array {
        if (!self::hasRelations()) {
            return $tableNames;
        }

        $degrees = [];
        foreach ($tableNames as $t) {
            $out = count(self::$relMap['from'][$t] ?? []);
            $in  = count(self::$relMap['to'][$t] ?? []);
            $degrees[$t] = ['out' => $out, 'in' => $in];
        }

        uasort($degrees, function ($a, $b) {
            if ($a['out'] != $b['out']) {
                return $b['out'] <=> $a['out']; // mayor out primero
            }
            if ($a['in'] != $b['in']) {
                return $a['in'] <=> $b['in'];   // menor in primero
            }
            return 0;
        });

        return array_keys($degrees);
    }

    private static function buildJoinTree(array $tableNames): array {
        $graph = [];
        foreach ($tableNames as $t) {
            $graph[$t] = [];
        }

        foreach (self::$relMap['from'] as $from => $rels) {
            foreach ($rels as $to => $rel) {
                if (in_array($from, $tableNames) && in_array($to, $tableNames)) {
                    $graph[$from][$to] = $rel;
                    $graph[$to][$from] = $rel;
                }
            }
        }
        foreach (self::$relMap['to'] as $from => $rels) {
            foreach ($rels as $to => $rel) {
                if (in_array($from, $tableNames) && in_array($to, $tableNames)) {
                    $graph[$from][$to] = $rel;
                    $graph[$to][$from] = $rel;
                }
            }
        }

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
            throw new \RuntimeException("No se pueden conectar todas las tablas: " . implode(',', $tableNames));
        }

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

        return ['root' => $root, 'edges' => $edges];
    }

    private static function buildJoinCondition(string $parentTabla, string $parentAlias, string $childTabla, string $childAlias, array $relation): string {
        $type = $relation['type'];
        $localKey = $relation['local_key'];
        $foreignKey = $relation['foreign_key'];

        $isDirectFromParentToChild = isset(self::$relMap['from'][$parentTabla][$childTabla]);
        $isDirectFromChildToParent = isset(self::$relMap['from'][$childTabla][$parentTabla]);

        if ($type === 'belongsTo') {
            if ($isDirectFromParentToChild) {
                return " ON " . self::quote($parentAlias) . "." . self::quote($localKey)
                     . " = " . self::quote($childAlias) . "." . self::quote($foreignKey);
            } elseif ($isDirectFromChildToParent) {
                return " ON " . self::quote($parentAlias) . "." . self::quote($foreignKey)
                     . " = " . self::quote($childAlias) . "." . self::quote($localKey);
            } else {
                return " ON " . self::quote($parentAlias) . "." . self::quote($localKey)
                     . " = " . self::quote($childAlias) . "." . self::quote($foreignKey);
            }
        } else {
            if (isset(self::$relMap['to'][$parentTabla][$childTabla])) {
                $relTo = self::$relMap['to'][$parentTabla][$childTabla];
                return " ON " . self::quote($childAlias) . "." . self::quote($relTo['local_key'])
                     . " = " . self::quote($parentAlias) . "." . self::quote($relTo['foreign_key']);
            } elseif (isset(self::$relMap['to'][$childTabla][$parentTabla])) {
                $relTo = self::$relMap['to'][$childTabla][$parentTabla];
                return " ON " . self::quote($parentAlias) . "." . self::quote($relTo['local_key'])
                     . " = " . self::quote($childAlias) . "." . self::quote($relTo['foreign_key']);
            } else {
                return " ON " . self::quote($childAlias) . "." . self::quote($localKey)
                     . " = " . self::quote($parentAlias) . "." . self::quote($foreignKey);
            }
        }
    }

    /**
     * Construye la cláusula FROM con soporte para:
     * - Array plano de strings: usa el mapa global y reordena por debilidad (por defecto).
     * - Array con subarrays (anidado): desactiva reordenamiento.
     * - Relaciones inline: cada elemento puede ser un array asociativo ['tabla' => definición]
     *   para definir la relación con la tabla anterior.
     *
     * @param string|array $table Nombre de tabla o array con tablas
     * @return string Cláusula FROM (incluye JOINs)
     */
    public static function buildFromWithMap(mixed $table): string {
        if (is_string($table)) {
            return " FROM " . self::quote($table) . " ";
        }

        if (!is_array($table)) {
            return "";
        }

        // Detectar si hay algún elemento que sea array (anidamiento o relación inline)
        $hasComplex = false;
        foreach ($table as $item) {
            if (is_array($item)) {
                $hasComplex = true;
                break;
            }
        }

        // Si hay complejidad, usamos construcción lineal respetando el orden exacto
        if ($hasComplex) {
            // Aplanar subarrays que sean listas simples (numéricos)
            $flat = [];
            foreach ($table as $item) {
                if (is_array($item) && array_is_list($item)) {
                    foreach ($item as $sub) {
                        $flat[] = $sub;
                    }
                } else {
                    $flat[] = $item;
                }
            }
            return self::buildFromLinear($flat);
        }

        // --- Formato plano (sin anidamiento) ---
        // Extraer nombres reales y alias
        $realNames = [];
        $aliases = [];
        foreach ($table as $t) {
            if (preg_match('/^\s*(\S+)\s+as\s+(\S+)\s*$/i', $t, $matches)) {
                $real = $matches[1];
                $alias = $matches[2];
            } else {
                $real = $t;
                $alias = $t;
            }
            $realNames[] = $real;
            $aliases[$real] = $alias;
        }

        $hasDuplicates = count($realNames) !== count(array_unique($realNames));

        // Auto-referencia o sin esquema pero con relaciones -> lineal
        if ($hasDuplicates || (!self::hasSchema() && self::hasRelations())) {
            return self::buildFromLinear($table);
        }

        // Sin esquema ni relaciones -> JOIN lineales sin ON
        if (!self::hasSchema() && !self::hasRelations()) {
            $first = array_shift($table);
            $from = " FROM " . self::quote($first) . " ";
            foreach ($table as $next) {
                $from .= " LEFT JOIN " . self::quote($next) . " ";
            }
            return $from;
        }

        // Caso normal: ordenar por debilidad y construir árbol de JOINs
        if (self::hasRelations()) {
            $realNames = self::orderTablesByWeakness($realNames);
            $aliasesOrdered = [];
            foreach ($realNames as $real) {
                $aliasesOrdered[$real] = $aliases[$real];
            }
            $aliases = $aliasesOrdered;
        }

        $cacheKey = implode('|', $realNames);
        if (!isset(self::$joinTreeCache[$cacheKey])) {
            self::$joinTreeCache[$cacheKey] = self::buildJoinTree($realNames);
        }
        $tree = self::$joinTreeCache[$cacheKey];

        $rootReal = $tree['root'];
        $rootAlias = $aliases[$rootReal];
        $from = " FROM " . self::quote($rootReal);
        if ($rootAlias !== $rootReal) $from .= " AS " . self::quote($rootAlias);
        $from .= " ";

        foreach ($tree['edges'] as $edge) {
            $parentReal = $edge['parent'];
            $childReal  = $edge['child'];
            $rel        = $edge['rel'];
            $parentAlias = $aliases[$parentReal];
            $childAlias  = $aliases[$childReal];
            $onClause = self::buildJoinCondition($parentReal, $parentAlias, $childReal, $childAlias, $rel);
            $from .= " LEFT JOIN " . self::quote($childReal);
            if ($childAlias !== $childReal) $from .= " AS " . self::quote($childAlias);
            $from .= " " . $onClause . " ";
        }

        return $from;
    }

    /**
     * Construye FROM con JOINs lineales (uno tras otro) respetando el orden exacto.
     * Soporta elementos que pueden ser:
     * - string: nombre de tabla (o "tabla AS alias")
     * - array asociativo con una sola clave: ['tabla' => $relationDef]
     *   donde $relationDef es ['type'=>..., 'local_key'=>..., 'foreign_key'=>...]
     *
     * @param array $tables Lista de elementos
     * @return string Cláusula FROM
     */
    private static function buildFromLinear(array $tables): string {
        if (empty($tables)) {
            return "";
        }

        // Procesar el primer elemento (debe ser string)
        $first = $tables[0];
        if (is_array($first)) {
            throw new \InvalidArgumentException("La primera tabla no puede ser una relación inline (no hay tabla anterior).");
        }
        $firstReal = $first;
        $firstAlias = $first;
        if (preg_match('/^\s*(\S+)\s+as\s+(\S+)\s*$/i', $first, $matches)) {
            $firstReal = $matches[1];
            $firstAlias = $matches[2];
        }

        $from = " FROM " . self::quote($firstReal);
        if ($firstAlias !== $firstReal) $from .= " AS " . self::quote($firstAlias);
        $from .= " ";

        $currentReal = $firstReal;
        $currentAlias = $firstAlias;

        // Recorrer desde el segundo elemento
        for ($i = 1; $i < count($tables); $i++) {
            $item = $tables[$i];
            $nextReal = null;
            $nextAlias = null;
            $relationDef = null;

            if (is_string($item)) {
                // Puede ser "tabla" o "tabla AS alias"
                if (preg_match('/^\s*(\S+)\s+as\s+(\S+)\s*$/i', $item, $matches)) {
                    $nextReal = $matches[1];
                    $nextAlias = $matches[2];
                } else {
                    $nextReal = $item;
                    $nextAlias = $item;
                }
                // Buscar relación en el mapa global
                $relationDef = self::$relMap['from'][$currentReal][$nextReal] ?? self::$relMap['to'][$currentReal][$nextReal] ?? null;
            } elseif (is_array($item)) {
                // Relación inline: debe tener exactamente una clave
                $keys = array_keys($item);
                if (count($keys) !== 1) {
                    throw new \InvalidArgumentException("Relación inline debe tener una sola clave: 'tabla' => definición");
                }
                $nextReal = $keys[0];
                $relationDef = $item[$nextReal];
                $nextAlias = $nextReal;
                // Permitir alias dentro de la definición con clave 'as'
                if (isset($relationDef['as'])) {
                    $nextAlias = $relationDef['as'];
                    unset($relationDef['as']);
                }
            } else {
                throw new \InvalidArgumentException("Elemento inválido en la lista de tablas: debe ser string o array asociativo.");
            }

            $from .= " LEFT JOIN " . self::quote($nextReal);
            if ($nextAlias !== $nextReal) $from .= " AS " . self::quote($nextAlias);

            if ($relationDef) {
                $onClause = self::buildJoinConditionFromDef($currentReal, $currentAlias, $nextReal, $nextAlias, $relationDef);
                $from .= " " . $onClause . " ";
            } else {
                // Sin relación definida: CROSS JOIN (producto cartesiano)
                $from .= " ";
            }

            $currentReal = $nextReal;
            $currentAlias = $nextAlias;
        }

        return $from;
    }

    /**
     * Construye la condición ON a partir de una definición de relación.
     * Formato esperado: ['type' => 'belongsTo', 'local_key' => 'campo', 'foreign_key' => 'campo']
     */
    private static function buildJoinConditionFromDef(string $parentReal, string $parentAlias, string $childReal, string $childAlias, array $def): string {
        $localKey = $def['local_key'];
        $foreignKey = $def['foreign_key'];
        // Asumimos la dirección: padre.local_key = hijo.foreign_key
        return " ON " . self::quote($parentAlias) . "." . self::quote($localKey)
             . " = " . self::quote($childAlias) . "." . self::quote($foreignKey);
    }

    // ========== WHERE Y ORDER BY ==========

    public static function buildWhere(array $where, array $context = [], string $defaultAlias = ''): array {
    if (empty($where)) return ['sql' => "1", 'params' => []];

    $sqlParts = [];
    $params = [];

    foreach ($where as $column => $value) {
        $rawColumn = trim($column);

        // Si la columna ya tiene punto (ej. "u.id") o es una expresión (función, operador), no inferimos
        if (!str_contains($rawColumn, '.') && !preg_match('/[^\w\.]/', $rawColumn)) {
            $foundAlias = null;

            if (self::hasSchema() && !empty($context)) {
                foreach ($context as $alias => $realTable) {
                    if (isset(self::$schema[$realTable][$rawColumn])) {
                        if ($foundAlias === null) {
                            $foundAlias = $alias;
                        } else {
                            // Columna ambigua: existe en más de una tabla del contexto
                            throw new \RuntimeException(
                                "Column '$rawColumn' is ambiguous, found in tables '{$context[$foundAlias]}' and '$realTable'. " .
                                "Please use alias prefix like '$alias.$rawColumn'."
                            );
                        }
                    }
                }
            }

            $prefix = $foundAlias ?? $defaultAlias;
            if ($prefix !== '') {
                $rawColumn = $prefix . '.' . $rawColumn;
            }
        }

        $safeColumn = self::quote($rawColumn);

        // Lógica de binding (igual que antes)
        if (is_null($value)) {
            $sqlParts[] = "$safeColumn IS NULL";
        } elseif (is_array($value)) {
            foreach ($value as $operator => $innerValue) {
                $token = self::nextToken();
                $sqlParts[] = "$safeColumn $operator :$token";
               
                $params[":$token"] = $innerValue; 
            }
        } else {
            $token = self::nextToken();
            $sqlParts[] = "$safeColumn = :$token";
            
            $params[":$token"] = $value;
        }
    }

    return ['sql' => implode(' AND ', $sqlParts), 'params' => $params];
}


	/**
	 * Califica y escapa el nombre de una columna.
	 * Por ahora, solo asegura el entrecomillado correcto.
	 */
	public static function qualifyColumn(string $column): string {
		// Si ya viene con punto (ej: "u.id"), quote() ya sabe manejarlo.
		// Si no, simplemente lo escapa.
		return self::quote($column);
	}



	public static function buildOrderBy(array $sortFields): string {
		if (empty($sortFields)) return "";

		$parts = [];
		foreach ($sortFields as $field) {
			// 1. Detectar si el primer carácter es '-'
			$direction = str_starts_with($field, '-') ? 'DESC' : 'ASC';
			
			// 2. Limpiar el nombre del campo (quitar el '-' si existe)
			$cleanField = ltrim($field, '-');
			
			// 3. Aplicar el prefijo de tabla/alias (si tenemos el contexto)
			$qualifiedField = self::qualifyColumn($cleanField); 

			$parts[] = "{$qualifiedField} {$direction}";
		}

		return " ORDER BY " . implode(', ', $parts);
	}
    // ========== OPERACIONES DE ESCRITURA ==========

    public static function buildInsert(string $table, array $rows): array {
        if (empty($rows)) throw new \InvalidArgumentException("No se pueden insertar registros vacíos.");
        $isSingle = !isset($rows[0]) || !is_array($rows[0]);
        $data = $isSingle ? [$rows] : $rows;
        if (empty($data[0])) throw new \InvalidArgumentException("El registro de datos está vacío.");
        $columns = array_keys($data[0]);
        $quotedCols = implode(', ', array_map([self::class, 'quote'], $columns));
        $placeholders = [];
        $params = [];
        foreach ($columns as $col) {
            $token = self::nextToken();
            $placeholders[] = ":$token";
            $params[$token] = $data[0][$col];
        }
        $sql = "INSERT INTO " . self::quote($table) . " ($quotedCols) VALUES (" . implode(', ', $placeholders) . ")";
        if ($isSingle) return [$sql, $params];
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

    public static function buildUpdate(string $table, array $data, array $where, bool $force = false): array {
        if (empty($where) && !$force) {
            throw new \RuntimeException("PELIGRO: UPDATE masivo sin WHERE en [$table].");
        }
        $setParts = [];
        $params = [];
        foreach ($data as $col => $val) {
            $quotedCol = self::quote($col);
            $token = self::nextToken();
            $setParts[] = "$quotedCol = :$token";
            $params[$token] = ($val === '') ? null : $val;
        }
        $whereData = self::buildWhere($where);
        $sql = "UPDATE " . self::quote($table) . " SET " . implode(', ', $setParts) . " WHERE " . $whereData['sql'];
        return [$sql, array_merge($params, $whereData['params'])];
    }

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
            return ["SELECT COUNT(*) $from WHERE {$whereData['sql']}", $whereData['params']];
        }
        [$subSql, $params] = self::buildSelect('1', $table, $where, $groupBy);
        return ["SELECT COUNT(*) FROM ($subSql) AS q", $params];
    }
}