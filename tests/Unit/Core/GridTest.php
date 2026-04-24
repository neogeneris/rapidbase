<?php

namespace Tests\Unit\Core;

require_once __DIR__ . '/../../../src/RapidBase/Core/Conn.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/SQL.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/Executor.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/Gateway.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/SchemaMap.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/QueryResponse.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/DBInterface.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/DB.php';

use RapidBase\Core\Conn;
use RapidBase\Core\DB;
use RapidBase\Core\SchemaMap;

echo "--- Ejecutando: DB::grid() Test (Ordenamiento y Paginación) ---\n";

/**
 * Helper para aserciones simple en consola
 */
function assert_grid($name, $assertion, $details = "") {
    if ($assertion) {
        echo "\033[32m[OK]\033[0m $name\n";
    } else {
        echo "\033[31m[FAIL]\033[0m $name\n";
        if ($details) echo "  Detalle: $details\n";
        exit(1);
    }
}

// --- SETUP ---
$tempDb = tempnam(sys_get_temp_dir(), 'rapidbase_grid_test_') . '.sqlite';
Conn::setup("sqlite:$tempDb", "", "", "main");
$pdo = Conn::get();
$pdo->exec("PRAGMA busy_timeout = 5000");
$pdo->exec("PRAGMA journal_mode = WAL");

// Crear tabla de prueba
$pdo->exec("DROP TABLE IF EXISTS products");
$pdo->exec("CREATE TABLE products (
    id INTEGER PRIMARY KEY, 
    name TEXT, 
    price REAL, 
    created_at TEXT
)");

// Insertar datos desordenados intencionalmente
$pdo->exec("INSERT INTO products (name, price, created_at) VALUES 
    ('Zebra', 30.00, '2024-01-03'),
    ('Apple', 10.00, '2024-01-01'),
    ('Banana', 15.00, '2024-01-02'),
    ('Cherry', 20.00, '2024-01-05'),
    ('Date', 25.00, '2024-01-04'),
    ('Elderberry', 35.00, '2024-01-06'),
    ('Fig', 40.00, '2024-01-07'),
    ('Grape', 45.00, '2024-01-08'),
    ('Honeydew', 50.00, '2024-01-09'),
    ('Kiwi', 55.00, '2024-01-10'),
    ('Lemon', 60.00, '2024-01-11'),
    ('Mango', 65.00, '2024-01-12')
");

// Configurar SchemaMap para la tabla
SchemaMap::setMap([
    'products' => [
        'columns' => [
            'id' => ['type' => 'int', 'description' => 'ID'],
            'name' => ['type' => 'string', 'description' => 'Nombre'],
            'price' => ['type' => 'float', 'description' => 'Precio'],
            'created_at' => ['type' => 'date', 'description' => 'Fecha Creación']
        ]
    ]
], 'main');

echo "\n--- TEST 1: Ordenamiento ASC por nombre ---\n";
$response = DB::grid('products', [], 1, 'name');
$data = $response->data;
assert_grid("Debe retornar 10 registros (default perPage)", count($data) === 10);
assert_grid("Primer registro debe ser 'Apple'", $data[0][1] === 'Apple', "Got: " . ($data[0][1] ?? 'N/A'));
assert_grid("Segundo registro debe ser 'Banana'", $data[1][1] === 'Banana', "Got: " . ($data[1][1] ?? 'N/A'));

echo "\n--- TEST 2: Ordenamiento DESC por nombre ---\n";
$response = DB::grid('products', [], 1, '-name');
$data = $response->data;
assert_grid("Primer registro debe ser 'Mango'", $data[0][1] === 'Mango', "Got: " . ($data[0][1] ?? 'N/A'));
assert_grid("Segundo registro debe ser 'Lemon'", $data[1][1] === 'Lemon', "Got: " . ($data[1][1] ?? 'N/A'));

echo "\n--- TEST 3: Ordenamiento por precio DESC ---\n";
$response = DB::grid('products', [], 1, '-price');
$data = $response->data;
assert_grid("Primer registro debe ser 'Kiwi' (55.00)", $data[0][1] === 'Kiwi' && $data[0][2] == 55.00, 
    "Got: " . ($data[0][1] ?? 'N/A') . " - " . ($data[0][2] ?? 'N/A'));

echo "\n--- TEST 4: Paginación página 2 ---\n";
$response = DB::grid('products', [], 2, 'name');
$data = $response->data;
assert_grid("Debe retornar 2 registros restantes", count($data) === 2, "Got: " . count($data));
assert_grid("Primer registro página 2 debe ser 'Lemon'", $data[0][1] === 'Lemon', "Got: " . ($data[0][1] ?? 'N/A'));

echo "\n--- TEST 5: Paginación con array [page, perPage] ---\n";
$response = DB::grid('products', [], [1, 5], 'name');
$data = $response->data;
assert_grid("Debe retornar 5 registros", count($data) === 5, "Got: " . count($data));
assert_grid("Primer registro debe ser 'Apple'", $data[0][1] === 'Apple', "Got: " . ($data[0][1] ?? 'N/A'));
assert_grid("Quinto registro debe ser 'Elderberry'", $data[4][1] === 'Elderberry', "Got: " . ($data[4][1] ?? 'N/A'));

echo "\n--- TEST 6: Sin límites (page = 0) ---\n";
$response = DB::grid('products', [], 0, 'name');
$data = $response->data;
assert_grid("Debe retornar todos los 12 registros", count($data) === 12, "Got: " . count($data));
assert_grid("Primer registro debe ser 'Apple'", $data[0][1] === 'Apple', "Got: " . ($data[0][1] ?? 'N/A'));
assert_grid("Último registro debe ser 'Mango'", $data[11][1] === 'Mango', "Got: " . ($data[11][1] ?? 'N/A'));

echo "\n--- TEST 7: Ordenamiento múltiple ---\n";
$response = DB::grid('products', [], 1, ['created_at', 'name']);
$data = $response->data;
assert_grid("Debe respetar orden por created_at primero", $data[0][3] === '2024-01-01', "Got: " . ($data[0][3] ?? 'N/A'));

echo "\n--- TEST 8: Verificar metadata de estado ---\n";
$response = DB::grid('products', [], 2, 'name');
$state = $response->state;
assert_grid("Estado page debe ser 2", $state['page'] === 2, "Got: " . ($state['page'] ?? 'N/A'));
assert_grid("Estado per_page debe ser 10", $state['per_page'] === 10, "Got: " . ($state['per_page'] ?? 'N/A'));
assert_grid("Estado offset debe ser 10", $state['offset'] === 10, "Got: " . ($state['offset'] ?? 'N/A'));
assert_grid("Estado last_page debe ser 2", $state['last_page'] === 2, "Got: " . ($state['last_page'] ?? 'N/A'));

echo "\n\033[32m[SUCCESS]\033[0m DB::grid() maneja correctamente ordenamiento y paginación.\n";

// Cleanup
if (isset($tempDb) && file_exists($tempDb)) {
    @unlink($tempDb);
    @unlink($tempDb . '-wal');
    @unlink($tempDb . '-shm');
}
