<?php

namespace RapidBase\Core;

/**
 * Clase Fm: Finalizer con Métricas - Wrapper de F para capturar métricas.
 * 
 * Esta clase actúa como un puente/decorador sobre F, implementando la misma
 * interfaz pero capturando tiempo y memoria de cada operación antes de delegar
 * a la clase F subyacente.
 * 
 * Esto permite que F se mantenga limpio y optimizado para velocidad, mientras
 * Fm se encarga exclusivamente de la telemetría.
 */
class Fm implements FinalizerInterface
{
    private F $finalizer;
    
    private static array $metrics = [];
    private static int $callCount = 0;
    private static bool $enabled = true;
    
    /**
     * Constructor que envuelve una instancia de F.
     */
    public function __construct(F $finalizer)
    {
        $this->finalizer = $finalizer;
    }
    
    /**
     * Crea una instancia Fm desde una instancia B.
     */
    public static function fromBuilder(B $builder): self
    {
        return new self(new F($builder->getState()));
    }
    
    /**
     * Habilita o deshabilita la captura de métricas.
     */
    public static function setEnabled(bool $enabled): void
    {
        self::$enabled = $enabled;
    }
    
    /**
     * Obtiene todas las métricas capturadas.
     */
    public static function getMetrics(): array
    {
        return self::$metrics;
    }
    
    /**
     * Obtiene estadísticas consolidadas.
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
     * Limpia las métricas acumuladas.
     */
    public static function clearMetrics(): void
    {
        self::$metrics = [];
        self::$callCount = 0;
    }
    
    /**
     * Ejecuta SELECT con métricas.
     */
    public function select($fields = '*'): array
    {
        return $this->wrap('select', function() use ($fields) {
            return $this->finalizer->select($fields);
        });
    }
    
    /**
     * Ejecuta DELETE con métricas.
     */
    public function delete(): array
    {
        return $this->wrap('delete', function() {
            return $this->finalizer->delete();
        });
    }
    
    /**
     * Ejecuta UPDATE con métricas.
     */
    public function update(array $data): array
    {
        return $this->wrap('update', function() use ($data) {
            return $this->finalizer->update($data);
        });
    }
    
    /**
     * Ejecuta COUNT con métricas.
     */
    public function count($field = '*'): array
    {
        return $this->wrap('count', function() use ($field) {
            return $this->finalizer->count($field);
        });
    }
    
    /**
     * Ejecuta EXISTS con métricas.
     */
    public function exists(): array
    {
        return $this->wrap('exists', function() {
            return $this->finalizer->exists();
        });
    }
    
    /**
     * Wrapper genérico para capturar métricas de cualquier operación.
     */
    private function wrap(string $operation, callable $callback): array
    {
        if (!self::$enabled) {
            return $callback();
        }
        
        $startTime = microtime(true);
        $startMem = memory_get_usage();
        
        // Ejecutar la operación real delegando a F
        $result = $callback();
        
        $endTime = microtime(true);
        $endMem = memory_get_usage();
        
        $timeMs = ($endTime - $startTime) * 1000;
        $memBytes = $endMem - $startMem;
        
        // Registrar métrica
        self::$metrics[] = [
            'id' => ++self::$callCount,
            'operation' => $operation,
            'time_ms' => round($timeMs, 4),
            'mem_bytes' => $memBytes,
            'sql_len' => strlen($result[0]),
            'sql_preview' => substr($result[0], 0, 100),
            'timestamp' => microtime(true)
        ];
        
        return $result;
    }
}
