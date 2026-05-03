<?php

/**
 * ReadTest - Prueba de lecturas: COUNT, SELECT ONE, EXISTS
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use RapidBase\Core\SQL\Q;
use RapidBase\Core\SQL\ConditionMatrix;
use RapidBase\Core\SchemaMap;
use RapidBase\Core\Conn;
use RapidBase\Core\Gateway;

// Entorno
Conn::setup('sqlite::memory:', '', '', 'main');
ConditionMatrix::setDriver('sqlite');

// Crear tabla e insertar datos
$pdo = Conn::get();
$pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, active INTEGER)");
$pdo->exec("INSERT INTO users VALUES (1,'Alice',1), (2,'Bob',1), (3,'Charlie',0)");

$schema = [
    'tables' => [
        'users' => [
            'id'     => ['type' => 'int'],
            'name'   => ['type' => 'varchar'],
            'active' => ['type' => 'int']
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
echo "PRUEBAS DE LECTURA: COUNT, ONE, EXISTS\n";
echo "================================================\n";

// ===========================================================================
// COUNT
// ===========================================================================
echo "\n--- COUNT ---\n";

// Count all
$compiled = Q::from('users')->count();
$sql = $compiled->getSql();
echo "SQL (todos): $sql\n";
$test("Count all genera SELECT COUNT(*)", function() use ($sql) {
    assert(str_starts_with($sql, 'SELECT COUNT(*)'));
});
$test("Count all sin WHERE", function() use ($sql) {
    assert(!str_contains($sql, 'WHERE'));
});

// Count con filtro
$compiled = Q::from('users', ['active' => 1])->count();
$sql = $compiled->getSql();
$params = $compiled->getParams();
echo "SQL (active=1): $sql\n";
echo "Params: " . json_encode($params) . "\n";
$test("Count con WHERE", function() use ($sql) {
    assert(str_contains($sql, 'WHERE'));
    assert(str_contains($sql, '"active" = ?'));
});
$test("Count param correcto", function() use ($params) {
    assert($params[0] === 1);
});

// Ejecutar y comprobar valor
$res = $compiled->run();
$countVal = $res['count'];
echo "Resultado: " . json_encode($res) . "\n";
$test("Count active=1 retorna 2", function() use ($countVal) {
    assert($countVal === 2);
});

// Count sin resultados
$compiled = Q::from('users', ['active' => 999])->count();
$res = $compiled->run();
$countVal = $res['count'];
echo "Resultado (active=999): " . json_encode($res) . "\n";
$test("Count sin resultados retorna 0", function() use ($countVal) {
    assert($countVal === 0);
});

// ===========================================================================
// SELECT ONE (usando select con [0,1])
// ===========================================================================
echo "\n--- SELECT ONE ---\n";

// Obtener un registro por id
$compiled = Q::from('users', ['id' => 1])->select('*', [0, 1]);
$sql = $compiled->getSql();
$params = $compiled->getParams();
echo "SQL: $sql\n";
echo "Params: " . json_encode($params) . "\n";

$test("One contiene LIMIT", function() use ($sql) {
    assert(str_contains($sql, 'LIMIT ? OFFSET ?'));
});
$test("One params LIMIT+OFFSET", function() use ($params) {
    assert(count($params) >= 2);
    assert($params[count($params)-2] === 1);   // LIMIT
    assert($params[count($params)-1] === 0);   // OFFSET
});

// Ejecutar
$data = $compiled->run();
echo "Data: " . json_encode($data) . "\n";
$test("One devuelve 1 fila", function() use ($data) {
    assert(count($data['rows']) === 1);
});
$test("One devuelve nombre Alice", function() use ($data) {
    // Con FETCH_NUM+projectionMap, el resultado es array asociativo
    $row = $data['rows'][0];
    // La clave puede ser 'users.name' o 'name'
    $name = $row['users.name'] ?? $row['name'] ?? '';
    assert($name === 'Alice');
});

// One sin coincidencia
$compiled = Q::from('users', ['id' => 999])->select('*', [0, 1]);
$data = $compiled->run();
echo "Data (sin coincidencia): " . json_encode($data) . "\n";
$test("One sin coincidencia devuelve array vacío", function() use ($data) {
    assert(empty($data['rows']));
});

// ===========================================================================
// EXISTS
// ===========================================================================
echo "\n--- EXISTS ---\n";

// Existe
$compiled = Q::from('users', ['id' => 1])->exists();
$sql = $compiled->getSql();
$params = $compiled->getParams();
echo "SQL: $sql\n";
echo "Params: " . json_encode($params) . "\n";

$test("Exists genera SELECT EXISTS", function() use ($sql) {
    assert(str_starts_with($sql, 'SELECT EXISTS'));
});
$test("Exists contiene subquery", function() use ($sql) {
    assert(str_contains($sql, 'SELECT 1'));
});
$test("Exists param correcto", function() use ($params) {
    assert($params[0] === 1);
});

// Ejecutar y comprobar
$result = $compiled->run();
$existsVal = $result['rows'][0];
echo "Resultado (id=1): " . ($existsVal ? 'true' : 'false') . "\n";
$test("Exists id=1 retorna true", function() use ($existsVal) {
    assert($existsVal === true);
});

// No existe
$compiled = Q::from('users', ['id' => 999])->exists();
$result = $compiled->run();
$existsVal = $result['rows'][0];
echo "Resultado (id=999): " . ($existsVal ? 'true' : 'false') . "\n";
$test("Exists id=999 retorna false", function() use ($existsVal) {
    assert($existsVal === false);
});

echo "\n================================================\n";
echo "RESULTADO: $passed pasaron, $failed fallaron\n";
echo "================================================\n";