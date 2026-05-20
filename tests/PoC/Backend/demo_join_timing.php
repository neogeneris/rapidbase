<?php

require_once __DIR__ . '/Backend.php';
require_once __DIR__ . '/JsonBackend.php';

use Tests\PoC\Backend\JsonBackend;

$testDir = sys_get_temp_dir() . '/timing_demo_' . uniqid();
@mkdir($testDir, 0755, true);

echo "=== DEMOSTRACIÓN DE TIEMPOS REALES ===\n\n";

// Insertar 1000 usuarios
echo "Insertando 1000 usuarios...\n";
$usersBackend = new JsonBackend($testDir);
$users = [];
for ($i = 1; $i <= 1000; $i++) {
    $users[] = ['name' => "Usuario $i", 'email' => "user$i@test.com", 'age' => rand(18, 80)];
}
$userIds = $usersBackend::into('users')->insert($users);
$time = $usersBackend::into('users')->getExecutionTime();
echo "Tiempo INSERT 1000 usuarios: " . number_format($time * 1000, 6) . " ms\n";
echo "IDs insertados: " . count($userIds) . "\n\n";

// Select de usuarios
echo "Seleccionando 1000 usuarios...\n";
$results = $usersBackend::from('users')->select('*');
$time = $usersBackend::from('users')->getExecutionTime();
echo "Tiempo SELECT 1000 usuarios: " . number_format($time * 1000, 6) . " ms\n";
echo "Registros encontrados: " . count($results) . "\n\n";

// Crear orders con user_id válidos
echo "Creando 500 orders con referencias a usuarios...\n";
$ordersBackend = new JsonBackend($testDir);
$orders = [];
for ($i = 0; $i < 500; $i++) {
    // Usar IDs reales (1-1000)
    $validUserId = ($i % 1000) + 1;
    $orders[] = ['product' => "Producto $i", 'user_id' => $validUserId, 'amount' => rand(10, 500)];
}
$orderIds = $ordersBackend::into('orders')->insert($orders);
$time = $ordersBackend::into('orders')->getExecutionTime();
echo "Tiempo INSERT 500 orders: " . number_format($time * 1000, 6) . " ms\n\n";

// Ejecutar JOIN con SQLite
echo "Ejecutando JOIN users INNER JOIN orders (SQLite)...\n";
$joinResults = $usersBackend::from('users')
    ->join('orders', 'id', 'user_id', 'INNER')
    ->get();
$timeSqlite = $usersBackend::from('users')->getExecutionTime();
echo "Tiempo JOIN SQLite: " . number_format($timeSqlite * 1000, 6) . " ms\n";
echo "Resultados del JOIN SQLite: " . count($joinResults) . " registros\n";

if (count($joinResults) > 0 && count($joinResults) <= 5) {
    foreach ($joinResults as $row) {
        echo "  - {$row['name']}: {$row['product']} (\${$row['amount']})\n";
    }
} elseif (count($joinResults) > 5) {
    echo "  (Mostrando primeros 5 resultados...)\n";
    for ($i = 0; $i < 5; $i++) {
        $row = $joinResults[$i];
        echo "  - {$row['name']}: {$row['product']} (\${$row['amount']})\n";
    }
}

echo "\n";

// Ejecutar JOIN nativo
echo "Ejecutando JOIN NATIVO (Hash Join en PHP)...\n";
$joinNativeResults = $usersBackend::from('users')
    ->joinNative('orders', 'id', 'user_id', 'INNER')
    ->get();
$timeNative = $usersBackend::from('users')->getExecutionTime();
echo "Tiempo JOIN NATIVO: " . number_format($timeNative * 1000, 6) . " ms\n";
echo "Resultados del JOIN NATIVO: " . count($joinNativeResults) . " registros\n";

if (count($joinNativeResults) > 0 && count($joinNativeResults) <= 5) {
    foreach ($joinNativeResults as $row) {
        echo "  - {$row['name']}: {$row['product']} (\${$row['amount']})\n";
    }
} elseif (count($joinNativeResults) > 5) {
    echo "  (Mostrando primeros 5 resultados...)\n";
    for ($i = 0; $i < 5; $i++) {
        $row = $joinNativeResults[$i];
        echo "  - {$row['name']}: {$row['product']} (\${$row['amount']})\n";
    }
}

// Comparativa final
echo "\n=== COMPARATIVA DE RENDIMIENTO ===\n";
$timeSqliteMs = $timeSqlite * 1000;
$timeNativeMs = $timeNative * 1000;
echo "JOIN SQLite:   " . number_format($timeSqliteMs, 6) . " ms\n";
echo "JOIN NATIVO:   " . number_format($timeNativeMs, 6) . " ms\n";

if ($timeNative > 0 && $timeSqlite > 0) {
    $speedup = $timeSqlite / $timeNative;
    if ($speedup > 1) {
        echo "Speedup:       " . number_format($speedup, 2) . "x más rápido con NATIVO\n";
    } else {
        echo "Speedup:       " . number_format(1/$speedup, 2) . "x más rápido con SQLite\n";
    }
} else {
    echo "Speedup:       Ambos métodos son extremadamente rápidos (< 1μs)\n";
}

// Cleanup
array_map('unlink', glob($testDir . '/*.json'));
rmdir($testDir);
echo "\n✓ Limpieza completada\n";
