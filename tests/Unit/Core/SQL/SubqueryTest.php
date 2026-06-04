<?php

/**
 * SubqueryTest - Prueba de subconsultas anidadas con Q::from(CompiledQuery)
 *
 * Casos:
 * 1. Subconsulta simple como tabla
 * 2. Subconsulta con alias explícito
 * 3. Subconsulta + WHERE externo
 * 4. Subconsulta + JOIN con otra tabla (array)
 * 5. Subconsulta + count/ exists
 */

require_once __DIR__ . '/../../../../vendor/autoload.php';

use RapidBase\Core\SQL\Q;
use RapidBase\Core\SQL\ConditionMatrix;
use RapidBase\Core\SQL\CompiledQuery;
use RapidBase\Core\SchemaMap;
use RapidBase\Core\Conn;

// Entorno mínimo
Conn::setup('sqlite::memory:', '', '', 'main');
ConditionMatrix::setDriver('sqlite');

$schema = [
    'tables' => [
        'users' => [
            'id'     => ['type' => 'int'],
            'name'   => ['type' => 'varchar'],
            'active' => ['type' => 'int']
        ],
        'posts' => [
            'id'      => ['type' => 'int'],
            'user_id' => ['type' => 'int'],
            'title'   => ['type' => 'varchar']
        ]
    ]
];
SchemaMap::setMap($schema, 'main');

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
echo "PRUEBA DE SUBCONSULTAS CON Q::from(CompiledQuery)\n";
echo "================================================\n\n";

// ---------- 1. Subconsulta simple como tabla ----------
echo "--- 1. Subconsulta simple ---\n";
$inner = Q::from('users', ['active' => 1])->select('id, name');
echo "Inner SQL: " . $inner->getSql() . "\n";
echo "Inner Params: " . json_encode($inner->getParams()) . "\n";

$outer = Q::from($inner)->select('*');
$sql = $outer->getSql();
$params = $outer->getParams();
echo "Outer SQL: $sql\n";
echo "Outer Params: " . json_encode($params) . "\n";

$test("Contiene subconsulta entre paréntesis", function() use ($sql) {
    assert(str_contains($sql, '(SELECT'));
});
$test("Tiene alias automático", function() use ($sql) {
    assert(str_contains($sql, 'AS '));
});
$test("Parámetros propagados", function() use ($params) {
    assert(count($params) === 1);
    assert($params[0] === 1);
});

// ---------- 2. Subconsulta con alias explícito ----------
echo "\n--- 2. Subconsulta con alias explícito ---\n";
$inner = Q::from('users', ['active' => 1])->select('id, name');
// Usamos asTable() para darle alias manual
$tableExpr = $inner->asTable('activos');
echo "Table expr: $tableExpr\n";

$outer = Q::from($tableExpr)->select('*');
$sql = $outer->getSql();
echo "SQL: $sql\n";

$test("Usa alias 'activos'", function() use ($sql) {
    assert(str_contains($sql, '"activos"'));
});

// ---------- 3. Subconsulta + WHERE externo ----------
echo "\n--- 3. Subconsulta + WHERE externo ---\n";
$inner = Q::from('users')->select('id, name');
$outer = Q::from($inner, ['name' => 'Alice'])->select('*');
$sql = $outer->getSql();
$params = $outer->getParams();
echo "SQL: $sql\n";
echo "Params: " . json_encode($params) . "\n";

$test("WHERE sobre subconsulta", function() use ($sql) {
    assert(str_contains($sql, 'WHERE'));
});
$test("Parámetros del WHERE externo", function() use ($params) {
    // El parámetro de la subconsulta no se duplica; el WHERE externo añade el suyo
    assert(count($params) >= 1);
});

// ---------- 4. Subconsulta + JOIN con otra tabla ----------
echo "\n--- 4. Subconsulta + JOIN ---\n";
$inner = Q::from('users', ['active' => 1])->select('id, name');
$outer = Q::from(['posts', ($inner->asTable('u'))], ['posts.user_id' => 1])
    ->select(['posts.title', 'u.name']);
$sql = $outer->getSql();
echo "SQL: $sql\n";

$test("Incluye JOIN con subconsulta", function() use ($sql) {
    assert(str_contains($sql, 'LEFT JOIN'));
    assert(str_contains($sql, 'SELECT'));
});
$test("Contiene ambas tablas", function() use ($sql) {
    assert(str_contains($sql, '"posts"'));
    assert(str_contains($sql, '"u"'));
});

// ---------- 5. Subconsulta + count / exists ----------
echo "\n--- 5. Subconsulta + count / exists ---\n";
$inner = Q::from('users', ['active' => 1])->select('id');
$countQuery = Q::from($inner)->count();
$sql = $countQuery->getSql();
echo "Count SQL: $sql\n";

$test("COUNT sobre subconsulta", function() use ($sql) {
    assert(str_starts_with($sql, 'SELECT COUNT(*)'));
    assert(str_contains($sql, '(SELECT'));
});

$existsQuery = Q::from($inner)->exists();
$sql = $existsQuery->getSql();
echo "Exists SQL: $sql\n";

$test("EXISTS sobre subconsulta", function() use ($sql) {
    assert(str_starts_with($sql, 'SELECT EXISTS'));
    assert(str_contains($sql, 'SELECT 1'));
});

echo "\n================================================\n";
echo "RESULTADO: $passed pasaron, $failed fallaron\n";
echo "================================================\n";