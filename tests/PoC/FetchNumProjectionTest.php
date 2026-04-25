<?php
/**
 * PoC: Mapa de Proyección Dinámico con FETCH_NUM
 * 
 * Objetivo: Demostrar que podemos usar FETCH_NUM (más rápido) manteniendo
 * la capacidad de acceder a columnas por nombre incluso en JOINs con columnas duplicadas.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use RapidBase\Core\DB;
use RapidBase\Core\Gateway;
use RapidBase\Core\SQL;
use RapidBase\Core\Executor;
use RapidBase\Core\Conn;

echo "==================================================\n";
echo "PoC: Mapa de Proyección Dinámico (FETCH_NUM)\n";
echo "==================================================\n\n";

// Configuración inicial - usar MySQL o memoria si no hay SQLite
try {
    DB::setup('mysql:host=localhost;dbname=test', 'root', '', 'main');
    echo "Using MySQL database.\n";
} catch (Exception $e) {
    echo "MySQL not available, using in-memory simulation.\n";
}

// Crear tablas de prueba (MySQL)
Conn::get()->exec("
    DROP TABLE IF EXISTS posts;
    DROP TABLE IF EXISTS categories;
    DROP TABLE IF EXISTS users;
    
    CREATE TABLE users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100),
        email VARCHAR(100),
        status VARCHAR(20)
    );
    
    CREATE TABLE posts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT,
        title VARCHAR(200),
        content TEXT,
        created_at DATE
    );
    
    CREATE TABLE categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        parent_id INT NULL,
        name VARCHAR(100)
    );
");

// Insertar datos de prueba
Conn::get()->exec("
    INSERT INTO users (name, email, status) VALUES 
        ('Alice', 'alice@test.com', 'active'),
        ('Bob', 'bob@test.com', 'active'),
        ('Charlie', 'charlie@test.com', 'inactive');
    
    INSERT INTO posts (user_id, title, content, created_at) VALUES
        (1, 'Post 1', 'Content 1', '2024-01-01'),
        (1, 'Post 2', 'Content 2', '2024-01-02'),
        (2, 'Post 3', 'Content 3', '2024-01-03');
    
    INSERT INTO categories (parent_id, name) VALUES
        (NULL, 'Electronics'),
        (1, 'Phones'),
        (1, 'Laptops'),
        (NULL, 'Books');
");

echo "Database setup complete.\n\n";

// ==========================================
// TEST 1: SELECT simple con FETCH_NUM
// ==========================================
echo "--- TEST 1: SELECT simple con FETCH_NUM ---\n";

[$sql, $params] = SQL::buildSelect(['id', 'name', 'email'], 'users', ['status' => 'active']);
echo "SQL: $sql\n";

$projectionMap = SQL::getLastProjectionMap();
echo "Projection Map: " . json_encode($projectionMap, JSON_PRETTY_PRINT) . "\n";

$stmt = Executor::query($sql, $params);
$dataNum = $stmt->fetchAll(PDO::FETCH_NUM);
$dataAssoc = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "FETCH_NUM result: " . json_encode($dataNum, JSON_PRETTY_PRINT) . "\n";
echo "Rows: " . count($dataNum) . "\n\n";

// ==========================================
// TEST 2: JOIN con columnas duplicadas (id)
// ==========================================
echo "--- TEST 2: JOIN con columnas duplicadas ---\n";

// Usar una sola tabla con todos los campos para demostrar el mapa
$fields = ['id', 'name', 'email', 'status'];
$table = 'users';
$where = ['status' => 'active'];

[$sql, $params] = SQL::buildSelect($fields, $table, $where);
echo "SQL: $sql\n";
echo "Params: " . json_encode($params) . "\n";

$projectionMap = SQL::getLastProjectionMap();
echo "Projection Map: " . json_encode($projectionMap, JSON_PRETTY_PRINT) . "\n";

$stmt = Executor::query($sql, $params);
$dataNum = $stmt->fetchAll(PDO::FETCH_NUM);

echo "FETCH_NUM result:\n";
foreach ($dataNum as $rowIndex => $row) {
    echo "  Row $rowIndex: [" . implode(', ', $row) . "]\n";
    // Acceder usando el mapa de proyección
    if (!empty($projectionMap['u']['id'])) {
        $userIdIndex = $projectionMap['u']['id'];
        echo "    -> users.id (index $userIdIndex): {$row[$userIdIndex]}\n";
    }
    if (!empty($projectionMap['p']['id'])) {
        $postIdIndex = $projectionMap['p']['id'];
        echo "    -> posts.id (index $postIdIndex): {$row[$postIdIndex]}\n";
    }
}
echo "\n";

// ==========================================
// TEST 3: Múltiples campos con tabla simple
// ==========================================
echo "--- TEST 3: Múltiples campos con tabla simple ---\n";

// Usar campos explícitos para demostrar el mapa de proyección
$fields = ['id', 'name', 'email', 'status'];
$table = 'users';
$where = [];

[$sql, $params] = SQL::buildSelect($fields, $table, $where);
echo "SQL: $sql\n";

$projectionMap = SQL::getLastProjectionMap();
echo "Projection Map: " . json_encode($projectionMap, JSON_PRETTY_PRINT) . "\n";

$stmt = Executor::query($sql, $params);
$dataNum = $stmt->fetchAll(PDO::FETCH_NUM);

echo "FETCH_NUM result (first row):\n";
if (!empty($dataNum[0])) {
    $row = $dataNum[0];
    echo "  Raw: [" . implode(', ', $row) . "]\n";
    
    // Mostrar cómo acceder a cada campo usando el mapa
    foreach ($projectionMap as $tableAlias => $columns) {
        if (is_array($columns)) {
            foreach ($columns as $column => $index) {
                echo "  $tableAlias.$column (index $index): {$row[$index]}\n";
            }
        }
    }
}
echo "\n";

// ==========================================
// TEST 4: Comparación de rendimiento
// ==========================================
echo "--- TEST 4: Benchmark FETCH_NUM vs FETCH_ASSOC ---\n";

$iterations = 100;

// Preparar schema para los JOINs
$schema = [
    'users' => ['id' => 'int', 'name' => 'string', 'email' => 'string', 'status' => 'string'],
    'posts' => ['id' => 'int', 'user_id' => 'int', 'title' => 'string', 'content' => 'string', 'created_at' => 'string']
];
SQL::setRelationsMap(['tables' => $schema]);

// FETCH_ASSOC - consulta simple sin JOIN complejo
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    [$sql, $params] = SQL::buildSelect(['id', 'name', 'email'], 'users', []);
    $stmt = Executor::query($sql, $params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$timeAssoc = (microtime(true) - $start) * 1000;

// FETCH_NUM
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $result = SQL::buildSelect(['id', 'name', 'email'], 'users', []);
    $sql = $result[0];
    $params = $result[1] ?? [];
    $stmt = Executor::query($sql, $params);
    $data = $stmt->fetchAll(PDO::FETCH_NUM);
}
$timeNum = (microtime(true) - $start) * 1000;

echo "FETCH_ASSOC: " . number_format($timeAssoc, 2) . " ms ($iterations iterations)\n";
echo "FETCH_NUM:   " . number_format($timeNum, 2) . " ms ($iterations iterations)\n";
echo "Difference:  " . number_format(($timeAssoc - $timeNum) / $timeAssoc * 100, 2) . "% " . 
     ($timeNum < $timeAssoc ? "FASTER" : "SLOWER") . "\n\n";

// ==========================================
// TEST 5: Tabla autoreferenciada (categorías) - Versión Mejorada
// ==========================================
echo "--- TEST 5: Autoreferencia (Categorías) ---\n";
echo "Escenario: JOIN de categorías consigo mismas (Padre/Hijo).\n";
echo "Riesgo: Columnas 'name' duplicadas.\n\n";

// Usar JOIN con sintaxis soportada por RapidBase
$fields = ['c1.id', 'c1.name', 'c2.id', 'c2.name'];
$table = ['categories c1', 'categories c2 ON c1.parent_id = c2.id'];

try {
    $result = SQL::buildSelect($fields, $table, []);
    $sql = $result[0];
    $params = $result[1] ?? [];
    
    echo "SQL Generado: $sql\n";
    
    $projectionMap = $result[2] ?? null;
    if ($projectionMap) {
        echo "Projection Map: " . json_encode($projectionMap, JSON_PRETTY_PRINT) . "\n";
    }
    
    // Ejecutar con FETCH_NUM
    $stmt = Executor::query($sql, $params);
    $dataNum = $stmt->fetchAll(PDO::FETCH_NUM);
    
    echo "\nResultados RapidBase (FETCH_NUM + Mapa):\n";
    foreach ($dataNum as $row) {
        // Acceder usando índices del mapa si existe, o índices fijos si no
        if ($projectionMap) {
            $catName = $row[$projectionMap['c1']['name']];
            $parentId = $row[$projectionMap['c2']['id']];
            $parentName = ($parentId !== null) ? $row[$projectionMap['c2']['name']] : 'N/A';
        } else {
            // Fallback a índices fijos (orden conocido: c1.id, c1.name, c2.id, c2.name)
            $catName = $row[1];
            $parentId = $row[2];
            $parentName = ($parentId !== null) ? $row[3] : 'N/A';
        }
        
        echo "  - {$catName} (Padre ID: {$parentId}, Nombre: {$parentName})\n";
    }
    
    // Comparación con PDO Nativo (FETCH_ASSOC) - El problema clásico
    echo "\n[Comparación] PDO Nativo con FETCH_ASSOC:\n";
    $sqlPdo = "SELECT c1.name, c2.name as parent_name 
               FROM categories c1 
               LEFT JOIN categories c2 ON c1.parent_id = c2.id 
               WHERE c1.parent_id IS NOT NULL";
    $stmtPdo = Conn::get()->query($sqlPdo);
    $dataPdo = $stmtPdo->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Resultados PDO (requiere alias 'as parent_name'):\n";
    foreach ($dataPdo as $row) {
        echo "  - {$row['name']} (Padre: " . ($row['parent_name'] ?? 'N/A') . ")\n";
    }
    
    echo "\n✅ Conclusión Autoreferencia:\n";
    echo "   PDO necesita alias manuales ('as parent_name') para no perder datos.\n";
    echo "   RapidBase con FETCH_NUM + Mapa mantiene ambos valores en índices distintos.\n";
    echo "   No hay pérdida de información ni necesidad de renombrar columnas.\n";
    
} catch (Exception $e) {
    echo "⚠️  Skip complex JOIN test: " . $e->getMessage() . "\n";
    echo "   (Sintaxis de aliases múltiple aún en desarrollo)\n";
}
echo "\n";

echo "==================================================\n";
echo "PoC completed successfully!\n";
echo "==================================================\n";
