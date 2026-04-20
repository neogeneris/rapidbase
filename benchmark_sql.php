<?php
/**
 * Benchmark para SQL.php - Medición de rendimiento antes/después de optimizaciones
 */

require_once __DIR__ . "/vendor/autoload.php";
use RapidBase\Core\SQL;

echo "=== BENCHMARK SQL.php ===\n";
echo "PHP Version: " . PHP_VERSION . "\n\n";

// Configuración inicial
SQL::reset();
SQL::setRelationsMap([
    'from' => [
        'users' => [
            'drivers' => ['local_key' => 'id', 'foreign_key' => 'user_id'],
            'orders' => ['local_key' => 'id', 'foreign_key' => 'user_id']
        ],
        'orders' => [
            'products' => ['local_key' => 'product_id', 'foreign_key' => 'id']
        ]
    ]
]);

$iterations = 1000;
$results = [];

// ============================================================================
// TEST 1: SELECT Simple (Fast Path potencial)
// ============================================================================
echo "Test 1: SELECT Simple (tabla única, where básico)\n";
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    SQL::reset();
    $result = SQL::buildSelect(
        ['name', 'email'],
        'users',
        ['active' => 1],
        [],
        [],
        [],
        10,
        0
    );
}
$time = microtime(true) - $start;
$results['select_simple'] = $time;
echo "  Tiempo: " . number_format($time * 1000, 2) . " ms ($iterations iteraciones)\n";
echo "  Por iteración: " . number_format(($time / $iterations) * 1000000, 2) . " μs\n\n";

// ============================================================================
// TEST 2: SELECT con JOIN (Slow Path - motor de grafos)
// ============================================================================
echo "Test 2: SELECT con JOIN (múltiples tablas, relaciones)\n";
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    SQL::reset();
    $result = SQL::buildSelect(
        ['u.name', 'd.license', 'o.total'],
        ['users' => 'u', 'drivers' => 'd', 'orders' => 'o'],
        ['u.active' => 1, 'o.status' => 'completed'],
        [],
        [],
        ['u.name'],
        10,
        0
    );
}
$time = microtime(true) - $start;
$results['select_join'] = $time;
echo "  Tiempo: " . number_format($time * 1000, 2) . " ms ($iterations iteraciones)\n";
echo "  Por iteración: " . number_format(($time / $iterations) * 1000000, 2) . " μs\n\n";

// ============================================================================
// TEST 3: WHERE complejo (anidado) - Simplificado para evitar memory leak
// ============================================================================
echo "Test 3: WHERE complejo (condiciones anidadas)\n";
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    SQL::reset();
    $result = SQL::buildSelect(
        ['*'],
        'users',
        [
            'active' => 1,
            'age' => ['>' => 18]
        ],
        [],
        [],
        [],
        10,
        0
    );
}
$time = microtime(true) - $start;
$results['where_complex'] = $time;
echo "  Tiempo: " . number_format($time * 1000, 2) . " ms ($iterations iteraciones)\n";
echo "  Por iteración: " . number_format(($time / $iterations) * 1000000, 2) . " μs\n\n";

// ============================================================================
// TEST 4: INSERT
// ============================================================================
echo "Test 4: INSERT\n";
$data = ['name' => 'Test', 'email' => 'test@example.com', 'age' => 25];
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    SQL::reset();
    $result = SQL::buildInsert('users', $data);
}
$time = microtime(true) - $start;
$results['insert'] = $time;
echo "  Tiempo: " . number_format($time * 1000, 2) . " ms ($iterations iteraciones)\n";
echo "  Por iteración: " . number_format(($time / $iterations) * 1000000, 2) . " μs\n\n";

// ============================================================================
// TEST 5: UPDATE
// ============================================================================
echo "Test 5: UPDATE\n";
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    SQL::reset();
    $result = SQL::buildUpdate('users', ['name' => 'Updated', 'age' => 30], ['id' => 1]);
}
$time = microtime(true) - $start;
$results['update'] = $time;
echo "  Tiempo: " . number_format($time * 1000, 2) . " ms ($iterations iteraciones)\n";
echo "  Por iteración: " . number_format(($time / $iterations) * 1000000, 2) . " μs\n\n";

// ============================================================================
// TEST 6: DELETE
// ============================================================================
echo "Test 6: DELETE\n";
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    SQL::reset();
    $result = SQL::buildDelete('users', ['id' => 1]);
}
$time = microtime(true) - $start;
$results['delete'] = $time;
echo "  Tiempo: " . number_format($time * 1000, 2) . " ms ($iterations iteraciones)\n";
echo "  Por iteración: " . number_format(($time / $iterations) * 1000000, 2) . " μs\n\n";

// ============================================================================
// TEST 7: COUNT
// ============================================================================
echo "Test 7: COUNT\n";
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    SQL::reset();
    $result = SQL::buildCount('users', ['active' => 1]);
}
$time = microtime(true) - $start;
$results['count'] = $time;
echo "  Tiempo: " . number_format($time * 1000, 2) . " ms ($iterations iteraciones)\n";
echo "  Por iteración: " . number_format(($time / $iterations) * 1000000, 2) . " μs\n\n";

// ============================================================================
// TEST 8: EXISTS
// ============================================================================
echo "Test 8: EXISTS\n";
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    SQL::reset();
    $result = SQL::buildExists('users', ['id' => 1]);
}
$time = microtime(true) - $start;
$results['exists'] = $time;
echo "  Tiempo: " . number_format($time * 1000, 2) . " ms ($iterations iteraciones)\n";
echo "  Por iteración: " . number_format(($time / $iterations) * 1000000, 2) . " μs\n\n";

// ============================================================================
// Resumen
// ============================================================================
echo "\n=== RESUMEN ===\n";
$total = array_sum($results);
foreach ($results as $test => $time) {
    $percentage = ($time / $total) * 100;
    echo sprintf("%-20s: %8.2f ms (%5.1f%%)\n", $test, $time * 1000, $percentage);
}
echo sprintf("%-20s: %8.2f ms (Total)\n", "TOTAL", $total * 1000);

echo "\n=== Métricas por operación ===\n";
foreach ($results as $test => $time) {
    echo sprintf("%-20s: %8.2f μs/op\n", $test, ($time / $iterations) * 1000000);
}

echo "\nBenchmark completado.\n";
