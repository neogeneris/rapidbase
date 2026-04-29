<?php

declare(strict_types=1);

namespace RapidBase\Core;

/**
 * Clase S - Propuesta de refactorización de SQL con mejor estética y legibilidad.
 * 
 * Esta es una prueba de concepto que explora diferentes patrones arquitectónicos
 * para la generación de SQL, manteniendo el performance de la clase original SQL.
 * 
 * PATRONES PROPUESTOS:
 * 
 * 1. **Builder Inmutable**: Cada método retorna una nueva instancia, permitiendo
 *    construir queries de forma funcional sin efectos secundarios.
 * 
 * 2. **Expresivo/Declarativo**: Sintaxis más cercana al lenguaje natural.
 * 
 * 3. **Composición sobre Herencia**: Uso de traits y composición para reutilización.
 * 
 * 4. **Static Facade + Fluent Interface**: Combina la facilidad de uso estático
 *    con la fluidez de métodos encadenables.
 * 
 * CARACTERÍSTICAS CLAVE:
 * - No pierde performance (usa las mismas optimizaciones que SQL)
 * - Más fácil de entender y mantener
 * - Permite testing más sencillo
 * - Compatible con el ecosistema existente (Gateway, DB, etc.)
 * 
 * EJEMPLOS DE USO:
 * 
 * // Estilo 1: Static Facade (recomendado para simplicidad)
 * $result = S::select('users', ['id', 'name'])
 *     ->where(['status' => 'active'])
 *     ->orderBy('created_at', 'DESC')
 *     ->limit(10)
 *     ->execute();
 * 
 * // Estilo 2: Builder Inmutable
 * $query = S::new()
 *     ->select(['u.id', 'u.name', 'p.title'])
 *     ->from(['users AS u', 'posts AS p'])
 *     ->where(['u.status' => 'active'])
 *     ->orderBy('-u.created_at');
 * [$sql, $params] = $query->build();
 * 
 * // Estilo 3: Declarativo
 * $users = S::find('users')->where(['age' => ['>' => 18]])->all();
 * 
 * @package RapidBase\Core
 */
class S
{
    // ========== ESTADO DEL BUILDER ==========
    private array $select = ['*'];
    private array|string $from = '';
    private array $where = [];
    private array $groupBy = [];
    private array $having = [];
    private array $orderBy = [];
    private ?int $limit = null;
    private ?int $offset = null;
    private array $params = [];
    private array $tables = [];
    
    // ========== CACHE Y OPTIMIZACIÓN (igual que SQL) ==========
    private static array $queryCache = [];
    private static bool $queryCacheEnabled = true;
    private static int $queryCacheMaxSize = 1000;
    private static int $parameterCount = 0;
    
    // ========== CONFIGURACIÓN ==========
    private static string $driver = 'sqlite';
    private static string $quoteChar = '"';
    private static array $relMap = [];
    private static array $schema = [];
    
    // ========== CONSTRUCTORES ==========
    
    /**
     * Constructor privado para forzar el uso de métodos estáticos o new().
     */
    private function __construct() {}
    
    /**
     * Crea una nueva instancia vacía del builder.
     * @return self
     */
    public static function new(): self
    {
        return new self();
    }
    
    /**
     * Inicia un SELECT desde una tabla específica.
     * Sugar syntax para new()->from($table)->select($fields)
     * 
     * @param string|array $table Tabla(s) para el FROM
     * @param string|array $fields Campos a seleccionar (default: '*')
     * @return self
     */
    public static function table(string|array $table, string|array $fields = '*'): self
    {
        return (new self())->from($table)->select($fields);
    }
    
    /**
     * Inicia un SELECT con campos específicos.
     * Sugar syntax para new()->select($fields)
     * 
     * @param string|array $fields Campos a seleccionar
     * @return self
     */
    public static function selectFields(string|array $fields = '*'): self
    {
        return (new self())->select($fields);
    }
    
    /**
     * Busca un registro único (similar a DB::one pero con builder).
     * 
     * @param string $table Tabla principal
     * @return self
     */
    public static function find(string $table): self
    {
        return (new self())->from($table)->limit(1);
    }
    
    /**
     * Cuenta registros (similar a DB::count pero con builder).
     * 
     * @param string|array $table Tabla(s)
     * @return self
     */
    public static function countFrom(string|array $table): self
    {
        return (new self())->from($table)->select('COUNT(*) as total');
    }
    
