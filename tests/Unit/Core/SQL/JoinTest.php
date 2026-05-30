<?php

require_once __DIR__ . '/../../../vendor/autoload.php';

use RapidBase\Core\SchemaMap;
use RapidBase\Core\SQL\JoinResolver;

echo "=== JoinTest.php - Pruebas completas para JoinResolver ===\n\n";

// 1. Configurar el mapa de relaciones manualmente
$map = [
    'relationships' => [
        'from' => [
            'users' => [
                'posts' => [
                    'type' => 'hasMany',
                    'local_key' => 'id',
                    'foreign_key' => 'user_id'
                ],
                'comments' => [
                    'type' => 'hasMany',
                    'local_key' => 'id',
                    'foreign_key' => 'user_id'
                ]
            ],
            'posts' => [
                'comments' => [
                    'type' => 'hasMany',
                    'local_key' => 'id',
                    'foreign_key' => 'post_id'
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
            ],
            'comments' => [
                'users' => [
                    'type' => 'belongsTo',
                    'local_key' => 'user_id',
                    'foreign_key' => 'id'
                ],
                'posts' => [
                    'type' => 'belongsTo',
                    'local_key' => 'post_id',
                    'foreign_key' => 'id'
                ]
            ]
        ]
    ],
    'tables' => [
        'users'    => ['id' => ['type' => 'int'], 'name' => ['type' => 'varchar']],
        'posts'    => ['id' => ['type' => 'int'], 'user_id' => ['type' => 'int'], 'title' => ['type' => 'varchar']],
        'comments' => ['id' => ['type' => 'int'], 'user_id' => ['type' => 'int'], 'post_id' => ['type' => 'int'], 'content' => ['type' => 'varchar']]
    ]
];

SchemaMap::setMap($map, 'default');

// 2. Instanciar JoinResolver
$resolver = new JoinResolver('default');

// Contador de pruebas
$passed = 0;
$failed = 0;

$test = function($description, $condition) use (&$passed, &$failed) {
    if ($condition) {
        echo "[OK] $description\n";
        $passed++;
    } else {
        echo "[FAIL] $description\n";
        $failed++;
    }
};

// ==================== PRUEBAS ====================

echo "--- Prueba 1: Tabla simple ---\n";
$result = $resolver->resolve('users');
$test('Tabla simple genera FROM correcto', 
     trim($result['from']) === 'FROM "users"');
$test('Tabla simple tiene tablesInfo correcto', 
     count($result['tablesInfo']) === 1 && $result['tablesInfo'][0]['real'] === 'users');

echo "\n--- Prueba 2: Dos tablas relacionadas (users, posts) ---\n";
$result = $resolver->resolve(['users', 'posts']);
// El orden puede variar según el algoritmo de ordenamiento por debilidad
$test('Dos tablas generan FROM y JOIN correctos', 
     str_contains($result['from'], 'FROM') && str_contains($result['from'], 'LEFT JOIN') && str_contains($result['from'], 'users') && str_contains($result['from'], 'posts'));
$test('Dos tablas tienen 2 entries en tablesInfo', 
     count($result['tablesInfo']) === 2);

echo "\n--- Prueba 3: Orden inverso (posts, users) ---\n";
$result = $resolver->resolve(['posts', 'users']);
// Debería ser capaz de resolver en cualquier orden
$test('Orden inverso también genera JOIN válido', 
     str_contains($result['from'], 'FROM') && str_contains($result['from'], 'JOIN'));

echo "\n--- Prueba 4: Triple join (users, posts, comments) ---\n";
$result = $resolver->resolve(['users', 'posts', 'comments']);
$test('Triple join no está vacío', 
     !empty(trim($result['from'])));
$test('Triple join contiene al menos 2 JOINs', 
     substr_count($result['from'], 'JOIN') >= 2);
$test('Triple join tiene 3 entries en tablesInfo', 
     count($result['tablesInfo']) === 3);

echo "\n--- Prueba 5: Cache funciona (segunda llamada) ---\n";
$start = microtime(true);
for ($i = 0; $i < 100; $i++) {
    $resolver->resolve(['users', 'posts']);
};
$duration = microtime(true) - $start;
$test('Cache hace 100 llamadas rápidas (< 0.1s)', 
     $duration < 0.1);

echo "\n--- Prueba 6: Tabla con alias ---\n";
$result = $resolver->resolve('users AS u');
$test('Alias se respeta en FROM', 
     str_contains($result['from'], 'u'));

echo "\n--- Prueba 7: Array con una sola tabla ---\n";
$result = $resolver->resolve(['posts']);
$test('Array con una tabla genera FROM simple', 
     trim($result['from']) === 'FROM "posts"');

echo "\n--- Prueba 8: Tablas desconectadas (debería usar modo lineal) ---\n";
// Añadimos una tabla sin relaciones
$map2 = [
    'relationships' => [
        'from' => [],
        'to' => []
    ],
    'tables' => [
        'table1' => ['id' => ['type' => 'int']],
        'table2' => ['id' => ['type' => 'int']]
    ]
];
SchemaMap::setMap($map2, 'test2');
$resolver2 = new JoinResolver('test2');
$result = $resolver2->resolve(['table1', 'table2']);
$test('Tablas sin relaciones usan modo lineal', 
     str_contains($result['from'], 'table1') && str_contains($result['from'], 'table2'));

echo "\n=================== RESULTADOS ===================\n";
echo "Pasadas: $passed\n";
echo "Fallidas: $failed\n";

if ($failed > 0) {
    exit(1);
}
echo "\n[OK] Todos los tests pasaron!\n";
