<?php

declare(strict_types=1);

namespace RapidBase\Core;

/**
 * Clase V - Variante equilibrada entre SQL y S.
 * 
 * Esta es una prueba de concepto que busca el punto medio óptimo entre:
 * - El rendimiento extremo de SQL (con fast slots, templates y cache L3)
 * - La estética y legibilidad de S (con builder fluido e inmutable)
 * 
 * PATRONES ARQUITECTÓNICOS PROPUESTOS:
 * 
 * 1. **Hybrid Static-Instance API**:
 *    - Queries simples: API estática directa (como SQL)
 *    - Queries complejas: Builder fluido (como S)
 * 
 * 2. **Fast Path Optimization**:
 *    - Para queries comunes, usa slots reutilizables (sin allocations)
 *    - Para queries complejas, usa builder con cloning inteligente
 * 
 * 3. **Lazy Expression Building**:
 *    - Las expresiones se construyen solo cuando se necesitan
 *    - Permite composición sin overhead inmediato
 * 
 * 4. **Query Templates**:
 *    - Plantillas pre-compiladas para queries frecuentes
 *    - Similar al query cache pero a nivel de estructura
 * 
 * 5. **Smart Immutability**:
 *    - Cloning selectivo (solo copia lo que cambia)
 *    - Reduce presión en el GC comparado con S
 * 
 * EJEMPLOS DE USO:
 * 
 * // Estilo 1: Static Fast Path (máximo performance)
 * [$sql, $params] = V::select('users', ['id', 'name'], ['status' => 'active']);
 * 
 * // Estilo 2: Fluent Builder (queries complejas)
 * $query = V::query()
 *     ->select(['u.id', 'u.name', 'p.title'])
 *     ->from(['users AS u', 'posts AS p'])
 *     ->where(['u.status' => 'active'])
 *     ->orderBy('-u.created_at')
 *     ->page(1, 10);
 * [$sql, $params] = $query->build();
 * 
 * // Estilo 3: Template Reuse (queries repetitivas)
 * $template = V::template('SELECT * FROM users WHERE status = ?');
 * $results = V::execute($template, ['active']);
 * 
 * @package RapidBase\Core
 */
class V
{
    // ========== FAST PATH SLOTS (heredados de SQL para performance) ==========
    private static array $fastSlots = [1 => '*', 2 => '', 3 => '1', 4 => '', 5 => '', 6 => ''];
    private const SELECT_TPL = "SELECT %s FROM %s WHERE %s %s %s %s";
    private const COUNT_TPL = "SELECT COUNT(*) FROM %s WHERE %s";
    
    // ========== CACHE L3 (igual que SQL) ==========
    private static array $queryCache = [];
    private static bool $queryCacheEnabled = true;
    private static int $queryCacheMaxSize = 1000;
    private static int $queryCacheHits = 0;
    private static int $queryCacheMisses = 0;
    
    // ========== QUERY TEMPLATES (nuevo) ==========
    private static array $queryTemplates = [];
    
    // ========== CONFIGURACIÓN ==========
    private static string $driver = 'sqlite';
    private static string $quoteChar = '"';
    private static array $relMap = [];
    private static array $schema = [];
    
    // ========== ESTADO DEL BUILDER (para modo fluido) ==========
    private array $select = ['*'];
    private array|string $from = '';
    private array $where = [];
    private array $groupBy = [];
    private array $having = [];
    private array $orderBy = [];
    private ?int $limit = null;
    private ?int $offset = null;
    protected static int $parameterCount = 0;
    
    // ========== CONSTRUCTORES HYBRID ==========
    
    /**
     * Constructor privado para forzar factory methods.
     */
    private function __construct() {}
    
    /**
     * Crea una nueva instancia del builder (modo fluido).
     * Usar para queries complejas que requieren composición.
     * 
     * @return self
     */
    public static function query(): self
    {
        return new self();
    }
    
