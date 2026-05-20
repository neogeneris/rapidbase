<?php

require_once __DIR__ . '/JsonBackend.php';

use Tests\PoC\Backend\JsonBackend;

echo "=== Benchmark: SQLite Join vs PHP Native Join ===\n\n";

// Configuración
$numRecords = 100;
$jsonDir = __DIR__ . '/data_benchmark';

// Limpiar datos previos
if (is_dir($jsonDir)) {
    array_map('unlink', glob("$jsonDir/*.json"));
    rmdir($jsonDir);
}
if (!is_dir($jsonDir)) {
    mkdir($jsonDir, 0755, true);
}

// Configurar JsonBackend para usar este directorio
JsonBackend::into('users')->setBaseDir($jsonDir);

echo "Generando $numRecords registros de prueba...\n";

// Generar datos para users
$users = [];
for ($i = 1; $i <= $numRecords; $i++) {
    $users[] = [
        'id' => $i,
        'name' => "User $i",
        'email' => "user$i@example.com",
        'age' => 20 + ($i % 30)
    ];
}

// Generar datos para posts (cada user tiene 2-3 posts)
$posts = [];
$postId = 1;
for ($userId = 1; $userId <= $numRecords; $userId++) {
    $numPosts = 2 + ($userId % 2); // 2 o 3 posts por usuario
    for ($j = 0; $j < $numPosts; $j++) {
        $posts[] = [
            'id' => $postId++,
            'user_id' => $userId,
            'title' => "Post $postId by User $userId",
            'content' => "Content of post $postId",
            'created_at' => date('Y-m-d H:i:s', strtotime("-$postId days"))
        ];
    }
}

echo "Users: " . count($users) . ", Posts: " . count($posts) . "\n\n";

// ==========================================
// PRUEBA 1: SQLite Join
// ==========================================
echo "=== PRUEBA 1: SQLite Join en Memoria ===\n";

// Insertar datos en JSON primero
JsonBackend::into('users')->insert($users);
JsonBackend::into('posts')->insert($posts);

$start = microtime(true);
$result = JsonBackend::from('users')
    ->join('posts', 'users.id', '=', 'posts.user_id')
    ->select(['users.*', 'posts.title as post_title', 'posts.content as post_content']);
$sqliteTime = (microtime(true) - $start) * 1000;

echo "SQLite Join Time: " . number_format($sqliteTime, 4) . " ms\n";
echo "Resultados obtenidos: " . count($result) . " filas\n\n";

// ==========================================
// PRUEBA 2: PHP Native Join
// ==========================================
echo "=== PRUEBA 2: PHP Native Join ===\n";

$start = microtime(true);
$resultNative = JsonBackend::from('users')
    ->joinNative('posts', 'users.id', '=', 'posts.user_id')
    ->select(['users.*', 'posts.title as post_title', 'posts.content as post_content']);
$phpTime = (microtime(true) - $start) * 1000;

echo "PHP Native Join Time: " . number_format($phpTime, 4) . " ms\n";
echo "Resultados obtenidos: " . count($resultNative) . " filas\n\n";

// ==========================================
// Comparación
// ==========================================
echo "=== COMPARACIÓN ===\n";
echo "SQLite Join:      " . number_format($sqliteTime, 4) . " ms\n";
echo "PHP Native Join:  " . number_format($phpTime, 4) . " ms\n";

if ($phpTime < $sqliteTime) {
    $improvement = (($sqliteTime - $phpTime) / $sqliteTime) * 100;
    echo "✓ PHP Native es " . number_format($improvement, 2) . "% más rápido\n";
} else {
    $slower = (($phpTime - $sqliteTime) / $sqliteTime) * 100;
    echo "✗ PHP Native es " . number_format($slower, 2) . "% más lento\n";
}

// Verificar que ambos métodos dan el mismo resultado
if (count($result) === count($resultNative)) {
    echo "\n✓ Ambos métodos devuelven la misma cantidad de resultados\n";
} else {
    echo "\n✗ Diferencia en cantidad de resultados!\n";
}

// Limpieza
array_map('unlink', glob("$jsonDir/*.json"));
rmdir($jsonDir);

echo "\n=== Benchmark completado ===\n";
