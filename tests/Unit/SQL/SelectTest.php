<?php

/**
 * QSelectTest - Prueba progresiva de Q::select()
 *
 * Orden:
 * 1. Tabla simple, todos los campos (*)
 * 2. Campos explícitos
 * 3. WHERE con condiciones
 * 4. ORDER BY
 * 5. LIMIT / OFFSET (paginación)
 * 6. JOIN de dos tablas (relación 1:n)
 * 7. GROUP BY
 * 8. GROUP BY + HAVING
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use RapidBase\Core\SQL\Q;
use RapidBase\Core\SQL\CompiledQuery;
use RapidBase\Core\SQL\ConditionMatrix;
use RapidBase\Core\SchemaMap;
use RapidBase\Core\Conn;

// Preparar entorno y esquema mínimo
Conn::setup('sqlite::memory:', '', '', 'main');
ConditionMatrix::setDriver('sqlite');

$schema = [
    'relationships' => [
        'from' => [
            'users' => [
                'posts' => ['type' => 'hasMany', 'local_key' => 'id', 'foreign_key' => 'user_id']
            ]
        ],
        'to' => [
            'posts' => [
                'users' => ['type' => 'belongsTo', 'local_key' => 'user_id', 'foreign_key' => 'id']
            ]
        ]
    ],
    'tables' => [
        'users' => [
            'id'    => ['type' => 'int'],
            'name'  => ['type' => 'varchar'],
            'email' => ['type' => 'varchar']
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
echo "PRUEBA PROGRESIVA DE Q::select()\n";
echo "================================================\n\n";

// ---------- 1. Tabla simple, todos los campos ----------
echo "--- 1. Tabla simple, todos los campos (*) ---\n";
$compiled = Q::from('users')->select('*');
echo "SQL: " . $compiled->getSql() . "\n";
test("Genera SELECT *", function() use ($compiled) {
    assert(str_starts_with($compiled->getSql(), 'SELECT *'));
});
test("Contiene FROM", function() use ($compiled) {
    assert(str_contains($compiled->getSql(), 'FROM'));
});
test("Parámetros vacíos", function() use ($compiled) {
    assert(empty($compiled->getParams()));
});

// ---------- 2. Campos explícitos ----------
echo "\n--- 2. Campos explícitos ---\n";
$compiled = Q::from('users')->select(['name', 'email']);
echo "SQL: " . $compiled->getSql() . "\n";
test("Contiene 'name'", function() use ($compiled) {
    assert(str_contains($compiled->getSql(), 'name'));
});
test("Contiene 'email'", function() use ($compiled) {
    assert(str_contains($compiled->getSql(), 'email'));
});

// ---------- 3. WHERE con condiciones ----------
echo "\n--- 3. WHERE con condiciones ---\n";
$compiled = Q::from('users', ['name' => 'Alice'])->select('*');
echo "SQL: " . $compiled->getSql() . "\n";
test("Contiene WHERE", function() use ($compiled) {
    assert(str_contains($compiled->getSql(), 'WHERE'));
});
test("Un parámetro", function() use ($compiled) {
    assert(count($compiled->getParams()) === 1);
});
test("Parámetro es 'Alice'", function() use ($compiled) {
    assert($compiled->getParams()[0] === 'Alice');
});

// Dos condiciones
$compiled = Q::from('users', ['name' => 'Bob', 'email' => 'bob@example.com'])->select('*');
test("WHERE con AND", function() use ($compiled) {
    assert(str_contains($compiled->getSql(), 'AND'));
});
test("Dos parámetros", function() use ($compiled) {
    assert(count($compiled->getParams()) === 2);
});

// ---------- 4. ORDER BY ----------
echo "\n--- 4. ORDER BY ---\n";
$compiled = Q::from('users')->select('*', null, 'name');
echo "SQL: " . $compiled->getSql() . "\n";
test("Contiene ORDER BY", function() use ($compiled) {
    assert(str_contains($compiled->getSql(), 'ORDER BY'));
});
test("Dirección ASC por defecto", function() use ($compiled) {
    $sql = $compiled->getSql();
    assert(str_contains($sql, 'ASC') || !str_contains($sql, 'DESC')); // ASC puede omitirse, pero si no hay DESC, es ASC
});

$compiled = Q::from('users')->select('*', null, '-name');
test("Dirección DESC", function() use ($compiled) {
    assert(str_contains($compiled->getSql(), 'DESC'));
});

// ---------- 5. LIMIT / OFFSET ----------
echo "\n--- 5. LIMIT / OFFSET ---\n";
$compiled = Q::from('users')->select('*', [0, 10]);
echo "SQL: " . $compiled->getSql() . "\n";
test("Contiene LIMIT", function() use ($compiled) {
    assert(str_contains($compiled->getSql(), 'LIMIT'));
});
test("Contiene OFFSET", function() use ($compiled) {
    assert(str_contains($compiled->getSql(), 'OFFSET'));
});

// ---------- 6. JOIN de dos tablas ----------
echo "\n--- 6. JOIN de dos tablas ---\n";
$compiled = Q::from(['users', 'posts'], ['users.id' => 1])->select('*');
echo "SQL: " . $compiled->getSql() . "\n";
test("Contiene LEFT JOIN", function() use ($compiled) {
    assert(str_contains($compiled->getSql(), 'LEFT JOIN'));
});
test("Contiene ON", function() use ($compiled) {
    assert(str_contains($compiled->getSql(), 'ON'));
});
test("Contiene ambas tablas", function() use ($compiled) {
    $sql = $compiled->getSql();
    assert(str_contains($sql, 'users') && str_contains($sql, 'posts'));
});

// ---------- 7. GROUP BY ----------
echo "\n--- 7. GROUP BY ---\n";
$compiled = Q::from('users')->select('email, COUNT(*)', null, [], 'email');
echo "SQL: " . $compiled->getSql() . "\n";
test("Contiene GROUP BY", function() use ($compiled) {
    assert(str_contains($compiled->getSql(), 'GROUP BY'));
});
test("Agrupa por email", function() use ($compiled) {
    assert(str_contains($compiled->getSql(), '"email"'));
});

// ---------- 8. GROUP BY + HAVING ----------
echo "\n--- 8. GROUP BY + HAVING ---\n";
$compiled = Q::from('users')->select(
    'email, COUNT(*) as total',
    null,
    [],
    'email',
    ['total' => ['>' => 1]]
);
echo "SQL: " . $compiled->getSql() . "\n";
test("Contiene HAVING", function() use ($compiled) {
    assert(str_contains($compiled->getSql(), 'HAVING'));
});
test("Parámetros de HAVING", function() use ($compiled) {
    assert(count($compiled->getParams()) > 0);
});

echo "\n================================================\n";
echo "RESULTADO: $passed pasaron, $failed fallaron\n";
echo "================================================\n";