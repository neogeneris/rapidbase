<?php

declare(strict_types=1);

namespace RapidBase\Core;

use RapidBase\Core\SQL\Builders\SelectBuilder;
use RapidBase\Core\SQL\Builders\InsertBuilder;
use RapidBase\Core\SQL\Builders\UpdateBuilder;
use RapidBase\Core\SQL\Builders\DeleteBuilder;

class SQL
{
    private static array $relMap = [];
    private static array $schema = [];
    private static string $driver = 'sqlite';
    private static string $quoteChar = '"';
    protected static int $parameterCount = 0;
    private static array $joinTreeCache = [];
    private static int $joinTreeCacheSize = 0;
    private static int $joinTreeCacheMaxSize = 500;
    
    // ========== FAST PATH SLOTS (for simple queries) ==========
    // Reusable slots to avoid memory allocations (GC pressure)
    // Indices: 1=Select, 2=From, 3=Where, 4=Group, 5=Order, 6=Limit
    private static array $fastSlots = [1 => '*', 2 => '', 3 => '1', 4 => '', 5 => '', 6 => ''];
    
    // Optimized templates for fast assembly
    private const SELECT_TPL = "SELECT %s FROM %s WHERE %s %s %s %s";
    private const INSERT_TPL = "INSERT INTO %s (%s) VALUES (%s)";
    private const UPDATE_TPL = "UPDATE %s SET %s WHERE %s";
    private const DELETE_TPL = "DELETE FROM %s WHERE %s";
    private const COUNT_TPL = "SELECT COUNT(*) FROM %s WHERE %s";
    private const EXISTS_TPL = "SELECT EXISTS(SELECT 1 FROM %s WHERE %s)";
    
    // ========== SQL QUERY CACHE ==========
    private static array $queryCache = [];
    private static bool $queryCacheEnabled = false;
    private static int $queryCacheMaxSize = 1000;
    private static int $queryCacheHits = 0;
    private static int $queryCacheMisses = 0;
    private static ?string $lastSchemaHash = null;

    // ========== DRIVER CONFIGURATION ==========

    public static function setDriver(string $driver): void
    {
        self::$driver = strtolower($driver);
        self::$quoteChar = (self::$driver === 'mysql') ? '`' : '"';
    }

    public static function getDriver(): string
    {
        return self::$driver;
    }

