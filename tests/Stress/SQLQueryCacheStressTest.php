<?php

/**
 * Prueba de Stress para el sistema de caché de consultas SQL (L3)
 * 
 * Esta prueba compara el rendimiento con y sin el caché de consultas activado,
 * midiendo la reducción en tiempo de generación de SQL y el impacto en consultas
 * complejas con múltiples JOINs.
 */

// Cargamos las dependencias en el orden correcto
require_once __DIR__ . '/../../src/RapidBase/Core/SQL.php';
require_once __DIR__ . '/../../src/RapidBase/Core/DB.php';
require_once __DIR__ . '/../../src/RapidBase/Core/Executor.php';
require_once __DIR__ . '/../../src/RapidBase/Core/Gateway.php';

use RapidBase\Core\SQL;
use RapidBase\Core\Gateway;

class StressTest
{
    private $iterations = 1000;
    private $complexJoins = 5;
    
    public function run()
    {
        echo "=== Prueba de Stress: Caché de Consultas SQL (L3) ===\n\n";
        
        // Configuración inicial
        $this->setup();
        
        // Ejecutar pruebas
        $resultsWithoutCache = $this->runTest(false);
        $resultsWithCache = $this->runTest(true);
        
        // Mostrar resultados
        $this->displayResults($resultsWithoutCache, $resultsWithCache);
        
        // Limpieza
        $this->cleanup();
    }
    
    private function setup()
    {
        echo "Configurando entorno de prueba...\n";
        
        // Limpiar cachés existentes
        SQL::clearQueryCache();
        SQL::setQueryCacheEnabled(false);
        SQL::setQueryCacheMaxSize(1000);
        
        echo "Entorno listo.\n\n";
    }
    
    private function runTest(bool $cacheEnabled): array
    {
        echo "Ejecutando prueba con caché " . ($cacheEnabled ? "ACTIVADO" : "DESACTIVADO") . "...\n";
        
        SQL::setQueryCacheEnabled($cacheEnabled);
        SQL::clearQueryCache();
        
        $startTime = microtime(true);
        $memoryStart = memory_get_usage();
        
        $successCount = 0;
        $failCount = 0;
        $totalBuildTime = 0;
        
        // Configurar mapa de relaciones para JOINs automáticos
        SQL::setRelationsMap([
            'from' => [
                'users' => [
                    'profiles' => ['local_key' => 'id', 'foreign_key' => 'user_id'],
                    'orders' => ['local_key' => 'id', 'foreign_key' => 'user_id'],
                ],
                'orders' => [
                    'products' => ['local_key' => 'product_id', 'foreign_key' => 'id'],
                ],
                'products' => [
                    'categories' => ['local_key' => 'category_id', 'foreign_key' => 'id'],
                    'reviews' => ['local_key' => 'id', 'foreign_key' => 'product_id'],
                ],
            ]
        ]);
        
        for ($i = 0; $i < $this->iterations; $i++) {
            try {
                $buildStart = microtime(true);
                
                // Construir consulta compleja con múltiples JOINs usando buildSelect
                SQL::reset();
                $result = SQL::buildSelect(
                    ['users.id', 'users.name', 'users.email'],
                    ['users', 'profiles', 'orders', 'products', 'categories', 'reviews'],
                    [
                        'users.active' => 1,
                        'orders.status' => ['IN' => ['pending', 'completed']]
                    ],
                    [],
                    [],
                    ['users.created_at' => 'DESC'],
                    100,
                    $i * 100
                );
                
                $query = $result[0];
                $params = $result[1];
                
                $buildEnd = microtime(true);
                $totalBuildTime += ($buildEnd - $buildStart);
                
                $successCount++;
                
                // Simular variabilidad en los parámetros (cada 10 iteraciones)
                if ($i % 10 === 0 || $i % 10 === 5) {
                    SQL::reset();
                    $country = ($i % 10 === 0) ? 'US' : 'ES';
                    $result = SQL::buildSelect(
                        ['users.id', 'users.name', 'users.email'],
                        ['users', 'profiles', 'orders', 'products', 'categories', 'reviews'],
                        [
                            'users.active' => 1,
                            'orders.status' => ['IN' => ['pending', 'completed']],
                            'users.country' => $country
                        ],
                        [],
                        [],
                        ['users.created_at' => 'DESC'],
                        100,
                        $i * 100
                    );
                }
                
            } catch (Exception $e) {
                $failCount++;
                echo "Error en iteración $i: " . $e->getMessage() . "\n";
            }
            
            // Progreso cada 100 iteraciones
            if (($i + 1) % 100 === 0) {
                echo "  Progreso: " . ($i + 1) . "/{$this->iterations}\n";
            }
        }
        
        $endTime = microtime(true);
        $memoryEnd = memory_get_usage();
        
        $stats = SQL::getQueryCacheStats();
        
        return [
            'cache_enabled' => $cacheEnabled,
            'total_time' => $endTime - $startTime,
            'avg_build_time' => $totalBuildTime / $this->iterations,
            'success_count' => $successCount,
            'fail_count' => $failCount,
            'memory_used' => $memoryEnd - $memoryStart,
            'cache_stats' => $stats,
            'cache_hit_rate' => $stats['hits'] > 0 ? ($stats['hits'] / ($stats['hits'] + $stats['misses'])) * 100 : 0
        ];
    }
    
