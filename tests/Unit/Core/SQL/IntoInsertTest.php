<?php

/**
 * IntoInsertTest - Prueba de las 4 formas de Q::into()->insert()
 *
 * 1. Array de filas
 * 2. Array de filas + callback
 * 3. from()->values() → insert()
 * 4. CompiledQuery → INSERT ... SELECT
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use RapidBase\Core\SQL\Q;
use RapidBase\Core\SQL\ConditionMatrix;
use RapidBase\Core\Conn;

// Entorno
Conn::setup('sqlite::memory:', '', '', 'main');
ConditionMatrix::setDriver('sqlite');

// Crear tablas
$pdo = Conn::get('main');
$pdo->exec("CREATE TABLE source_items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, price REAL)");
$pdo->exec("CREATE TABLE dest_items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, price REAL)");
$pdo->exec("INSERT INTO source_items (name, price) VALUES ('Laptop', 999.99)");
$pdo->exec("INSERT INTO source_items (name, price) VALUES ('Mouse', 24.50)");
$pdo->exec("INSERT INTO source_items (name, price) VALUES ('Keyboard', 59.95)");

$passed = 0;
$failed = 0;

$test = function(string $description, callable $fn) use (&$passed, &$failed): void {
    try {
        $fn();
        echo "  [OK] $description\n";
        $passed++;
    } catch (Throwable $e) {
        echo "  [FAIL] $description\n";
        echo "         Error: " . $e->getMessage() . "\n";
        $failed++;
    }
};

echo "================================================\n";
echo "PRUEBA DE Q::into()->insert() (4 formas)\n";
echo "================================================\n\n";

// ---------- Forma 1: Array de filas ----------
echo "--- Forma 1: Array de filas ---\n";
$compiled = Q::into('dest_items')->insert([
    ['name' => 'Monitor', 'price' => 199.99],
    ['name' => 'Webcam',  'price' => 49.50],
]);
$sql = $compiled->getSql();
echo "SQL: $sql\n";

$compiled->run();
$count = $pdo->query("SELECT COUNT(*) FROM dest_items")->fetchColumn();
$test("Insertó 2 filas desde array directo", function() use ($count) {
    assert($count == 2);
});

// Limpiar para la siguiente prueba
$pdo->exec("DELETE FROM dest_items");

// ---------- Forma 2: Array de filas + callback ----------
echo "\n--- Forma 2: Array de filas + callback ---\n";
$rows = [
    ['name' => 'Laptop', 'price' => 999.99],
    ['name' => 'Mouse',  'price' => 24.50],
];
$compiled = Q::into('dest_items')->insert($rows, function($row) {
    $row['name'] = strtoupper($row['name']);
    $row['price'] *= 1.1;
    return $row;
});
$sql = $compiled->getSql();
echo "SQL: $sql\n";

$compiled->run();
$row = $pdo->query("SELECT * FROM dest_items WHERE name = 'LAPTOP'")->fetch(PDO::FETCH_ASSOC);
$test("Transformación aplicada (nombre en mayúsculas)", function() use ($row) {
    assert($row['name'] === 'LAPTOP');
});
$test("Precio incrementado 10%", function() use ($row) {
    assert(abs($row['price'] - 1099.989) < 0.1);
});

// Limpiar para la siguiente prueba
$pdo->exec("DELETE FROM dest_items");

// ---------- Forma 3: from()->values() → insert() ----------
echo "\n--- Forma 3: from()->values() como entrada ---\n";
$data = Q::from('source_items')->values();
$compiled = Q::into('dest_items')->insert($data);
$sql = $compiled->getSql();
echo "SQL: $sql\n";

$compiled->run();
$count = $pdo->query("SELECT COUNT(*) FROM dest_items")->fetchColumn();
$test("Insertó 3 filas desde from()->values()", function() use ($count) {
    assert($count == 3);
});

// Limpiar para la siguiente prueba
$pdo->exec("DELETE FROM dest_items");

// ---------- Forma 4: INSERT ... SELECT (CompiledQuery) ----------
echo "\n--- Forma 4: INSERT ... SELECT ---\n";
$source = Q::from('source_items', ['price' => ['>' => 50]])->select(['name', 'price']);
$compiled = Q::into('dest_items')->insert($source);
$sql = $compiled->getSql();
echo "Source SQL: " . $source->getSql() . "\n";
echo "Insert SQL: $sql\n";

$compiled->run();
$count = $pdo->query("SELECT COUNT(*) FROM dest_items")->fetchColumn();
$test("Insertó 2 filas desde SELECT (price > 50)", function() use ($count) {
    assert($count == 2);
});

echo "\n================================================\n";
echo "RESULTADO: $passed pasaron, $failed fallaron\n";
echo "================================================\n";