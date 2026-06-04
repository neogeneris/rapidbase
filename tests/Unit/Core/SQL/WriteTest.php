<?php

/**
 * WriteTest - Prueba de operaciones de escritura y consulta escalar de Q.
 *
 * Cubre:
 *   - insert (simple, múltiple, con objetos)
 *   - update (normal, con objeto, con límite)
 *   - delete (normal, con límite)
 *   - count
 *   - exists
 */

require_once __DIR__ . '/../../../../vendor/autoload.php';

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
echo "PRUEBA DE OPERACIONES DE ESCRITURA Y CONSULTA\n";
echo "================================================\n\n";

// ─── 1. INSERT simple ───────────────────────────────────────
echo "--- 1. INSERT simple ---\n";
$compiled = Q::into('users')->insert(['name' => 'Alice', 'email' => 'alice@test.com']);
$sql      = $compiled->getSql();
$params   = $compiled->getParams();

echo "SQL: $sql\n";
echo "Params: " . json_encode($params) . "\n";

$test("Contiene INSERT INTO", function () use ($sql) {
    assert(str_contains($sql, 'INSERT INTO'));
});
$test("Dos columnas", function () use ($sql) {
    assert(str_contains($sql, '"name", "email"'));
});
$test("Dos placeholders", function () use ($sql) {
    assert(substr_count($sql, '?') === 2);
});
$test("Parametros correctos", function () use ($params) {
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

$test("Contiene dos grupos de VALUES", function () use ($sql) {
    assert(substr_count($sql, 'VALUES') === 1);
    assert(substr_count($sql, '?, ?') === 2);
});
$test("Tiene 4 params", function () use ($compiled) {
    assert(count($compiled->getParams()) === 4);
});

// ─── 3. INSERT con objetos ─────────────────────────────────
echo "\n--- 3. INSERT con objetos ---\n";
$obj1 = new stdClass();
$obj1->name = 'Oscar';
$obj1->email = 'oscar@test.com';
$obj2 = new stdClass();
$obj2->name = 'Paula';
$obj2->email = 'paula@test.com';

$compiled = Q::into('users')->insert([$obj1, $obj2]);
$sql = $compiled->getSql();
$params = $compiled->getParams();

echo "SQL: $sql\n";
echo "Params: " . json_encode($params) . "\n";

$test("Acepta array de objetos", function () use ($sql) {
    assert(str_contains($sql, 'INSERT INTO'));
    assert(substr_count($sql, '?') === 4);
});
$test("Convierte objetos a arrays", function () use ($params) {
    assert($params === ['Oscar', 'oscar@test.com', 'Paula', 'paula@test.com']);
});

// ─── 4. UPDATE normal ──────────────────────────────────────
echo "\n--- 4. UPDATE ---\n";
$compiled = Q::from('users', ['id' => 1])->update(['name' => 'Charlie']);
$sql      = $compiled->getSql();
$params   = $compiled->getParams();

echo "SQL: $sql\n";
echo "Params: " . json_encode($params) . "\n";

$test("Contiene UPDATE", function () use ($sql) {
    assert(str_contains($sql, 'UPDATE'));
});
$test("Contiene SET", function () use ($sql) {
    assert(str_contains($sql, 'SET'));
});
$test("Contiene WHERE", function () use ($sql) {
    assert(str_contains($sql, 'WHERE'));
});
$test("Params con condicion", function () use ($params) {
    assert($params[0] === 'Charlie');
    assert($params[1] === 1);
});

// ─── 5. UPDATE con objeto ──────────────────────────────────
echo "\n--- 5. UPDATE con objeto ---\n";
$data = new stdClass();
$data->name = 'Diana';
$data->email = 'diana@test.com';

$compiled = Q::from('users', ['id' => 2])->update($data);
$sql = $compiled->getSql();
$params = $compiled->getParams();

echo "SQL: $sql\n";
echo "Params: " . json_encode($params) . "\n";

$test("UPDATE acepta objeto", function () use ($sql) {
    assert(str_contains($sql, 'UPDATE'));
    assert(str_contains($sql, '"name" = ?'));
});
$test("Convierte objeto a array para parámetros", function () use ($params) {
    assert($params[0] === 'Diana');
    assert($params[1] === 'diana@test.com');
    assert($params[2] === 2); // where id = 2
});

// ─── 6. DELETE normal ──────────────────────────────────────
echo "\n--- 6. DELETE ---\n";
$compiled = Q::from('users', ['id' => 99])->delete();
$sql      = $compiled->getSql();
$params   = $compiled->getParams();

echo "SQL: $sql\n";
echo "Params: " . json_encode($params) . "\n";

$test("DELETE FROM", function () use ($sql) {
    assert(str_starts_with($sql, 'DELETE FROM'));
});
$test("WHERE id = ?", function () use ($sql) {
    assert(str_contains($sql, '"id" = ?'));
});
$test("Un solo param", function () use ($params) {
    assert(count($params) === 1);
    assert($params[0] === 99);
});

// ─── 7. DELETE con límite (SQLite usa rowid) ───────────────
echo "\n--- 7. DELETE con límite ---\n";
$compiled = Q::from('users', ['active' => 1])->delete(2);
$sql = $compiled->getSql();
$params = $compiled->getParams();

echo "SQL: $sql\n";
echo "Params: " . json_encode($params) . "\n";

$test("DELETE con límite usa rowid", function () use ($sql) {
    assert(str_contains($sql, 'rowid'));
    assert(str_contains($sql, 'LIMIT 2'));
});
$test("Conserva parámetros WHERE", function () use ($params) {
    assert($params[0] === 1);
});

// ─── 8. UPDATE con límite ──────────────────────────────────
echo "\n--- 8. UPDATE con límite ---\n";
$compiled = Q::from('users', ['active' => 0])->update(['active' => 1], 1);
$sql = $compiled->getSql();
$params = $compiled->getParams();

echo "SQL: $sql\n";
echo "Params: " . json_encode($params) . "\n";

$test("UPDATE con límite usa rowid", function () use ($sql) {
    assert(str_contains($sql, 'rowid'));
    assert(str_contains($sql, 'LIMIT 1'));
});
$test("SET parámetros antes que WHERE en límite", function () use ($params) {
    // El SET tiene un parámetro (active=1) y el WHERE otro (active=0)
    // pero con subconsulta el WHERE va dentro de la subconsulta, por lo que los params del WHERE están antes? 
    // Realmente la construcción es: UPDATE ... SET ... WHERE rowid IN (SELECT rowid FROM ... WHERE ... LIMIT 1)
    // Los params del WHERE van después de los del SET, igual que en el caso sin límite.
    assert($params[0] === 1);    // valor del SET
    assert($params[1] === 0);    // valor del WHERE
});

// ─── 9. COUNT ───────────────────────────────────────────────
echo "\n--- 9. COUNT ---\n";
$compiled = Q::from('users', ['active' => 1])->count();
$sql      = $compiled->getSql();
$params   = $compiled->getParams();

echo "SQL: $sql\n";
echo "Params: " . json_encode($params) . "\n";

$test("SELECT COUNT(*)", function () use ($sql) {
    assert(str_starts_with($sql, 'SELECT COUNT(*)'));
});
$test("WHERE active = ?", function () use ($sql) {
    assert(str_contains($sql, '"active" = ?'));
});
$test("Parametro es 1", function () use ($params) {
    assert($params[0] === 1);
});

// ─── 10. EXISTS ──────────────────────────────────────────────
echo "\n--- 10. EXISTS ---\n";
$compiled = Q::from('users', ['email' => 'test@test.com'])->exists();
$sql      = $compiled->getSql();
$params   = $compiled->getParams();

echo "SQL: $sql\n";
echo "Params: " . json_encode($params) . "\n";

$test("SELECT EXISTS", function () use ($sql) {
    assert(str_starts_with($sql, 'SELECT EXISTS'));
});
$test("Contiene subquery", function () use ($sql) {
    assert(str_contains($sql, 'SELECT 1'));
});
$test("WHERE email = ?", function () use ($sql) {
    assert(str_contains($sql, '"email" = ?'));
});
$test("Parametro email", function () use ($params) {
    assert($params[0] === 'test@test.com');
});

echo "\n================================================\n";
echo "RESULTADO: $passed pasaron, $failed fallaron\n";
echo "================================================\n";