    /**
     * Verifica existencia (similar a Gateway::exists pero con builder).
     * 
     * @param string $table Tabla
     * @return self
     */
    public static function existsIn(string $table): self
    {
        return (new self())->from($table)->select('1')->limit(1);
    }
    
    // ========== MÉTODOS FLUENTES (INMUTABLES) ==========
    
    /**
     * Establece los campos a seleccionar.
     * 
     * @param string|array $fields Puede ser '*', array de campos, o array asociativo con aliases
     * @return self Nueva instancia con los campos actualizados
     */
    public function select(string|array $fields): self
    {
        $clone = clone $this;
        $clone->select = is_array($fields) ? $fields : [$fields];
        return $clone;
    }
    
    /**
     * Establece la tabla o tablas para el FROM (con soporte para JOINs automáticos).
     * 
     * @param string|array $table Tabla simple o array de tablas para JOINs
     * @return self Nueva instancia con la tabla actualizada
     */
    public function from(string|array $table): self
    {
        $clone = clone $this;
        $clone->from = $table;
        
        // Si es array, extraer nombres de tablas para JOINs automáticos
        if (is_array($table)) {
            $clone->tables = $table;
        } else {
            $clone->tables = [$table];
        }
        
        return $clone;
    }
    
    /**
     * Agrega condiciones WHERE.
     * 
     * @param array $conditions Condiciones matriciales (ver documentación de SQL::buildWhere)
     * @return self Nueva instancia con las condiciones agregadas
     */
    public function where(array $conditions): self
    {
        $clone = clone $this;
        $clone->where = array_merge($clone->where, $conditions);
        return $clone;
    }
    
    /**
     * Establece el GROUP BY.
     * 
     * @param array $columns Columnas para agrupar
     * @return self Nueva instancia con el GROUP BY establecido
     */
    public function groupBy(array $columns): self
    {
        $clone = clone $this;
        $clone->groupBy = $columns;
        return $clone;
    }
    
    /**
     * Agrega condiciones HAVING.
     * 
     * @param array $conditions Condiciones HAVING matriciales
     * @return self Nueva instancia con las condiciones HAVING agregadas
     */
    public function having(array $conditions): self
    {
        $clone = clone $this;
        $clone->having = array_merge($clone->having, $conditions);
        return $clone;
    }
    
    /**
     * Establece el ordenamiento.
     * 
     * @param string|array $sort Puede ser:
     *   - string: 'name' o '-created_at' (prefijo - para DESC)
     *   - array: ['name', '-created_at'] o ['name' => 'ASC', 'created_at' => 'DESC']
     * @return self Nueva instancia con el ordenamiento establecido
     */
    public function orderBy(string|array $sort): self
    {
        $clone = clone $this;
        
        if (is_string($sort)) {
            $clone->orderBy = [$sort];
        } else {
            // Convertir array asociativo a formato con prefijos
            $normalized = [];
            foreach ($sort as $key => $value) {
                if (is_string($key)) {
                    // Array asociativo: ['name' => 'DESC']
                    $prefix = strtoupper($value) === 'DESC' ? '-' : '';
                    $normalized[] = $prefix . $key;
                } else {
                    // Array numérico: ['name', '-created_at']
                    $normalized[] = $value;
                }
            }
            $clone->orderBy = !empty($normalized) ? $normalized : $sort;
        }
        
        return $clone;
    }
    
    /**
     * Establece el límite de registros.
     * 
     * @param int $limit Cantidad máxima de registros
     * @return self Nueva instancia con el límite establecido
     */
    public function limit(int $limit): self
    {
        $clone = clone $this;
        $clone->limit = $limit;
        return $clone;
    }
    
    /**
     * Establece el offset (desplazamiento).
     * 
     * @param int $offset Cantidad de registros a saltar
     * @return self Nueva instancia con el offset establecido
     */
    public function offset(int $offset): self
    {
        $clone = clone $this;
        $clone->offset = $offset;
        return $clone;
    }
    
