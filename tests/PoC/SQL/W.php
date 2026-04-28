<?php

namespace RapidBase\Core;

/**
 * Clase W: Variante de bajo acoplamiento y encadenamiento corto (PoC).
 * 
 * Soporta:
 * - Auto-join automático: W::from(['users', 'posts'])
 * - Auto-join determinista: W::from(['users', ['posts']]) (users es pivote)
 * - Relaciones inline: W::from(['drivers' => ['users' => ['type'=>'belongsTo', ...]]])
 * - Alias de tablas: W::from('users as u') o ['users as u', 'posts as p']
 * - Quoting automático según driver
 */
class W
{
    // Constantes de 4 caracteres para claves del estado
    protected const T_FRO = 0;      // FROM clause
    protected const T_TYP = 1;      // Type: raw, list, relations, deterministic
    protected const T_WHR = 2;      // WHERE clause
    protected const T_PRM = 3;      // Parameters
    protected const T_SEL = 4;      // SELECT fields
    protected const T_ORD = 5;      // ORDER BY
    protected const T_LIM = 6;      // LIMIT
    protected const T_OFF = 7;      // OFFSET
    protected const T_GRP = 8;      // GROUP BY
    protected const T_HAV = 9;      // HAVING
    protected const T_JON = 10;     // JOIN clauses
    protected const T_ALI = 11;     // Table aliases map
    
    private static string $driver = 'sqlite';
    private static string $quoteChar = '"';
    
    // Estado interno protegido para permitir herencia
    protected array $state = [];
    
    private static array $whereCache = [];
    private const DEF_PAGE = 20;

    public static function setDriver(string $driver): void
    {
        self::$driver = strtolower($driver);
        self::$quoteChar = (self::$driver === 'mysql') ? '`' : '"';
    }

    /**
     * Inicializa la consulta con tabla(s) y filtro opcional.
     * 
     * @param mixed $from Puede ser:
     *   - string: SQL crudo o tabla simple ('users', 'users as u')
     *   - array plano: ['users', 'posts'] → auto-join automático
     *   - array mixto: ['users', ['posts']] → auto-join determinista (users es pivote)
     *   - array relacional: ['drivers' => ['users' => ['type'=>'belongsTo', ...]]]
     * @param array $filter Filtro WHERE inicial
     * @return static
     */
    public static function from($from, array $filter = []): self
    {
        $instance = new static();
        
        // Procesar $from según su tipo
        if (is_string($from)) {
            // String: SQL crudo o tabla simple
            $instance->state[self::T_FRO] = self::parseTableString($from);
            $instance->state[self::T_TYP] = 'raw';
            $instance->state[self::T_ALI] = self::extractAlias($from);
            $instance->state[self::T_JON] = '';
        } elseif (is_array($from)) {
            // Determinar si es array relacional o lista de tablas
            if (self::isRelationalArray($from)) {
                // Array relacional inline
                $result = self::parseRelationalFrom($from);
                $instance->state[self::T_FRO] = $result['from'];
                $instance->state[self::T_JON] = $result['joins'];
                $instance->state[self::T_TYP] = 'relations';
                $instance->state[self::T_ALI] = $result['aliases'];
            } else {
                // Lista de tablas para auto-join
                $result = self::parseTableList($from);
                $instance->state[self::T_FRO] = $result['from'];
                $instance->state[self::T_JON] = $result['joins'];
                $instance->state[self::T_TYP] = $result['type']; // 'list' o 'deterministic'
                $instance->state[self::T_ALI] = $result['aliases'];
            }
        } else {
            throw new \InvalidArgumentException("El primer parámetro de from() debe ser string o array.");
        }

        // Procesar filtro WHERE
        if (!empty($filter)) {
            $whereKey = self::getWhereCacheKey($filter);
            if (isset(self::$whereCache[$whereKey])) {
                $instance->state[self::T_WHR] = self::$whereCache[$whereKey]['sql'];
                $instance->state[self::T_PRM] = self::$whereCache[$whereKey]['params'];
            } else {
                $parsed = self::parseWhere($filter);
                self::$whereCache[$whereKey] = $parsed;
                $instance->state[self::T_WHR] = $parsed['sql'];
                $instance->state[self::T_PRM] = $parsed['params'];
            }
        } else {
            $instance->state[self::T_WHR] = '';
            $instance->state[self::T_PRM] = [];
        }

        // Inicializar resto del estado
        $instance->state[self::T_SEL] = '*';
        $instance->state[self::T_ORD] = '';
        $instance->state[self::T_LIM] = null;
        $instance->state[self::T_OFF] = null;
        $instance->state[self::T_GRP] = '';
        $instance->state[self::T_HAV] = '';

        return $instance;
    }

