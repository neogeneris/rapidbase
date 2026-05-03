<?php

/**
 * InsertSelectTest - Prueba de Q::into()->insertSelect()
 *
 * Verifica la generación de INSERT INTO ... SELECT ...
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use RapidBase\Core\SQL\Q;
use RapidBase\Core\SQL\ConditionMatrix;
use RapidBase\Core\SQL\CompiledQuery;
use RapidBase\Core\SchemaMap;
use RapidBase\Core\Conn;

// Preparar entorno y esquema mínimo
Conn::setup('sqlite::memory:', '', '', 'main');
ConditionMatrix::setDriver('sqlite');

$schema = [
    'tables' => [
        'users' => [
            'id'    => ['type' => 'int'],
            'name'  => ['type' => 'varchar'],
            'email' => ['type' => 'varchar']
        ],
        'active_users' => [
            'id'    => ['type' => 'int'],
            'name'  => ['type' => 'varchar'],
            'email' => ['type' => 'varchar']
        ]
    ]
];

SchemaMap::setMap($schema, 'main');

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
echo "PRUEBA DE Q::insertSelect()\n";
echo "================================================\n\n";

// ---------- 1. Con columnas explícitas ----------
echo "--- 1. InsertSelect con columnas explícitas ---\n";
$source = Q::from('users', ['active' => 1])->select(['id', 'name', 'email']);
$insert = Q::into('active_users')->insertSelect($source, ['id', 'name', 'email']);

$sql    = $insert->getSql();
$params = $insert->getParams();

echo "Source SQL: " . $source->getSql() . "\n";
echo "Insert SQL: $sql\n";
echo "Params: " . json_encode($params) . "\n";

$test("Contiene INSERT INTO", function() use ($sql) {
    assert(str_contains($sql, 'INSERT INTO'));
});
$test("Usa tabla destino", function() use ($sql) {
    assert(str_contains($sql, '"active_users"'));
});
$test("Contiene SELECT subquery", function() use ($sql) {
    assert(str_contains($sql, 'SELECT'));
});
$test("Parámetros de la subconsulta", function() use ($params) {
    assert(count($params) === 1);
    assert($params[0] === 1);
});

// ---------- 2. Sin columnas explícitas (infiere del projection map) ----------
echo "\n--- 2. InsertSelect sin columnas (infiere) ---\n";
$source = Q::from('users', ['active' => 1])->select(['id', 'name', 'email']);
$insert = Q::into('active_users')->insertSelect($source); // sin pasar columnas

$sql = $insert->getSql();

echo "SQL: $sql\n";

$test("Contiene las columnas inferidas", function() use ($sql) {
    // Debe contener las tres columnas
    assert(str_contains($sql, '"id"'));
    assert(str_contains($sql, '"name"'));
    assert(str_contains($sql, '"email"'));
});

// ---------- 3. Con subconsulta más compleja ----------
echo "\n--- 3. InsertSelect con subconsulta más compleja ---\n";
$source = Q::from('users', ['status' => 'active'])
    ->select(['id', 'name'], [0, 10], '-id');
$insert = Q::into('active_users')->insertSelect($source, ['id', 'name']);

$sql = $insert->getSql();

echo "Source SQL: " . $source->getSql() . "\n";
echo "Insert SQL: $sql\n";

$test("Subconsulta incluida", function() use ($sql, $source) {
    assert(str_contains($sql, $source->getSql()));
});

// ---------- 4. Con INSERT FROM (alias) ----------
echo "\n--- 4. InsertFrom (alias) ---\n";
$source = Q::from('users')->select(['name', 'email']);
$insert = Q::into('active_users')->insertFrom($source, ['name', 'email']);

$sql = $insert->getSql();

echo "SQL: $sql\n";

$test("Funciona igual que insertSelect", function() use ($sql) {
    assert(str_contains($sql, 'INSERT INTO'));
    assert(str_contains($sql, 'SELECT'));
});

echo "\n================================================\n";
echo "RESULTADO: $passed pasaron, $failed fallaron\n";
echo "================================================\n";