<?php

/**
 * FromValuesTest - Prueba de Q::from()->values()
 *
 * Verifica la extracción de columnas y valores en formato SQL para INSERT.
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use RapidBase\Core\SQL\Q;
use RapidBase\Core\SQL\ConditionMatrix;
use RapidBase\Core\Conn;

// Entorno
Conn::setup('sqlite::memory:', '', '', 'main');
ConditionMatrix::setDriver('sqlite');

// Crear tabla e insertar datos
$pdo = Conn::get('main');
$pdo->exec("DROP TABLE IF EXISTS test_items; CREATE TABLE test_items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, price REAL)");
$pdo->exec("INSERT INTO test_items (name, price) VALUES ('Laptop', 999.99)");
$pdo->exec("INSERT INTO test_items (name, price) VALUES ('Mouse', 24.50)");
$pdo->exec("INSERT INTO test_items (name, price) VALUES ('Keyboard', 59.95)");

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
echo "PRUEBA DE Q::from()->values()\n";
echo "================================================\n\n";

// ---------- 1. Sin límite (todas las filas) ----------
echo "--- 1. Sin límite ---\n";
$data = Q::from('test_items')->values();
echo "Columns: " . json_encode($data['columns']) . "\n";
echo "Values:  " . $data['values'] . "\n";

$test("Devuelve array con keys 'columns' y 'values'", function() use ($data) {
    assert(isset($data['columns'], $data['values']));
});
$test("columns contiene id, name, price", function() use ($data) {
    assert($data['columns'] === ['id', 'name', 'price']);
});
$test("values contiene 3 filas", function() use ($data) {
    assert(substr_count($data['values'], '),(') === 2);
});
$test("values contiene 'Laptop'", function() use ($data) {
    assert(str_contains($data['values'], 'Laptop'));
});

// ---------- 2. Con límite ----------
echo "\n--- 2. Con límite ---\n";
$data = Q::from('test_items')->values(2, 0);
echo "Values (limit 2): " . $data['values'] . "\n";

$test("Solo 2 filas", function() use ($data) {
    assert(substr_count($data['values'], '),(') === 1);
});

// ---------- 3. Con filtro WHERE ----------
echo "\n--- 3. Con filtro WHERE ---\n";
$data = Q::from('test_items', ['price' => ['>' => 50]])->values();
echo "Values (price > 50): " . $data['values'] . "\n";

$test("Filtra por precio", function() use ($data) {
    assert(str_contains($data['values'], 'Laptop'));
    assert(str_contains($data['values'], 'Keyboard'));
    assert(!str_contains($data['values'], 'Mouse'));
});

echo "\n================================================\n";
echo "RESULTADO: $passed pasaron, $failed fallaron\n";
echo "================================================\n";