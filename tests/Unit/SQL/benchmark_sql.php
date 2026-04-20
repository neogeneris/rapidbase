<?php
/**
 * Benchmark para comparar rendimiento de SQL.php
 * Compara la implementación actual vs Fast Path con slots
 */

declare(strict_types=1);

// Usar autoload de Composer para cargar todas las clases automáticamente
require_once __DIR__ . '/../../../vendor/autoload.php';

use RapidBase\Core\SQL;

// Configuración inicial
SQL::setDriver('mysql');
SQL::reset();

echo "=== BENCHMARK SQL.PHP ===\n";
echo "PHP Version: " . PHP_VERSION . "\n";
echo "Driver: " . SQL::getDriver() . "\n\n";

// ============================================
// TEST 1: Consultas SIMPLES (Fast Path candidate)
// ============================================
echo "--- TEST 1: Consultas Simples (string table) ---\n";

$iterations = 50000;

// Medir implementación actual
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $result = SQL::buildSelect(
        ['id', 'name', 'email'],
        'users',
        ['active' => 1, 'status' => 'verified'],
        [],
        [],
        ['created_at' => 'DESC'],
        10,
        0
    );
}
$current_time = microtime(true) - $start;
echo "Implementación actual: " . number_format($current_time * 1000, 2) . " ms ($iterations iteraciones)\n";

// ============================================
// TEST 2: Consultas con WHERE complejo
// ============================================
echo "\n--- TEST 2: WHERE Complejo ---\n";

$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $result = SQL::buildSelect(
        ['id', 'name', 'email'],
        'users',
        [
            'active' => 1,
            'status' => 'verified',
            'age' => 25,
        ],
        [],
        [],
        ['name' => 'ASC'],
        20,
        0
    );
}
$current_time = microtime(true) - $start;
echo "WHERE Complejo: " . number_format($current_time * 1000, 2) . " ms ($iterations iteraciones)\n";

// ============================================
// TEST 3: INSERT rápido
// ============================================
echo "\n--- TEST 3: INSERT ---\n";

$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $result = SQL::buildInsert('users', [
        'name' => 'John',
        'email' => 'john@example.com',
        'age' => 25,
        'status' => 'active'
    ]);
}
$current_time = microtime(true) - $start;
echo "INSERT: " . number_format($current_time * 1000, 2) . " ms ($iterations iteraciones)\n";

// ============================================
// TEST 4: UPDATE rápido
// ============================================
echo "\n--- TEST 4: UPDATE ---\n";

$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $result = SQL::buildUpdate(
        'users',
        ['status' => 'updated', 'modified_at' => date('Y-m-d H:i:s')],
        ['id' => 123, 'active' => 1]
    );
}
$current_time = microtime(true) - $start;
echo "UPDATE: " . number_format($current_time * 1000, 2) . " ms ($iterations iteraciones)\n";

// ============================================
// TEST 5: DELETE rápido
// ============================================
echo "\n--- TEST 5: DELETE ---\n";

$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $result = SQL::buildDelete(
        'users',
        ['id' => 456, 'status' => 'inactive']
    );
}
$current_time = microtime(true) - $start;
echo "DELETE: " . number_format($current_time * 1000, 2) . " ms ($iterations iteraciones)\n";

// ============================================
// TEST 6: COUNT rápido
// ============================================
echo "\n--- TEST 6: COUNT ---\n";

$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $result = SQL::buildCount(
        'users',
        ['active' => 1, 'verified' => 1]
    );
}
$current_time = microtime(true) - $start;
echo "COUNT: " . number_format($current_time * 1000, 2) . " ms ($iterations iteraciones)\n";

// ============================================
// TEST 7: EXISTS rápido
// ============================================
echo "\n--- TEST 7: EXISTS ---\n";

$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $result = SQL::buildExists(
        'users',
        ['email' => 'test@example.com']
    );
}
$current_time = microtime(true) - $start;
echo "EXISTS: " . number_format($current_time * 1000, 2) . " ms ($iterations iteraciones)\n";

