<?php

namespace RapidBase\Core;

/**
 * Clase F: Finalizer - Finaliza la cadena de responsabilidad.
 * 
 * Contiene los métodos que ejecutan/finalizan consultas:
 * - select(), delete(), update()
 * - count(), exists()
 * 
 * Esta clase recibe el estado de B y construye/ejecuta la consulta SQL final.
 */
class F
{
    // Constantes (deben coincidir con B)
    protected const T_FRO = 0;
    protected const T_TYP = 1;
    protected const T_WHR = 2;
    protected const T_PRM = 3;
    protected const T_SEL = 4;
    protected const T_ORD = 5;
    protected const T_LIM = 6;
    protected const T_OFF = 7;
    protected const T_GRP = 8;
    protected const T_HAV = 9;
    protected const T_JON = 10;
    protected const T_ALI = 11;
    
    // Métricas (índices separados para no colisionar)
    protected const ST_METRICS_START = 100;
    protected const ST_METRICS_ID = 101;
    
    private static string $driver = 'sqlite';
    private static string $quoteChar = '"';
    
    // Estado interno
    protected array $state = [];
    
    // Métricas estáticas
    private static array $metrics = [];
    private static bool $metricsEnabled = true;
    private static int $callCount = 0;

    /**
     * Constructor que acepta estado desde B.
     */
    public function __construct(array $state = [])
    {
        $this->state = $state;
    }

    /**
     * Crea una instancia F desde una instancia B.
     */
    public static function fromBuilder(B $builder): self
    {
        return new self($builder->getState());
    }

    public static function setDriver(string $driver): void
    {
        self::$driver = strtolower($driver);
        self::$quoteChar = (self::$driver === 'mysql') ? '`' : '"';
    }
    
    // ========== MÉTODOS DE MÉTRICAS ==========
    
    public static function setMetricsEnabled(bool $enabled): void
    {
        self::$metricsEnabled = $enabled;
    }
    
    public static function getMetrics(): array
    {
        return self::$metrics;
    }
    
    public static function getStats(): array
    {
        if (empty(self::$metrics)) {
            return [
                'calls' => 0,
                'total_time_ms' => 0,
                'avg_time_ms' => 0,
                'total_mem_bytes' => 0,
                'avg_mem_bytes' => 0
            ];
        }
        
        $totalTime = 0;
        $totalMem = 0;
        foreach (self::$metrics as $m) {
            $totalTime += $m['time_ms'] ?? 0;
            $totalMem += $m['mem_bytes'] ?? 0;
        }
        
        $count = count(self::$metrics);
        return [
            'calls' => $count,
            'total_time_ms' => round($totalTime, 4),
            'avg_time_ms' => round($totalTime / $count, 4),
            'total_mem_bytes' => $totalMem,
            'avg_mem_bytes' => (int)($totalMem / $count)
        ];
    }
    
    public static function clearMetrics(): void
    {
        self::$metrics = [];
        self::$callCount = 0;
    }

    /**
     * Ejecuta SELECT y retorna [sql, params].
     */
    public function select($fields = '*', $limit = null, $sort = null, array $group = [], array $having = []): array
    {
        $startTime = self::$metricsEnabled ? microtime(true) : 0;
        $startMem = self::$metricsEnabled ? memory_get_usage() : 0;
        
        // Configurar campos si se proporcionan
        if ($fields !== '*') {
            $this->state[self::T_SEL] = is_array($fields) ? implode(', ', $fields) : $fields;
        }

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

        $result = $this->buildSelect();
        
        // Registrar métricas
        if (self::$metricsEnabled && $startTime > 0) {
            $endTime = microtime(true);
            $endMem = memory_get_usage();
            
            $timeMs = ($endTime - $startTime) * 1000;
            $memBytes = $endMem - $startMem;
            
            self::$metrics[] = [
                'id' => ++self::$callCount,
                'operation' => 'select',
                'time_ms' => round($timeMs, 4),
                'mem_bytes' => $memBytes,
                'sql_len' => strlen($result[0]),
                'sql_preview' => substr($result[0], 0, 100),
                'timestamp' => microtime(true)
            ];
        }

        return $result;
    }