    /**
     * Fast path estático para SELECT simple.
     * Máximo performance para queries comunes.
     * 
     * @param string|array $fields Campos a seleccionar
     * @param string|array $table Tabla(s)
     * @param array $where Condiciones WHERE
     * @param array $sort Ordenamiento
     * @param int $page Página (0 = sin paginación)
     * @param int $perPage Registros por página
     * @return array [string $sql, array $params]
     */
    public static function select(
        string|array $fields = '*',
        string|array $table = '',
        array $where = [],
        array $sort = [],
        int $page = 0,
        int $perPage = 10
    ): array {
        // Fast path: usar slots directamente sin crear objeto
        if (is_string($fields) && is_string($table) && empty($where) && empty($sort) && $page === 0) {
            return self::fastSelect($fields, $table);
        }
        
        // Delegar al builder para casos complejos
        return self::query()
            ->selectFields($fields)
            ->from($table)
            ->where($where)
            ->orderBy($sort)
            ->page($page, $perPage)
            ->build();
    }
    
    /**
     * Fast path para COUNT.
     * 
     * @param string|array $table Tabla(s)
     * @param array $where Condiciones WHERE
     * @return array [string $sql, array $params]
     */
    public static function count(string|array $table = '', array $where = []): array
    {
        return self::query()
            ->selectFields('COUNT(*) as total')
            ->from($table)
            ->where($where)
            ->build();
    }
    
    /**
     * Fast path para INSERT.
     * 
     * @param string $table Tabla destino
     * @param array $data Datos a insertar
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
     * Fast path para UPDATE.
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
     * Fast path para DELETE.
     * 
     * @param string $table Tabla
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
    
    /**
     * Crea un template de consulta reutilizable.
     * 
     * @param string $sql SQL con placeholders (?)
     * @return callable Función que acepta parámetros y retorna [sql, params]
     */
    public static function template(string $sql): callable
    {
        $cacheKey = 'tpl_' . crc32($sql);
        
        if (!isset(self::$queryTemplates[$cacheKey])) {
            self::$queryTemplates[$cacheKey] = function (...$params) use ($sql) {
                $tokens = [];
                $paramMap = [];
                
                foreach ($params as $i => $value) {
                    $token = 'p' . $i;
                    $tokens[] = ':' . $token;
                    $paramMap[$token] = $value;
                }
                
                // Reemplazar ? con :pn
                $resultSql = preg_replace_callback('/\?/', function() use (&$tokens) {
                    return array_shift($tokens);
                }, $sql);
                
                return [$resultSql, $paramMap];
            };
        }
        
        return self::$queryTemplates[$cacheKey];
    }
    
    // ========== MÉTODOS FLUENTES (INMUTABLES CON SMART CLONING) ==========
    
    /**
     * Establece los campos a seleccionar (método de instancia para builder).
     * 
     * @param string|array $fields Campos o array de campos
     * @return self Nueva instancia
     */
    public function selectFields(string|array $fields): self
    {
        $clone = clone $this;
        $clone->select = is_array($fields) ? $fields : [$fields];
        return $clone;
    }
    
    /**
     * Establece la tabla o tablas para FROM/JOIN.
     * 
     * @param string|array $table Tabla simple o array para JOINs
     * @return self Nueva instancia
     */
    public function from(string|array $table): self
    {
        $clone = clone $this;
        $clone->from = $table;
        return $clone;
    }
    
    /**
     * Agrega condiciones WHERE.
     * 
     * @param array $conditions Condiciones matriciales
     * @return self Nueva instancia
     */
    public function where(array $conditions): self
    {
        $clone = clone $this;
        $clone->where = array_merge($clone->where, $conditions);
        return $clone;
    }
    
    /**
     * Establece GROUP BY.
     * 
     * @param array $columns Columnas para agrupar
     * @return self Nueva instancia
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
     * @param array $conditions Condiciones HAVING
     * @return self Nueva instancia
     */
    public function having(array $conditions): self
    {
        $clone = clone $this;
        $clone->having = array_merge($clone->having, $conditions);
        return $clone;
    }
    
