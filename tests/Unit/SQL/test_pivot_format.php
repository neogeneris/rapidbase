<?php
/**
 * Test para verificar el formato pivote: [t1, [t2, t3]]
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use RapidBase\Core\SQL;
use RapidBase\Core\Gateway;

// Configurar mapa de relaciones de prueba
$testMap = [
    'relationships' => [
        'from' => [
            'users' => [
                'orders' => [
                    'type' => 'hasMany',
                    'local_key' => 'id',
                    'foreign_key' => 'user_id',
                ],
                'drivers' => [
                    'type' => 'hasMany',
                    'local_key' => 'id',
                    'foreign_key' => 'user_id',
                ],
            ],
            'orders' => [
                'products' => [
                    'type' => 'belongsTo',
                    'local_key' => 'product_id',
                    'foreign_key' => 'id',
                ],
            ],
            'drivers' => [
                'race_results' => [
                    'type' => 'hasMany',
                    'local_key' => 'id',
                    'foreign_key' => 'driver_id',
                ],
            ],
        ],
        'to' => [
            'orders' => [
                'users' => [
                    'type' => 'belongsTo',
                    'local_key' => 'user_id',
                    'foreign_key' => 'id',
                ],
            ],
            'drivers' => [
                'users' => [
                    'type' => 'belongsTo',
                    'local_key' => 'user_id',
                    'foreign_key' => 'id',
                ],
            ],
            'products' => [
                'orders' => [
                    'type' => 'hasMany',
                    'local_key' => 'id',
                    'foreign_key' => 'product_id',
                ],
            ],
            'race_results' => [
                'drivers' => [
                    'type' => 'belongsTo',
                    'local_key' => 'driver_id',
                    'foreign_key' => 'id',
                ],
            ],
        ],
    ],
];

SQL::setRelationsMap($testMap);

echo "=== TEST FORMATO PIVOTE ===\n\n";

// Test 1: Formato plano (automático completo)
echo "Test 1: Array plano ['users', 'orders']\n";
[$sql1, $params1] = SQL::buildSelect('*', ['users', 'orders'], []);
echo "SQL: $sql1\n";
echo "Params: " . json_encode($params1) . "\n\n";

// Test 2: Formato pivote (t1 es FROM, resto se conecta auto)
echo "Test 2: Formato pivote ['users', ['orders', 'products']]\n";
[$sql2, $params2] = SQL::buildSelect('*', ['users', ['orders', 'products']], []);
echo "SQL: $sql2\n";
echo "Params: " . json_encode($params2) . "\n\n";

// Test 3: Formato pivote con drivers
echo "Test 3: Formato pivote ['drivers', ['race_results']]\n";
[$sql3, $params3] = SQL::buildSelect('*', ['drivers', ['race_results']], []);
echo "SQL: $sql3\n";
echo "Params: " . json_encode($params3) . "\n\n";

// Test 4: Formato pivote con alias
echo "Test 4: Formato pivote con alias ['users as u', ['orders as o']]\n";
[$sql4, $params4] = SQL::buildSelect('*', ['users as u', ['orders as o']], []);
echo "SQL: $sql4\n";
echo "Params: " . json_encode($params4) . "\n\n";

// Test 5: String simple (fast path)
echo "Test 5: String simple 'users'\n";
[$sql5, $params5] = SQL::buildSelect('*', 'users', []);
echo "SQL: $sql5\n";
echo "Params: " . json_encode($params5) . "\n\n";

// Verificar que users sea la tabla FROM en el test 2
if (strpos($sql2, 'FROM "users"') !== false || strpos($sql2, 'FROM `users`') !== false || strpos($sql2, 'FROM users') !== false) {
    echo "✅ SUCCESS: El formato pivote funciona correctamente - 'users' es la tabla FROM\n";
} else {
    echo "❌ FAIL: El formato pivote NO funciona - 'users' no es la tabla FROM\n";
    echo "SQL generado: $sql2\n";
}

// Verificar que drivers sea la tabla FROM en el test 3
if (strpos($sql3, 'FROM "drivers"') !== false || strpos($sql3, 'FROM `drivers`') !== false || strpos($sql3, 'FROM drivers') !== false) {
    echo "✅ SUCCESS: El formato pivote funciona correctamente - 'drivers' es la tabla FROM\n";
} else {
    echo "❌ FAIL: El formato pivote NO funciona - 'drivers' no es la tabla FROM\n";
    echo "SQL generado: $sql3\n";
}

echo "\n=== FIN DEL TEST ===\n";
