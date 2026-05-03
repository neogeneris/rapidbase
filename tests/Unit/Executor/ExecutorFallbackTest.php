<?php

/**
 * ExecutorFallbackTest - Verifica que Executor::execute use getColumnMeta()
 * como fallback cuando el CompiledQuery no tiene projection map,
 * y que el resultado sea FETCH_NUM (array numérico) mientras los nombres
 * de columna se almacenan en el CompiledQuery.
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use RapidBase\Core\Conn;
use RapidBase\Core\Executor;
use RapidBase\Core\SQL\CompiledQuery;

// Configurar base de datos en memoria (sin schema_map)
Conn::setup('sqlite::memory:', '', '', 'main');
$pdo = Conn::get('main');
$pdo->exec("CREATE TABLE products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    price REAL NOT NULL
)");
$pdo->exec("INSERT INTO products (name, price) VALUES ('Laptop', 999.99)");
$pdo->exec("INSERT INTO products (name, price) VALUES ('Mouse', 24.50)");

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
echo "PRUEBA DE EXECUTOR CON FALLBACK getColumnMeta()\n";
echo "================================================\n\n";

// Crear un CompiledQuery sin projection map
$sql = 'SELECT id, name, price FROM products ORDER BY id';
$params = [];
$compiled = new CompiledQuery($sql, $params, CompiledQuery::SELECT, []); // sin mapa

echo "Ejecutando SELECT con FETCH_NUM y sin projection map...\n";

// Ejecutar con FETCH_NUM (el valor por defecto en run())
$result = Executor::execute($compiled, \PDO::FETCH_NUM);

// Recuperar el mapa que el Executor acaba de descubrir
$map = $compiled->getProjectionMap();

echo "Resultado (numérico): " . json_encode($result, JSON_PRETTY_PRINT) . "\n";
echo "Mapa de columnas descubierto: " . json_encode($map, JSON_PRETTY_PRINT) . "\n\n";

// Verificar que el resultado es un array numérico
test("Devuelve un array con 2 elementos", function() use ($result) {
    assert(count($result) === 2);
});

test("Primera fila es un array numérico (índice 0 es '1')", function() use ($result) {
    assert(is_array($result[0]) && $result[0][0] === 1);
});

// Verificar el mapa de columnas
test("El mapa contiene la clave 'id'", function() use ($map) {
    assert(array_key_exists('id', $map));
});

test("El mapa contiene la clave 'name'", function() use ($map) {
    assert(array_key_exists('name', $map));
});

test("El mapa contiene la clave 'price'", function() use ($map) {
    assert(array_key_exists('price', $map));
});

test("Nombre del primer producto es 'Laptop' (accediendo vía mapa)", function() use ($result, $map) {
    $nameIndex = $map['name'];
    assert($result[0][$nameIndex] === 'Laptop');
});

test("Precio del primer producto es 999.99 (accediendo vía mapa)", function() use ($result, $map) {
    $priceIndex = $map['price'];
    assert(abs($result[0][$priceIndex] - 999.99) < 0.01);
});

echo "\n================================================\n";
echo "RESULTADO: $passed pasaron, $failed fallaron\n";
echo "================================================\n";