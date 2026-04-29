<?php

namespace RapidBase\SQLEngine;

require_once __DIR__ . '/JoinManager.php';
require_once __DIR__ . '/JoinManagerAuto.php';
require_once __DIR__ . '/JoinManagerGenetic.php';
require_once __DIR__ . '/Parser.php';
require_once __DIR__ . '/EB.php';
require_once __DIR__ . '/EF.php';

use RapidBase\SQLEngine\EB;
use RapidBase\SQLEngine\EF;
use RapidBase\SQLEngine\JoinManager;
use RapidBase\SQLEngine\JoinManagerAuto;
use RapidBase\SQLEngine\JoinManagerGenetic;
use RapidBase\SQLEngine\Parser;

echo "=== Benchmark: SQL Engine Fragmentado ===\n\n";

// Configuración
$iterations = 10000;

// ============================================
// TEST 1: EB + EF (Motor fragmentado base)
// ============================================
echo "Test 1: EB + EF (Determinista)\n";
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
// TEST 2: B + F (Original en SQL/)
// ============================================
require_once __DIR__ . '/../SQL/FinalizerInterface.php';
require_once __DIR__ . '/../SQL/B.php';
require_once __DIR__ . '/../SQL/F.php';

use RapidBase\Core\B;
use RapidBase\Core\F;

echo "Test 2: B + F (Original)\n";
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
// TEST 3: Parser de URLs
// ============================================
echo "Test 3: Parser (URL a condiciones)\n";
echo str_repeat('-', 50) . "\n";

$urlTests = [
    '?status=active&role=admin',
    'age>18&country=US',
    'ids[]=1&ids[]=2&ids[]=3',
];

foreach ($urlTests as $url) {
    $result = Parser::parseConditions($url);
    echo "Input: {$url}\n";
    echo "Output: " . json_encode($result) . "\n\n";
}

// ============================================
// TEST 4: JoinManager con diferentes estrategias
// ============================================
echo "Test 4: JoinManager Strategies\n";
echo str_repeat('-', 50) . "\n";

// Determinista
$jm = new JoinManager();
$jm->addJoin('LEFT', '"posts" AS "p"', '"u"."id" = "p"."user_id"');
echo "Determinista: " . $jm->buildJoinSQL() . "\n";

// Auto
$jma = new JoinManagerAuto();
$jma->addAutoJoin('users as u');
$jma->addAutoJoin('posts as p', ['u' => 'users']);
echo "Auto: " . $jma->buildJoinSQL() . "\n";

// ============================================
// COMPARACIÓN FINAL
// ============================================
echo "\n" . str_repeat('=', 50) . "\n";
echo "COMPARACIÓN FINAL\n";
echo str_repeat('=', 50) . "\n";

$difference = $timeB_F - $timeEB_EF;
$percentChange = ($difference / $timeB_F) * 100;

echo "B+F Tiempo: " . number_format($timeB_F, 4) . " ms\n";
echo "EB+EF Tiempo: " . number_format($timeEB_EF, 4) . " ms\n";
echo "Diferencia: " . ($difference > 0 ? '+' : '') . number_format($difference, 4) . " ms ";
echo "(" . ($percentChange > 0 ? '+' : '') . number_format($percentChange, 2) . "%)\n";

if ($timeEB_EF < $timeB_F) {
    echo "\n✓ EB+EF es más rápido que B+F\n";
} else {
    echo "\n✗ EB+EF es más lento que B+F\n";
}

echo "\nMemoria:\n";
echo "B+F: " . number_format($memB_F) . " bytes\n";
echo "EB+EF: " . number_format($memEB_EF) . " bytes\n";
