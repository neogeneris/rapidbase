<?php

namespace RapidBase\Core;

/**
 * Clase Wm: Wrapper de W con soporte para telemetría/métricas.
 */
class Wm extends W
{
    private static array $metrics = [];
    private static bool $enabled = true;
    private static int $callCount = 0;
    
    private const ST_METRICS_START = 10;
    private const ST_METRICS_ID = 11;
    
    public static function setEnabled(bool $enabled): void
    {
        self::$enabled = $enabled;
    }
    
    public static function isEnabled(): bool
    {
        return self::$enabled;
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
    
    public static function from($from, array $filter = []): self
    {
        $startTime = microtime(true);
        $startMem = memory_get_usage();
        
        $instance = parent::from($from, $filter);
        
        $instance->state[self::ST_METRICS_START] = ['time' => $startTime, 'mem' => $startMem];
        $instance->state[self::ST_METRICS_ID] = ++self::$callCount;
        
        return $instance;
    }
    
    public function select($fields = '*', $limit = null, $sort = null, array $group = [], array $having = []): array
    {
        if (!self::$enabled) {
            return parent::select($fields, $limit, $sort, $group, $having);
        }
        
        $result = parent::select($fields, $limit, $sort, $group, $having);
        $this->recordMetric('select', $result[0]);
        
        return $result;
    }
    
    public function delete(): array
    {
        if (!self::$enabled) {
            return parent::delete();
        }
        
        $result = parent::delete();
        $this->recordMetric('delete', $result[0]);
        
        return $result;
    }
    
    public function update(array $data): array
    {
        if (!self::$enabled) {
            return parent::update($data);
        }
        
        $result = parent::update($data);
        $this->recordMetric('update', $result[0]);
        
        return $result;
    }
    
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
