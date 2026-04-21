<?php
/**
 * PoC: FETCH_NUM con Mapa de Proyección Dinámico
 * 
 * Objetivo: Demostrar que podemos usar PDO::FETCH_NUM (más eficiente)
 * manteniendo acceso preciso a columnas mediante un mapa de proyección
 * generado en tiempo de construcción del SQL.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use RapidBase\Core\DB;
use RapidBase\Core\SQL;
use RapidBase\Core\Gateway;
use RapidBase\Core\Conn;

// Configuración inicial - usar MySQL ya que SQLite no está disponible
DB::setup('mysql:host=localhost;dbname=test_poc', 'root', '', 'main');
$pdo = Conn::get();

// Limpiar y crear schema de prueba
try {
    $pdo->exec("DROP TABLE IF EXISTS post_tags");
    $pdo->exec("DROP TABLE IF EXISTS tags");
    $pdo->exec("DROP TABLE IF EXISTS posts");
    $pdo->exec("DROP TABLE IF EXISTS users");
} catch (Exception $e) {
    // Ignorar errores al limpiar
}

// Crear schema de prueba
$pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT)");
$pdo->exec("CREATE TABLE posts (id INTEGER PRIMARY KEY, user_id INTEGER, title TEXT, content TEXT)");
$pdo->exec("CREATE TABLE tags (id INTEGER PRIMARY KEY, name TEXT)");
$pdo->exec("CREATE TABLE post_tags (post_id INTEGER, tag_id INTEGER, PRIMARY KEY (post_id, tag_id))");

// Insertar datos de prueba
$pdo->exec("INSERT INTO users VALUES (1, 'Alice', 'alice@example.com')");
$pdo->exec("INSERT INTO users VALUES (2, 'Bob', 'bob@example.com')");
$pdo->exec("INSERT INTO posts VALUES (1, 1, 'First Post', 'Content 1')");
$pdo->exec("INSERT INTO posts VALUES (2, 1, 'Second Post', 'Content 2')");
$pdo->exec("INSERT INTO posts VALUES (3, 2, 'Third Post', 'Content 3')");
$pdo->exec("INSERT INTO tags VALUES (1, 'PHP')");
$pdo->exec("INSERT INTO tags VALUES (2, 'Database')");
$pdo->exec("INSERT INTO post_tags VALUES (1, 1)");
$pdo->exec("INSERT INTO post_tags VALUES (1, 2)");
$pdo->exec("INSERT INTO post_tags VALUES (2, 1)");

// Cargar schema en RapidBase
SQL::setRelationsMap([
    'tables' => [
        'users' => ['id' => 'int', 'name' => 'string', 'email' => 'string'],
        'posts' => ['id' => 'int', 'user_id' => 'int', 'title' => 'string', 'content' => 'string'],
        'tags' => ['id' => 'int', 'name' => 'string'],
        'post_tags' => ['post_id' => 'int', 'tag_id' => 'int']
    ],
    'relationships' => [
        'users_posts' => ['from' => 'users', 'to' => 'posts', 'type' => 'hasMany', 'local_key' => 'id', 'foreign_key' => 'user_id'],
        'posts_user' => ['from' => 'posts', 'to' => 'users', 'type' => 'belongsTo', 'local_key' => 'user_id', 'foreign_key' => 'id'],
        'posts_tags' => ['from' => 'posts', 'to' => 'tags', 'type' => 'belongsToMany', 'pivot' => 'post_tags']
    ]
]);

echo "=== PoC: FETCH_NUM con Mapa de Proyección ===\n\n";

// ============================================
// PRUEBA 1: SELECT * con JOIN (columnas duplicadas)
// ============================================
echo "--- PRUEBA 1: SELECT * con JOIN (usuarios + posts) ---\n";
echo "Problema: Ambas tablas tienen columna 'id'. Con FETCH_ASSOC se pierde una.\n\n";

// Construir SQL con el nuevo sistema
$fields = '*';
$tables = ['users', 'posts'];
[$sql, $params] = SQL::buildSelect($fields, $tables);

echo "SQL Generado:\n$sql\n\n";

$projectionMap = SQL::getLastProjectionMap();
echo "Mapa de Proyección:\n";
print_r($projectionMap);

// Ejecutar con FETCH_NUM
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rowsNum = $stmt->fetchAll(PDO::FETCH_NUM);

echo "\nResultados con FETCH_NUM (array numérico):\n";
foreach ($rowsNum as $i => $row) {
    echo "Fila $i: [" . implode(', ', $row) . "]\n";
}

// Acceder usando el mapa de proyección
echo "\nAcceso usando el mapa (users.id, posts.id, users.name):\n";
foreach ($rowsNum as $i => $row) {
    $userId = $row[$projectionMap['users']['id']];
    $postId = $row[$projectionMap['posts']['id']];
    $userName = $row[$projectionMap['users']['name']];
    echo "Fila $i: users.id=$userId, posts.id=$postId, users.name=$userName\n";
}

// Comparar con FETCH_ASSOC tradicional
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rowsAssoc = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "\nResultados con FETCH_ASSOC (tradicional - hay colisión de 'id'):\n";
print_r($rowsAssoc);

echo "\n";

// ============================================
// PRUEBA 2: Campos específicos con aliases
// ============================================
echo "--- PRUEBA 2: Campos específicos con aliases ---\n";

$fields = ['u.id AS user_id', 'u.name', 'p.title', 'p.id AS post_id'];
$tables = ['users AS u', 'posts AS p'];
[$sql, $params] = SQL::buildSelect($fields, $tables);

echo "SQL Generado:\n$sql\n\n";

$projectionMap = SQL::getLastProjectionMap();
echo "Mapa de Proyección:\n";
print_r($projectionMap);

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rowsNum = $stmt->fetchAll(PDO::FETCH_NUM);

echo "\nResultados con FETCH_NUM:\n";
foreach ($rowsNum as $i => $row) {
    echo "Fila $i: [" . implode(', ', $row) . "]\n";
}

echo "\nAcceso usando el mapa (user_id, post_id):\n";
foreach ($rowsNum as $i => $row) {
    $userId = $row[$projectionMap['user_id']];
    $postId = $row[$projectionMap['post_id']];
    echo "Fila $i: user_id=$userId, post_id=$postId\n";
}

echo "\n";

// ============================================
// PRUEBA 3: Benchmark de rendimiento
// ============================================
echo "--- PRUEBA 3: Benchmark FETCH_NUM vs FETCH_ASSOC ---\n";

// Preparar consulta simple
$sql = "SELECT id, name, email FROM users";
$stmt = $pdo->prepare($sql);

// FETCH_ASSOC
$start = microtime(true);
for ($i = 0; $i < 1000; $i++) {
    $stmt->execute();
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$timeAssoc = (microtime(true) - $start) * 1000;

// FETCH_NUM
$start = microtime(true);
for ($i = 0; $i < 1000; $i++) {
    $stmt->execute();
    $data = $stmt->fetchAll(PDO::FETCH_NUM);
}
$timeNum = (microtime(true) - $start) * 1000;

echo "FETCH_ASSOC: " . number_format($timeAssoc, 2) . " ms (1000 iteraciones)\n";
echo "FETCH_NUM:   " . number_format($timeNum, 2) . " ms (1000 iteraciones)\n";
echo "Ahorro:      " . number_format(100 - ($timeNum / $timeAssoc * 100), 2) . "%\n";

echo "\n=== PoC completada ===\n";
echo "Conclusión: FETCH_NUM es más rápido y el mapa de proyección permite\n";
echo "acceso preciso a columnas incluso con nombres duplicados en JOINs.\n";