    /**
     * Establece paginación (página y por página).
     * 
     * @param int $page Número de página (1-based)
     * @param int $perPage Registros por página
     * @return self Nueva instancia con la paginación establecida
     */
    public function page(int $page, int $perPage = 10): self
    {
        $clone = clone $this;
        $clone->limit = $perPage;
        $clone->offset = ($page - 1) * $perPage;
        return $clone;
    }
    
    // ========== BUILDERS DE SQL ==========
    
    /**
     * Construye la consulta SELECT completa.
     * 
     * @return array [string $sql, array $params]
     */
    public function build(): array
    {
        // Resetear contador de parámetros
        self::$parameterCount = 0;
        
        // Generar clave de caché
        $cacheKey = $this->generateCacheKey();
        
        // Intentar recuperar de caché
        if (self::$queryCacheEnabled && isset(self::$queryCache[$cacheKey])) {
            return self::$queryCache[$cacheKey];
        }
        
        // Construir cláusulas
        $selectClause = $this->buildSelectClause();
        $fromClause = $this->buildFromClause();
        [$whereClause, $whereParams] = $this->buildWhereClause();
        $groupByClause = $this->buildGroupByClause();
        $havingClause = $this->buildHavingClause();
        $orderByClause = $this->buildOrderByClause();
        $limitClause = $this->buildLimitClause();
        
        // Ensamblar SQL final
        $sql = trim(sprintf(
            "SELECT %s FROM %s WHERE %s %s %s %s %s",
            $selectClause,
            $fromClause,
            $whereClause,
            $groupByClause,
            $havingClause,
            $orderByClause,
            $limitClause
        ));
        
        // Limpiar espacios múltiples
        $sql = preg_replace('/\s+/', ' ', $sql);
        
        // Merge de parámetros
        $params = array_merge($whereParams, $this->params);
        
        // Guardar en caché
        if (self::$queryCacheEnabled && count(self::$queryCache) < self::$queryCacheMaxSize) {
            self::$queryCache[$cacheKey] = [$sql, $params];
        }
        
        return [$sql, $params];
    }
    
