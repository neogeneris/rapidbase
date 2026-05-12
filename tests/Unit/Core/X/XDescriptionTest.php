<?php

require_once __DIR__ . '/bootstrap.php';

use RapidBase\Core\X;
use RapidBase\Core\SchemaMap;
use RapidBase\Core\Conn;

// ---- Configuración del entorno de prueba ----
// Registramos una conexión real (aunque no se use para datos) y cargamos un mapa ficticio.
\RapidBase\Core\DB::setup('sqlite::memory:', '', '', 'xdesc_test');

// --- Mapa de esquema de ejemplo ---
$testMap = [
    'tables' => [
        'users' => [
            'id'    => ['type' => 'integer', 'primary' => true],
            'name'  => ['type' => 'text',    'primary' => false],
            'email' => ['type' => 'text',    'primary' => false],
        ],
        'roles' => [
            'id'   => ['type' => 'integer', 'primary' => true],
            'name' => ['type' => 'text',    'primary' => false],
        ],
        'user_roles' => [
            'user_id' => ['type' => 'integer', 'primary' => false, 'foreign' => true],
            'role_id' => ['type' => 'integer', 'primary' => false, 'foreign' => true],
        ],
    ],
    'relationships' => [
        'from' => [
            'user_roles' => [
                'users' => [
                    'type'        => 'belongsTo',
                    'local_key'   => 'user_id',
                    'foreign_key' => 'id',
                ],
                'roles' => [
                    'type'        => 'belongsTo',
                    'local_key'   => 'role_id',
                    'foreign_key' => 'id',
                ],
            ],
        ],
        'to' => [
            'users' => [
                'user_roles' => [
                    'type'        => 'hasMany',
                    'local_key'   => 'user_id',
                    'foreign_key' => 'id',
                ],
            ],
            'roles' => [
                'user_roles' => [
                    'type'        => 'hasMany',
                    'local_key'   => 'role_id',
                    'foreign_key' => 'id',
                ],
            ],
        ],
    ],
];

// Inyectamos el mapa en SchemaMap para la conexión activa
SchemaMap::setMap($testMap, 'xdesc_test');

$pass = true;

// ------------------------------------------------------------
// Test 1: Single table (users)
// ------------------------------------------------------------
echo "Test 1: Description of 'users' table... ";
$desc = X::con('xdesc_test')->from('users')->description();
$users = $desc['users'] ?? null;
if (!$users) {
    echo "[ERROR] users key missing\n";
    $pass = false;
} else {
    if ($users['columns'] === ['id' => 'integer', 'name' => 'text', 'email' => 'text'] &&
        $users['pks'] === ['id'] &&
        count($users['relations']) === 1) { // hasMany user_roles
        echo "[OK]\n";
    } else {
        echo "[ERROR] Unexpected structure: " . json_encode($users) . "\n";
        $pass = false;
    }
}

// ------------------------------------------------------------
// Test 2: Multiple tables (users and roles)
// ------------------------------------------------------------
echo "Test 2: Description of 'users' and 'roles'... ";
$desc = X::con('xdesc_test')->from(['users', 'roles'])->description();
if (count($desc) !== 2) {
    echo "[ERROR] Expected 2 tables, got " . count($desc) . "\n";
    $pass = false;
} else {
    if (isset($desc['users']['columns']['id']) && isset($desc['roles']['columns']['id'])) {
        echo "[OK]\n";
    } else {
        echo "[ERROR] Missing columns\n";
        $pass = false;
    }
}

// ------------------------------------------------------------
// Test 3: All tables (without from() call)
// ------------------------------------------------------------
echo "Test 3: Description of all tables (no from())... ";
$desc = X::con('xdesc_test')->description();
if (count($desc) === 3) { // users, roles, user_roles
    echo "[OK]\n";
} else {
    echo "[ERROR] Expected 3 tables, got " . count($desc) . "\n";
    $pass = false;
}

// ------------------------------------------------------------
// Test 4: Table with outgoing relations (user_roles)
// ------------------------------------------------------------
echo "Test 4: Relations of 'user_roles'... ";
$desc = X::con('xdesc_test')->from('user_roles')->description();
$relations = $desc['user_roles']['relations'] ?? [];
$expectedOut = 2; // Two outgoing belongsTo
if (count($relations) === $expectedOut) {
    $outCount = 0;
    foreach ($relations as $r) {
        if ($r['direction'] === 'out') $outCount++;
    }
    if ($outCount === 2) {
        echo "[OK]\n";
    } else {
        echo "[ERROR] Expected 2 outgoing relations\n";
        $pass = false;
    }
} else {
    echo "[ERROR] Expected $expectedOut relations, got " . count($relations) . "\n";
    $pass = false;
}

// ------------------------------------------------------------
// Test 5: Table with incoming relations (users)
// ------------------------------------------------------------
echo "Test 5: Incoming relations of 'users'... ";
$desc = X::con('xdesc_test')->from('users')->description();
$relations = $desc['users']['relations'] ?? [];
$inCount = 0;
foreach ($relations as $r) {
    if ($r['direction'] === 'in') $inCount++;
}
if ($inCount === 1) {
    echo "[OK]\n";
} else {
    echo "[ERROR] Expected 1 incoming relation, got $inCount\n";
    $pass = false;
}

// ------------------------------------------------------------
// Test 6: Table not in schema map
// ------------------------------------------------------------
echo "Test 6: Non-existent table returns empty structure... ";
$desc = X::con('xdesc_test')->from('ghost_table')->description();
$ghost = $desc['ghost_table'] ?? null;
if ($ghost && $ghost['columns'] === [] && $ghost['pks'] === [] && $ghost['relations'] === []) {
    echo "[OK]\n";
} else {
    echo "[ERROR] Expected empty arrays for missing table\n";
    $pass = false;
}

// ------------------------------------------------------------
echo "---------------------------\n";
if ($pass) {
    echo "Resultado: All XDescriptionTest passed.\n";
} else {
    echo "Resultado: Some XDescriptionTest failed.\n";
    exit(1);
}