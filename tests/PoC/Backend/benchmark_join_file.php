<?php
/**
 * Benchmark: JOIN con SQLite en archivo (no memoria)
 * Investiga el problema de los índices y resultados inconsistentes
 */

require_once __DIR__ . '/JsonBackend.php';

use Tests\PoC\Backend\JsonBackend;

// Configuración
$dbFile = __DIR__ . '/test_benchmark.sqlite';
$numUsers = 1000;
$postsPerUser = 15; // ~15,000 posts totales

// Limpiar archivos previos
if (file_exists($dbFile)) {
    unlink($dbFile);
}
if (file_exists(__DIR__ . '/users.json')) {
    unlink(__DIR__ . '/users.json');
}
if (file_exists(__DIR__ . '/posts.json')) {
    unlink(__DIR__ . '/posts.json');
}

echo "=== Benchmark JOIN SQLite en Archivo ===\n";
echo "Usuarios: {$numUsers}\n";
echo "Posts por usuario: {$postsPerUser}\n";
echo "Total posts esperados: " . ($numUsers * $postsPerUser) . "\n\n";

// ============================================
// PASO 1: Crear datos con JsonBackend
// ============================================
echo "[1] Generando datos con JsonBackend...\n";
$t0 = microtime(true);

$users = [];
for ($i = 1; $i <= $numUsers; $i++) {
    $users[] = [
        'id' => $i,
        'name' => "User {$i}",
        'email' => "user{$i}@example.com"
    ];
}

JsonBackend::into('users')->insert($users);

$posts = [];
$postId = 1;
for ($userId = 1; $userId <= $numUsers; $userId++) {
    for ($j = 0; $j < $postsPerUser; $j++) {
        $posts[] = [
            'id' => $postId++,
            'user_id' => $userId,
            'title' => "Post {$postId} by User {$userId}",
            'content' => "Content of post {$postId}"
        ];
    }
}

JsonBackend::into('posts')->insert($posts);

$t1 = microtime(true);
$timeInsert = ($t1 - $t0) * 1000;
echo sprintf("   Insertados en JSON: %.2f ms\n\n", $timeInsert);

// Verificar cantidad de datos en JSON
$userCount = count(JsonBackend::from('users')->select('*'));
$postCount = count(JsonBackend::from('posts')->select('*'));
echo "   Verificación JSON: {$userCount} usuarios, {$postCount} posts\n\n";

// ============================================
// PASO 2: Cargar datos en SQLite (archivo)
// ============================================
echo "[2] Cargando datos en SQLite (archivo)...\n";