    private function displayResults(array $withoutCache, array $withCache)
    {
        echo "\n";
        echo "=== RESULTADOS ===\n\n";
        
        echo "--- Sin Caché L3 ---\n";
        echo "Tiempo total: " . number_format($withoutCache['total_time'], 4) . " segundos\n";
        echo "Tiempo promedio por consulta: " . number_format($withoutCache['avg_build_time'] * 1000, 4) . " ms\n";
        echo "Consultas exitosas: {$withoutCache['success_count']}\n";
        echo "Consultas fallidas: {$withoutCache['fail_count']}\n";
        echo "Memoria utilizada: " . number_format($withoutCache['memory_used'] / 1024, 2) . " KB\n";
        echo "\n";
        
        echo "--- Con Caché L3 ---\n";
        echo "Tiempo total: " . number_format($withCache['total_time'], 4) . " segundos\n";
        echo "Tiempo promedio por consulta: " . number_format($withCache['avg_build_time'] * 1000, 4) . " ms\n";
        echo "Consultas exitosas: {$withCache['success_count']}\n";
        echo "Consultas fallidas: {$withCache['fail_count']}\n";
        echo "Memoria utilizada: " . number_format($withCache['memory_used'] / 1024, 2) . " KB\n";
        echo "Hits en caché: {$withCache['cache_stats']['hits']}\n";
        echo "Misses en caché: {$withCache['cache_stats']['misses']}\n";
        echo "Tasa de acierto: " . number_format($withCache['cache_hit_rate'], 2) . "%\n";
        echo "\n";
        
        // Cálculo de mejora
        $timeImprovement = (($withoutCache['total_time'] - $withCache['total_time']) / $withoutCache['total_time']) * 100;
        $buildTimeImprovement = (($withoutCache['avg_build_time'] - $withCache['avg_build_time']) / $withoutCache['avg_build_time']) * 100;
        
        echo "--- MEJORA DE RENDIMIENTO ---\n";
        echo "Mejora en tiempo total: " . number_format($timeImprovement, 2) . "%\n";
        echo "Mejora en tiempo de construcción: " . number_format($buildTimeImprovement, 2) . "%\n";
        echo "Ahorro estimado por consulta: " . number_format(($withoutCache['avg_build_time'] - $withCache['avg_build_time']) * 1000, 4) . " ms\n";
        echo "\n";
        
        // Recomendación
        echo "--- RECOMENDACIÓN ---\n";
        if ($timeImprovement > 20) {
            echo "✓ El caché L3 proporciona una mejora significativa. Se recomienda su uso en producción.\n";
        } elseif ($timeImprovement > 5) {
            echo "⚠ El caché L3 proporciona una mejora moderada. Evaluar según la carga de la aplicación.\n";
        } else {
            echo "ℹ La mejora es mínima. Considerar activar solo para consultas muy complejas o alta concurrencia.\n";
        }
    }
    
    private function cleanup()
    {
        echo "\nLimpiando entorno...\n";
        SQL::clearQueryCache();
        SQL::setQueryCacheEnabled(false);
        echo "Limpieza completada.\n";
    }
}

// Ejecutar la prueba
$test = new StressTest();
$test->run();