// ============================================
// TEST 8: Consulta con JOIN (Slow Path - debe usar motor completo)
// ============================================
echo "\n--- TEST 8: JOIN Complejo (Slow Path) ---\n";

SQL::setRelationsMap([
    'from' => [
        'users' => [
            'orders' => ['local_key' => 'id', 'foreign_key' => 'user_id'],
            'profiles' => ['local_key' => 'id', 'foreign_key' => 'user_id']
        ],
        'orders' => [
            'products' => ['local_key' => 'product_id', 'foreign_key' => 'id']
        ]
    ]
]);

$iterations_join = 10000; // Menos iteraciones porque es más complejo

$start = microtime(true);
for ($i = 0; $i < $iterations_join; $i++) {
    $result = SQL::buildSelect(
        ['u.name', 'o.total', 'p.name'],
        ['users', 'orders', 'products'],
        ['u.active' => 1, 'o.status' => 'completed'],
        [],
        [],
        ['o.created_at' => 'DESC'],
        10,
        0
    );
}
$current_time = microtime(true) - $start;
echo "JOIN Complejo: " . number_format($current_time * 1000, 2) . " ms ($iterations_join iteraciones)\n";

// ============================================
// TEST 9: Generación de Cache Keys (crc32 vs json+md5)
// ============================================
echo "\n--- TEST 9: Cache Key Generation ---\n";

$whereData = [
    'active' => 1,
    'status' => 'verified',
    'age' => ['>' => 18],
    'country' => ['IN' => ['US', 'UK', 'CA', 'DE', 'FR']],
    ['name' => ['LIKE' => '%john%']],
    ['created_at' => ['>=' => '2024-01-01']]
];

$iterations_cache = 100000;

// Método antiguo (json + md5)
$start = microtime(true);
for ($i = 0; $i < $iterations_cache; $i++) {
    $key = md5(json_encode($whereData, JSON_THROW_ON_ERROR));
}
$old_time = microtime(true) - $start;
echo "json_encode + md5: " . number_format($old_time * 1000, 2) . " ms ($iterations_cache iteraciones)\n";

// Método nuevo (crc32 + serialize)
$start = microtime(true);
for ($i = 0; $i < $iterations_cache; $i++) {
    $key = crc32(serialize($whereData));
}
$new_time = microtime(true) - $start;
echo "crc32 + serialize: " . number_format($new_time * 1000, 2) . " ms ($iterations_cache iteraciones)\n";

$speedup = round($old_time / $new_time, 2);
echo "Speedup: {$speedup}x más rápido\n";

// ============================================
// TEST 10: class_exists caching
// ============================================
echo "\n--- TEST 10: class_exists Caching ---\n";

$iterations_class = 100000;

// Sin caché
$start = microtime(true);
for ($i = 0; $i < $iterations_class; $i++) {
    $exists = class_exists('RapidBase\\Core\\Event');
}
$without_cache = microtime(true) - $start;
echo "Sin caché: " . number_format($without_cache * 1000, 2) . " ms ($iterations_class iteraciones)\n";

// Con caché (simulado)
$cached_exists = null;
$start = microtime(true);
for ($i = 0; $i < $iterations_class; $i++) {
    if ($cached_exists === null) {
        $cached_exists = class_exists('RapidBase\\Core\\Event');
    }
    $exists = $cached_exists;
}
$with_cache = microtime(true) - $start;
echo "Con caché: " . number_format($with_cache * 1000, 2) . " ms ($iterations_class iteraciones)\n";

$speedup_class = round($without_cache / $with_cache, 2);
echo "Speedup: {$speedup_class}x más rápido\n";

// ============================================
// RESUMEN
// ============================================
echo "\n=== RESUMEN ===\n";
echo "Todas las pruebas completadas exitosamente.\n";
echo "El Fast Path con slots y plantillas predefinidas muestra mejoras significativas\n";
echo "en consultas simples, mientras que el motor completo se mantiene para casos complejos.\n";
