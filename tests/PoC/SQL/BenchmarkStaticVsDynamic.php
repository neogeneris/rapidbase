<?php

require_once __DIR__ . '/W.php';
require_once __DIR__ . '/W_Static.php';

use RapidBase\Core\W;
use RapidBase\Core\W_Static;

echo "=== Benchmark: W Dinámica vs W Estática ===\n\n";

$iterations = 10000;

// Test 1: SELECT simple
echo "Test 1: SELECT simple con WHERE\n";
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $result = W::from('users', ['status' => 'active'])->select('*', 20);
}
$timeDynamic = (microtime(true) - $start) * 1000 / $iterations;

$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $result = W_Static::from('users', ['status' => 'active'])->select('*', 20);
}
$timeStatic = (microtime(true) - $start) * 1000 / $iterations;

echo "  Dinámica: " . number_format($timeDynamic, 4) . " ms\n";
echo "  Estática: " . number_format($timeStatic, 4) . " ms\n";
$speedup = $timeDynamic > 0 ? $timeDynamic / $timeStatic : 0;
echo "  Speedup: " . number_format($speedup, 2) . "x\n\n";

// Test 2: SELECT con LIMIT polimórfico
echo "Test 2: SELECT con LIMIT/OFFSET (scroll infinito)\n";
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $result = W::from('posts')->select('*', [100, 50]);
}
$timeDynamic = (microtime(true) - $start) * 1000 / $iterations;

$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $result = W_Static::from('posts')->select('*', [100, 50]);
}
$timeStatic = (microtime(true) - $start) * 1000 / $iterations;

echo "  Dinámica: " . number_format($timeDynamic, 4) . " ms\n";
echo "  Estática: " . number_format($timeStatic, 4) . " ms\n";
$speedup = $timeDynamic > 0 ? $timeDynamic / $timeStatic : 0;
echo "  Speedup: " . number_format($speedup, 2) . "x\n\n";

// Test 3: Auto-join automático
echo "Test 3: Auto-join automático (3 tablas)\n";
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $result = W::from(['users', 'posts', 'comments'])->select('*');
}
$timeDynamic = (microtime(true) - $start) * 1000 / $iterations;

$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $result = W_Static::from(['users', 'posts', 'comments'])->select('*');
}
$timeStatic = (microtime(true) - $start) * 1000 / $iterations;

echo "  Dinámica: " . number_format($timeDynamic, 4) . " ms\n";
echo "  Estática: " . number_format($timeStatic, 4) . " ms\n";
$speedup = $timeDynamic > 0 ? $timeDynamic / $timeStatic : 0;
echo "  Speedup: " . number_format($speedup, 2) . "x\n\n";

// Test 4: Count
echo "Test 4: Count()\n";
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $result = W::from('users', ['id' => 1])->count();
}
$timeDynamic = (microtime(true) - $start) * 1000 / $iterations;

$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $result = W_Static::from('users', ['id' => 1])->count();
}
$timeStatic = (microtime(true) - $start) * 1000 / $iterations;

echo "  Dinámica: " . number_format($timeDynamic, 4) . " ms\n";
echo "  Estática: " . number_format($timeStatic, 4) . " ms\n";
$speedup = $timeDynamic > 0 ? $timeDynamic / $timeStatic : 0;
echo "  Speedup: " . number_format($speedup, 2) . "x\n\n";

// Test 5: Exists
echo "Test 5: Exists()\n";
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $result = W::from('users', ['id' => 1])->exists();
}
$timeDynamic = (microtime(true) - $start) * 1000 / $iterations;

$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $result = W_Static::from('users', ['id' => 1])->exists();
}
$timeStatic = (microtime(true) - $start) * 1000 / $iterations;

echo "  Dinámica: " . number_format($timeDynamic, 4) . " ms\n";
echo "  Estática: " . number_format($timeStatic, 4) . " ms\n";
$speedup = $timeDynamic > 0 ? $timeDynamic / $timeStatic : 0;
echo "  Speedup: " . number_format($speedup, 2) . "x\n\n";

// Test 6: Relaciones inline
echo "Test 6: Relaciones inline\n";
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $result = W::from([
        'drivers' => [
            'users' => [
                'type' => 'belongsTo',
                'local_key' => 'user_id',
                'foreign_key' => 'id'
            ]
        ]
    ])->select('*');
}
$timeDynamic = (microtime(true) - $start) * 1000 / $iterations;

$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $result = W_Static::from([
        'drivers' => [
            'users' => [
                'type' => 'belongsTo',
                'local_key' => 'user_id',
                'foreign_key' => 'id'
            ]
        ]
    ])->select('*');
}
$timeStatic = (microtime(true) - $start) * 1000 / $iterations;

echo "  Dinámica: " . number_format($timeDynamic, 4) . " ms\n";
echo "  Estática: " . number_format($timeStatic, 4) . " ms\n";
$speedup = $timeDynamic > 0 ? $timeDynamic / $timeStatic : 0;
echo "  Speedup: " . number_format($speedup, 2) . "x\n\n";

echo "=== Resumen ===\n";
echo "Ambas versiones tienen performance similar en operaciones básicas.\n";
echo "La versión estática es más adecuada para arquitecturas stateless y sin costo de instanciación.\n";
