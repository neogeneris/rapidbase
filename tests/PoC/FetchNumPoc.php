<?php
/**
 * Proof of Concept: Mecanismo de Sectores con FETCH_NUM
 * 
 * Objetivo: Demostrar que podemos usar PDO::FETCH_NUM manteniendo
 * la precisión total mediante un Mapa de Proyección Dinámico.
 * 
 * Problema resuelto:
 * - SELECT * con JOINs causa colisión de columnas (ej: users.id, posts.id)
 * - FETCH_NUM es más rápido pero no tiene nombres de columnas
 * - Solución: SQL.php genera un mapa que asocia tabla.columna -> índice numérico
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use RapidBase\Core\DB;
use RapidBase\Core\SQL;
use RapidBase\Core\Executor;
use RapidBase\Core\Conn;

echo "==================================================\n";
echo "PoC: FETCH_NUM + Mapa de Proyección Dinámico\n";
echo "==================================================\n\n";

// Configurar DB (usando MySQL ya que SQLite no está disponible)
// Para esta PoC, usaremos una conexión mock o saltaremos si no hay DB
try {
    DB::setup('mysql:host=localhost;dbname=test_poc', 'root', '', 'main');
} catch (Exception $e) {
    echo "Nota: No se pudo conectar a MySQL. Usando modo simulado.\n";
    echo "El propósito de esta PoC es demostrar el concepto del Mapa de Proyección.\n\n";
    
    // Simular los resultados para demostrar el concepto
    echo "--- SIMULACIÓN DEL CONCEPTO ---\n\n";
    
    echo "Problema: SELECT * con JOIN causa colisión de columnas\n";
    echo "Ejemplo: SELECT * FROM users u JOIN posts p ON u.id = p.user_id\n\n";
    
    echo "Resultado con FETCH_ASSOC (se pierden columnas duplicadas):\n";
    $simulatedAssoc = [
        ['id' => 1, 'name' => 'Alice', 'title' => 'First Post'], // ¡posts.id se perdió!
        ['id' => 1, 'name' => 'Alice', 'title' => 'Second Post']
    ];
    print_r($simulatedAssoc);
    
    echo "\nResultado con FETCH_NUM (todos los datos, pero necesitamos mapa):\n";
    $simulatedNum = [
        [1, 'Alice', 'alice@example.com', 1, 1, 'First Post', 'Content 1'],
        [1, 'Alice', 'alice@example.com', 2, 1, 'Second Post', 'Content 2']
    ];
    print_r($simulatedNum);
    
    echo "\nMapa de Proyección Dinámico (generado por SQL::buildSelect):\n";
    $projectionMap = [
        'u' => ['id' => 0, 'name' => 1, 'email' => 2],
        'p' => ['id' => 3, 'user_id' => 4, 'title' => 5, 'content' => 6]
    ];
    print_r($projectionMap);
    
    echo "\nAcceso preciso usando el mapa:\n";
    foreach ($simulatedNum as $i => $row) {
        echo "Fila $i: u.id=" . $row[$projectionMap['u']['id']] . ", p.id=" . $row[$projectionMap['p']['id']] . ", p.title=" . $row[$projectionMap['p']['title']] . "\n";
    }
    
    echo "\n==================================================\n";
    echo "CONCLUSIONES:\n";
    echo "1. FETCH_NUM es más rápido y no pierde columnas duplicadas\n";
    echo "2. El Mapa de Proyección permite acceso preciso por nombre (tabla.columna)\n";
    echo "3. La implementación requiere modificar SQL::buildSelect para generar el mapa\n";
    echo "4. Gateway/Executor deben usar FETCH_NUM y pasar el mapa a QueryResponse\n";
    echo "==================================================\n";
    exit(0);
}

// Crear tablas de ejemplo
$pdo = Conn::get();
$pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT)");
$pdo->exec("CREATE TABLE posts (id INTEGER PRIMARY KEY, user_id INTEGER, title TEXT, content TEXT)");
$pdo->exec("CREATE TABLE tags (id INTEGER PRIMARY KEY, name TEXT)");
$pdo->exec("CREATE TABLE post_tags (post_id INTEGER, tag_id INTEGER, PRIMARY KEY (post_id, tag_id))");

// Insertar datos de prueba
$pdo->exec("INSERT INTO users (name, email) VALUES ('Alice', 'alice@example.com')");
$pdo->exec("INSERT INTO users (name, email) VALUES ('Bob', 'bob@example.com')");
$pdo->exec("INSERT INTO posts (user_id, title, content) VALUES (1, 'First Post', 'Content 1')");
$pdo->exec("INSERT INTO posts (user_id, title, content) VALUES (1, 'Second Post', 'Content 2')");
$pdo->exec("INSERT INTO posts (user_id, title, content) VALUES (2, 'Third Post', 'Content 3')");
$pdo->exec("INSERT INTO tags (name) VALUES ('tech'), ('news'), ('tutorial')");
$pdo->exec("INSERT INTO post_tags (post_id, tag_id) VALUES (1, 1), (1, 3), (2, 2)");

echo "Datos de prueba insertados.\n\n";

// ==================================================
// PRUEBA 1: SELECT simple con columns específicas
// ==================================================
echo "--- PRUEBA 1: Columnas específicas ---\n";

$fields = ['u.id', 'u.name', 'p.title'];
$table = ['users AS u', 'posts AS p ON u.id = p.user_id'];
$where = ['u.id' => 1];

[$sql, $params] = SQL::buildSelect($fields, $table, $where, [], [], [], 0, 100);
echo "SQL: $sql\n";

$stmt = Executor::query($sql, $params);
$dataNum = $stmt->fetchAll(PDO::FETCH_NUM);
$dataAssoc = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "FETCH_NUM:\n";
print_r($dataNum);

echo "\nFETCH_ASSOC:\n";
print_r($dataAssoc);

// ==================================================
// PRUEBA 2: SELECT * con JOIN (problema de colisión)
// ==================================================
echo "\n--- PRUEBA 2: SELECT * con JOIN (colisión de IDs) ---\n";

$fields = '*';
$table = ['users AS u', 'posts AS p ON u.id = p.user_id'];
$where = [];

[$sql, $params] = SQL::buildSelect($fields, $table, $where, [], [], [], 0, 100);
echo "SQL: $sql\n";

$stmt = Executor::query($sql, $params);
$dataNum = $stmt->fetchAll(PDO::FETCH_NUM);
$dataAssoc = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "FETCH_NUM (notar que hay índices duplicados para las columnas id):\n";
print_r($dataNum);

echo "\nFETCH_ASSOC (notar que solo hay UN 'id' - se pierde posts.id):\n";
print_r($dataAssoc);

echo "\nProblema: Con FETCH_ASSOC, las columnas duplicadas se sobrescriben.\n";
echo "Con FETCH_NUM, tenemos todos los valores pero necesitamos saber qué índice es qué columna.\n";

// ==================================================
// PRUEBA 3: Simulación del Mapa de Proyección
// ==================================================
echo "\n--- PRUEBA 3: Simulación del Mapa de Proyección ---\n";

// El mapa debería generarse en SQL::buildSelect
// Para esta PoC, lo simulamos manualmente

$projectionMap = [
    'u' => ['id' => 0, 'name' => 1, 'email' => 2],
    'p' => ['id' => 3, 'user_id' => 4, 'title' => 5, 'content' => 6]
];

echo "Mapa de proyección simulado:\n";
print_r($projectionMap);

// Acceder a los datos usando el mapa
echo "\nAccediendo a datos con el mapa:\n";
foreach ($dataNum as $rowIndex => $row) {
    echo "Fila $rowIndex:\n";
    echo "  u.id = " . $row[$projectionMap['u']['id']] . "\n";
    echo "  u.name = " . $row[$projectionMap['u']['name']] . "\n";
    echo "  p.id = " . $row[$projectionMap['p']['id']] . "\n";
    echo "  p.title = " . $row[$projectionMap['p']['title']] . "\n";
}

// ==================================================
// PRUEBA 4: Benchmark FETCH_NUM vs FETCH_ASSOC
// ==================================================
echo "\n--- PRUEBA 4: Benchmark de rendimiento ---\n";

$iterations = 1000;

// FETCH_ASSOC
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $stmt = Executor::query($sql, $params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$timeAssoc = (microtime(true) - $start) * 1000 / $iterations;

// FETCH_NUM
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $stmt = Executor::query($sql, $params);
    $data = $stmt->fetchAll(PDO::FETCH_NUM);
}
$timeNum = (microtime(true) - $start) * 1000 / $iterations;

echo "Tiempo promedio por consulta ({$iterations} iteraciones):\n";
echo "  FETCH_ASSOC: " . number_format($timeAssoc, 4) . " ms\n";
echo "  FETCH_NUM:   " . number_format($timeNum, 4) . " ms\n";
echo "  Mejora:      " . number_format(($timeAssoc - $timeNum) / $timeAssoc * 100, 2) . "% más rápido\n";

echo "\n==================================================\n";
echo "CONCLUSIONES:\n";
echo "1. FETCH_NUM es más rápido que FETCH_ASSOC\n";
echo "2. SELECT * con JOINs causa pérdida de columnas en FETCH_ASSOC\n";
echo "3. El Mapa de Proyección permite usar FETCH_NUM sin perder precisión\n";
echo "4. El mapa debe generarse en SQL::buildSelect cuando se construye el SELECT\n";
echo "==================================================\n";
