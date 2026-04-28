<?php
/**
 * Benchmark: SQL.php vs Wm.php
 * Comparativa de rendimiento entre la clase SQL tradicional y la nueva clase Wm (con métricas)
 * 
 * NOTA: Este benchmark mide SOLO la generación de consultas SQL (sin ejecución real)
 */

require_once __DIR__ . '/../../../src/RapidBase/Core/DBInterface.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/DB.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/SQL.php';
require_once __DIR__ . '/W.php';
require_once __DIR__ . '/Wm.php';

use RapidBase\Core\SQL;
use RapidBase\Core\W;
use RapidBase\Core\Wm;

// Configuración
$iterations = 1000;

echo "=== BENCHMARK: SQL.php vs Wm.php (Generación de SQL) ===\n";
echo "Iteraciones: $iterations\n";
echo "Nota: Solo se mide generación de SQL, sin ejecución real\n\n";

$results = [];

// ============================================================================
// TEST 1: SELECT simple con WHERE
// ============================================================================
echo "TEST 1: SELECT simple con WHERE\n";
echo str_repeat('-', 60) . "\n";

// SQL.php
$start = microtime(true);
$memStart = memory_get_usage(true);
for ($i = 0; $i < $iterations; $i++) {
    [$sql, $params] = SQL::buildSelect('*', 'users', ['id' => 1], [], [], [], 0, false);
}
$memEnd = memory_get_usage(true);
$timeSql = microtime(true) - $start;
$memSql = $memEnd - $memStart;
$results['SQL_simple'] = ['time' => $timeSql, 'memory' => $memSql];

echo "SQL.php:  " . number_format($timeSql * 1000, 3) . " ms | Memory: " . number_format($memSql / 1024, 2) . " KB\n";

// Wm.php
$start = microtime(true);
$memStart = memory_get_usage(true);
for ($i = 0; $i < $iterations; $i++) {
    [$sql, $params] = Wm::from('users')->select('*', ['id' => 1]);
}
$memEnd = memory_get_usage(true);
$timeWm = microtime(true) - $start;
$memWm = $memEnd - $memStart;
$results['Wm_simple'] = ['time' => $timeWm, 'memory' => $memWm];

echo "Wm.php:   " . number_format($timeWm * 1000, 3) . " ms | Memory: " . number_format($memWm / 1024, 2) . " KB\n";

$speedup = $timeSql / $timeWm;
echo "Speedup:  " . number_format($speedup, 2) . "x " . ($speedup > 1 ? "(Wm más rápido)" : "(SQL más rápido)") . "\n\n";

// ============================================================================
// TEST 2: SELECT con LIMIT y OFFSET
// ============================================================================
echo "TEST 2: SELECT con LIMIT y OFFSET\n";
echo str_repeat('-', 60) . "\n";

// SQL.php
$start = microtime(true);
$memStart = memory_get_usage(true);
for ($i = 0; $i < $iterations; $i++) {
    [$sql, $params] = SQL::buildSelect('*', 'users', [], [], [], [], 3, 10, false);
}
$memEnd = memory_get_usage(true);
$timeSql = microtime(true) - $start;
$memSql = $memEnd - $memStart;
$results['SQL_limit'] = ['time' => $timeSql, 'memory' => $memSql];

echo "SQL.php:  " . number_format($timeSql * 1000, 3) . " ms | Memory: " . number_format($memSql / 1024, 2) . " KB\n";

// Wm.php
$start = microtime(true);
$memStart = memory_get_usage(true);
for ($i = 0; $i < $iterations; $i++) {
    [$sql, $params] = Wm::from('users')->select('*', [20, 10]); // [offset, limit]
}
$memEnd = memory_get_usage(true);
$timeWm = microtime(true) - $start;
$memWm = $memEnd - $memStart;
$results['Wm_limit'] = ['time' => $timeWm, 'memory' => $memWm];

echo "Wm.php:   " . number_format($timeWm * 1000, 3) . " ms | Memory: " . number_format($memWm / 1024, 2) . " KB\n";

$speedup = $timeSql / $timeWm;
echo "Speedup:  " . number_format($speedup, 2) . "x " . ($speedup > 1 ? "(Wm más rápido)" : "(SQL más rápido)") . "\n\n";

// ============================================================================
// TEST 3: SELECT con ORDER BY
// ============================================================================
echo "TEST 3: SELECT con ORDER BY\n";
echo str_repeat('-', 60) . "\n";

// SQL.php
$start = microtime(true);
$memStart = memory_get_usage(true);
for ($i = 0; $i < $iterations; $i++) {
    [$sql, $params] = SQL::buildSelect('*', 'users', [], [], [], ['-created_at'], 1, 10, false);
}
$memEnd = memory_get_usage(true);
$timeSql = microtime(true) - $start;
$memSql = $memEnd - $memStart;
$results['SQL_sort'] = ['time' => $timeSql, 'memory' => $memSql];

echo "SQL.php:  " . number_format($timeSql * 1000, 3) . " ms | Memory: " . number_format($memSql / 1024, 2) . " KB\n";

// Wm.php
$start = microtime(true);
$memStart = memory_get_usage(true);
for ($i = 0; $i < $iterations; $i++) {
    [$sql, $params] = Wm::from('users')->select('*', 10, '-created_at');
}
$memEnd = memory_get_usage(true);
$timeWm = microtime(true) - $start;
$memWm = $memEnd - $memStart;
$results['Wm_sort'] = ['time' => $timeWm, 'memory' => $memWm];

