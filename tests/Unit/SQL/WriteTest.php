<?php

/**
 * WriteTest - Prueba de operaciones de escritura y consulta escalar de Q.
 *
 * Cubre:
 *   - insert (simple y múltiple)
 *   - update
 *   - delete
 *   - count
 *   - exists
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use RapidBase\Core\SQL\Q;
use RapidBase\Core\SQL\ConditionMatrix;

$passed = 0;
$failed = 0;

function test(string $description, callable $fn): void {
    global $passed, $failed;
    try {
        $fn();
        echo "  [OK] $description\n";
        $passed++;
    } catch (Throwable $e) {
        echo "  [FAIL] $description\n";
        echo "         Error: " . $e->getMessage() . "\n";
        $failed++;
    }
}

echo "================================================\n";
echo "PRUEBA DE OPERACIONES DE ESCRITURA Y CONSULTA\n";
echo "================================================\n\n";

// ─── 1. INSERT simple ───────────────────────────────────────
echo "--- 1. INSERT simple ---\n";
$compiled = Q::into('users')->insert(['name' => 'Alice', 'email' => 'alice@test.com']);
$sql      = $compiled->getSql();
$params   = $compiled->getParams();

echo "SQL: $sql\n";
echo "Params: " . json_encode($params) . "\n";

test("Contiene INSERT INTO", function () use ($sql) {
    assert(str_contains($sql, 'INSERT INTO'));
});
test("Dos columnas", function () use ($sql) {
    assert(str_contains($sql, '"name", "email"'));
});
test("Dos placeholders", function () use ($sql) {
    assert(substr_count($sql, '?') === 2);
});
test("Parametros correctos", function () use ($params) {
    assert($params === ['Alice', 'alice@test.com']);
});

// ─── 2. INSERT múltiple ─────────────────────────────────────
echo "\n--- 2. INSERT multiple ---\n";
$compiled = Q::into('users')->insert([
    ['name' => 'Bob', 'email' => 'bob@test.com'],
    ['name' => 'Eve', 'email' => 'eve@test.com'],
]);
$sql = $compiled->getSql();

echo "SQL: $sql\n";
echo "Params: " . json_encode($compiled->getParams()) . "\n";

test("Contiene dos grupos de VALUES", function () use ($sql) {
    assert(substr_count($sql, 'VALUES') === 1);
    assert(substr_count($sql, '?, ?') === 2);
});
test("Tiene 4 params", function () use ($compiled) {
    assert(count($compiled->getParams()) === 4);
});

// ─── 3. UPDATE ──────────────────────────────────────────────
echo "\n--- 3. UPDATE ---\n";
$compiled = Q::from('users', ['id' => 1])->update(['name' => 'Charlie']);
$sql      = $compiled->getSql();
$params   = $compiled->getParams();

echo "SQL: $sql\n";
echo "Params: " . json_encode($params) . "\n";

test("Contiene UPDATE", function () use ($sql) {
    assert(str_contains($sql, 'UPDATE'));
});
test("Contiene SET", function () use ($sql) {
    assert(str_contains($sql, 'SET'));
});
test("Contiene WHERE", function () use ($sql) {
    assert(str_contains($sql, 'WHERE'));
});
test("Params con condicion", function () use ($params) {
    // El parametro del SET va primero, luego el del WHERE
    assert($params[0] === 'Charlie');
    assert($params[1] === 1);
});

// ─── 4. DELETE ──────────────────────────────────────────────
echo "\n--- 4. DELETE ---\n";
$compiled = Q::from('users', ['id' => 99])->delete();
$sql      = $compiled->getSql();
$params   = $compiled->getParams();

echo "SQL: $sql\n";
echo "Params: " . json_encode($params) . "\n";

test("DELETE FROM", function () use ($sql) {
    assert(str_starts_with($sql, 'DELETE FROM'));
});
test("WHERE id = ?", function () use ($sql) {
    assert(str_contains($sql, '"id" = ?'));
});
test("Un solo param", function () use ($params) {
    assert(count($params) === 1);
    assert($params[0] === 99);
});

// ─── 5. COUNT ───────────────────────────────────────────────
echo "\n--- 5. COUNT ---\n";
$compiled = Q::from('users', ['active' => 1])->count();
$sql      = $compiled->getSql();
$params   = $compiled->getParams();

echo "SQL: $sql\n";
echo "Params: " . json_encode($params) . "\n";

test("SELECT COUNT(*)", function () use ($sql) {
    assert(str_starts_with($sql, 'SELECT COUNT(*)'));
});
test("WHERE active = ?", function () use ($sql) {
    assert(str_contains($sql, '"active" = ?'));
});
test("Parametro es 1", function () use ($params) {
    assert($params[0] === 1);
});

// ─── 6. EXISTS ──────────────────────────────────────────────
echo "\n--- 6. EXISTS ---\n";
$compiled = Q::from('users', ['email' => 'test@test.com'])->exists();
$sql      = $compiled->getSql();
$params   = $compiled->getParams();

echo "SQL: $sql\n";
echo "Params: " . json_encode($params) . "\n";

test("SELECT EXISTS", function () use ($sql) {
    assert(str_starts_with($sql, 'SELECT EXISTS'));
});
test("Contiene subquery", function () use ($sql) {
    assert(str_contains($sql, 'SELECT 1'));
});
test("WHERE email = ?", function () use ($sql) {
    assert(str_contains($sql, '"email" = ?'));
});
test("Parametro email", function () use ($params) {
    assert($params[0] === 'test@test.com');
});

echo "\n================================================\n";
echo "RESULTADO: $passed pasaron, $failed fallaron\n";
echo "================================================\n";