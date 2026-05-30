<?php

/**
 * UpsertTest - Prueba de Q::into()->upsert()
 *
 * Verifica la generación de INSERT ON CONFLICT / ON DUPLICATE KEY UPDATE
 * según el driver configurado.
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use RapidBase\Core\SQL\Q;
use RapidBase\Core\SQL\ConditionMatrix;

$passed = 0;
$failed = 0;

$test = function(string $description, callable $fn) use (&$passed, &$failed) {
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
echo "PRUEBA DE Q::upsert()\n";
echo "================================================\n\n";

// ---------- 1. UPSERT SQLite / PostgreSQL (con columna de conflicto) ----------
echo "--- 1. UPSERT SQLite / PostgreSQL (ON CONFLICT) ---\n";
ConditionMatrix::setDriver('sqlite');

$compiled = Q::into('users')->upsert(
    ['id' => 5, 'name' => 'John', 'email' => 'john@test.com'],
    ['id']
);
$sql = $compiled->getSql();
$params = $compiled->getParams();

echo "SQL: $sql\n";
echo "Params: " . json_encode($params) . "\n";

$test("Contiene INSERT INTO", function() use ($sql) {
    assert(str_contains($sql, 'INSERT INTO'));
});
$test("Contiene ON CONFLICT", function() use ($sql) {
    assert(str_contains($sql, 'ON CONFLICT'));
});
$test("Contiene DO UPDATE SET", function() use ($sql) {
    assert(str_contains($sql, 'DO UPDATE SET'));
});
$test("Usa excluded.name", function() use ($sql) {
    assert(str_contains($sql, 'excluded.'));
});
$test("No usa ON DUPLICATE KEY", function() use ($sql) {
    assert(!str_contains($sql, 'ON DUPLICATE KEY'));
});
$test("Número de parámetros", function() use ($params) {
    assert(count($params) === 3);
});

// ---------- 2. UPSERT SQLite / PostgreSQL sin columna de conflicto ----------
echo "\n--- 2. UPSERT SQLite sin conflicto (INSERT simple) ---\n";
$compiled = Q::into('users')->upsert(['name' => 'Alice', 'email' => 'alice@test.com']);
$sql = $compiled->getSql();

echo "SQL: $sql\n";

$test("Es un INSERT simple", function() use ($sql) {
    assert(!str_contains($sql, 'ON CONFLICT'));
});

// ---------- 3. UPSERT SQLite con DO NOTHING cuando todos los campos son el conflicto ----------
echo "\n--- 3. UPSERT SQLite DO NOTHING ---\n";
$compiled = Q::into('users')->upsert(
    ['id' => 5, 'name' => 'John'],
    ['id', 'name']   // todas las columnas son parte del conflicto
);
$sql = $compiled->getSql();

echo "SQL: $sql\n";

$test("Contiene DO NOTHING", function() use ($sql) {
    assert(str_contains($sql, 'DO NOTHING'));
});
$test("No contiene DO UPDATE SET", function() use ($sql) {
    assert(!str_contains($sql, 'DO UPDATE SET'));
});

// ---------- 4. UPSERT MySQL (ON DUPLICATE KEY UPDATE) ----------
echo "\n--- 4. UPSERT MySQL ---\n";
ConditionMatrix::setDriver('mysql');

$compiled = Q::into('users')->upsert(
    ['id' => 5, 'name' => 'John', 'email' => 'john@test.com'],
    ['id']
);
$sql = $compiled->getSql();
$params = $compiled->getParams();

echo "SQL: $sql\n";
echo "Params: " . json_encode($params) . "\n";

$test("Contiene INSERT INTO", function() use ($sql) {
    assert(str_contains($sql, 'INSERT INTO'));
});
$test("Contiene ON DUPLICATE KEY UPDATE", function() use ($sql) {
    assert(str_contains($sql, 'ON DUPLICATE KEY UPDATE'));
});
$test("Usa VALUES()", function() use ($sql) {
    assert(str_contains($sql, 'VALUES('));
});
$test("No usa ON CONFLICT", function() use ($sql) {
    assert(!str_contains($sql, 'ON CONFLICT'));
});

// ---------- 5. UPSERT MySQL sin conflicto (INSERT simple) ----------
echo "\n--- 5. UPSERT MySQL sin conflicto ---\n";
$compiled = Q::into('users')->upsert(['name' => 'Bob']);
$sql = $compiled->getSql();

echo "SQL: $sql\n";

$test("INSERT simple (sin UPDATE)", function() use ($sql) {
    assert(!str_contains($sql, 'ON DUPLICATE KEY'));
});

// ---------- 6. UPSERT MySQL INSERT IGNORE cuando todos los campos son el conflicto ----------
echo "\n--- 6. UPSERT MySQL INSERT IGNORE ---\n";
$compiled = Q::into('users')->upsert(
    ['id' => 5, 'name' => 'John'],
    ['id', 'name']
);
$sql = $compiled->getSql();

echo "SQL: $sql\n";

$test("Contiene INSERT IGNORE", function() use ($sql) {
    assert(str_contains($sql, 'INSERT IGNORE'));
});

echo "\n================================================\n";
echo "RESULTADO: $passed pasaron, $failed fallaron\n";
echo "================================================\n";