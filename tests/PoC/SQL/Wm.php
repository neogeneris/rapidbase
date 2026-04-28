<?php

namespace RapidBase\Core;

/**
 * Clase Wm: Wrapper de W con soporte para telemetría/métricas.
 * 
 * Filosofía:
 * 1. Mismos métodos que W pero registrando timing y uso de memoria.
 * 2. W permanece limpia de telemetría.
 * 3. Wm es un perfilador que envuelve las llamadas a W.
 * 4. Las métricas se almacenan estáticamente para acceso posterior.
 */
class Wm extends W
{
    // Métricas estáticas acumuladas
    private static array $metrics = [];
    private static bool $enabled = true;
    private static int $callCount = 0;
    
    // Constantes para índices del estado (heredadas de W pero con agregados)
    private const ST_METRICS_START = 8;
    private const ST_METRICS_ID = 9;
    
    /**
     * Habilita o deshabilita la recolección de métricas.
     */
    public static function setEnabled(bool $enabled): void
    {
        self::$enabled = $enabled;
    }
    
    /**
     * Retorna el estado de habilitación de métricas.
     */
    public static function isEnabled(): bool
    {
        return self::$enabled;
    }
    
    /**
     * Obtiene todas las métricas recolectadas.
     * @return array Con timing, memoria y detalles por llamada.
     */
    public static function getMetrics(): array
    {
        return self::$metrics;
    }
    
    /**
     * Obtiene estadísticas resumidas de las métricas.
     * @return array Con total calls, avg time, avg mem, etc.
     */
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
    
    /**
     * Limpia todas las métricas recolectadas.
     */
    public static function clearMetrics(): void
    {
        self::$metrics = [];
        self::$callCount = 0;
    }
    
    /**
     * Punto de entrada único con métricas.
     * 
     * @param string|array $table Si es string: SQL crudo. Si es array: Lista de tablas.
     * @param array $filter Filtro base para el WHERE.
     * @return self Retorna una instancia nueva con el estado inicializado y tracking de métricas.
     */
    public static function table($table, array $filter = []): self
    {
        $startTime = microtime(true);
        $startMem = memory_get_usage();
        
        $instance = parent::table($table, $filter);
        
        // Guardar timestamp de inicio en el estado extendido
        $instance->state[self::ST_METRICS_START] = ['time' => $startTime, 'mem' => $startMem];
        $instance->state[self::ST_METRICS_ID] = ++self::$callCount;
        
        return $instance;
    }
    
    /**
     * Ejecuta SELECT con registro de métricas.
     * 
     * @param string|array $fields Campos a seleccionar.
     * @param int|array $page Página actual o [page, pageSize].
     * @param string|array $sort Ordenamiento.
     * @return array [sql, params]
     */
    public function select($fields = '*', $page = null, $sort = null): array
    {
        if (!self::$enabled) {
            return parent::select($fields, $page, $sort);
        }
        
        $result = parent::select($fields, $page, $sort);
        $this->recordMetric('select', $result[0]);
        
        return $result;
    }
    
    /**
     * Ejecuta DELETE con registro de métricas.
     * 
     * @return array [sql, params]
     */
    public function delete(): array
    {
        if (!self::$enabled) {
            return parent::delete();
        }
        
        $result = parent::delete();
        $this->recordMetric('delete', $result[0]);
        
        return $result;
    }
    
    /**
     * Ejecuta UPDATE con registro de métricas.
     * 
     * @param array $data Datos a actualizar.
     * @return array [sql, params]
     */
    public function update(array $data): array
    {
        if (!self::$enabled) {
            return parent::update($data);
        }
        
        $result = parent::update($data);
        $this->recordMetric('update', $result[0]);
        
        return $result;
    }
    
    /**
     * Registra una métrica para la operación actual.
     */
    private function recordMetric(string $operation, string $sql): void
    {
        $metricsData = $this->state[self::ST_METRICS_START] ?? null;
        if (!$metricsData) {
            return;
        }
        
        $endTime = microtime(true);
        $endMem = memory_get_usage();
        
        $timeMs = ($endTime - $metricsData['time']) * 1000;
        $memBytes = $endMem - $metricsData['mem'];
        
        self::$metrics[] = [
            'id' => $this->state[self::ST_METRICS_ID],
            'operation' => $operation,
            'time_ms' => round($timeMs, 4),
            'mem_bytes' => $memBytes,
            'sql_len' => strlen($sql),
            'sql_preview' => substr($sql, 0, 100),
            'timestamp' => microtime(true)
        ];
    }
}
