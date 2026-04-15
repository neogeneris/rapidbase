<?php

namespace RapidBase\Core;

class SQL
{
    private static array $relMap = [];
    private static array $schema = [];
    private static string $driver = 'sqlite';
    private static string $quoteChar = '"';
    protected static int $parameterCount = 0;
    private static array $joinTreeCache = [];
    
    // ========== CACHÉ DE CONSULTAS SQL ==========
    private static array $queryCache = [];
    private static bool $queryCacheEnabled = false;
    private static int $queryCacheMaxSize = 1000;
    private static int $queryCacheHits = 0;
    private static int $queryCacheMisses = 0;

    // ========== CONFIGURACIÓN DE DRIVER ==========

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

    // ========== CONFIGURACIÓN DEL CACHÉ DE CONSULTAS ==========

    /**
     * Habilita o deshabilita el caché de consultas SQL generadas.
     * Útil para reducir la CPU en consultas complejas con múltiples JOINs.
     */
    public static function setQueryCacheEnabled(bool $enabled): void
    {
        self::$queryCacheEnabled = $enabled;
    }

    /**
     * Establece el tamaño máximo del caché de consultas.
     * Cuando se alcanza el límite, se eliminan las entradas más antiguas (LRU).
     */
    public static function setQueryCacheMaxSize(int $size): void
    {
        self::$queryCacheMaxSize = max(100, $size);
    }

    /**
     * Obtiene estadísticas del caché de consultas.
     * @return array Con hits, misses, size y hitRate.
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
     * Limpia completamente el caché de consultas.
     */
    public static function clearQueryCache(): void
    {
        self::$queryCache = [];
        self::$queryCacheHits = 0;
        self::$queryCacheMisses = 0;
    }

    // ========== CARGA DEL MAPA Y ESQUEMA ==========

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

    // ========== MÉTODOS BASE ==========

    public static function reset(): void
    {
        self::$parameterCount = 0;
    }

    protected static function nextToken(): string
    {
        return "p" . (self::$parameterCount++);
    }

    public static function quote(string $identifier): string
    {
        $q = self::$quoteChar;
        if (is_object($identifier) && isset($identifier->raw)) {
            return $identifier->raw;
        }
        $identifier = trim($identifier);
        if ($identifier === '*' || str_starts_with($identifier, $q))
            return $identifier;
        $parts = explode('.', $identifier);
        $quotedParts = array_map(function ($part) use ($q) {
            return $part === '*' ? '*' : $q . trim($part, $q) . $q;
        }, $parts);
        return implode('.', $quotedParts);
    }

    private static function quoteField(string $field): string
    {
        $field = trim($field);
        if (preg_match('/^(.*?)\s+AS\s+(.*)$/i', $field, $matches)) {
            $left = trim($matches[1]);
            $right = trim($matches[2]);
            return self::quote($left) . ' AS ' . self::quote($right);
        }
        return self::quote($field);
    }

    // ========== CONSTRUCCIÓN DE SELECT ==========

