<?php

// Carga manual de clases para el benchmark
require_once __DIR__ . '/B.php';
require_once __DIR__ . '/FinalizerInterface.php';
require_once __DIR__ . '/F.php';
require_once __DIR__ . '/Fm.php';

use RapidBase\Core\B;
use RapidBase\Core\F;
use RapidBase\Core\Fm;

echo "=== Benchmark: F (puro) vs Fm (con métricas) ===\n\n";

$iterations = 10000;

// ==========================================
// PRUEBA CON F (sin métricas)
// ==========================================
echo "Ejecutando {$iterations} iteraciones con F (sin métricas)...\n";
$startTotal = microtime(true);
$startMem = memory_get_usage();

for ($i = 0; $i < $iterations; $i++) {
    $sql = F::fromBuilder(
        B::from('users', ['status' => 'active'])
         ->orderBy('name')
         ->limit(10)
    )->select('id, name, email');
}

$endTotal = microtime(true);
$endMem = memory_get_usage();
$timeF = ($endTotal - $startTotal) * 1000;
$memF = $endMem - $startMem;

echo "✓ F completado\n";
echo "  Tiempo total: " . number_format($timeF, 2) . " ms\n";
echo "  Tiempo promedio: " . number_format($timeF / $iterations, 4) . " ms/op\n";
echo "  Memoria usada: " . number_format($memF) . " bytes\n\n";

// ==========================================
// PRUEBA CON Fm (con métricas)
// ==========================================
echo "Ejecutando {$iterations} iteraciones con Fm (con métricas)...\n";
Fm::clearMetrics();
$startTotal = microtime(true);
$startMem = memory_get_usage();

for ($i = 0; $i < $iterations; $i++) {
    $sql = Fm::fromBuilder(
        B::from('users', ['status' => 'active'])
         ->orderBy('name')
         ->limit(10)
    )->select('id, name, email');
}

$endTotal = microtime(true);
$endMem = memory_get_usage();
$timeFm = ($endTotal - $startTotal) * 1000;
$memFm = $endMem - $startMem;

echo "✓ Fm completado\n";
echo "  Tiempo total: " . number_format($timeFm, 2) . " ms\n";
echo "  Tiempo promedio: " . number_format($timeFm / $iterations, 4) . " ms/op\n";
echo "  Memoria usada: " . number_format($memFm) . " bytes\n\n";

// ==========================================
// COMPARACIÓN
// ==========================================
$overhead = (($timeFm - $timeF) / $timeF) * 100;
$memOverhead = (($memFm - $memF) / $memF) * 100;

echo "=== RESULTADOS DE COMPARACIÓN ===\n";
echo "Overhead de tiempo de Fm vs F: " . number_format($overhead, 2) . "%\n";
echo "Overhead de memoria de Fm vs F: " . number_format($memOverhead, 2) . "%\n\n";

// ==========================================
// MÉTRICAS CAPTURADAS POR Fm
// ==========================================
$stats = Fm::getStats();
echo "=== ESTADÍSTICAS CAPTURADAS POR Fm ===\n";
echo "Total de llamadas: {$stats['calls']}\n";
echo "Tiempo total capturado: {$stats['total_time_ms']} ms\n";
echo "Tiempo promedio: {$stats['avg_time_ms']} ms/op\n";
echo "Memoria total: {$stats['total_mem_bytes']} bytes\n";
echo "Memoria promedio: {$stats['avg_mem_bytes']} bytes/op\n\n";

// ==========================================
// EJEMPLO DE USO
// ==========================================
echo "=== EJEMPLO DE USO ===\n\n";

echo "1. Uso de F (rápido, sin métricas):\n";
$sql = F::fromBuilder(
    B::from('users', ['id' => 1])
)->select('name, email');
echo "   SQL: {$sql[0]}\n";
echo "   Params: " . json_encode($sql[1]) . "\n\n";

echo "2. Uso de Fm (con métricas):\n";
$sql = Fm::fromBuilder(
    B::from('users', ['id' => 1])
)->select('name, email');
echo "   SQL: {$sql[0]}\n";
echo "   Params: " . json_encode($sql[1]) . "\n\n";

echo "3. Métricas después de una operación:\n";
$stats = Fm::getStats();
echo "   Llamadas: {$stats['calls']}\n";
echo "   Última operación: " . number_format($stats['avg_time_ms'], 4) . " ms\n\n";

echo "4. Reutilización del mismo builder:\n";
$builder = B::from('products', ['category' => 'electronics'])->orderBy('price');
echo "   Count: " . json_encode(Fm::fromBuilder($builder)->count()) . "\n";
echo "   Select: " . json_encode(Fm::fromBuilder($builder)->select('id, name')) . "\n";
echo "   Exists: " . json_encode(Fm::fromBuilder($builder)->exists()) . "\n\n";

echo "=== PRUEBA COMPLETADA ===\n";
