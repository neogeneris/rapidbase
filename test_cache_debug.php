<?php
require_once __DIR__ . '/src/RapidBase/Core/SQL.php';

use RapidBase\Core\SQL;

// Debug con archivo PHP para evitar problemas de bash
SQL::setQueryCacheEnabled(true);
SQL::clearQueryCache();

echo "=== Test 1: Primera llamada (debería ser MISS) ===" . PHP_EOL;
$result1 = SQL::buildSelect(['*'], 'users', [], [], [], [], [], 0, 10, false);
$stats1 = SQL::getQueryCacheStats();
echo "Stats después de 1ra: " . json_encode($stats1) . PHP_EOL;
echo "SQL: " . $result1[0] . PHP_EOL;

echo PHP_EOL . "=== Test 2: Segunda llamada (debería ser HIT) ===" . PHP_EOL;
$result2 = SQL::buildSelect(['*'], 'users', [], [], [], [], [], 0, 10, false);
$stats2 = SQL::getQueryCacheStats();
echo "Stats después de 2da: " . json_encode($stats2) . PHP_EOL;
echo "SQL: " . $result2[0] . PHP_EOL;

echo PHP_EOL . "=== Test 3: Tercera llamada (debería ser HIT también) ===" . PHP_EOL;
$result3 = SQL::buildSelect(['*'], 'users', [], [], [], [], [], 0, 10, false);
$stats3 = SQL::getQueryCacheStats();
echo "Stats después de 3ra: " . json_encode($stats3) . PHP_EOL;

echo PHP_EOL . "=== Resumen ===" . PHP_EOL;
echo "Hits esperados: 2, Misses esperados: 1" . PHP_EOL;
echo "Hits reales: {$stats3['hits']}, Misses reales: {$stats3['misses']}" . PHP_EOL;
echo ($stats3['hits'] == 2 && $stats3['misses'] == 1 ? "✓ CORRECTO" : "✗ INCORRECTO") . PHP_EOL;
