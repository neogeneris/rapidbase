<?php


namespace Tests\Unit\Gateway;

// 1. Carga de infraestructura refactorizada (SQL v2 con Q.php)
require_once __DIR__ . '/../../../src/RapidBase/Core/Conn.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/Executor.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/Gateway.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/Cache/CacheService.php';

// Cargar clases SQL v2
require_once __DIR__ . '/../../../src/RapidBase/Core/SQL/Q.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/SQL/CompiledQuery.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/SQL/ConditionMatrix.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/SQL/DeterministicJoin.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/SQL/JoinResolver.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/SQL/JoinStrategy.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/SQL/SqlCompiler.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/SQL/QType.php';

// Dependencias adicionales de SQL v2
require_once __DIR__ . '/../../../src/RapidBase/Core/SchemaMap.php';

use RapidBase\Core\Conn;
use RapidBase\Core\Gateway;

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
$tempDb = tempnam(sys_get_temp_dir(), 'rapidbase_action_') . '.sqlite';
Conn::setup("sqlite:$tempDb", "", "", "main");
$pdo = Conn::get();
$pdo->exec("PRAGMA busy_timeout = 5000");
$pdo->exec("PRAGMA journal_mode = WAL");

// Configurar SchemaMap para que projectionMap funcione correctamente
$schema = [
    'relationships' => [
        'from' => [],
        'to' => []
    ],
    'tables' => [
        'pilotos' => [
            'id'      => ['type' => 'int'],
            'nombre'  => ['type' => 'varchar'],
            'escuderia' => ['type' => 'varchar'],
            'puntos'  => ['type' => 'int']
        ]
    ]
];
\RapidBase\Core\SchemaMap::setMap($schema, 'main');

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
$result = Gateway::select('*', 'pilotos', ['id' => 1], [], [], [], 1, false, \PDO::FETCH_ASSOC);
$piloto = $result['data'][0] ?? null;
assert_action("Recuperar datos consistentes", $piloto && $piloto['nombre'] === 'Ayrton Senna');

// --- TEST 3: UPDATE ---
Gateway::action('update', 'pilotos', ['puntos' => 25], ['id' => 1]);
$result2 = Gateway::select('puntos', 'pilotos', ['id' => 1], [], [], [], 1, false, \PDO::FETCH_ASSOC);
$puntosActualizados = $result2['data'][0]['puntos'] ?? null;
assert_action("Actualización de puntos", $puntosActualizados == 25);

// --- TEST 4: SEGURIDAD (DELETE masivo) ---
$bloqueoExitoso = false;
try {
    Gateway::action('delete', 'pilotos', []); 
} catch (\RuntimeException $e) {
    $bloqueoExitoso = str_contains($e->getMessage(), 'DANGER');
}
assert_action("Protección contra DELETE masivo (Seguridad)", $bloqueoExitoso);

// --- TEST 5: EXISTS & COUNT ---
assert_action("Verificar existencia de piloto", Gateway::exists('pilotos', ['nombre' => 'Ayrton Senna']));
assert_action("Contar registros totales", Gateway::count('pilotos') === 1);

echo "\n\033[32m[SUCCESS]\033[0m El Gateway y la Fundición SQL están perfectamente acoplados.\n";

// Cleanup: eliminar archivo temporal
if (isset($tempDb) && file_exists($tempDb)) {
    @unlink($tempDb);
    @unlink($tempDb . '-wal');
    @unlink($tempDb . '-shm');
}
