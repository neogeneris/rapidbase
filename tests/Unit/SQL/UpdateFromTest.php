<?php

/**
 * UpdateFromTest - Prueba de Q::from()->updateFrom()
 *
 * Verifica la generación de UPDATE ... FROM (SELECT ...)
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use RapidBase\Core\SQL\Q;
use RapidBase\Core\SQL\ConditionMatrix;
use RapidBase\Core\SQL\CompiledQuery;
use RapidBase\Core\SchemaMap;
use RapidBase\Core\Conn;

// Preparar entorno y esquema con relaciones
Conn::setup('sqlite::memory:', '', '', 'main');
ConditionMatrix::setDriver('sqlite');

$schema = [
    'relationships' => [
        'from' => [
            'users' => [
                'posts' => [
                    'type' => 'hasMany',
                    'local_key' => 'id',
                    'foreign_key' => 'user_id'
                ]
            ]
        ],
        'to' => [
            'posts' => [
                'users' => [
                    'type' => 'belongsTo',
                    'local_key' => 'user_id',
                    'foreign_key' => 'id'
                ]
            ]
        ]
    ],
    'tables' => [
        'users' => [
            'id'    => ['type' => 'int'],
            'name'  => ['type' => 'varchar'],
            'active'=> ['type' => 'int']
        ],
        'posts' => [
            'id'      => ['type' => 'int'],
            'user_id' => ['type' => 'int'],
            'title'   => ['type' => 'varchar'],
            'status'  => ['type' => 'varchar']
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
echo "PRUEBA DE Q::updateFrom()\n";
echo "================================================\n\n";

// ---------- 1. UpdateFrom con inferencia automática ----------
echo "--- 1. UpdateFrom con inferencia automática (JOIN users -> posts) ---\n";
$source = Q::from('users', ['active' => 1])->select('id');
$update = Q::from('posts')->updateFrom($source, ['status' => 'reviewed']);

$sql    = $update->getSql();
$params = $update->getParams();

echo "Source SQL: " . $source->getSql() . "\n";
echo "Update SQL: $sql\n";
echo "Params: " . json_encode($params) . "\n";

test("Contiene UPDATE", function() use ($sql) {
    assert(str_contains($sql, 'UPDATE'));
});
test("Contiene SET", function() use ($sql) {
    assert(str_contains($sql, 'SET'));
});
test("Contiene FROM subquery", function() use ($sql) {
    assert(str_contains($sql, 'FROM (SELECT'));
});
test("Contiene condicion ON inferida", function() use ($sql) {
    // Debe unir posts.user_id = _src.id
    assert(str_contains($sql, 'ON') || str_contains($sql, 'WHERE'));
});
test("Parámetros incluyen SET y subconsulta", function() use ($params) {
    assert(count($params) === 2); // 'reviewed' + 1 (active = 1)
    assert($params[0] === 'reviewed');
    assert($params[1] === 1);
});

// ---------- 2. UpdateFrom con condición manual ----------
echo "\n--- 2. UpdateFrom con condición manual ---\n";
$source = Q::from('users', ['active' => 1])->select('id');
$update = Q::from('posts')->updateFrom(
    $source,
    ['status' => 'active'],
    'ON posts.user_id = _src.id'
);

$sql = $update->getSql();

echo "SQL: $sql\n";

test("No usa inferencia (condición explícita)", function() use ($sql) {
    assert(str_contains($sql, 'ON posts.user_id = _src.id'));
});

// ---------- 3. UpdateFrom con múltiples columnas a actualizar ----------
echo "\n--- 3. UpdateFrom con múltiples columnas ---\n";
$source = Q::from('users', ['active' => 1])->select('id');
$update = Q::from('posts')->updateFrom($source, [
    'status' => 'archived',
    'title'  => 'Old Post'
]);

$sql = $update->getSql();

echo "SQL: $sql\n";

test("Actualiza dos columnas", function() use ($sql) {
    assert(str_contains($sql, '"status" = ?'));
    assert(str_contains($sql, '"title" = ?'));
});

echo "\n================================================\n";
echo "RESULTADO: $passed pasaron, $failed fallaron\n";
echo "================================================\n";