    /**
     * Construye y ejecuta la consulta, retornando resultados.
     * Usa Gateway::select internamente para compatibilidad.
     * 
     * @param string|null $class Clase para hidratar (null para array asociativo)
     * @return array Resultados de la consulta
     */
    public function execute(?string $class = null): array
    {
        [$sql, $params] = $this->build();
        
        // Usar Executor directamente para máximo control
        $stmt = Executor::query($sql, $params);
        
        if ($class !== null) {
            return $stmt->fetchAll(\PDO::FETCH_CLASS, $class);
        }
        
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    /**
     * Ejecuta y obtiene un único registro.
     * 
     * @return array|object|null
     */
    public function one(): array|object|null
    {
        $results = $this->limit(1)->execute();
        return $results[0] ?? null;
    }
    
    /**
     * Ejecuta y obtiene todos los registros.
     * Alias de execute().
     * 
     * @return array
     */
    public function all(): array
    {
        return $this->execute();
    }
    
    /**
     * Ejecuta y obtiene un valor escalar (primera columna del primer registro).
     * 
     * @return mixed
     */
    public function value(): mixed
    {
        $results = $this->limit(1)->execute();
        if (empty($results)) {
            return null;
        }
        $firstRow = $results[0];
        return is_array($firstRow) ? reset($firstRow) : ($firstRow->{array_keys(get_object_vars($firstRow))[0]} ?? null);
    }
    
    /**
     * Ejecuta y verifica si existe al menos un registro.
     * 
     * @return bool
     */
    public function exists(): bool
    {
        $result = $this->limit(1)->value();
        return $result !== null;
    }
    
    /**
     * Ejecuta y cuenta los registros.
     * 
     * @return int
     */
    public function count(): int
    {
        $clone = clone $this;
        $clone->select = ['COUNT(*) as total'];
        $clone->limit = null;
        $clone->offset = null;
        return (int) $clone->value();
    }
    
    // ========== BUILDERS DE INSERT, UPDATE, DELETE ==========
    
    /**
     * Construye un INSERT.
     * 
     * @param string $table Tabla destino
     * @param array $data Datos a insertar (associative array)
     * @return array [string $sql, array $params]
     */
    public static function insert(string $table, array $data): array
    {
        self::$parameterCount = 0;
        
        $columns = array_keys($data);
        $placeholders = [];
        $params = [];
        
        foreach ($columns as $col) {
            $token = self::nextToken();
            $placeholders[] = self::quote($col);
            $params[$token] = $data[$col];
        }
        
        $sql = sprintf(
            "INSERT INTO %s (%s) VALUES (%s)",
            self::quote($table),
            implode(', ', $placeholders),
            implode(', ', array_map(fn($t) => ':' . $t, array_keys($params)))
        );
        
        return [$sql, $params];
    }
    
    /**
     * Construye un UPDATE.
     * 
     * @param string $table Tabla a actualizar
     * @param array $data Datos a actualizar
     * @param array $where Condiciones WHERE
     * @return array [string $sql, array $params]
     */
    public static function update(string $table, array $data, array $where = []): array
    {
        self::$parameterCount = 0;
        
        $setParts = [];
        $params = [];
        
        foreach ($data as $col => $value) {
            $token = self::nextToken();
            $setParts[] = self::quote($col) . ' = :' . $token;
            $params[$token] = $value;
        }
        
        [$whereClause, $whereParams] = self::buildWhereSimple($where);
        
        $sql = sprintf(
            "UPDATE %s SET %s WHERE %s",
            self::quote($table),
            implode(', ', $setParts),
            $whereClause
        );
        
        return [$sql, array_merge($params, $whereParams)];
    }
    
    /**
     * Construye un DELETE.
     * 
     * @param string $table Tabla de donde eliminar
     * @param array $where Condiciones WHERE
     * @return array [string $sql, array $params]
     */
    public static function delete(string $table, array $where = []): array
    {
        self::$parameterCount = 0;
        
        [$whereClause, $params] = self::buildWhereSimple($where);
        
        $sql = sprintf(
            "DELETE FROM %s WHERE %s",
            self::quote($table),
            $whereClause
        );
        
        return [$sql, $params];
    }
    
    // ========== HELPERS INTERNOS ==========
    
    private function buildSelectClause(): string
    {
        if (empty($this->select)) {
            return '*';
        }
        
        $fields = [];
        foreach ($this->select as $key => $field) {
            if (is_string($key)) {
                // Array asociativo: alias => campo
                $fields[] = self::quoteField($field) . ' AS ' . self::quote($key);
            } elseif (is_object($field) && isset($field->raw)) {
                // Expresión raw
                $fields[] = $field->raw;
            } else {
                // Campo simple o expresión
                $fields[] = self::quoteField($field);
            }
        }
        
        return implode(', ', $fields);
    }
    
    private function buildFromClause(): string
    {
        if (empty($this->from)) {
            return '(SELECT 1)';
        }
        
        if (is_string($this->from)) {
            return self::quote($this->from);
        }
        
        // Array de tablas - construir JOINs automáticos
        return $this->buildJoinTree($this->tables);
    }
    
    private function buildWhereClause(): array
    {
        if (empty($this->where)) {
            return ['1', []];
        }
        
        return self::buildWhereSimple($this->where);
    }
    
    private function buildGroupByClause(): string
    {
        if (empty($this->groupBy)) {
            return '';
        }
        
        $columns = array_map([self::class, 'quote'], $this->groupBy);
        return 'GROUP BY ' . implode(', ', $columns);
    }
    
    private function buildHavingClause(): array|string
    {
        if (empty($this->having)) {
            return '';
        }
        
        [$clause, $_] = self::buildWhereSimple($this->having);
        return 'HAVING ' . $clause;
    }
    
    private function buildOrderByClause(): string
    {
        if (empty($this->orderBy)) {
            return '';
        }
        
        $parts = [];
        foreach ($this->orderBy as $field) {
            $direction = 'ASC';
            if (str_starts_with($field, '-')) {
                $direction = 'DESC';
                $field = substr($field, 1);
            }
            $parts[] = self::quoteField($field) . ' ' . $direction;
        }
        
        return 'ORDER BY ' . implode(', ', $parts);
    }
    
    private function buildLimitClause(): string
    {
        $clauses = [];
        
        if ($this->limit !== null) {
            $clauses[] = 'LIMIT ' . (int)$this->limit;
        }
        
        if ($this->offset !== null && $this->offset > 0) {
            $clauses[] = 'OFFSET ' . (int)$this->offset;
        }
        
        return implode(' ', $clauses);
    }
    
    private function buildJoinTree(array $tables): string
    {
        if (count($tables) === 1) {
            $table = $tables[0];
            // Manejar alias (ej: 'users AS u')
            if (stripos($table, ' AS ') !== false) {
                $parts = preg_split('/\s+AS\s+/i', $table);
                return self::quote($parts[0]) . ' AS ' . self::quote($parts[1]);
            }
            return self::quote($table);
        }
        
        // Múltiples tablas - construir JOINs
        // Esto es una simplificación; en producción usaría el relMap como SQL.php
        $mainTable = array_shift($tables);
        $sql = self::quote($mainTable);
        
        foreach ($tables as $table) {
            // Aquí iría la lógica de JOIN automático usando relMap
            // Por ahora, JOIN simple INNER
            if (stripos($table, ' AS ') !== false) {
                $parts = preg_split('/\s+AS\s+/i', $table);
                $tableName = $parts[0];
                $alias = $parts[1];
                $sql .= ' INNER JOIN ' . self::quote($tableName) . ' AS ' . self::quote($alias);
            } else {
                $sql .= ' INNER JOIN ' . self::quote($table);
            }
            
            // ON condition would be derived from relMap in production
            $sql .= ' ON 1=1'; // Placeholder
        }
        
        return $sql;
    }
    
    private static function buildWhereSimple(array $where, string $operator = 'AND'): array
    {
        if (empty($where)) {
            return ['1', []];
        }
        
        $conditions = [];
        $params = [];
        
        foreach ($where as $key => $value) {
            if (is_int($key)) {
                // Condición anidada (OR, AND complejo)
                if (is_array($value)) {
                    [$subClause, $subParams] = self::buildWhereSimple($value);
                    $conditions[] = '(' . $subClause . ')';
                    $params = array_merge($params, $subParams);
                }
                continue;
            }
            
            // Procesar operador
            if (is_array($value)) {
                // Múltiples operadores o IN
                $opConditions = [];
                foreach ($value as $op => $val) {
                    if (is_int($op)) {
                        // IN clause
                        $tokens = [];
                        foreach ($val as $v) {
                            $t = self::nextToken();
                            $tokens[] = ':' . $t;
                            $params[$t] = $v;
                        }
                        $opConditions[] = self::quote($key) . ' IN (' . implode(', ', $tokens) . ')';
                    } else {
                        // Operador comparativo
                        $t = self::nextToken();
                        $opConditions[] = self::quote($key) . ' ' . $op . ' :' . $t;
                        $params[$t] = $val;
                    }
                }
                $conditions[] = '(' . implode(' AND ', $opConditions) . ')';
            } elseif ($value === null) {
                // IS NULL
                $conditions[] = self::quote($key) . ' IS NULL';
            } else {
                // Igualdad simple
                $t = self::nextToken();
                $conditions[] = self::quote($key) . ' = :' . $t;
                $params[$t] = $value;
            }
        }
        
        $clause = implode(' ' . $operator . ' ', $conditions);
        return [$clause ?: '1', $params];
    }
    
    private function generateCacheKey(): string
    {
        $data = [
            $this->select,
            $this->from,
            $this->where,
            $this->groupBy,
            $this->having,
            $this->orderBy,
            $this->limit,
            $this->offset
        ];
        
        $json = json_encode($data);
        return function_exists('xxh128') ? xxh128($json) : md5($json);
    }
    
    private static function nextToken(): string
    {
        return 'p' . (self::$parameterCount++);
    }
    
    public static function quote(string $identifier): string
    {
        $q = self::$quoteChar;
        
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
    
    // ========== CONFIGURACIÓN ESTATICA ==========
    
    public static function setDriver(string $driver): void
    {
        self::$driver = strtolower($driver);
        self::$quoteChar = (self::$driver === 'mysql') ? '`' : '"';
    }
    
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
    
    public static function clearQueryCache(): void
    {
        self::$queryCache = [];
    }
    
    public static function setQueryCacheEnabled(bool $enabled): void
    {
        self::$queryCacheEnabled = $enabled;
    }
    
    public static function getQueryCacheStats(): array
    {
        return [
            'size' => count(self::$queryCache),
            'max_size' => self::$queryCacheMaxSize,
            'enabled' => self::$queryCacheEnabled
        ];
    }
}