echo "Wm.php:   " . number_format($timeWm * 1000, 3) . " ms | Memory: " . number_format($memWm / 1024, 2) . " KB\n";

$speedup = $timeSql / $timeWm;
echo "Speedup:  " . number_format($speedup, 2) . "x " . ($speedup > 1 ? "(Wm más rápido)" : "(SQL más rápido)") . "\n\n";

// ============================================================================
// TEST 4: SELECT con múltiples tablas (JOIN implícito en where)
// ============================================================================
echo "TEST 4: SELECT con múltiples condiciones WHERE\n";
echo str_repeat('-', 60) . "\n";

// SQL.php
$start = microtime(true);
$memStart = memory_get_usage(true);
for ($i = 0; $i < $iterations; $i++) {
    $filter = ['status' => 'active', 'role' => 'admin', 'country' => 'US'];
    [$sql, $params] = SQL::buildSelect('*', 'users', $filter, [], [], [], 1, 50, false);
}
$memEnd = memory_get_usage(true);
$timeSql = microtime(true) - $start;
$memSql = $memEnd - $memStart;
$results['SQL_multi_where'] = ['time' => $timeSql, 'memory' => $memSql];

echo "SQL.php:  " . number_format($timeSql * 1000, 3) . " ms | Memory: " . number_format($memSql / 1024, 2) . " KB\n";

// Wm.php
$start = microtime(true);
$memStart = memory_get_usage(true);
for ($i = 0; $i < $iterations; $i++) {
    $filter = ['status' => 'active', 'role' => 'admin', 'country' => 'US'];
    [$sql, $params] = Wm::from('users')->select('*', 50, null, $filter);
}
$memEnd = memory_get_usage(true);
$timeWm = microtime(true) - $start;
$memWm = $memEnd - $memStart;
$results['Wm_multi_where'] = ['time' => $timeWm, 'memory' => $memWm];

echo "Wm.php:   " . number_format($timeWm * 1000, 3) . " ms | Memory: " . number_format($memWm / 1024, 2) . " KB\n";

$speedup = $timeSql / $timeWm;
echo "Speedup:  " . number_format($speedup, 2) . "x " . ($speedup > 1 ? "(Wm más rápido)" : "(SQL más rápido)") . "\n\n";

// ============================================================================
// TEST 5: Métricas de Wm (overhead de telemetría)
// ============================================================================
echo "TEST 5: Overhead de telemetría en Wm vs W\n";
echo str_repeat('-', 60) . "\n";

// W.php (sin métricas)
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    [$sql, $params] = W::from('users')->select('*', 10);
}
$timeW = microtime(true) - $start;
echo "W.php (sin métricas): " . number_format($timeW * 1000, 3) . " ms\n";

// Wm.php (con métricas)
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    [$sql, $params] = Wm::from('users')->select('*', 10);
}
$timeWm = microtime(true) - $start;
echo "Wm.php (con métricas): " . number_format($timeWm * 1000, 3) . " ms\n";

$overhead = (($timeWm - $timeW) / $timeW) * 100;
echo "Overhead de Wm: " . number_format($overhead, 2) . "%\n\n";

// ============================================================================
// RESUMEN
// ============================================================================
echo "=== RESUMEN ===\n";
echo str_repeat('=', 60) . "\n";

$totalSpeedup = 0;
$count = 0;
foreach ($results as $test => $data) {
    if (strpos($test, 'SQL') === 0) {
        $wmKey = str_replace('SQL', 'Wm', $test);
        if (isset($results[$wmKey])) {
            $speedup = $data['time'] / $results[$wmKey]['time'];
            $totalSpeedup += $speedup;
            $count++;
            echo sprintf("%-20s: %6.2fx %s\n", 
                $test, 
                $speedup,
                $speedup > 1 ? "✓ Wm más rápido" : "✗ SQL más rápido"
            );
        }
    }
}

if ($count > 0) {
    $avgSpeedup = $totalSpeedup / $count;
    echo str_repeat('-', 60) . "\n";
    echo "Speedup promedio: " . number_format($avgSpeedup, 2) . "x\n";
    
    if ($avgSpeedup > 1) {
        $improvement = ($avgSpeedup - 1) * 100;
        echo "Wm es " . number_format($improvement, 1) . "% más rápido en promedio\n";
    } else {
        $degradation = (1 - $avgSpeedup) * 100;
        echo "Wm es " . number_format($degradation, 1) . "% más lento en promedio\n";
    }
}

echo "\n=== MÉTRICAS DE Wm (última ejecución) ===\n";
$metrics = Wm::getMetrics();
if (!empty($metrics)) {
    echo "Consultas ejecutadas: " . ($metrics['count'] ?? 0) . "\n";
    echo "Tiempo total: " . number_format(($metrics['total_time'] ?? 0) * 1000, 3) . " ms\n";
    echo "Tiempo promedio: " . number_format(($metrics['avg_time'] ?? 0) * 1000, 3) . " ms\n";
    echo "Memoria peak: " . number_format(($metrics['peak_memory'] ?? 0) / 1024 / 1024, 2) . " MB\n";
}

echo "\nBenchmark completado.\n";