    /**
     * Construye una consulta SELECT utilizando SelectBuilder internamente.
     * 
     * Esta versión usa un objeto SelectBuilder en lugar de arrays para mejor
     * rendimiento y mantenibilidad. El caché almacena la plantilla SQL generada.
     * 
     * @param mixed $fields Columnas a seleccionar.
     * @param mixed $table Tabla o array de tablas para JOINs.
     * @param array $where Condiciones WHERE.
     * @param array $groupBy Agrupamiento.
     * @param array $having Condiciones HAVING.
     * @param array $sort Ordenamiento.
     * @param int $page Página actual.
     * @param int $perPage Registros por página.
     * @return array [sql, params]
     */
    public static function buildSelect(
        $fields = '*',
        $table = '',
        array $where = [],
        array $groupBy = [],
        array $having = [],
        array $sort = [],
        int $page = 1,
        int $perPage = 10
    ): array {
        self::reset();
        
        // Generar clave de caché basada en la ESTRUCTURA de la consulta (no los valores)
        $cacheKey = null;
        if (self::$queryCacheEnabled) {
            $structureKey = json_encode([
                'fields' => $fields,
                'table' => $table,
                'where_keys' => self::getWhereStructure($where),
                'groupBy' => $groupBy,
                'having_keys' => self::getWhereStructure($having),
                'sort_keys' => array_keys($sort),
                'page' => $page,
                'perPage' => $perPage
            ]);
            $cacheKey = 'select_' . md5($structureKey);
            
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
        
        // Usar SelectBuilder para construir la consulta
        $builder = new SelectBuilder($fields, '', $where, $sort, $page, $perPage);
        
        // Configurar FROM y JOINs
        $builder->setFrom($table);
        
        // Configurar GROUP BY
        if (!empty($groupBy)) {
            $builder->groupBy = $groupBy;
        }
        
        // Configurar HAVING
        if (!empty($having)) {
            $builder->having = $having;
        }
        
        // Construir SQL final
        $sql = $builder->toSql();
        $params = $builder->params;
        
        // Almacenar en caché la plantilla SQL
        if ($cacheKey !== null && count(self::$queryCache) < self::$queryCacheMaxSize) {
            self::$queryCache[$cacheKey] = $sql;
            if (count(self::$queryCache) > self::$queryCacheMaxSize) {
                array_splice(self::$queryCache, 0, (int)(self::$queryCacheMaxSize * 0.1));
            }
        }
        
        return [$sql, $params];
    }

    /**
     * Extrae la estructura de un array WHERE/HAVING para usar como clave de caché.
     * Ignora los valores específicos, solo considera las claves y operadores.
     */
    private static function getWhereStructure(array $conditions): array
    {
        $structure = [];
        foreach ($conditions as $key => $value) {
            if (is_array($value)) {
                // Operadores personalizados o arrays anidados
                $structure[$key] = is_array($value) ? array_keys($value) : null;
            } else {
                $structure[$key] = null; // Solo nos importa la clave
            }
        }
        return $structure;
    }
    // ========== CONSTRUCCIÓN DE FROM CON JOINS ==========


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

        if (!self::hasRelations())
            return $tableNames;

        $degrees = [];

        foreach ($tableNames as $t) {
            $out = count(self::$relMap['from'][$t] ?? []);
            $in = count(self::$relMap['to'][$t] ?? []);
            $degrees[$t] = ['out' => $out, 'in' => $in];
        }
        uasort($degrees, function ($a, $b) {
            if ($a['out'] != $b['out'])
                return $b['out'] <=> $a['out'];
            if ($a['in'] != $b['in'])
                return $a['in'] <=> $b['in'];
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
            $edges[] = ['parent' => $info['parent'], 'child' => $child, 'rel' => $info['rel']];
        }
        return ['root' => $root, 'edges' => $edges];
    }


    /**
     * Construye la condición ON para un JOIN entre dos tablas, usando el mapa de relaciones.
     *
     * La función determina automáticamente la dirección correcta de la relación:
     * - Si la relación está definida en 'from' desde la tabla padre hacia la hija,
     *   se usa `padre.local_key = hijo.foreign_key`.
     * - Si está definida en sentido inverso (desde la hija hacia la padre),
     *   se usa `hijo.local_key = padre.foreign_key`.
     *
     * También distingue entre tipos de relación ('belongsTo' vs 'hasMany/hasOne')
     * para ajustar la semántica.
     *
     * @param string $parentTabla Nombre real de la tabla padre.
     * @param string $parentAlias Alias de la tabla padre en la consulta.
     * @param string $childTabla  Nombre real de la tabla hija.
     * @param string $childAlias  Alias de la tabla hija.
     * @param array $relation     Array con la definición de la relación (debe contener
     *                            'type', 'local_key', 'foreign_key').
     * @return string Cláusula ON (incluye la palabra "ON" y la condición).
     */


    private static function buildJoinCondition(string $parentTabla, string $parentAlias, string $childTabla, string $childAlias, array $relation): string
    {
        $type = $relation['type'];
        $localKey = $relation['local_key'];
        $foreignKey = $relation['foreign_key'];

        $isDirectFromParentToChild = isset(self::$relMap['from'][$parentTabla][$childTabla]);
        $isDirectFromChildToParent = isset(self::$relMap['from'][$childTabla][$parentTabla]);

        if ($type === 'belongsTo') {
            if ($isDirectFromParentToChild) {
                return "ON " . self::quote($parentAlias) . "." . self::quote($localKey)
                    . " = " . self::quote($childAlias) . "." . self::quote($foreignKey);
            } elseif ($isDirectFromChildToParent) {
                return "ON " . self::quote($parentAlias) . "." . self::quote($foreignKey)
                    . " = " . self::quote($childAlias) . "." . self::quote($localKey);
            } else {
                return "ON " . self::quote($parentAlias) . "." . self::quote($localKey)
                    . " = " . self::quote($childAlias) . "." . self::quote($foreignKey);
            }
        } else {
            if (isset(self::$relMap['to'][$parentTabla][$childTabla])) {
                $relTo = self::$relMap['to'][$parentTabla][$childTabla];
                return "ON " . self::quote($childAlias) . "." . self::quote($relTo['local_key'])
                    . " = " . self::quote($parentAlias) . "." . self::quote($relTo['foreign_key']);
            } elseif (isset(self::$relMap['to'][$childTabla][$parentTabla])) {
                $relTo = self::$relMap['to'][$childTabla][$parentTabla];
                return "ON " . self::quote($parentAlias) . "." . self::quote($relTo['local_key'])
                    . " = " . self::quote($childAlias) . "." . self::quote($relTo['foreign_key']);
            } else {
                return "ON " . self::quote($childAlias) . "." . self::quote($localKey)
                    . " = " . self::quote($parentAlias) . "." . self::quote($foreignKey);
            }
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

    public static function buildFromWithMap(mixed $table): string
    {
        if (is_string($table)) {
            return "FROM " . self::quote($table);
        }
        if (!is_array($table))
            return "";

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
        if ($hasDuplicates || (!self::hasSchema() && self::hasRelations())) {
            return self::buildFromLinear($table);
        }
        if (!self::hasSchema() && !self::hasRelations()) {
            $first = array_shift($table);
            $from = "FROM " . self::quote($first);
            foreach ($table as $next)
                $from .= " LEFT JOIN " . self::quote($next);
            return $from;
        }

        if (self::hasRelations()) {
            $realNames = self::orderTablesByWeakness($realNames);
            $aliasesOrdered = [];
            foreach ($realNames as $real)
                $aliasesOrdered[$real] = $aliases[$real];
            $aliases = $aliasesOrdered;
        }

        $cacheKey = implode('|', $realNames);
        if (!isset(self::$joinTreeCache[$cacheKey])) {
            self::$joinTreeCache[$cacheKey] = self::buildJoinTree($realNames);
        }
        $tree = self::$joinTreeCache[$cacheKey];
        $rootReal = $tree['root'];
        $rootAlias = $aliases[$rootReal];
        $parts = ["FROM " . self::quote($rootReal)];
        if ($rootAlias !== $rootReal)
            $parts[] = "AS " . self::quote($rootAlias);

        foreach ($tree['edges'] as $edge) {
            $parentReal = $edge['parent'];
            $childReal = $edge['child'];
            $rel = $edge['rel'];
            $parentAlias = $aliases[$parentReal];
            $childAlias = $aliases[$childReal];
            $onClause = self::buildJoinCondition($parentReal, $parentAlias, $childReal, $childAlias, $rel);
            $joinPart = "LEFT JOIN " . self::quote($childReal);
            if ($childAlias !== $childReal)
                $joinPart .= " AS " . self::quote($childAlias);
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

    // ========== WHERE Y ORDER BY ==========


    /**
     * Construye la cláusula WHERE de una consulta SQL a partir de un array de condiciones.
     *
     * Soporta múltiples formatos de entrada:
     *
     * 1. **Array asociativo (AND)**
     *    `['col1' => 'valor1', 'col2' => 'valor2']`
     *    → `col1 = :p0 AND col2 = :p1`
     *
     * 2. **Array indexado de grupos (OR)**
     *    `[ ['col1' => 'valor1'], ['col2' => 'valor2'] ]`
     *    → `(col1 = :p0) OR (col2 = :p1)`
     *
     *    También grupos con múltiples condiciones:
     *    `[ ['col1' => 'valor1', 'col2' => 'valor2'], ['col3' => 'valor3'] ]`
     *    → `(col1 = :p0 AND col2 = :p1) OR (col3 = :p2)`
     *
     * 3. **Valores NULL** (genera IS NULL)
     *    `['col' => null]` → `col IS NULL`
     *
     * 4. **Operadores personalizados**
     *    `['col' => ['>' => 18, '<' => 30]]` → `col > :p0 AND col < :p1`
     *
     * 5. **Lista de valores (IN)**
     *    `['col' => [1, 2, 3]]` → `col IN (:p0, :p1, :p2)`
     *
     * 6. **Condiciones con alias de tabla**
     *    Si se pasa `$context` (array `alias => tablaReal`) y `$defaultAlias`,
     *    se resuelven automáticamente los nombres de columna sin prefijo.
     *
     * @param array $where       Array de condiciones (asociativo o indexado).
     * @param array $context     Mapeo de alias de tabla a nombre real (opcional).
     * @param string $defaultAlias Alias por defecto para columnas sin prefijo (opcional).
     *
     * @return array<string, mixed> Devuelve `['sql' => string, 'params' => array]`.
     *                               La clave `sql` contiene la cláusula WHERE (sin la palabra WHERE).
     *                               La clave `params` contiene los parámetros nombrados (claves como "p0", valores a bindear).
     *
     * @throws \RuntimeException Si una columna es ambigua (presente en más de una tabla del contexto).
     */


    public static function buildWhere(array $where, array $context = [], string $defaultAlias = ''): array
    {
        if (empty($where)) {
            return ['sql' => "1", 'params' => []];
        }

        // --- Detección de grupos OR (array indexado) ---
        $isIndexed = array_keys($where) === range(0, count($where) - 1);
        if ($isIndexed) {
            $groupSql = [];
            $allParams = [];
            foreach ($where as $group) {
                if (!is_array($group)) {
                    // Si no es array, tratarlo como condición simple (columna = valor)
                    $group = [$group];
                }
                $sub = self::buildWhere($group, $context, $defaultAlias);
                $groupSql[] = '(' . $sub['sql'] . ')';
                $allParams = array_merge($allParams, $sub['params']);
            }
            // Si solo hay un grupo, no añadimos "OR" superfluo
            $sql = implode(' OR ', $groupSql);
            return ['sql' => $sql, 'params' => $allParams];
        }

        // --- Modo normal: AND entre condiciones (array asociativo) ---
        $sqlParts = [];
        $params = [];

        foreach ($where as $column => $value) {
            $rawColumn = trim($column);
            if (!str_contains($rawColumn, '.') && !preg_match('/[^\w\.]/', $rawColumn)) {
                $foundAlias = null;
                if (self::hasSchema() && !empty($context)) {
                    foreach ($context as $alias => $realTable) {
                        if (isset(self::$schema[$realTable][$rawColumn])) {
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

            if (is_null($value)) {
                $sqlParts[] = "$safeColumn IS NULL";
            } elseif (is_array($value)) {
                // Si el array es indexado (sin operadores) -> se trata como lista para IN (...)
                $isList = array_keys($value) === range(0, count($value) - 1);
                if ($isList) {
                    $placeholders = [];
                    foreach ($value as $val) {
                        $token = self::nextToken();
                        $placeholders[] = ":$token";
                        $params[$token] = $val;
                    }
                    $sqlParts[] = "$safeColumn IN (" . implode(', ', $placeholders) . ")";
                } else {
                    // Es un array asociativo [operador => valor]
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
     * Construye la cláusula ORDER BY a partir de un array de especificadores de orden.
     *
     * Cada elemento puede ser:
     * - Un **string** con el nombre de una columna (puede incluir alias de tabla, ej. "u.name").
     * - Un **entero** positivo indicando la posición de la columna en el SELECT (empezando en 1).
     *
     * Para orden descendente, se antepone un guion `-` al nombre o al número.
     *
     * Ejemplos:
     * - `['name', '-id']` → `ORDER BY name ASC, id DESC`
     * - `['-created_at']` → `ORDER BY created_at DESC`
     * - `[1, '-2']` → `ORDER BY 1 ASC, 2 DESC` (primera columna ascendente, segunda descendente)
     * - `[-1]` → `ORDER BY 1 DESC`
     * - `[]` → retorna cadena vacía (sin ORDER BY)
     *
     * @param array $sortFields Lista de strings o enteros (pueden llevar prefijo '-').
     * @return string Cláusula ORDER BY (incluye la palabra "ORDER BY") o cadena vacía si no hay campos.
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

    // ========== OPERACIONES DE ESCRITURA ==========

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