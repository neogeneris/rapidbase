<?php

namespace RapidBase\SQLEngine2;

require_once __DIR__ . '/B2.php';
require_once __DIR__ . '/F2.php';

use RapidBase\SQLEngine2\B2;
use RapidBase\SQLEngine2\F2;

echo "=== Benchmark: SQLEngine2 (JoinManager Integrado) ===\n\n";

// Configuración
$iterations = 10000;

// ============================================
// TEST 1: B2 + F2 (JoinManager integrado)
// ============================================
echo "Test 1: B2 + F2 (JoinManager Integrado)\n";
echo str_repeat('-', 50) . "\n";

$startMem = memory_get_usage();
$startTime = microtime(true);

for ($i = 0; $i < $iterations; $i++) {
    // SELECT simple
    $sql = F2::fromBuilder(B2::from('users', ['status' => 'active']))->select('id, name');
    
    // SELECT con orden y límite
    $sql = F2::fromBuilder(
        B2::from('users')->orderBy('name')->limit(10)
    )->select();
    
    // DELETE
    $sql = F2::fromBuilder(B2::from('users', ['id' => 1]))->delete();
    
    // UPDATE
    $sql = F2::fromBuilder(B2::from('users', ['id' => 1]))->update(['name' => 'Test']);
    
    // COUNT
    $sql = F2::fromBuilder(B2::from('users', ['status' => 'active']))->count();
    
    // EXISTS
    $sql = F2::fromBuilder(B2::from('users', ['id' => 1]))->exists();
}

$endTime = microtime(true);
$endMem = memory_get_usage();

$timeB2_F2 = ($endTime - $startTime) * 1000; // ms
$memB2_F2 = $endMem - $startMem; // bytes

echo "Iteraciones: {$iterations}\n";
echo "Tiempo total: " . number_format($timeB2_F2, 4) . " ms\n";
echo "Tiempo por iteración: " . number_format($timeB2_F2 / $iterations, 6) . " ms\n";
echo "Memoria usada: " . number_format($memB2_F2) . " bytes\n\n";

// ============================================
// TEST 2: EB + EF (Motor fragmentado completo)
// ============================================
require_once __DIR__ . '/../SQLEngine/EB.php';
require_once __DIR__ . '/../SQLEngine/EF.php';
require_once __DIR__ . '/../SQLEngine/JoinManager.php';

use RapidBase\SQLEngine\EB;
use RapidBase\SQLEngine\EF;

echo "Test 2: EB + EF (Fragmentado Completo)\n";
echo str_repeat('-', 50) . "\n";

$startMem = memory_get_usage();
$startTime = microtime(true);

for ($i = 0; $i < $iterations; $i++) {
    // SELECT simple
    $sql = EF::fromBuilder(EB::from('users', ['status' => 'active']))->select('id, name');
    
    // SELECT con orden y límite
    $sql = EF::fromBuilder(
        EB::from('users')->orderBy('name')->limit(10)
    )->select();
    
    // DELETE
    $sql = EF::fromBuilder(EB::from('users', ['id' => 1]))->delete();
    
    // UPDATE
    $sql = EF::fromBuilder(EB::from('users', ['id' => 1]))->update(['name' => 'Test']);
    
    // COUNT
    $sql = EF::fromBuilder(EB::from('users', ['status' => 'active']))->count();
    
    // EXISTS
    $sql = EF::fromBuilder(EB::from('users', ['id' => 1]))->exists();
}

$endTime = microtime(true);
$endMem = memory_get_usage();

$timeEB_EF = ($endTime - $startTime) * 1000; // ms
$memEB_EF = $endMem - $startMem; // bytes

echo "Iteraciones: {$iterations}\n";
echo "Tiempo total: " . number_format($timeEB_EF, 4) . " ms\n";
echo "Tiempo por iteración: " . number_format($timeEB_EF / $iterations, 6) . " ms\n";
echo "Memoria usada: " . number_format($memEB_EF) . " bytes\n\n";

// ============================================
// TEST 3: B + F (Original en SQL/)
// ============================================
require_once __DIR__ . '/../SQL/FinalizerInterface.php';
require_once __DIR__ . '/../SQL/B.php';
require_once __DIR__ . '/../SQL/F.php';