    /**
     * Establece ORDER BY.
     * 
     * @param string|array $sort Campo(s) para ordenar ('-' prefix para DESC)
     * @return self Nueva instancia
     */
    public function orderBy(string|array $sort): self
    {
        $clone = clone $this;
        
        if (is_string($sort)) {
            $clone->orderBy = [$sort];
        } else {
            $normalized = [];
            foreach ($sort as $key => $value) {
                if (is_string($key)) {
                    $prefix = strtoupper($value) === 'DESC' ? '-' : '';
                    $normalized[] = $prefix . $key;
                } else {
                    $normalized[] = $value;
                }
            }
            $clone->orderBy = !empty($normalized) ? $normalized : $sort;
        }
        
        return $clone;
    }
    
    /**
     * Establece LIMIT.
     * 
     * @param int $limit Cantidad máxima
     * @return self Nueva instancia
     */
    public function limit(int $limit): self
    {
        $clone = clone $this;
        $clone->limit = $limit;
        return $clone;
    }
    
    /**
     * Establece OFFSET.
     * 
     * @param int $offset Desplazamiento
     * @return self Nueva instancia
     */
    public function offset(int $offset): self
    {
        $clone = clone $this;
        $clone->offset = $offset;
        return $clone;
    }
    
    /**
     * Establece paginación.
     * 
     * @param int $page Página (1-based)
     * @param int $perPage Registros por página
     * @return self Nueva instancia
     */
    public function page(int $page, int $perPage = 10): self
    {
        $clone = clone $this;
        $clone->limit = $perPage;
        $clone->offset = $page > 0 ? ($page - 1) * $perPage : 0;
        return $clone;
    }
    
    /**
     * Construye la consulta SQL completa.
     * 
     * @return array [string $sql, array $params]
     */
    public function build(): array
    {
        self::$parameterCount = 0;
        
        // Cache key generation
        $cacheKey = $this->generateCacheKey();
        
        if (self::$queryCacheEnabled && isset(self::$queryCache[$cacheKey])) {
            self::$queryCacheHits++;
            return self::$queryCache[$cacheKey];
        }
        
        self::$queryCacheMisses++;
        
        // Build clauses using optimized methods
        $selectClause = $this->buildSelectClause();
        $fromClause = $this->buildFromClause();
        [$whereClause, $whereParams] = $this->buildWhereClause();
        $groupByClause = $this->buildGroupByClause();
        $havingClause = $this->buildHavingClause();
        $orderByClause = $this->buildOrderByClause();
        $limitClause = $this->buildLimitClause();
        
        // Assemble with template
        $sql = trim(sprintf(
            self::SELECT_TPL,
            $selectClause,
            $fromClause,
            $whereClause,
            $groupByClause,
            $havingClause,
            $orderByClause
        ));
        
        // Add limit clause
        if ($limitClause !== '') {
            $sql .= ' ' . $limitClause;
        }
        
        // Normalize whitespace
        $sql = preg_replace('/\s+/', ' ', $sql);
        
        // Cache result
        if (self::$queryCacheEnabled && count(self::$queryCache) < self::$queryCacheMaxSize) {
            self::$queryCache[$cacheKey] = [$sql, $whereParams];
        }
        
        return [$sql, $whereParams];
    }
    