    /**
     * Ejecuta DELETE y retorna [sql, params].
     */
    public function delete(): array
    {
        $startTime = self::$metricsEnabled ? microtime(true) : 0;
        $startMem = self::$metricsEnabled ? memory_get_usage() : 0;
        
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

        $result = [$sql, $params];
        
        // Registrar métricas
        if (self::$metricsEnabled && $startTime > 0) {
            $endTime = microtime(true);
            $endMem = memory_get_usage();
            
            $timeMs = ($endTime - $startTime) * 1000;
            $memBytes = $endMem - $startMem;
            
            self::$metrics[] = [
                'id' => ++self::$callCount,
                'operation' => 'delete',
                'time_ms' => round($timeMs, 4),
                'mem_bytes' => $memBytes,
                'sql_len' => strlen($result[0]),
                'sql_preview' => substr($result[0], 0, 100),
                'timestamp' => microtime(true)
            ];
        }

        return $result;
    }

    /**
     * Ejecuta UPDATE y retorna [sql, params].
     */
    public function update(array $data): array
    {
        $startTime = self::$metricsEnabled ? microtime(true) : 0;
        $startMem = self::$metricsEnabled ? memory_get_usage() : 0;
        
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

        $result = [$sql, $params];
        
        // Registrar métricas
        if (self::$metricsEnabled && $startTime > 0) {
            $endTime = microtime(true);
            $endMem = memory_get_usage();
            
            $timeMs = ($endTime - $startTime) * 1000;
            $memBytes = $endMem - $startMem;
            
            self::$metrics[] = [
                'id' => ++self::$callCount,
                'operation' => 'update',
                'time_ms' => round($timeMs, 4),
                'mem_bytes' => $memBytes,
                'sql_len' => strlen($result[0]),
                'sql_preview' => substr($result[0], 0, 100),
                'timestamp' => microtime(true)
            ];
        }

        return $result;
    }

    /**
     * Ejecuta COUNT y retorna [sql, params].
     */
    public function count(): array
    {
        $startTime = self::$metricsEnabled ? microtime(true) : 0;
        $startMem = self::$metricsEnabled ? memory_get_usage() : 0;
        
        $this->state[self::T_SEL] = 'COUNT(*) as total';
        $result = $this->buildSelect();
        
        // Registrar métricas
        if (self::$metricsEnabled && $startTime > 0) {
            $endTime = microtime(true);
            $endMem = memory_get_usage();
            
            $timeMs = ($endTime - $startTime) * 1000;
            $memBytes = $endMem - $startMem;
            
            self::$metrics[] = [
                'id' => ++self::$callCount,
                'operation' => 'count',
                'time_ms' => round($timeMs, 4),
                'mem_bytes' => $memBytes,
                'sql_len' => strlen($result[0]),
                'sql_preview' => substr($result[0], 0, 100),
                'timestamp' => microtime(true)
            ];
        }

        return $result;
    }

    /**
     * Ejecuta EXISTS y retorna [sql, params].
     */
    public function exists(): array
    {
        $startTime = self::$metricsEnabled ? microtime(true) : 0;
        $startMem = self::$metricsEnabled ? memory_get_usage() : 0;
        
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

        $result = [$sql, $params];
        
        // Registrar métricas
        if (self::$metricsEnabled && $startTime > 0) {
            $endTime = microtime(true);
            $endMem = memory_get_usage();
            
            $timeMs = ($endTime - $startTime) * 1000;
            $memBytes = $endMem - $startMem;
            
            self::$metrics[] = [
                'id' => ++self::$callCount,
                'operation' => 'exists',
                'time_ms' => round($timeMs, 4),
                'mem_bytes' => $memBytes,
                'sql_len' => strlen($result[0]),
                'sql_preview' => substr($result[0], 0, 100),
                'timestamp' => microtime(true)
            ];
        }

        return $result;
    }

    /**
     * Construye la consulta SELECT final.
     */
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
}
