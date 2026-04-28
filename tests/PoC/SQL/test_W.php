<?php

require_once __DIR__ . '/../../../src/RapidBase/Core/SQL.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/S.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/V.php';
require_once __DIR__ . '/W.php';

use RapidBase\Core\SQL;
use RapidBase\Core\S;
use RapidBase\Core\V;
use RapidBase\Core\W;

echo "=== PRUEBA DE CONCEPTO: CLASE W (Encadenamiento Corto) ===\n\n";

// ============================================================================
// 1. DEMOSTRACIÓN DE SINTAXIS
// ============================================================================
echo "--- 1. DEMOSTRACIÓN DE SINTAXIS ---\n\n";

// Ejemplo 1: SELECT simple con W
echo "W::table('users', ['status' => 'active'])->select(['id', 'name'], 1, '-created_at'):\n";
[$sql, $params] = W::table('users', ['status' => 'active'])->select(['id', 'name'], 1, '-created_at');
echo "SQL: {$sql}\n";
echo "Params: " . json_encode($params) . "\n\n";

// Ejemplo 2: DELETE con W
echo "W::table('users', ['id' => 5])->delete():\n";
[$sql, $params] = W::table('users', ['id' => 5])->delete();
echo "SQL: {$sql}\n";
echo "Params: " . json_encode($params) . "\n\n";

// Ejemplo 3: UPDATE con W
echo "W::table('users', ['id' => 5])->update(['name' => 'Nuevo']):\n";
[$sql, $params] = W::table('users', ['id' => 5])->update(['name' => 'Nuevo']);
echo "SQL: {$sql}\n";
echo "Params: " . json_encode($params) . "\n\n";

// Ejemplo 4: FROM polimórfico (array de tablas)
echo "W::table(['users u', 'posts p'], ['u.status' => 'active'])->select('u.id, p.title'):\n";
[$sql, $params] = W::table(['users u', 'posts p'], ['u.status' => 'active'])->select('u.id, p.title');
echo "SQL: {$sql}\n";
echo "Params: " . json_encode($params) . "\n\n";

// Comparativa de longitud de código
echo "--- COMPARATIVA DE LONGITUD DE CÓDIGO ---\n";
$examples = [
    'SQL' => "SQL::buildSelect(['id','name'], 'users', ['status'=>'active'], [], [], ['-created_at'], 1, 10)",
    'S' => "S::select(['id','name'])->from('users')->where(['status'=>'active'])->orderBy('-created_at')->page(1,10)->build()",
    'V Static' => "V::select(['id','name'], 'users', ['status'=>'active'], null, '-created_at', [1,10])",
    'W' => "W::table('users',['status'=>'active'])->select(['id','name'],1,'-created_at')"
];

foreach ($examples as $class => $code) {
    echo sprintf("%-12s: %d caracteres\n", $class, strlen($code));
}
echo "\n";

// ============================================================================
// 2. BENCHMARK DE PERFORMANCE
// ============================================================================
echo "--- 2. BENCHMARK DE PERFORMANCE (10000 iteraciones) ---\n\n";

$iterations = 10000;
$results = [];

// Benchmark SQL
$start = microtime(true);
$startMem = memory_get_usage();
for ($i = 0; $i < $iterations; $i++) {
    SQL::buildSelect(['id', 'name', 'email'], 'users', ['status' => 'active', 'type' => 1], [], [], ['-created_at'], 1, 10);
}
$time = (microtime(true) - $start) * 1000;
$mem = memory_get_usage() - $startMem;
$results['SQL'] = ['time' => $time, 'mem' => $mem];

// Benchmark S
$start = microtime(true);
$startMem = memory_get_usage();
for ($i = 0; $i < $iterations; $i++) {
    S::selectFields(['id', 'name', 'email'])->from('users')->where(['status' => 'active', 'type' => 1])->orderBy('-created_at')->page(1, 10)->build();
}
$time = (microtime(true) - $start) * 1000;
$mem = memory_get_usage() - $startMem;
$results['S'] = ['time' => $time, 'mem' => $mem];

// Benchmark V Static
$start = microtime(true);
$startMem = memory_get_usage();
for ($i = 0; $i < $iterations; $i++) {
    V::select(['id', 'name', 'email'], 'users', ['status' => 'active', 'type' => 1], ['-created_at'], 1, 10);
}
$time = (microtime(true) - $start) * 1000;
$mem = memory_get_usage() - $startMem;
$results['V Static'] = ['time' => $time, 'mem' => $mem];

// Benchmark W
$start = microtime(true);
$startMem = memory_get_usage();
for ($i = 0; $i < $iterations; $i++) {
    W::table('users', ['status' => 'active', 'type' => 1])->select(['id', 'name', 'email'], 1, '-created_at');
}
$time = (microtime(true) - $start) * 1000;
$mem = memory_get_usage() - $startMem;
$results['W'] = ['time' => $time, 'mem' => $mem];

// Mostrar resultados
printf("%-12s | %-10s | %-10s | %-10s\n", "Clase", "Tiempo(ms)", "Mem(KB)", "vs SQL");
echo str_repeat("-", 50) . "\n";

$sqlTime = $results['SQL']['time'];
$sqlMem = $results['SQL']['mem'];

foreach ($results as $class => $data) {
    $timePct = ($data['time'] / $sqlTime) * 100;
    $memPct = $data['mem'] > 0 ? ($data['mem'] / $sqlMem) * 100 : 0;
    printf("%-12s | %-10.2f | %-10.2f | %-10.1f%%\n", 
        $class, 
        $data['time'], 
        $data['mem'] / 1024,
        $timePct
    );
}

echo "\n";
echo "CONCLUSIONES:\n";
echo "- W reduce el encadenamiento a solo 2 métodos: table() -> action()\n";
echo "- W usa arrays internos en lugar de objetos, reduciendo overhead\n";
echo "- La sintaxis es más corta pero mantiene polimorfismo en from, where, page y orderBy\n";
echo "- El performance debería ser mejor que S y V Builder, acercándose a V Static\n";