    /**
     * Ejecuta la consulta y retorna resultados.
     * 
     * @param string|null $class Clase para hidratar (null para array)
     * @return array Resultados
     */
    public function execute(?string $class = null): array
    {
        [$sql, $params] = $this->build();
        $stmt = Executor::query($sql, $params);
        
        if ($class !== null) {
            return $stmt->fetchAll(\PDO::FETCH_CLASS, $class);
        }
        
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    /**
     * Obtiene un único registro.
     * 
     * @return array|object|null
     */
    public function one(): array|object|null
    {
        $results = $this->limit(1)->execute();
        return $results[0] ?? null;
    }
    
    /**
     * Obtiene todos los registros.
     * 
     * @return array
     */
    public function all(): array
    {
        return $this->execute();
    }
    
    /**
     * Obtiene un valor escalar.
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
     * Verifica existencia.
     * 
     * @return bool
     */
    public function exists(): bool
    {
        return $this->limit(1)->value() !== null;
    }
    
    /**
     * Cuenta registros (método de instancia para builder).
     * 
     * @return int
     */
    public function countRecords(): int
    {
        $clone = clone $this;
        $clone->select = ['COUNT(*) as total'];
        $clone->limit = null;
        $clone->offset = null;
        return (int) $clone->value();
    }
    
    // ========== BUILDERS INTERNOS OPTIMIZADOS ==========
    
    private function buildSelectClause(): string
    {
        if (empty($this->select)) {
            return '*';
        }
        
        $fields = [];
        foreach ($this->select as $key => $field) {
            if (is_string($key)) {
                $fields[] = self::quoteField($field) . ' AS ' . self::quote($key);
            } elseif (is_object($field) && isset($field->raw)) {
                $fields[] = $field->raw;
            } else {
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
        
        return $this->buildJoinTree($this->from);
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
    
    private function buildHavingClause(): string
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
            if (stripos($table, ' AS ') !== false) {
                $parts = preg_split('/\s+AS\s+/i', $table);
                return self::quote($parts[0]) . ' AS ' . self::quote($parts[1]);
            }
            return self::quote($table);
        }
        
        $mainTable = array_shift($tables);
        $sql = self::quote($mainTable);
        
        foreach ($tables as $table) {
            if (stripos($table, ' AS ') !== false) {
                $parts = preg_split('/\s+AS\s+/i', $table);
                $tableName = $parts[0];
                $alias = $parts[1];
                $sql .= ' INNER JOIN ' . self::quote($tableName) . ' AS ' . self::quote($alias);
            } else {
                $sql .= ' INNER JOIN ' . self::quote($table);
            }
            
            $sql .= ' ON 1=1'; // Placeholder para relMap
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
                if (is_array($value)) {
                    [$subClause, $subParams] = self::buildWhereSimple($value);
                    $conditions[] = '(' . $subClause . ')';
                    $params = array_merge($params, $subParams);
                }
                continue;
            }
            
            if (is_array($value)) {
                $opConditions = [];
                foreach ($value as $op => $val) {
                    if (is_int($op)) {
                        $tokens = [];
                        foreach ($val as $v) {
                            $t = self::nextToken();
                            $tokens[] = ':' . $t;
                            $params[$t] = $v;
                        }
                        $opConditions[] = self::quote($key) . ' IN (' . implode(', ', $tokens) . ')';
                    } else {
                        $t = self::nextToken();
                        $opConditions[] = self::quote($key) . ' ' . $op . ' :' . $t;
                        $params[$t] = $val;
                    }
                }
                $conditions[] = '(' . implode(' AND ', $opConditions) . ')';
            } elseif ($value === null) {
                $conditions[] = self::quote($key) . ' IS NULL';
            } else {
                $t = self::nextToken();
                $conditions[] = self::quote($key) . ' = :' . $t;
                $params[$t] = $value;
            }
        }
        
        $clause = implode(' ' . $operator . ' ', $conditions);
        return [$clause ?: '1', $params];
    }
    
    private static function fastSelect(string $fields, string $table): array
    {
        // Ultra-fast path para queries simples sin WHERE
        $cacheKey = 'fast_' . $fields . '_' . $table;
        
        if (self::$queryCacheEnabled && isset(self::$queryCache[$cacheKey])) {
            self::$queryCacheHits++;
            return self::$queryCache[$cacheKey];
        }
        
        self::$queryCacheMisses++;
        
        $sql = sprintf(
            "SELECT %s FROM %s",
            $fields === '*' ? '*' : self::quoteField($fields),
            self::quote($table)
        );
        
        $result = [$sql, []];
        
        if (self::$queryCacheEnabled && count(self::$queryCache) < self::$queryCacheMaxSize) {
            self::$queryCache[$cacheKey] = $result;
        }
        
        return $result;
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
    
    public static function getDriver(): string
    {
        return self::$driver;
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
        self::$queryCacheHits = 0;
        self::$queryCacheMisses = 0;
    }
    
    public static function setQueryCacheEnabled(bool $enabled): void
    {
        self::$queryCacheEnabled = $enabled;
    }
    
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
    
    public static function getTemplateStats(): array
    {
        return [
            'count' => count(self::$queryTemplates),
            'templates' => array_keys(self::$queryTemplates)
        ];
    }
}