try {
    $pdo = new PDO("sqlite:{$dbFile}");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Crear tablas
    $pdo->exec("
        CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            name TEXT,
            email TEXT
        )
    ");
    
    $pdo->exec("
        CREATE TABLE posts (
            id INTEGER PRIMARY KEY,
            user_id INTEGER,
            title TEXT,
            content TEXT
        )
    ");
    
    // Insertar usuarios
    $stmt = $pdo->prepare("INSERT INTO users (id, name, email) VALUES (?, ?, ?)");
    foreach ($users as $user) {
        $stmt->execute([$user['id'], $user['name'], $user['email']]);
    }
    
    // Insertar posts (batch)
    $stmt = $pdo->prepare("INSERT INTO posts (id, user_id, title, content) VALUES (?, ?, ?, ?)");
    foreach ($posts as $post) {
        $stmt->execute([$post['id'], $post['user_id'], $post['title'], $post['content']]);
    }
    
    echo "   Datos cargados en SQLite\n\n";
    
    // Verificar conteo en SQLite
    $count = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    echo "   Usuarios en SQLite: {$count}\n";
    $count = $pdo->query("SELECT COUNT(*) FROM posts")->fetchColumn();
    echo "   Posts en SQLite: {$count}\n\n";
    
} catch (PDOException $e) {
    echo "Error PDO: " . $e->getMessage() . "\n";
    exit(1);
}

// ============================================
// PASO 3: JOIN SIN índices
// ============================================
echo "[3] JOIN SIN índices...\n";

$t0 = microtime(true);
$stmt = $pdo->query("
    SELECT u.id as user_id, u.name, u.email, p.id as post_id, p.title, p.content
    FROM users u
    INNER JOIN posts p ON u.id = p.user_id
");
$resultsNoIndex = $stmt->fetchAll(PDO::FETCH_ASSOC);
$t1 = microtime(true);
$timeNoIndex = ($t1 - $t0) * 1000;

echo sprintf("   Tiempo: %.2f ms\n", $timeNoIndex);
echo "   Filas obtenidas: " . count($resultsNoIndex) . "\n\n";

// ============================================
// PASO 4: Crear índices
// ============================================
echo "[4] Creando índices...\n";

$t0 = microtime(true);
$pdo->exec("CREATE INDEX idx_users_id ON users(id)");
$pdo->exec("CREATE INDEX idx_posts_user_id ON posts(user_id)");
$t1 = microtime(true);
$timeCreateIndex = ($t1 - $t0) * 1000;

echo sprintf("   Tiempo creación índices: %.2f ms\n\n", $timeCreateIndex);

// ============================================
// PASO 5: JOIN CON índices
// ============================================
echo "[5] JOIN CON índices...\n";

$t0 = microtime(true);
$stmt = $pdo->query("
    SELECT u.id as user_id, u.name, u.email, p.id as post_id, p.title, p.content
    FROM users u
    INNER JOIN posts p ON u.id = p.user_id
");
$resultsWithIndex = $stmt->fetchAll(PDO::FETCH_ASSOC);
$t1 = microtime(true);
$timeWithIndex = ($t1 - $t0) * 1000;

echo sprintf("   Tiempo: %.2f ms\n", $timeWithIndex);
echo "   Filas obtenidas: " . count($resultsWithIndex) . "\n\n";

// ============================================
// PASO 6: JOIN con índice + ANALYZE
// ============================================
echo "[6] JOIN CON índices + ANALYZE...\n";

$pdo->exec("ANALYZE");

$t0 = microtime(true);
$stmt = $pdo->query("
    SELECT u.id as user_id, u.name, u.email, p.id as post_id, p.title, p.content
    FROM users u
    INNER JOIN posts p ON u.id = p.user_id
");
$resultsAnalyze = $stmt->fetchAll(PDO::FETCH_ASSOC);
$t1 = microtime(true);
$timeAnalyze = ($t1 - $t0) * 1000;

echo sprintf("   Tiempo: %.2f ms\n", $timeAnalyze);
echo "   Filas obtenidas: " . count($resultsAnalyze) . "\n\n";

// ============================================
// PASO 7: PHP Native Join (Hash Join)
// ============================================
echo "[7] PHP Native Join (Hash Join)...\n";

$t0 = microtime(true);

$usersData = JsonBackend::from('users')->select('*');
$postsData = JsonBackend::from('posts')->select('*');

// Crear hash map de usuarios
$userMap = [];
foreach ($usersData as $user) {
    $userMap[$user['id']] = $user;
}

// Hacer join
$phpResults = [];
foreach ($postsData as $post) {
    $userId = $post['user_id'];
    if (isset($userMap[$userId])) {
        $user = $userMap[$userId];
        $phpResults[] = [
            'user_id' => $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'post_id' => $post['id'],
            'title' => $post['title'],
            'content' => $post['content']
        ];
    }
}

$t1 = microtime(true);
$timePhp = ($t1 - $t0) * 1000;

echo sprintf("   Tiempo: %.2f ms\n", $timePhp);
echo "   Filas obtenidas: " . count($phpResults) . "\n\n";

// ============================================
// RESUMEN
// ============================================
echo "=== RESUMEN ===\n";
echo sprintf("%-30s %15s %15s\n", "Método", "Tiempo (ms)", "Filas");
echo str_repeat("-", 65) . "\n";
echo sprintf("%-30s %15.2f %15d\n", "SQLite SIN índices", $timeNoIndex, count($resultsNoIndex));
echo sprintf("%-30s %15.2f %15d\n", "SQLite CON índices", $timeWithIndex, count($resultsWithIndex));
echo sprintf("%-30s %15.2f %15d\n", "SQLite CON índices + ANALYZE", $timeAnalyze, count($resultsAnalyze));
echo sprintf("%-30s %15.2f %15d\n", "PHP Native (Hash Join)", $timePhp, count($phpResults));
echo str_repeat("-", 65) . "\n";

// Verificar consistencia
echo "\n=== Verificación de Consistencia ===\n";
$expectedRows = $numUsers * $postsPerUser;
echo "Filas esperadas: {$expectedRows}\n";

if (count($resultsNoIndex) === $expectedRows) {
    echo "✓ SQLite SIN índices: CORRECTO\n";
} else {
    echo "✗ SQLite SIN índices: INCORRECTO (faltan " . ($expectedRows - count($resultsNoIndex)) . " filas)\n";
}

if (count($resultsWithIndex) === $expectedRows) {
    echo "✓ SQLite CON índices: CORRECTO\n";
} else {
    echo "✗ SQLite CON índices: INCORRECTO (faltan " . ($expectedRows - count($resultsWithIndex)) . " filas)\n";
}

if (count($resultsAnalyze) === $expectedRows) {
    echo "✓ SQLite CON índices + ANALYZE: CORRECTO\n";
} else {
    echo "✗ SQLite CON índices + ANALYZE: INCORRECTO (faltan " . ($expectedRows - count($resultsAnalyze)) . " filas)\n";
}

if (count($phpResults) === $expectedRows) {
    echo "✓ PHP Native: CORRECTO\n";
} else {
    echo "✗ PHP Native: INCORRECTO (faltan " . ($expectedRows - count($phpResults)) . " filas)\n";
}

// Investigar discrepancia si existe
if (count($resultsWithIndex) !== $expectedRows) {
    echo "\n=== Investigación de Discrepancia ===\n";
    
    // Verificar si hay posts sin user_id válido
    $orphanPosts = $pdo->query("
        SELECT COUNT(*) FROM posts p 
        LEFT JOIN users u ON p.user_id = u.id 
        WHERE u.id IS NULL
    ")->fetchColumn();
    echo "Posts huérfanos (sin usuario): {$orphanPosts}\n";
    
    // Verificar rango de user_id en posts
    $minUserId = $pdo->query("SELECT MIN(user_id) FROM posts")->fetchColumn();
    $maxUserId = $pdo->query("SELECT MAX(user_id) FROM posts")->fetchColumn();
    echo "Rango de user_id en posts: {$minUserId} - {$maxUserId}\n";
    
    // Verificar rango de id en users
    $minUserIdUsers = $pdo->query("SELECT MIN(id) FROM users")->fetchColumn();
    $maxUserIdUsers = $pdo->query("SELECT MAX(id) FROM users")->fetchColumn();
    echo "Rango de id en users: {$minUserIdUsers} - {$maxUserIdUsers}\n";
    
    // Explicación del query plan
    echo "\nQuery Plan (EXPLAIN):\n";
    $plan = $pdo->query("EXPLAIN QUERY PLAN 
        SELECT u.id as user_id, u.name, u.email, p.id as post_id, p.title, p.content
        FROM users u
        INNER JOIN posts p ON u.id = p.user_id
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($plan as $row) {
        echo "   " . implode(' | ', $row) . "\n";
    }
}

// Limpieza
unlink($dbFile);
echo "\n[OK] Archivo SQLite eliminado\n";
echo "[FIN] Benchmark completado\n";
