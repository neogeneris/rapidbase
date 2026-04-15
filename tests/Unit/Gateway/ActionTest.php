<?php


namespace Tests\Unit\Gateway;

// 1. Carga de infraestructura
require_once __DIR__ . '/../../../src/Core/SQL.php';
require_once __DIR__ . '/../../../src/Core/Conn.php';
require_once __DIR__ . '/../../../src/Core/Executor.php';
require_once __DIR__ . '/../../../src/Core/Gateway.php';
require_once __DIR__ . '/../../../src/Core/Cache/CacheService.php'; // <-- Línea añadida

use Core\Conn;
use Core\Gateway;
use Core\SQL;

echo "--- Ejecutando: Gateway ActionTest (Integración con SQL v2) ---\n";

function assert_action($name, $assertion, $details = "") {
    if ($assertion) {
        echo "\033[32m[OK]\033[0m $name\n";
    } else {
        echo "\033[31m[FAIL]\033[0m $name\n";
        if ($details) echo "  Detalle: $details\n";
        $status = Gateway::status();
        echo "  Último SQL: " . ($status['sql'] ?? 'N/A') . "\n";
        echo "  Último Error: " . ($status['error'] ?? 'Ninguno') . "\n";
        exit(1);
    }
}

// 2. SETUP
Conn::setup("sqlite::memory:", "", "", "main");
$pdo = Conn::get();
$pdo->exec("CREATE TABLE pilotos (
    id INTEGER PRIMARY KEY AUTOINCREMENT, 
    nombre TEXT, 
    escuderia TEXT,
    puntos INTEGER
)");

// --- TEST 1: INSERT ---
$resInsert = Gateway::action('insert', 'pilotos', [
    'nombre' => 'Ayrton Senna',
    'escuderia' => 'McLaren',
    'puntos' => 0
]);
assert_action("Insertar registro (ID retornado)", $resInsert['lastId'] == 1);

// --- TEST 2: SELECT (usando el array devuelto) ---
$result = Gateway::select('*', 'pilotos', ['id' => 1]);
$piloto = $result['data'][0] ?? null;
assert_action("Recuperar datos consistentes", $piloto && $piloto['nombre'] === 'Ayrton Senna');

// --- TEST 3: UPDATE ---
Gateway::action('update', 'pilotos', ['puntos' => 25], ['id' => 1]);
$result2 = Gateway::select('puntos', 'pilotos', ['id' => 1]);
$puntosActualizados = $result2['data'][0]['puntos'] ?? null;
assert_action("Actualización de puntos", $puntosActualizados == 25);

// --- TEST 4: SEGURIDAD (DELETE masivo) ---
$bloqueoExitoso = false;
try {
    Gateway::action('delete', 'pilotos', []); 
} catch (\RuntimeException $e) {
    $bloqueoExitoso = str_contains($e->getMessage(), 'PELIGRO');
}
assert_action("Protección contra DELETE masivo (Seguridad)", $bloqueoExitoso);

// --- TEST 5: EXISTS & COUNT ---
assert_action("Verificar existencia de piloto", Gateway::exists('pilotos', ['nombre' => 'Ayrton Senna']));
assert_action("Contar registros totales", Gateway::count('pilotos') === 1);

echo "\n\033[32m[SUCCESS]\033[0m El Gateway y la Fundición SQL están perfectamente acoplados.\n";