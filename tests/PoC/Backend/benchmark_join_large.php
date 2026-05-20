<?php

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/JsonBackend.php';

use Tests\PoC\Backend\JsonBackend;
use RapidBase\Core\X;

echo "=== Benchmark: JOIN con 1,000 usuarios y 5,000 posts ===\n\n";

// Limpiar datos previos
@unlink(__DIR__ . '/data/users.json');
@unlink(__DIR__ . '/data/posts.json');

// ==========================================
// 1. Generar datos (1k users, 5k posts)
// ==========================================
echo "Generando 1,000 usuarios y 5,000 posts...\n";
$users = [];
for ($i = 1; $i <= 1000; $i++) {
    $users[] = [
        'id' => $i,
        'name' => "User $i",
        'email' => "user$i@example.com",
        'age' => rand(18, 80)
    ];
}

$posts = [];
$postId = 1;
for ($i = 1; $i <= 1000; $i++) {
    // Cada usuario tiene 5 posts
    for ($j = 0; $j < 5; $j++) {
        $posts[] = [
            'id' => $postId++,
            'user_id' => $i,
            'title' => "Post $postId by User $i",
            'content' => "Content of post $postId",
            'created_at' => date('Y-m-d H:i:s', strtotime("-" . rand(0, 365) . " days"))
        ];
    }
}

echo "Datos generados: " . count($users) . " usuarios, " . count($posts) . " posts\n\n";

// Insertar en JSON
JsonBackend::into('users')->insert($users);
JsonBackend::into('posts')->insert($posts);
echo "Datos insertados en archivos JSON.\n\n";

// ==========================================
// 2. Test SQLite SIN índices
// ==========================================
echo "--- SQLite SIN índices ---\n";
$totalTime = 0;
$numRuns = 3;

for ($run = 0; $run < $numRuns; $run++) {
    $startTime = microtime(true);
    
    $result = JsonBackend::from('users')
        ->join('posts', 'users.id', '=', 'posts.user_id')
        ->select(['users.id as user_id', 'users.name', 'posts.id as post_id', 'posts.title']);
    
    $endTime = microtime(true);
    $time = ($endTime - $startTime) * 1000;
    $totalTime += $time;
    
    echo "Run " . ($run + 1) . ": " . number_format($time, 4) . " ms - " . count($result) . " filas\n";
}

$sqliteAvgTime = $totalTime / $numRuns;
echo "Promedio SQLite (sin índices): " . number_format($sqliteAvgTime, 4) . " ms\n\n";

// ==========================================
// 3. Test SQLite CON índices
// ==========================================
echo "--- SQLite CON índices ---\n";
$totalTimeIndexed = 0;

// Crear conexión con índices
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Crear tablas con índices
$db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT, age INTEGER)");
$db->exec("CREATE INDEX idx_users_id ON users(id)");
$db->exec("CREATE TABLE posts (id INTEGER PRIMARY KEY, user_id INTEGER, title TEXT, content TEXT, created_at TEXT)");
$db->exec("CREATE INDEX idx_posts_user_id ON posts(user_id)");

// Insertar datos masivamente usando PDO directamente
$stmt = $db->prepare("INSERT INTO users (id, name, email, age) VALUES (?, ?, ?, ?)");
foreach ($users as $user) {
    $stmt->execute([$user['id'], $user['name'], $user['email'], $user['age']]);
}

$stmt = $db->prepare("INSERT INTO posts (id, user_id, title, content, created_at) VALUES (?, ?, ?, ?, ?)");
foreach ($posts as $post) {
    $stmt->execute([$post['id'], $post['user_id'], $post['title'], $post['content'], $post['created_at']]);
}

for ($run = 0; $run < $numRuns; $run++) {
    $startTime = microtime(true);
    
    $stmt = $db->query("
        SELECT u.id as user_id, u.name, p.id as post_id, p.title
        FROM users u
        INNER JOIN posts p ON u.id = p.user_id
    ");
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $endTime = microtime(true);
    $time = ($endTime - $startTime) * 1000;
    $totalTimeIndexed += $time;
    
    echo "Run " . ($run + 1) . ": " . number_format($time, 4) . " ms - " . count($result) . " filas\n";
}

$sqliteIndexedAvgTime = $totalTimeIndexed / $numRuns;
echo "Promedio SQLite (con índices): " . number_format($sqliteIndexedAvgTime, 4) . " ms\n\n";

// ==========================================
// 4. Test PHP Native Join (Hash Join)
// ==========================================
echo "--- PHP Native Join (Hash Join) ---\n";
$totalTimePhp = 0;

for ($run = 0; $run < $numRuns; $run++) {
    $startTime = microtime(true);
    
    $result = JsonBackend::from('users')
        ->joinNative('posts', 'users.id', '=', 'posts.user_id')
        ->select(['users.id as user_id', 'users.name', 'posts.id as post_id', 'posts.title']);
    
    $endTime = microtime(true);
    $time = ($endTime - $startTime) * 1000;
    $totalTimePhp += $time;
    
    echo "Run " . ($run + 1) . ": " . number_format($time, 4) . " ms - " . count($result) . " filas\n";
}

$phpAvgTime = $totalTimePhp / $numRuns;
echo "Promedio PHP Native: " . number_format($phpAvgTime, 4) . " ms\n\n";

// ==========================================
// 5. Resumen comparativo
// ==========================================
echo "=== RESUMEN COMPARATIVO ===\n";
echo "SQLite (sin índices):     " . number_format($sqliteAvgTime, 4) . " ms\n";
echo "SQLite (con índices):     " . number_format($sqliteIndexedAvgTime, 4) . " ms\n";
echo "PHP Native (Hash Join):   " . number_format($phpAvgTime, 4) . " ms\n\n";

$improvementWithIndex = (($sqliteAvgTime - $sqliteIndexedAvgTime) / $sqliteAvgTime) * 100;
echo "Mejora con índices: " . number_format($improvementWithIndex, 2) . "%\n";

if ($sqliteIndexedAvgTime < $phpAvgTime) {
    $faster = ($phpAvgTime / $sqliteIndexedAvgTime);
    echo "SQLite es " . number_format($faster, 2) . "x más rápido que PHP Native\n";
} else {
    $faster = ($sqliteIndexedAvgTime / $phpAvgTime);
    echo "PHP Native es " . number_format($faster, 2) . "x más rápido que SQLite\n";
}

// Limpieza
@unlink(__DIR__ . '/data/users.json');
@unlink(__DIR__ . '/data/posts.json');
