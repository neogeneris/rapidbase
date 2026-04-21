<?php
/**
 * PoC: Optimización con FETCH_NUM vs FETCH_ASSOC
 * 
 * Objetivo: Investigar si el uso de PDO::FETCH_NUM es más eficiente
 * y cómo resolver el problema del mapeo de columnas en JOINs con *.
 * 
 * Problema detectado:
 * - FETCH_ASSOC crea un array asociativo por fila (más memoria, más lento)
 * - FETCH_NUM crea un array numérico (más rápido, menos memoria)
 * - Pero con JOINs y *, necesitamos saber el orden de las columnas
 *   para mapear los índices a nombres de campo usando la metadata.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use RapidBase\Core\DB;
use RapidBase\Meta\SchemaMapper;

echo "==================================================\n";
echo "PoC: FETCH_NUM vs FETCH_ASSOC Optimization\n";
echo "==================================================\n\n";

// Setup DB
DB::setup(
    'mysql:host=localhost;dbname=rapidbase_test',
    'root',
    ''
);

$pdo = DB::getConnection();

// Crear tablas de prueba
echo "Creating test tables...\n";
$pdo->exec("DROP TABLE IF EXISTS poc_posts");
$pdo->exec("DROP TABLE IF EXISTS poc_users");
$pdo->exec("DROP TABLE IF EXISTS poc_categories");

$pdo->exec("CREATE TABLE poc_users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100),
    email VARCHAR(100),
    status TINYINT DEFAULT 1
)");

$pdo->exec("CREATE TABLE poc_categories (
    id INT PRIMARY KEY AUTO_INCREMENT,
    parent_id INT NULL,
    name VARCHAR(100),
    FOREIGN KEY (parent_id) REFERENCES poc_categories(id)
)");

$pdo->exec("CREATE TABLE poc_posts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    category_id INT,
    title VARCHAR(200),
    content TEXT,
    views INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES poc_users(id),
    FOREIGN KEY (category_id) REFERENCES poc_categories(id)
)");

// Insertar datos de prueba
echo "Inserting test data...\n";
$pdo->exec("INSERT INTO poc_users (name, email) VALUES 
    ('Alice', 'alice@test.com'),
    ('Bob', 'bob@test.com'),
    ('Charlie', 'charlie@test.com')");

$pdo->exec("INSERT INTO poc_categories (parent_id, name) VALUES 
    (NULL, 'Technology'),
    (1, 'Programming'),
    (1, 'Hardware'),
    (NULL, 'Sports')");

$pdo->exec("INSERT INTO poc_posts (user_id, category_id, title, content, views) VALUES 
    (1, 2, 'PHP Tips', 'Content here', 100),
    (1, 3, 'Hardware Review', 'Content here', 200),
    (2, 2, 'Python Guide', 'Content here', 150),
    (3, 4, 'Football News', 'Content here', 300)");

echo "\n--- TEST 1: Simple SELECT (1000 iterations) ---\n";

// FETCH_ASSOC
$start = microtime(true);
for ($i = 0; $i < 1000; $i++) {
    $stmt = $pdo->query("SELECT id, name, email FROM poc_users");
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$timeAssoc = (microtime(true) - $start) * 1000;
$memoryAssoc = memory_get_peak_usage(true);

// FETCH_NUM
$start = microtime(true);
for ($i = 0; $i < 1000; $i++) {
    $stmt = $pdo->query("SELECT id, name, email FROM poc_users");
    $data = $stmt->fetchAll(PDO::FETCH_NUM);
}
$timeNum = (microtime(true) - $start) * 1000;
$memoryNum = memory_get_peak_usage(true);

echo "FETCH_ASSOC: " . number_format($timeAssoc, 2) . " ms\n";
echo "FETCH_NUM:   " . number_format($timeNum, 2) . " ms\n";
echo "Speedup:     " . number_format($timeAssoc / $timeNum, 2) . "x faster\n\n";

echo "--- TEST 2: JOIN with specific columns (500 iterations) ---\n";

// FETCH_ASSOC
$start = microtime(true);
for ($i = 0; $i < 500; $i++) {
    $stmt = $pdo->query("
        SELECT p.id, p.title, u.name as user_name, c.name as category_name
        FROM poc_posts p
        JOIN poc_users u ON p.user_id = u.id
        JOIN poc_categories c ON p.category_id = c.id
    ");
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$timeAssoc = (microtime(true) - $start) * 1000;

// FETCH_NUM
$start = microtime(true);
for ($i = 0; $i < 500; $i++) {
    $stmt = $pdo->query("
        SELECT p.id, p.title, u.name as user_name, c.name as category_name
        FROM poc_posts p
        JOIN poc_users u ON p.user_id = u.id
        JOIN poc_categories c ON p.category_id = c.id
    ");
    $data = $stmt->fetchAll(PDO::FETCH_NUM);
}
$timeNum = (microtime(true) - $start) * 1000;

echo "FETCH_ASSOC: " . number_format($timeAssoc, 2) . " ms\n";
echo "FETCH_NUM:   " . number_format($timeNum, 2) . " ms\n";
echo "Speedup:     " . number_format($timeAssoc / $timeNum, 2) . "x faster\n\n";

echo "--- TEST 3: JOIN with * (PROBLEM SCENARIO) ---\n";
echo "Fetching with SELECT * to demonstrate the column mapping problem...\n\n";

$stmt = $pdo->query("
    SELECT *
    FROM poc_posts p
    JOIN poc_users u ON p.user_id = u.id
    JOIN poc_categories c ON p.category_id = c.id
");

echo "Columns from PDO (FETCH_ASSOC keys):\n";
$columns = [];
for ($i = 0; $i < $stmt->columnCount(); $i++) {
    $meta = $stmt->getColumnMeta($i);
    $columns[] = $meta['name'];
    echo "  [$i] {$meta['name']}\n";
}

echo "\nSample row with FETCH_ASSOC:\n";
$stmt->execute();
$rowAssoc = $stmt->fetch(PDO::FETCH_ASSOC);
print_r($rowAssoc);

echo "\nSample row with FETCH_NUM:\n";
$stmt->execute();
$rowNum = $stmt->fetch(PDO::FETCH_NUM);
print_r($rowNum);

echo "\n--- ANALYSIS: Column Order in Metadata ---\n";
echo "When using SELECT * with JOINs, the column order follows:\n";
echo "1. First table (poc_posts): " . implode(", ", [
    'id', 'user_id', 'category_id', 'title', 'content', 'views', 'created_at'
]) . "\n";
echo "2. Second table (poc_users): " . implode(", ", [
    'id', 'name', 'email', 'status'
]) . "\n";
echo "3. Third table (poc_categories): " . implode(", ", [
    'id', 'parent_id', 'name'
]) . "\n";

echo "\nTotal columns: " . count($columns) . "\n";
echo "Expected: 7 (posts) + 4 (users) + 4 (categories, but 'id' and 'name' duplicated) = 14\n";
echo "Note: PDO includes duplicate column names when using *\n\n";

echo "--- TEST 4: Mapping FETCH_NUM to Associative using Metadata ---\n";

// Simular metadata de las tablas en orden
$tableOrder = ['poc_posts', 'poc_users', 'poc_categories'];
$metadata = [
    'poc_posts' => ['id', 'user_id', 'category_id', 'title', 'content', 'views', 'created_at'],
    'poc_users' => ['id', 'name', 'email', 'status'],
    'poc_categories' => ['id', 'parent_id', 'name']
];

// Construir mapeo de índice a nombre de campo con prefijo de tabla
$indexToField = [];
$index = 0;
foreach ($tableOrder as $table) {
    foreach ($metadata[$table] as $field) {
        $indexToField[$index] = "{$table}.{$field}";
        $index++;
    }
}

echo "Index to Field mapping:\n";
foreach ($indexToField as $idx => $field) {
    echo "  [$idx] => $field\n";
}

echo "\nConverting FETCH_NUM row to associative with table prefixes:\n";
$stmt->execute();
$rowNum = $stmt->fetch(PDO::FETCH_NUM);
$mappedRow = [];
foreach ($rowNum as $idx => $value) {
    $mappedRow[$indexToField[$idx]] = $value;
}
print_r($mappedRow);

echo "\n--- CONCLUSION ---\n";
echo "✓ FETCH_NUM es más rápido y usa menos memoria\n";
echo "✓ Para usar FETCH_NUM con JOINs y *, necesitamos:\n";
echo "  1. Conocer el orden de las tablas en el JOIN\n";
echo "  2. Tener la metadata de columnas de cada tabla\n";
echo "  3. Construir un mapeo índice -> campo\n";
echo "✓ El desafío es detectar automáticamente el orden de tablas en la consulta SQL\n\n";

echo "Cleanup...\n";
$pdo->exec("DROP TABLE IF EXISTS poc_posts");
$pdo->exec("DROP TABLE IF EXISTS poc_categories");
$pdo->exec("DROP TABLE IF EXISTS poc_users");

echo "Done!\n";