use RapidBase\Core\B;
use RapidBase\Core\F;

echo "Test 3: B + F (Original)\n";
echo str_repeat('-', 50) . "\n";

$startMem = memory_get_usage();
$startTime = microtime(true);

for ($i = 0; $i < $iterations; $i++) {
    // SELECT simple
    $sql = F::fromBuilder(B::from('users', ['status' => 'active']))->select('id, name');
    
    // SELECT con orden y límite
    $sql = F::fromBuilder(
        B::from('users')->orderBy('name')->limit(10)
    )->select();
    
    // DELETE
    $sql = F::fromBuilder(B::from('users', ['id' => 1]))->delete();
    
    // UPDATE
    $sql = F::fromBuilder(B::from('users', ['id' => 1]))->update(['name' => 'Test']);
    
    // COUNT
    $sql = F::fromBuilder(B::from('users', ['status' => 'active']))->count();
    
    // EXISTS
    $sql = F::fromBuilder(B::from('users', ['id' => 1]))->exists();
}

$endTime = microtime(true);
$endMem = memory_get_usage();

$timeB_F = ($endTime - $startTime) * 1000; // ms
$memB_F = $endMem - $startMem; // bytes

echo "Iteraciones: {$iterations}\n";
echo "Tiempo total: " . number_format($timeB_F, 4) . " ms\n";
echo "Tiempo por iteración: " . number_format($timeB_F / $iterations, 6) . " ms\n";
echo "Memoria usada: " . number_format($memB_F) . " bytes\n\n";

// ============================================
// COMPARACIÓN FINAL
// ============================================
echo "\n" . str_repeat('=', 50) . "\n";
echo "COMPARACIÓN FINAL\n";
echo str_repeat('=', 50) . "\n\n";

echo "TIEMPOS:\n";
echo "B+F (Original):        " . number_format($timeB_F, 4) . " ms\n";
echo "EB+EF (Fragmentado):   " . number_format($timeEB_EF, 4) . " ms\n";
echo "B2+F2 (Integrado):     " . number_format($timeB2_F2, 4) . " ms\n\n";

// Comparaciones
$diff1 = $timeB_F - $timeEB_EF;
$pct1 = ($diff1 / $timeB_F) * 100;

$diff2 = $timeB_F - $timeB2_F2;
$pct2 = ($diff2 / $timeB_F) * 100;

$diff3 = $timeEB_EF - $timeB2_F2;
$pct3 = ($diff3 / $timeEB_EF) * 100;

echo "Mejora EB+EF vs B+F:   " . ($diff1 > 0 ? '+' : '') . number_format($diff1, 4) . " ms (" . ($pct1 > 0 ? '+' : '') . number_format($pct1, 2) . "%)\n";
echo "Mejora B2+F2 vs B+F:   " . ($diff2 > 0 ? '+' : '') . number_format($diff2, 4) . " ms (" . ($pct2 > 0 ? '+' : '') . number_format($pct2, 2) . "%)\n";
echo "Mejora B2+F2 vs EB+EF: " . ($diff3 > 0 ? '+' : '') . number_format($diff3, 4) . " ms (" . ($pct3 > 0 ? '+' : '') . number_format($pct3, 2) . "%)\n\n";

echo "MEMORIA:\n";
echo "B+F (Original):        " . number_format($memB_F) . " bytes\n";
echo "EB+EF (Fragmentado):   " . number_format($memEB_EF) . " bytes\n";
echo "B2+F2 (Integrado):     " . number_format($memB2_F2) . " bytes\n\n";

// Determinar ganador
$times = [
    'B+F' => $timeB_F,
    'EB+EF' => $timeEB_EF,
    'B2+F2' => $timeB2_F2
];

asort($times);
$winner = array_key_first($times);

echo "🏆 GANADOR: {$winner} con " . number_format($times[$winner], 4) . " ms\n";

if ($winner === 'B2+F2') {
    echo "\n✓ Integrar JoinManager en el Builder MEJORA el rendimiento.\n";
} elseif ($winner === 'EB+EF') {
    echo "\n✓ La fragmentación completa ES la mejor opción.\n";
} else {
    echo "\n✓ El enfoque original sigue siendo competitivo.\n";
}