    public static function detectDriverFromPDO(\PDO $pdo): void
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        self::setDriver($driver);
    }

    // ========== QUERY CACHE CONFIGURATION ==========

    /**
     * Enables or disables the SQL query cache.
     * Useful for reducing CPU usage in complex queries with multiple JOINs.
     */
    public static function setQueryCacheEnabled(bool $enabled): void
    {
        self::$queryCacheEnabled = $enabled;
    }

    /**
     * Sets the maximum size of the query cache.
     * When the limit is reached, the oldest entries are removed (LRU).
     */
    public static function setQueryCacheMaxSize(int $size): void
    {
        self::$queryCacheMaxSize = max(100, $size);
    }

    /**
     * Gets query cache statistics.
     * @return array With hits, misses, size and hitRate.
     */
    public static function getQueryCacheStats(): array
    {
        $total = self::$queryCacheHits + self::$queryCacheMisses;
        return [
            'hits' => self::$queryCacheHits,
            'misses' => self::$queryCacheMisses,
            'size' => count(self::$queryCache),
            'max_size' => self::$queryCacheMaxSize,
            'hit_rate' => $total > 0 ? round(self::$queryCacheHits / $total, 4) : 0.0,
            'enabled' => self::$queryCacheEnabled
        ];
    }

    /**
     * Completely clears the query cache.
     */
    public static function clearQueryCache(): void
    {
        self::$queryCache = [];
        self::$queryCacheHits = 0;
        self::$queryCacheMisses = 0;
    }

    // ========== MAP AND SCHEMA LOADING ==========

    public static function setRelationsMap(array $map): void
    {
        if (isset($map['relationships'])) {
            self::$relMap = $map['relationships'];
        } else {
            self::$relMap = $map;
        }
        if (isset($map['tables'])) {
            self::$schema = $map['tables'];
        }
    }

    private static function hasSchema(): bool
    {
        return !empty(self::$schema);
    }

    private static function hasRelations(): bool
    {
        return !empty(self::$relMap);
    }

    private static function getTableColumns(string $tableName): array
    {
        if (!isset(self::$schema[$tableName])) {
            throw new \RuntimeException("Table '$tableName' not found in schema map.");
        }
        return array_keys(self::$schema[$tableName]);
    }

    // ========== BASE METHODS ==========

    public static function reset(): void
    {
        self::$parameterCount = 0;
    }

    protected static function nextToken(): string
    {
        return "p" . (self::$parameterCount++);
    }

    /**
     * Versión pública de nextToken para uso en Builders
     */
    public static function nextTokenPublic(): string
    {
        return self::nextToken();
    }

    public static function quote(string $identifier): string
    {
        $q = self::$quoteChar;
        // Optimización: verificar solo si es objeto una vez al inicio
        if (isset($identifier) && is_object($identifier) && isset($identifier->raw)) {
            return $identifier->raw;
        }
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

    public static function quoteField(string $field): string
    {
        $field = trim($field);
        if (preg_match('/^(.*?)\s+AS\s+(.*)$/i', $field, $matches)) {
            $left = trim($matches[1]);
            $right = trim($matches[2]);
            return self::quote($left) . ' AS ' . self::quote($right);
        }
        return self::quote($field);
    }

    // ========== SELECT BUILDING ==========

    /**
     * Construye una consulta SELECT completa con soporte para JOINs automáticos, paginación y ordenamiento.
     * 
     * ## Parámetros:
     * - **$fields**: Columnas a seleccionar. Puede ser:
     *   - `'*'` para todas las columnas
     *   - `['id', 'name']` array de columnas
     *   - `['id' => 'user_id', 'name' => 'user_name']` array asociativo para aliases
     *   - `'MAX(price) AS max_price'` expresiones SQL directas
     * 
     * - **$table**: Tabla(s) para el FROM. Puede ser:
     *   - `'users'` string simple para una tabla
     *   - `['users', 'posts', 'comments']` array plano para JOINs automáticos (usa el mapa de relaciones)
     *   - `['users', ['posts' => ['local_key' => 'user_id', 'foreign_key' => 'id']]]` JOIN manual
     *   - `['users', ['posts', 'ON u.id = p.user_id']]` JOIN con condición manual
     * 
     * - **$where**: Condiciones WHERE matriciales (ver ejemplos abajo)
     * - **$groupBy**: Array de columnas para GROUP BY ej: `['category', 'status']`
     * - **$having**: Condiciones HAVING (mismo formato que $where)
     * - **$sort**: Ordenamiento. Puede ser:
     *   - `['name', '-created_at']` array numérico (prefijo `-` indica DESC)
     *   - `['name' => 'ASC', 'created_at' => 'DESC']` array asociativo
     * 
     * - **$page**: Número de página (1-based). Si es 0 o null, no hay paginación.
     * - **$perPage**: Cantidad de registros por página. Default: 10.
     * 
     * ## Ejemplos de WHERE matricial:
     * 
     * @example
     * // Condición simple: WHERE status = 'active'
     * ['status' => 'active']
     * 
     * @example
     * // Múltiples condiciones AND: WHERE status = 'active' AND age > 18
     * ['status' => 'active', 'age' => ['>' => 18]]
     * 
     * @example
     * // Condición IN: WHERE id IN (1, 2, 3)
     * ['id' => [1, 2, 3]]
     * 
     * @example
     * // Condición IS NULL: WHERE deleted_at IS NULL
     * ['deleted_at' => null]
     * 
     * @example
     * // Múltiples operadores: WHERE age >= 18 AND age < 65
     * ['age' => ['>=' => 18, '<' => 65]]
     * 
     * @example
     * // Condiciones OR (array indexado): WHERE (status = 'active') OR (status = 'pending')
     * [['status' => 'active'], ['status' => 'pending']]
     * 
     * @example
     * // Combinando AND/OR: WHERE (status = 'active' AND role = 'admin') OR (status = 'pending')
     * [['status' => 'active', 'role' => 'admin'], ['status' => 'pending']]
     * 
     * ## Ejemplos de ordenamiento con prefijo -:
     * 
     * @example
     * // ORDER BY name ASC, created_at DESC
     * ['name', '-created_at']
     * 
     * @example
     * // ORDER BY price DESC
     * ['-price']
     * 
     * ## Ejemplos de paginado:
     * 
     * @example
     * // Page 3, 20 records per page (OFFSET 40 LIMIT 20)
     * SQL::buildSelect('*', 'users', [], [], [], [], 3, 20);
     * 
     * @example
     * // Without pagination (all results)
     * SQL::buildSelect('*', 'users', [], [], [], [], 0, 0);
     * 
     * ## Complete example:
     * 
     * @example
     * // SELECT u.id, u.name, p.title FROM users AS u INNER JOIN posts AS p ON u.id = p.user_id
     * // WHERE u.status = 'active' AND u.age >= 18
     * // ORDER BY u.created_at DESC
     * // LIMIT 10 OFFSET 20
     * SQL::buildSelect(
     *     ['u.id', 'u.name', 'p.title'],
     *     ['users AS u', 'posts AS p'],
     *     ['u.status' => 'active', 'u.age' => ['>=' => 18]],
     *     [],
     *     [],
     *     ['-u.created_at'],
     *     3,  // Página 3
     *     10  // 10 por página
     * );
     * 
     * @param mixed $fields Columnas a seleccionar.
     * @param mixed $table Tabla o array de tablas para JOINs.
     * @param array $where Condiciones WHERE matriciales.
     * @param array $groupBy Agrupamiento por columnas.
     * @param array $having Condiciones HAVING matriciales.
     * @param array $sort Ordenamiento (prefijo - para DESC).
     * @param int $page Página actual (1-based, 0 para sin paginación).
     * @param int $perPage Registros por página.
     * @return array [string $sql, array $params] SQL generado y parámetros para PDO.
     */
    public static function buildSelect(
        $fields = '*',
        $table = '',
        array $where = [],
        array $groupBy = [],
        array $having = [],
        array $sort = [],
        $page = 0,
        int $perPage = 10
    ): array {
        // Normalize page: null, [], 0, or false -> 0 (No pagination)
        $page = empty($page) ? 0 : (int)$page;
        self::reset();
        
        // Create SelectBuilder instance as structured container
        $builder = new SelectBuilder();
        
        // Assign properties directly (replaces $parts['select'] = $fields)
        $builder->select = $fields;
        $builder->from = $table;
        $builder->where = $where;
        $builder->groupBy = $groupBy;
        $builder->having = $having;
        $builder->orderBy = $sort;
        $builder->limit = $perPage;
        $builder->offset = $page > 0 ? ($page - 1) * $perPage : 0;
        $builder->params = [];
        
        // Manejar joins si se proporcionan (bug fix: $joins no estaba definido)
        // Los joins ahora deben venir en el parámetro $table como array
        
        // Generar clave de caché basada en la ESTRUCTURA de la consulta (no los valores)
        $cacheKey = null;
        if (self::$queryCacheEnabled) {
            // Optimización: usar implode con separador único en lugar de json_encode/serialize
            // Es más rápido y genera claves consistentes
            $tableStr = is_array($table) ? implode(',', self::flattenTables($table)) : (string)$table;
            $fieldsStr = is_array($fields) ? (is_string(key($fields)) ? implode(',', array_keys($fields)) : implode(',', $fields)) : (string)$fields;
            
            $structureKey = $fieldsStr . '|' . $tableStr . '|' 
                . self::getWhereKeysString($where) . '|' 
                . implode(',', $groupBy) . '|' 
                . self::getWhereKeysString($having) . '|' 
                . implode(',', array_keys($sort)) . '|' 
                . ($page > 0 ? '1' : '0') . '|' . $perPage;
            
            $cacheKey = 'select_' . crc32($structureKey);
            
            // Verificar caché
            if (isset(self::$queryCache[$cacheKey])) {
                self::$queryCacheHits++;
                $cachedTemplate = self::$queryCache[$cacheKey];
                $params = self::buildWhere($where)['params'];
                if (!empty($having)) {
                    $havingData = self::buildWhere($having);
                    $params = array_merge($params, $havingData['params']);
                }
                return [$cachedTemplate, $params];
            }
            self::$queryCacheMisses++;
        }
        
        // Use builder to build clauses
        $fromClause = $builder->buildFromClause();
        $whereData = empty($where) ? ['sql' => '', 'params' => []] : self::buildWhere($where);
        $whereClause = empty($whereData['sql']) ? '' : 'WHERE ' . $whereData['sql'];
        $builder->params = $whereData['params'];
        
        // Build GROUP BY
        $groupByClause = '';
        if (!empty($groupBy)) {
            $groupByFields = [];
            foreach ($groupBy as $field) {
                // If already quoted or is a function, don't quote
                if (strpos($field, '`') !== false || preg_match('/\(/', $field)) {
                    $groupByFields[] = $field;
                } else {
                    // Quote each part of field (e.g., 'd.category' -> '`d`.`category`')
                    $parts = explode('.', $field);
                    $quotedParts = array_map(function($part) {
                        return self::quote($part);
                    }, $parts);
                    $groupByFields[] = implode('.', $quotedParts);
                }
            }
            $groupByClause = 'GROUP BY ' . implode(', ', $groupByFields);
        }
        
        // Build HAVING
        $havingClause = '';
        if (!empty($having)) {
            $havingData = self::buildWhere($having);
            if (!empty($havingData['sql'])) {
                $havingClause = 'HAVING ' . $havingData['sql'];
                $whereData['params'] = array_merge($whereData['params'], $havingData['params']);
            }
        }
        
        // Build ORDER BY
        $orderByClause = '';
        if (!empty($sort)) {
            // Support both numeric format ['field1', '-field2'] and associative ['field' => 'ASC']
            $sortFields = [];
            $isAssociative = !empty($sort) && !is_numeric(key($sort));
            
            if ($isAssociative) {
                // Associative format: ['field' => 'ASC/DESC']
                foreach ($sort as $field => $dir) {
                    $dirUpper = strtoupper($dir);
                    if ($dirUpper === 'DESC') {
                        $sortFields[] = '-' . ltrim($field, '-');
                    } else {
                        $sortFields[] = ltrim($field, '-');
                    }
                }
            } else {
                // Numeric format: ['field1', '-field2']
                $sortFields = $sort;
            }
            $orderByClause = self::buildOrderBy($sortFields);
        }
        
        // Use builder to build SELECT clause
        $selectClause = $builder->buildSelectClause();
        
        // Assemble final SQL using builder clauses
        $sqlParts = [$selectClause, $fromClause];
        if ($whereClause !== '') $sqlParts[] = $whereClause;
        if ($groupByClause !== '') $sqlParts[] = $groupByClause;
        if ($havingClause !== '') $sqlParts[] = $havingClause;
        if ($orderByClause !== '') $sqlParts[] = $orderByClause;
        $sqlParts[] = "LIMIT {$builder->limit} OFFSET {$builder->offset}";
        
        $sql = implode(' ', $sqlParts);
        
        // Almacenar en caché la plantilla SQL
        if ($cacheKey !== null && count(self::$queryCache) < self::$queryCacheMaxSize) {
            self::$queryCache[$cacheKey] = $sql;
        }
        
        return [$sql, $whereData['params']];
    }

    /**
     * Extrae las claves de un array WHERE/HAVING como string para usar en clave de caché.
     */
    private static function getWhereKeysString(array $conditions): string
    {
        $keys = [];
        foreach ($conditions as $key => $value) {
            if (is_array($value)) {
                $keys[] = $key . '(' . implode(',', array_keys($value)) . ')';
            } else {
                $keys[] = $key;
            }
        }
        sort($keys);
        return implode(',', $keys);
    }

    /**
     * Aplana un array de tablas para generar clave de caché.
     */
    private static function flattenTables(array $tables): array
    {
        $result = [];
        foreach ($tables as $table) {
            if (is_array($table)) {
                $result = array_merge($result, self::flattenTables($table));
            } else {
                $result[] = $table;
            }
        }
        return $result;
    }

    /**
     * Extrae la estructura de un array WHERE/HAVING para usar como clave de caché.
     * Ignora los valores específicos, solo considera las claves y operadores.
     * @deprecated Usar getWhereKeysString() que es más eficiente
     */
    private static function getWhereStructure(array $conditions): array
    {
        $structure = [];
        foreach ($conditions as $key => $value) {
            if (is_array($value)) {
                $structure[$key] = array_keys($value);
            } else {
                $structure[$key] = null;
            }
        }
        return $structure;
    }
    // ========== FROM BUILDING WITH JOINS ==========


    /**
     * Ordena las tablas de más débil (mayor número de relaciones salientes) a más fuerte.
     *
     * El algoritmo asigna a cada tabla un grado de salida (outDegree = número de relaciones
     * definidas en 'from' de esa tabla hacia otras) y un grado de entrada (inDegree).
     * Primero ordena por outDegree descendente (más débil primero), luego por inDegree ascendente.
     *
     * Esto coloca las tablas "hoja" (con muchas relaciones hacia otras) al inicio del FROM,
     * lo que optimiza los JOINs y evita productos cartesianos innecesarios.
     *
     * @param array $tableNames Lista de nombres reales de tablas (sin alias).
     * @return array Lista ordenada de nombres de tablas.
     */
    private static function orderTablesByWeakness(array $tableNames): array
    {
        if (!self::hasRelations()) {
            return $tableNames;
        }

        $degrees = [];
        $relMapFrom = self::$relMap['from'] ?? [];
        $relMapTo = self::$relMap['to'] ?? [];

        foreach ($tableNames as $t) {
            $out = isset($relMapFrom[$t]) ? count($relMapFrom[$t]) : 0;
            $in = isset($relMapTo[$t]) ? count($relMapTo[$t]) : 0;
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

    /**
     * Construye un árbol de JOINs a partir de la lista de tablas y el mapa de relaciones.
     *
     * Algoritmo:
     * 1. Construye un grafo no dirigido donde cada arista representa una relación entre dos tablas
     *    (tomando tanto las direcciones 'from' como 'to' del mapa).
     * 2. Verifica que todas las tablas estén conectadas (lanzar excepción si no).
     * 3. Realiza una búsqueda BFS desde la primera tabla para determinar un árbol de expansión.
     * 4. Devuelve la raíz (primera tabla) y las aristas (cada arista tiene padre, hijo y la relación).
     *
     * Este árbol se usa luego para generar los JOINs en orden, asegurando que cada tabla se una
     * a su padre ya presente en la cláusula FROM.
     *
     * @param array $tableNames Nombres reales de las tablas.
     * @return array<string, mixed> Array con 'root' (string) y 'edges' (array de aristas).
     * @throws \RuntimeException Si el grafo no es conexo.
     */
    private static function buildJoinTree(array $tableNames): array
    {
        $graph = [];
        foreach ($tableNames as $t)
            $graph[$t] = [];

        $relMapFrom = self::$relMap['from'] ?? [];
        $relMapTo = self::$relMap['to'] ?? [];

        foreach ($relMapFrom as $from => $rels) {
            foreach ($rels as $to => $rel) {
                if (in_array($from, $tableNames) && in_array($to, $tableNames)) {
                    $graph[$from][$to] = $rel;
                    $graph[$to][$from] = $rel;
                }
            }
        }
        foreach ($relMapTo as $from => $rels) {
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
            $edges[] = ['parent' => $info['parent'], 'child' => $child, 'rel' => $info['rel']];
        }
        return ['root' => $root, 'edges' => $edges];
    }


    /**
     * Builds the ON condition for a JOIN between two tables using the relationship map.
     *
     * The function automatically determines the correct direction of the relationship:
     * - If the relationship is defined from parent to child in relMap['from'],
     *   it uses `parent.local_key = child.foreign_key` (hasMany/hasOne semantics).
     * - If the relationship is defined in the reverse direction (child to parent),
     *   it uses `child.local_key = parent.foreign_key` (belongsTo semantics).
     *
     * It also distinguishes between relationship types ('belongsTo' vs 'hasMany/hasOne')
     * to adjust the semantics accordingly.
     *
     * @param string $parentTabla Real name of the parent table.
     * @param string $parentAlias Alias of the parent table in the query.
     * @param string $childTabla  Real name of the child table.
     * @param string $childAlias  Alias of the child table.
     * @param array $relation     Array with relationship definition (must contain
     *                            'type', 'local_key', 'foreign_key').
     * @return string ON clause (includes "ON" keyword and condition).
     */


    private static function buildJoinCondition(string $parentTabla, string $parentAlias, string $childTabla, string $childAlias, array $relation): string
    {
        // Explicit type takes precedence
        if (isset($relation['type'])) {
            $type = $relation['type'];
        } else {
            // Infer type from relationship direction:
            // If relation is defined from parent to child in relMap['from'], it's hasMany/hasOne
            // Otherwise (defined from child to parent), it's belongsTo
            $type = isset(self::$relMap['from'][$parentTabla][$childTabla]) ? 'hasOne' : 'belongsTo';
        }
        
        $localKey = $relation['local_key'] ?? '';
        $foreignKey = $relation['foreign_key'] ?? '';

        // For hasMany/hasOne: parent.local_key = child.foreign_key
        // For belongsTo: child.local_key = parent.foreign_key
        if ($type === 'hasMany' || $type === 'hasOne') {
            return "ON " . self::quote($parentAlias) . "." . self::quote($localKey)
                . " = " . self::quote($childAlias) . "." . self::quote($foreignKey);
        } else {
            // belongsTo: the child table has the foreign key
            return "ON " . self::quote($childAlias) . "." . self::quote($localKey)
                . " = " . self::quote($parentAlias) . "." . self::quote($foreignKey);
        }
    }


    /**
     * Construye la cláusula FROM de una consulta SQL, incluyendo JOINs automáticos.
     *
     * Soporta múltiples formatos de entrada:
     *
     * 1. **String simple** – una sola tabla.
     *    `'users'` → `FROM users`
     *
     * 2. **Array plano de strings** – múltiples tablas.
     *    `['users', 'posts']` → se ordenan automáticamente por "debilidad" (tablas con más relaciones salientes primero)
     *    y se generan LEFT JOIN con condiciones ON basadas en el mapa de relaciones global.
     *
     * 3. **Array anidado** – para forzar un orden específico sin reordenamiento automático.
     *    `['users', ['posts', 'comments']]` → `users` se une con `posts`, y `posts` con `comments`,
     *    respetando el orden anidado (no se reordena por debilidad).
     *
     * 4. **Relaciones inline** – definición explícita de la relación en el mismo array.
     *    `['users', ['posts' => ['type' => 'belongsTo', 'local_key' => 'user_id', 'foreign_key' => 'id']]]`
     *    → `FROM users LEFT JOIN posts ON users.user_id = posts.id`
     *
     * 5. **Alias de tablas** – se pueden usar alias con la sintaxis `"tabla as alias"`.
     *    `['users as u', 'posts as p']` → `FROM users AS u LEFT JOIN posts AS p ON ...`
     *
     * La función utiliza el mapa de relaciones cargado mediante `setRelationsMap()` para determinar
     * las condiciones ON. Si el mapa no está disponible, se usa un fallback genérico (`id = user_id`).
     *
     * @param string|array $table Nombre de tabla o array con la estructura descrita.
     * @return string Cláusula FROM completa (incluye la palabra "FROM" y todos los JOINs).
     * @throws \InvalidArgumentException Si la estructura es inválida.
     * @throws \RuntimeException Si no se pueden conectar todas las tablas del grafo.
     */

    /**
     * Construye la cláusula FROM con JOINs automáticos usando SchemaMap
     * 
     * OPTIMIZACIÓN: Solo activa el motor de grafos si $table es un array.
     * Si es un string, asume tabla simple y retorna inmediatamente sin overhead.
     * 
     * SOPORTA:
     * 1. String simple: 'users' → FROM users
     * 2. Array plano: ['users', 'orders'] → Automático (grafo completo)
     * 3. Pivote fijo: ['users', ['orders', 'products']] → users es FROM, resto se conecta auto
     * 4. Determinista: ['users' => 'JOIN orders ON ...'] → Respeta orden exacto
     */
    public static function buildFromWithMap(mixed $table): string
    {
        // OPTIMIZACIÓN: Tabla simple como string - evitar completamente el motor de grafos
        if (is_string($table)) {
            return "FROM " . self::quote($table);
        }
        if (!is_array($table))
            return "";

        // OPTIMIZACIÓN: Array vacío o con un solo elemento - no necesita grafo
        if (count($table) === 0) {
            return "";
        }
        if (count($table) === 1 && is_string($table[0])) {
            return "FROM " . self::quote($table[0]);
        }

        // DETECTAR FORMATO PIVOTE: [t1, [t2, t3, ...]]
        // The first element is the base table, the second is an array of tables to connect
        if (count($table) >= 2 && 
            is_string($table[0]) && 
            is_array($table[1]) && 
            array_is_list($table[1])) 
        {
            // Pivot format detected: t1 is FROM, [t2, t3, ...] are connected automatically
            return self::buildFromPivot($table[0], $table[1]);
        }

        $hasComplex = false;
        foreach ($table as $item) {
            if (is_array($item)) {
                $hasComplex = true;
                break;
            }
        }

        if ($hasComplex) {
            $flat = [];
            foreach ($table as $item) {
                if (is_array($item) && array_is_list($item)) {
                    foreach ($item as $sub)
                        $flat[] = $sub;
                } else {
                    $flat[] = $item;
                }
            }
            return self::buildFromLinear($flat);
        }

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
        
        // OPTIMIZACIÓN: Si no hay relaciones definidas, evitar el motor de grafos completamente
        if (!self::hasRelations()) {
            // Tablas múltiples sin relaciones: JOINs lineales simples
            if (count($realNames) > 1) {
                return self::buildFromLinear($table);
            }
            // Una sola tabla sin relaciones: FROM simple
            if (!empty($realNames)) {
                $first = $realNames[0];
                $from = "FROM " . self::quote($first);
                if ($aliases[$first] !== $first) {
                    $from .= " AS " . self::quote($aliases[$first]);
                }
                return $from;
            }
            return "";
        }
        
        // Solo ejecutar motor de grafos si hay relaciones Y múltiples tablas
        if ($hasDuplicates) {
            return self::buildFromLinear($table);
        }

        if (count($realNames) > 1) {
            $realNames = self::orderTablesByWeakness($realNames);
            $aliasesOrdered = [];
            foreach ($realNames as $real) {
                $aliasesOrdered[$real] = $aliases[$real];
            }
            $aliases = $aliasesOrdered;
        }

        // Optimization: use serialize + crc32 for joinTree cache key
        $cacheKey = 'join_' . crc32(serialize($realNames));
        
        // Limit joinTreeCache size
        if (self::$joinTreeCacheSize >= self::$joinTreeCacheMaxSize) {
            // Remove oldest 10%
            $keysToRemove = array_slice(array_keys(self::$joinTreeCache), 0, (int)(self::$joinTreeCacheMaxSize * 0.1));
            foreach ($keysToRemove as $key) {
                unset(self::$joinTreeCache[$key]);
                self::$joinTreeCacheSize--;
            }
        }
        
        if (!isset(self::$joinTreeCache[$cacheKey])) {
            self::$joinTreeCache[$cacheKey] = self::buildJoinTree($realNames);
            self::$joinTreeCacheSize++;
        }
        $tree = self::$joinTreeCache[$cacheKey];
        $rootReal = $tree['root'];
        $rootAlias = $aliases[$rootReal];
        $parts = ["FROM " . self::quote($rootReal)];
        if ($rootAlias !== $rootReal) {
            $parts[] = "AS " . self::quote($rootAlias);
        }

        foreach ($tree['edges'] as $edge) {
            $parentReal = $edge['parent'];
            $childReal = $edge['child'];
            $rel = $edge['rel'];
            $parentAlias = $aliases[$parentReal];
            $childAlias = $aliases[$childReal];
            $onClause = self::buildJoinCondition($parentReal, $parentAlias, $childReal, $childAlias, $rel);
            $joinPart = "LEFT JOIN " . self::quote($childReal);
            if ($childAlias !== $childReal) {
                $joinPart .= " AS " . self::quote($childAlias);
            }
            $joinPart .= " " . $onClause;
            $parts[] = $joinPart;
        }
        return implode(' ', $parts);
    }

    /**
     * Construye la cláusula FROM con JOINs lineales, respetando el orden exacto de las tablas.
     *
     * Este método se usa cuando:
     * - El array de tablas contiene subarrays (anidamiento) → se fuerza el orden anidado.
     * - Se detecta auto-referencia (misma tabla repetida) o cuando no hay esquema ni relaciones.
     * - El usuario proporciona relaciones inline (definición explícita dentro del array).
     *
     * Soporta:
     * - Strings simples: `'users'` o `'users as u'`.
     * - Subarrays aplanados: `['users', ['posts', 'tags']]` → se aplanan recursivamente.
     * - Relaciones inline: `['users', ['posts' => ['type' => 'belongsTo', ...]]]`
     *
     * @param array $tables Lista de elementos (strings o arrays asociativos).
     * @return string Cláusula FROM (incluye FROM y todos los LEFT JOIN).
     * @throws \InvalidArgumentException Si el primer elemento es una relación inline o la estructura es inválida.
     */

    private static function buildFromLinear(array $tables): string
    {
        if (empty($tables))
            return "";
        $first = $tables[0];
        if (is_array($first))
            throw new \InvalidArgumentException("La primera tabla no puede ser una relación inline.");
        $firstReal = $first;
        $firstAlias = $first;
        if (preg_match('/^\s*(\S+)\s+as\s+(\S+)\s*$/i', $first, $matches)) {
            $firstReal = $matches[1];
            $firstAlias = $matches[2];
        }
        $parts = [];
        $parts[] = "FROM " . self::quote($firstReal);
        if ($firstAlias !== $firstReal)
            $parts[] = "AS " . self::quote($firstAlias);

        $currentReal = $firstReal;
        $currentAlias = $firstAlias;

        for ($i = 1; $i < count($tables); $i++) {
            $item = $tables[$i];
            $nextReal = null;
            $nextAlias = null;
            $relationDef = null;

            if (is_string($item)) {
                if (preg_match('/^\s*(\S+)\s+as\s+(\S+)\s*$/i', $item, $matches)) {
                    $nextReal = $matches[1];
                    $nextAlias = $matches[2];
                } else {
                    $nextReal = $item;
                    $nextAlias = $item;
                }
                $relationDef = self::$relMap['from'][$currentReal][$nextReal] ?? self::$relMap['to'][$currentReal][$nextReal] ?? null;
            } elseif (is_array($item)) {
                $keys = array_keys($item);
                if (count($keys) !== 1)
                    throw new \InvalidArgumentException("Relación inline debe tener una sola clave.");
                $nextReal = $keys[0];
                $relationDef = $item[$nextReal];
                $nextAlias = $nextReal;
                if (isset($relationDef['as'])) {
                    $nextAlias = $relationDef['as'];
                    unset($relationDef['as']);
                }
            } else {
                throw new \InvalidArgumentException("Elemento inválido.");
            }

            $joinPart = "LEFT JOIN " . self::quote($nextReal);
            if ($nextAlias !== $nextReal)
                $joinPart .= " AS " . self::quote($nextAlias);

            if ($relationDef) {
                $onClause = self::buildJoinConditionFromDef($currentReal, $currentAlias, $nextReal, $nextAlias, $relationDef);
                $joinPart .= " " . $onClause;
            } else {
                $joinPart .= " ";
            }
            $parts[] = $joinPart;
            $currentReal = $nextReal;
            $currentAlias = $nextAlias;
        }
        return implode(' ', $parts);
    }

    /**
     * Construye FROM con pivote fijo: [t1, [t2, t3, ...]]
     * 
     * t1 es la tabla principal (FROM), las demás se conectan automáticamente usando el grafo.
     * Esto permite al programador especificar qué tabla es la base sin definir manualmente todos los joins.
     * 
     * @param string $pivot Tabla pivote (será el FROM)
     * @param array $connectedTables Array de tablas a conectar automáticamente
     * @return string Cláusula FROM completa
     */
    private static function buildFromPivot(string $pivot, array $connectedTables): string
    {
        // Extract real name and alias from pivot
        $pivotReal = $pivot;
        $pivotAlias = $pivot;
        if (preg_match('/^\s*(\S+)\s+as\s+(\S+)\s*$/i', $pivot, $matches)) {
            $pivotReal = $matches[1];
            $pivotAlias = $matches[2];
        }

        // Initialize FROM clause with pivot
        $parts = [];
        $parts[] = "FROM " . self::quote($pivotReal);
        if ($pivotAlias !== $pivotReal) {
            $parts[] = "AS " . self::quote($pivotAlias);
        }

        // If no tables to connect, return only FROM
        if (empty($connectedTables)) {
            return implode(' ', $parts);
        }

        // Build complete list: [pivot, t2, t3, ...] to use graph engine
        // but forcing pivot to always be the first table
        $allTables = array_merge([$pivotReal], $connectedTables);
        
        // Extract real names and aliases from all connected tables
        $realNames = [$pivotReal];
        $aliases = [$pivotReal => $pivotAlias];
        
        foreach ($connectedTables as $t) {
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

        // Pivot is already at position 0, now connect the rest using the graph
        // but WITHOUT reordering (orderTablesByWeakness) to keep pivot first
        $currentReal = $pivotReal;
        $currentAlias = $pivotAlias;
        $usedTables = [$pivotReal => true];

        // Connect each remaining table looking for the best route from pivot or already connected tables
        $tablesToConnect = array_slice($realNames, 1);
        
        foreach ($tablesToConnect as $nextReal) {
            if (isset($usedTables[$nextReal])) {
                continue; // Already connected
            }

            $nextAlias = $aliases[$nextReal];
            
            // Look for relationship from current table or any already connected table
            $foundRelation = false;
            $sourceTable = $currentReal;
            $sourceAlias = $currentAlias;
            
            // Try to find relationship from pivot first
            $relationDef = self::$relMap['from'][$currentReal][$nextReal] ?? self::$relMap['to'][$currentReal][$nextReal] ?? null;
            
            // If no direct relationship from current table, search from any connected table
            if (!$relationDef) {
                foreach (array_keys($usedTables) as $connectedTable) {
                    $relationDef = self::$relMap['from'][$connectedTable][$nextReal] ?? self::$relMap['to'][$connectedTable][$nextReal] ?? null;
                    if ($relationDef) {
                        $sourceTable = $connectedTable;
                        $sourceAlias = $aliases[$connectedTable];
                        break;
                    }
                }
            }

            $joinPart = "LEFT JOIN " . self::quote($nextReal);
            if ($nextAlias !== $nextReal) {
                $joinPart .= " AS " . self::quote($nextAlias);
            }

            if ($relationDef) {
                $onClause = self::buildJoinConditionFromDef($sourceTable, $sourceAlias, $nextReal, $nextAlias, $relationDef);
                $joinPart .= " " . $onClause;
                $foundRelation = true;
            }

            $parts[] = $joinPart;
            $usedTables[$nextReal] = true;
            
            // Update current table for next iteration
            if ($foundRelation) {
                $currentReal = $nextReal;
                $currentAlias = $nextAlias;
            }
        }

        return implode(' ', $parts);
    }


    /**
     * Construye la condición ON a partir de una definición de relación inline.
     *
     * Utilizada exclusivamente en `buildFromLinear` cuando se proporciona una relación
     * explícita en el propio array de tablas (formato `['tabla' => $def]`).
     *
     * La dirección siempre es `padre.local_key = hijo.foreign_key`.
     *
     * @param string $parentReal   Nombre real de la tabla padre.
     * @param string $parentAlias  Alias de la tabla padre.
     * @param string $childReal    Nombre real de la tabla hija.
     * @param string $childAlias   Alias de la tabla hija.
     * @param array $def           Definición de la relación (debe tener 'local_key' y 'foreign_key').
     * @return string Cláusula ON (incluye la palabra "ON").
     */
    private static function buildJoinConditionFromDef(string $parentReal, string $parentAlias, string $childReal, string $childAlias, array $def): string
    {
        $localKey = $def['local_key'];
        $foreignKey = $def['foreign_key'];
        return "ON " . self::quote($parentAlias) . "." . self::quote($localKey)
            . " = " . self::quote($childAlias) . "." . self::quote($foreignKey);
    }

    // ========== WHERE AND ORDER BY ==========


    /**
     * Construye la cláusula WHERE de una consulta SQL a partir de un array de condiciones.
     * 
     * Este método es el corazón del sistema de consultas dinámicas. Permite construir
     * condiciones WHERE complejas de forma segura y legible, usando parámetros nombrados
     * para prevenir inyección SQL.
     *
     * ## Formatos soportados:
     *
     * ### 1. Condiciones simples (AND implícito)
     * Las claves del array asociativo se combinan con AND automáticamente.
     * 
     * @example
     * // WHERE status = 'active' AND role = 'admin'
     * ['status' => 'active', 'role' => 'admin']
     *
     * ### 2. Grupos OR (array indexado)
     * Los arrays dentro de un array indexado se combinan con OR.
     * 
     * @example
     * // WHERE (status = 'active') OR (status = 'pending')
     * [['status' => 'active'], ['status' => 'pending']]
     * 
     * @example
     * // WHERE (status = 'active' AND role = 'admin') OR (status = 'closed' AND priority = 'high')
     * [
     *     ['status' => 'active', 'role' => 'admin'],
     *     ['status' => 'closed', 'priority' => 'high']
     * ]
     *
     * ### 3. Valores NULL (IS NULL / IS NOT NULL)
     * 
     * @example
     * // WHERE deleted_at IS NULL
     * ['deleted_at' => null]
     * 
     * @example
     * // WHERE deleted_at IS NOT NULL
     * ['deleted_at' => ['!=' => null]]
     *
     * ### 4. Operadores personalizados
     * Usar un array asociativo con el operador como clave.
     * 
     * @example
     * // WHERE age > 18 AND age < 65
     * ['age' => ['>' => 18, '<' => 65]]
     * 
     * @example
     * // WHERE created_at >= '2024-01-01'
     * ['created_at' => ['>=' => '2024-01-01']]
     * 
     * @example
     * // WHERE name LIKE '%john%'
     * ['name' => ['LIKE' => '%john%']]
     *
     * ### 5. Lista de valores (IN)
     * Un array numérico genera automáticamente una cláusula IN.
     * 
     * @example
     * // WHERE id IN (1, 2, 3, 4)
     * ['id' => [1, 2, 3, 4]]
     * 
     * @example
     * // WHERE status IN ('active', 'pending')
     * ['status' => ['active', 'pending']]
     *
     * ### 6. Combinación de operadores e IN
     * 
     * @example
     * // WHERE age >= 18 AND status IN ('active', 'verified')
     * ['age' => ['>=' => 18], 'status' => ['active', 'verified']]
     *
     * ### 7. Resolución automática de alias con contexto
     * Cuando se usa con JOINs, el método resuelve automáticamente los prefijos de tabla.
     * 
     * @example
     * // Con contexto ['u' => 'users', 'p' => 'posts']
     * // WHERE u.status = 'active' AND p.published = 1
     * buildWhere(
     *     ['status' => 'active', 'published' => 1],
     *     ['u' => 'users', 'p' => 'posts'],
     *     'u'  // alias por defecto
     * )
     *
     * ### 8. Ejemplo complejo combinando todo
     * 
     * @example
     * // WHERE (status = 'active' AND (role = 'admin' OR role = 'moderator'))
     * // AND age >= 18 AND deleted_at IS NULL
     * [
     *     'status' => 'active',
     *     ['role' => 'admin'],
     *     ['role' => 'moderator'],
     *     'age' => ['>=' => 18],
     *     'deleted_at' => null
     * ]
     * 
     * ⚠️ **Nota importante**: Para combinar AND/OR correctamente, recuerda:
     * - Array asociativo = condiciones AND
     * - Array indexado = grupos OR
     * - Para mezclar, anida arrays indexados dentro del asociativo principal
     *
     * @param array $where Array de condiciones (asociativo para AND, indexado para OR).
     * @param array $context Mapeo de alias de tabla a nombre real ej: `['u' => 'users', 'p' => 'posts']`.
     * @param string $defaultAlias Alias por defecto para columnas sin prefijo.
     *
     * @return array<string, mixed> Devuelve `['sql' => string, 'params' => array]`:
     *   - **sql**: La cláusula WHERE completa (sin la palabra "WHERE")
     *   - **params**: Parámetros nombrados para bindear con PDO (claves como "p0", "p1", etc.)
     *
     * @throws \RuntimeException Si una columna es ambigua (existe en múltiples tablas del contexto).
     */
    public static function buildWhere(array $where, array $context = [], string $defaultAlias = ''): array
    {
        if (empty($where)) {
            return ['sql' => "1", 'params' => []];
        }

        // --- OR groups detection (indexed array) ---
        $isIndexed = array_is_list($where);
        if ($isIndexed) {
            $groupSql = [];
            $allParams = [];
            foreach ($where as $group) {
                if (!is_array($group)) {
                    $group = [$group];
                }
                $sub = self::buildWhere($group, $context, $defaultAlias);
                $groupSql[] = '(' . $sub['sql'] . ')';
                $allParams = array_merge($allParams, $sub['params']);
            }
            $sql = count($groupSql) > 1 ? implode(' OR ', $groupSql) : $groupSql[0];
            return ['sql' => $sql, 'params' => $allParams];
        }

        // --- Normal mode: AND between conditions (associative array) ---
        $sqlParts = [];
        $params = [];
        $hasSchema = self::hasSchema();
        $schema = self::$schema;

        foreach ($where as $column => $value) {
            $rawColumn = trim($column);
            if (!str_contains($rawColumn, '.') && !preg_match('/[^\w\.]/', $rawColumn)) {
                $foundAlias = null;
                if ($hasSchema && !empty($context)) {
                    foreach ($context as $alias => $realTable) {
                        if (isset($schema[$realTable][$rawColumn])) {
                            if ($foundAlias === null) {
                                $foundAlias = $alias;
                            } else {
                                throw new \RuntimeException("Column '$rawColumn' ambiguous in tables '{$context[$foundAlias]}' and '$realTable'.");
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

            if ($value === null) {
                $sqlParts[] = "$safeColumn IS NULL";
            } elseif (is_array($value)) {
                $isList = array_is_list($value);
                if ($isList) {
                    $placeholders = [];
                    foreach ($value as $val) {
                        $token = self::nextToken();
                        $placeholders[] = ":$token";
                        $params[$token] = $val;
                    }
                    $sqlParts[] = "$safeColumn IN (" . implode(', ', $placeholders) . ")";
                } else {
                    foreach ($value as $op => $val) {
                        $token = self::nextToken();
                        $sqlParts[] = "$safeColumn $op :$token";
                        $params[$token] = $val;
                    }
                }
            } else {
                $token = self::nextToken();
                $sqlParts[] = "$safeColumn = :$token";
                $params[$token] = $value;
            }
        }

        return ['sql' => implode(' AND ', $sqlParts), 'params' => $params];
    }


    public static function qualifyColumn(string $column): string
    {
        return self::quote($column);
    }

    /**
     * Construye la cláusula ORDER BY a partir de un array de campos.
     * 
     * Este método utiliza una convención simple pero poderosa: el prefijo `-` (guion)
     * indica orden descendente (DESC). Si no hay prefijo, se asume ASC.
     * 
     * ## Formatos soportados:
     * 
     * ### 1. Campos simples (ASC por defecto)
     * 
     * @example
     * // ORDER BY name ASC
     * buildOrderBy(['name'])
     * 
     * @example
     * // ORDER BY name ASC, email ASC
     * buildOrderBy(['name', 'email'])
     * 
     * ### 2. Orden descendente con prefijo -
     * El guion `-` antes del nombre del campo indica DESC.
     * 
     * @example
     * // ORDER BY created_at DESC
     * buildOrderBy(['-created_at'])
     * 
     * @example
     * // ORDER BY name ASC, created_at DESC
     * buildOrderBy(['name', '-created_at'])
     * 
     * ### 3. Múltiples niveles de ordenamiento
     * 
     * @example
     * // ORDER BY category ASC, price DESC, name ASC
     * buildOrderBy(['category', '-price', 'name'])
     * 
     * ### 4. Columnas con alias de tabla
     * 
     * @example
     * // ORDER BY u.name ASC, p.price DESC
     * buildOrderBy(['u.name', '-p.price'])
     * 
     * ### 5. Funciones y expresiones SQL
     * Las expresiones que ya contienen paréntesis no son quoteadas.
     * 
     * @example
     * // ORDER BY COUNT(*) DESC, AVG(rating) ASC
     * buildOrderBy(['-COUNT(*)', 'AVG(rating)'])
     * 
     * ### 6. Posiciones numéricas (útil para SELECTs con funciones)
     * 
     * @example
     * // ORDER BY 1 ASC, 2 DESC (primera y segunda columna del SELECT)
     * buildOrderBy([1, '-2'])
     * 
     * @example
     * // ORDER BY 1 DESC
     * buildOrderBy([-1])  // o ['-1']
     * 
     * ## Integración con buildSelect():
     * 
     * El método `buildSelect()` también acepta formato asociativo alternativo:
     * 
     * @example
     * // Ambas formas son equivalentes:
     * buildSelect('*', 'users', [], [], [], ['name', '-created_at']);
     * buildSelect('*', 'users', [], [], [], ['name' => 'ASC', 'created_at' => 'DESC']);
     * 
     * @param array $sortFields Lista de campos a ordenar. Cada elemento puede ser:
     *   - `string`: Nombre del campo, opcionalmente con prefijo `-` para DESC
     *   - `int`: Posición de la columna en el SELECT (1-based), negativo para DESC
     *   - `string con punto`: Campo calificado con alias ej: `'u.name'`
     * 
     * @return string Cláusula ORDER BY completa (incluye "ORDER BY") o cadena vacía si no hay campos.
     */
    public static function buildOrderBy(array $sortFields): string
    {
        if (empty($sortFields))
            return "";
        $parts = [];
        foreach ($sortFields as $field) {
            $direction = str_starts_with($field, '-') ? 'DESC' : 'ASC';
            $cleanField = ltrim($field, '-');
            $qualifiedField = self::qualifyColumn($cleanField);
            $parts[] = "$qualifiedField $direction";
        }
        return "ORDER BY " . implode(', ', $parts);
    }

    // ========== WRITE OPERATIONS ==========

    private static function normalizeValue(mixed $value): mixed
    {
        if ($value === '' || $value === "\0")
            return null;
        return $value;
    }

    public static function buildInsert(string $table, array $rows): array
    {
        if (empty($rows))
            throw new \InvalidArgumentException("No se pueden insertar registros vacíos.");
        $isSingle = !isset($rows[0]) || !is_array($rows[0]);
        $data = $isSingle ? [$rows] : $rows;
        if (empty($data[0]))
            throw new \InvalidArgumentException("El registro de datos está vacío.");
        $columns = array_keys($data[0]);
        $quotedCols = implode(', ', array_map([self::class, 'quote'], $columns));
        $placeholders = [];
        $params = [];
        foreach ($columns as $col) {
            $token = self::nextToken();
            $value = self::normalizeValue($data[0][$col]);
            $placeholders[] = ":$token";
            $params[$token] = $value;
        }
        $sql = "INSERT INTO " . self::quote($table) . " ($quotedCols) VALUES (" . implode(', ', $placeholders) . ")";
        return [$sql, $params];
    }

    public static function buildUpdate(string $table, array $data, array $where, bool $force = false): array
    {
        if (empty($where) && !$force) {
            throw new \RuntimeException("PELIGRO: UPDATE masivo sin WHERE en [$table].");
        }
        $parts = ["UPDATE " . self::quote($table), "SET"];
        $setParts = [];
        $params = [];
        foreach ($data as $col => $val) {
            $token = self::nextToken();
            $setParts[] = self::quote($col) . " = :$token";
            $params[$token] = self::normalizeValue($val);
        }
        $parts[] = implode(', ', $setParts);
        $whereData = self::buildWhere($where);
        $parts[] = "WHERE " . $whereData['sql'];
        $sql = implode(' ', $parts);
        return [$sql, array_merge($params, $whereData['params'])];
    }

    public static function buildDelete(string $table, array $where, bool $force = false): array
    {
        if (empty($where) && !$force)
            throw new \RuntimeException("PELIGRO: DELETE masivo sin WHERE.");
        $whereData = self::buildWhere($where);
        return ["DELETE FROM " . self::quote($table) . " WHERE " . $whereData['sql'], $whereData['params']];
    }

    public static function buildExists(string $table, array $where): array
    {
        $whereData = self::buildWhere($where);
        $sub = "SELECT 1 FROM " . self::quote($table);
        if ($whereData['sql'] !== '1')
            $sub .= " WHERE " . $whereData['sql'];
        $sql = "SELECT EXISTS($sub) AS `check`";
        return [$sql, $whereData['params']];
    }

    public static function buildCount(mixed $table, array $where = [], array $groupBy = []): array
    {
        if (empty($groupBy)) {
            $parts = ['SELECT', 'COUNT(*)'];
            $from = self::buildFromWithMap($table);
            if ($from)
                $parts[] = $from;
            $whereData = self::buildWhere($where);
            $parts[] = 'WHERE';
            $parts[] = $whereData['sql'];
            $sql = implode(' ', $parts);
            return [$sql, $whereData['params']];
        }
        [$subSql, $params] = self::buildSelect('1', $table, $where, $groupBy);
        return ["SELECT COUNT(*) FROM ($subSql) AS q", $params];
    }
}