    /**
     * Parsea un string de tabla extrayendo alias si existe.
     */
    private static function parseTableString(string $table): string
    {
        $table = trim($table);
        // Detectar alias: "tabla as alias" o "tabla alias"
        if (preg_match('/^(\w+)\s+(?:as\s+)?(\w+)$/i', $table, $matches)) {
            return self::quote($matches[1]) . ' AS ' . self::quote($matches[2]);
        }
        return self::quote($table);
    }

    /**
     * Extrae el alias de una tabla.
     */
    private static function extractAlias(string $table): array
    {
        $table = trim($table);
        if (preg_match('/^(\w+)(?:\s+(?:as\s+)?(\w+))?$/i', $table, $matches)) {
            $realName = $matches[1];
            $alias = $matches[2] ?? $realName;
            return [$alias => $realName];
        }
        return [];
    }

    /**
     * Verifica si un array es relacional (tiene estructura anidada con 'type').
     */
    private static function isRelationalArray(array $from): bool
    {
        foreach ($from as $key => $value) {
            if (is_string($key) && is_array($value)) {
                // Buscar 'type' en algún nivel
                foreach ($value as $subKey => $subValue) {
                    if (is_array($subValue) && isset($subValue['type'])) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * Parsea un array relacional inline.
     */
    private static function parseRelationalFrom(array $from): array
    {
        $fromClause = '';
        $joinClauses = [];
        $aliases = [];
        
        foreach ($from as $tableName => $relations) {
            // Primera tabla es la base
            if (empty($fromClause)) {
                $alias = self::extractAlias($tableName)[array_key_first(self::extractAlias($tableName))] ?? $tableName;
                $realName = array_values(self::extractAlias($tableName))[0] ?? $tableName;
                $fromClause = self::quote($realName);
                if ($alias !== $realName) {
                    $fromClause .= ' AS ' . self::quote($alias);
                }
                $aliases[$alias] = $realName;
            }
            
            // Procesar relaciones
            if (is_array($relations)) {
                foreach ($relations as $relatedTable => $relationDef) {
                    if (isset($relationDef['type'])) {
                        $joinSql = self::buildInlineJoin($tableName, $relatedTable, $relationDef);
                        if ($joinSql) {
                            $joinClauses[] = $joinSql;
                        }
                    }
                }
            }
        }
        
        return [
            'from' => $fromClause,
            'joins' => implode(' ', $joinClauses),
            'aliases' => $aliases
        ];
    }

    /**
     * Construye un JOIN a partir de una definición inline.
     */
    private static function buildInlineJoin(string $fromTable, string $toTable, array $relationDef): string
    {
        $type = strtoupper($relationDef['type'] ?? 'INNER');
        if (!in_array($type, ['INNER', 'LEFT', 'RIGHT', 'FULL'])) {
            $type = 'LEFT';
        }
        
        $localKey = $relationDef['local_key'] ?? 'id';
        $foreignKey = $relationDef['foreign_key'] ?? $fromTable . '_id';
        
        // Extraer alias si existen
        $fromAlias = self::extractAlias($fromTable)[array_key_first(self::extractAlias($fromTable))] ?? $fromTable;
        $toAlias = self::extractAlias($toTable)[array_key_first(self::extractAlias($toTable))] ?? $toTable;
        $toReal = array_values(self::extractAlias($toTable))[0] ?? $toTable;
        
        $joinType = ($type === 'BELONGSTO' || $type === 'HASONE' || $type === 'HASMANY') ? 'LEFT' : $type;
        
        return "{$joinType} JOIN " . self::quote($toReal) . " AS " . self::quote($toAlias) . 
               " ON " . self::quote($fromAlias) . "." . self::quote($localKey) . 
               " = " . self::quote($toAlias) . "." . self::quote($foreignKey);
    }

    /**
     * Parsea una lista de tablas para auto-join.
     */
    private static function parseTableList(array $tables): array
    {
        $fromClause = '';
        $joinClauses = [];
        $aliases = [];
        $type = 'list';
        
        // Verificar si es determinista (segundo elemento es array)
        if (count($tables) >= 2 && is_array($tables[1])) {
            $type = 'deterministic';
            // Primera tabla es pivote
            $firstTable = $tables[0];
            $alias = self::extractAlias($firstTable)[array_key_first(self::extractAlias($firstTable))] ?? $firstTable;
            $realName = array_values(self::extractAlias($firstTable))[0] ?? $firstTable;
            $fromClause = self::quote($realName);
            if ($alias !== $realName) {
                $fromClause .= ' AS ' . self::quote($alias);
            }
            $aliases[$alias] = $realName;
            
            // Resto de tablas
            for ($i = 1; $i < count($tables); $i++) {
                $table = $tables[$i];
                if (is_array($table)) {
                    // Tabla con configuración manual
                    $tableName = $table[0] ?? key($table);
                    $tableConfig = $table[1] ?? [];
                    $joinSql = self::buildManualJoin($tableName, $tableConfig, $aliases);
                    if ($joinSql) {
                        $joinClauses[] = $joinSql;
                    }
                } else {
                    // Tabla simple - usar lógica de auto-join
                    $joinSql = self::buildAutoJoin($table, $aliases);
                    if ($joinSql) {
                        $joinClauses[] = $joinSql;
                    }
                }
            }
        } else {
            // Auto-join automático
            foreach ($tables as $i => $table) {
                if ($i === 0) {
                    // Primera tabla va en FROM
                    $alias = self::extractAlias($table)[array_key_first(self::extractAlias($table))] ?? $table;
                    $realName = array_values(self::extractAlias($table))[0] ?? $table;
                    $fromClause = self::quote($realName);
                    if ($alias !== $realName) {
                        $fromClause .= ' AS ' . self::quote($alias);
                    }
                    $aliases[$alias] = $realName;
                } else {
                    // Resto son JOINs
                    $joinSql = self::buildAutoJoin($table, $aliases);
                    if ($joinSql) {
                        $joinClauses[] = $joinSql;
                    }
                }
            }
        }
        
        return [
            'from' => $fromClause,
            'joins' => implode(' ', $joinClauses),
            'aliases' => $aliases,
            'type' => $type
        ];
    }

    /**
     * Construye un JOIN manual.
     */
    private static function buildManualJoin(string $table, array $config, array &$aliases): string
    {
        $alias = $config['alias'] ?? $table;
        $realName = $table;
        $onCondition = $config['on'] ?? '';
        
        $quotedAlias = self::quote($alias);
        $quotedReal = self::quote($realName);
        
        $aliases[$alias] = $realName;
        
        if ($onCondition) {
            return "LEFT JOIN {$quotedReal} AS {$quotedAlias} ON {$onCondition}";
        }
        
        return "LEFT JOIN {$quotedReal} AS {$quotedAlias}";
    }

    /**
     * Construye un auto-JOIN basado en convenciones.
     */
    private static function buildAutoJoin(string $table, array &$aliases): string
    {
        $alias = self::extractAlias($table)[array_key_first(self::extractAlias($table))] ?? $table;
        $realName = array_values(self::extractAlias($table))[0] ?? $table;
        
        $quotedAlias = self::quote($alias);
        $quotedReal = self::quote($realName);
        
        $aliases[$alias] = $realName;
        
        // Auto-detectar relación por convención de nombres
        // Ej: users -> user_id, posts -> post_id
        $singular = rtrim($alias, 's');
        $foreignKey = $singular . '_id';
        
        // Buscar en aliases existentes una coincidencia
        foreach ($aliases as $existingAlias => $existingReal) {
            // No hacer join consigo misma
            if ($existingAlias === $alias) continue;
            
            if ($existingAlias === $singular || $existingReal === $singular) {
                return "LEFT JOIN {$quotedReal} AS {$quotedAlias} ON " . 
                       self::quote($existingAlias) . ".{$foreignKey} = {$quotedAlias}.id";
            }
            // Verificar inversa: si el alias existente termina en _id
            if (strpos($existingAlias, '_id') !== false) {
                $potentialFk = str_replace('_id', '', $existingAlias);
                if ($potentialFk === $singular) {
                    return "LEFT JOIN {$quotedReal} AS {$quotedAlias} ON {$quotedAlias}.id = " . 
                           self::quote($existingAlias) . "." . $existingAlias;
                }
            }
        }
        
        // Sin coincidencia: intentar con la convención inversa
        // Si la nueva tabla es el singular de alguna existente
        foreach ($aliases as $existingAlias => $existingReal) {
            if ($existingAlias === $alias) continue;
            
            $existingSingular = rtrim($existingAlias, 's');
            if ($existingSingular === $singular) {
                // La tabla existente tiene el FK hacia esta nueva
                return "LEFT JOIN {$quotedReal} AS {$quotedAlias} ON {$quotedAlias}.id = " . 
                       self::quote($existingAlias) . "." . $singular . "_id";
            }
        }
        
        // Sin coincidencia: JOIN sin condición (producto cartesiano parcial)
        return "LEFT JOIN {$quotedReal} AS {$quotedAlias}";
    }

    public function select($fields = '*', $limit = null, $sort = null, array $group = [], array $having = []): array
    {
        $this->state[self::T_SEL] = is_array($fields) ? implode(', ', $fields) : $fields;

        if ($limit !== null) {
            $this->applyLimit($limit);
        }

        if ($sort) {
            $this->applyOrder($sort);
        }

        if (!empty($group)) {
            $this->state[self::T_GRP] = implode(', ', $group);
        }

        if (!empty($having)) {
            $parsed = self::parseWhere($having);
            $this->state[self::T_HAV] = $parsed['sql'];
            $this->state[self::T_PRM] = array_merge($this->state[self::T_PRM], $parsed['params']);
        }

        return $this->buildSelect();
    }

    public function delete(): array
    {
        $table = $this->state[self::T_FRO];
        if ($this->state[self::T_TYP] === 'list') {
            $parts = explode(',', $table);
            $table = trim($parts[0]);
        }

        $whereSql = $this->state[self::T_WHR];
        $params = $this->state[self::T_PRM];

        $sql = "DELETE FROM {$table}";
        if ($whereSql) {
            $sql .= " WHERE " . $whereSql;
        }

        return [$sql, $params];
    }

    public function update(array $data): array
    {
        $setParts = [];
        $params = $this->state[self::T_PRM];

        foreach ($data as $col => $val) {
            $setParts[] = "{$col} = ?";
            $params[] = $val;
        }

        $table = $this->state[self::T_FRO];
        if ($this->state[self::T_TYP] === 'list') {
            $parts = explode(',', $table);
            $table = trim($parts[0]);
        }

        $whereSql = $this->state[self::T_WHR];
        
        $sql = "UPDATE {$table} SET " . implode(', ', $setParts);
        if ($whereSql) {
            $sql .= " WHERE " . $whereSql;
        }

        return [$sql, $params];
    }

    private function buildSelect(): array
    {
        $sql = "SELECT {$this->state[self::T_SEL]} FROM {$this->state[self::T_FRO]}";
        
        // Agregar JOINs si existen
        if (!empty($this->state[self::T_JON])) {
            $sql .= " " . $this->state[self::T_JON];
        }
        
        $params = $this->state[self::T_PRM];

        if (!empty($this->state[self::T_WHR])) {
            $sql .= " WHERE " . $this->state[self::T_WHR];
        }

        if ($this->state[self::T_ORD]) {
            $sql .= " ORDER BY " . $this->state[self::T_ORD];
        }

        if (!empty($this->state[self::T_GRP])) {
            $sql .= " GROUP BY " . $this->state[self::T_GRP];
        }

        if (!empty($this->state[self::T_HAV])) {
            $sql .= " HAVING " . $this->state[self::T_HAV];
        }

        if ($this->state[self::T_LIM] !== null) {
            $sql .= " LIMIT ?";
            $params[] = $this->state[self::T_LIM];
        }

        if ($this->state[self::T_OFF] !== null) {
            $sql .= " OFFSET ?";
            $params[] = $this->state[self::T_OFF];
        }

        return [$sql, $params];
    }

    /**
     * Método quote para escapar identificadores según el driver.
     */
    private static function quote(string $identifier): string
    {
        // Si ya está quoted, no hacer nada
        if ((strpos($identifier, self::$quoteChar) === 0) || 
            (strpos($identifier, '"') === 0) || 
            (strpos($identifier, '`') === 0)) {
            return $identifier;
        }
        // Manejar alias con "AS"
        if (stripos($identifier, ' AS ') !== false) {
            $parts = preg_split('/\s+AS\s+/i', $identifier);
            if (count($parts) === 2) {
                return self::quote(trim($parts[0])) . ' AS ' . self::quote(trim($parts[1]));
            }
        }
        return self::$quoteChar . $identifier . self::$quoteChar;
    }

    /**
     * Método count() para obtener el número de registros.
     */
    public function count(): array
    {
        $this->state[self::T_SEL] = 'COUNT(*) as total';
        return $this->buildSelect();
    }

    /**
     * Método exists() para verificar existencia de registros.
     */
    public function exists(): array
    {
        $table = $this->state[self::T_FRO];
        $joins = $this->state[self::T_JON];
        $whereSql = $this->state[self::T_WHR];
        $params = $this->state[self::T_PRM];

        $sql = "SELECT EXISTS(SELECT 1 FROM {$table}";
        if (!empty($joins)) {
            $sql .= " " . $joins;
        }
        if (!empty($whereSql)) {
            $sql .= " WHERE " . $whereSql;
        }
        $sql .= ") as exists_flag";

        return [$sql, $params];
    }

    private function applyOrder($sort): void
    {
        if (is_string($sort)) {
            $dir = strpos($sort, '-') === 0 ? 'DESC' : 'ASC';
            $field = ltrim($sort, '-+');
            $this->state[self::T_ORD] = "{$field} {$dir}";
        } elseif (is_array($sort)) {
            $parts = [];
            foreach ($sort as $s) {
                $dir = strpos($s, '-') === 0 ? 'DESC' : 'ASC';
                $field = ltrim($s, '-+');
                $parts[] = "{$field} {$dir}";
            }
            $this->state[self::T_ORD] = implode(', ', $parts);
        }
    }

    private function applyLimit($limit): void
    {
        if (is_int($limit)) {
            $this->state[self::T_LIM] = $limit;
            $this->state[self::T_OFF] = null;
        } elseif (is_array($limit) && count($limit) >= 2) {
            $this->state[self::T_OFF] = max(0, (int)$limit[0]);
            $this->state[self::T_LIM] = max(1, (int)$limit[1]);
        }
    }

    private static function parseWhere(array $filter): array
    {
        $sqlParts = [];
        $params = [];

        foreach ($filter as $key => $value) {
            if ($value === null) {
                $sqlParts[] = "{$key} IS NULL";
            } elseif (is_array($value)) {
                $placeholders = implode(',', array_fill(0, count($value), '?'));
                $sqlParts[] = "{$key} IN ({$placeholders})";
                $params = array_merge($params, $value);
            } else {
                $sqlParts[] = "{$key} = ?";
                $params[] = $value;
            }
        }

        return [
            'sql' => implode(' AND ', $sqlParts),
            'params' => $params
        ];
    }

    public static function page(int $currentPage, int $pageSize): array
    {
        return [max(0, ($currentPage - 1) * $pageSize), $pageSize];
    }

    private static function getWhereCacheKey(array $filter): string
    {
        $structure = [];
        foreach ($filter as $key => $value) {
            if ($value === null) {
                $structure[$key] = 'null';
            } elseif (is_array($value)) {
                $structure[$key] = 'in:' . count($value);
            } else {
                $structure[$key] = 'eq';
            }
        }
        ksort($structure);
        return md5(serialize($structure));
    }
}
