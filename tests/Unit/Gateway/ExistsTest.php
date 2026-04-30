<?php

// 1. Carga de infraestructura
require_once __DIR__ . '/../../../src/RapidBase/Core/SQL.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/Conn.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/Executor.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/Gateway.php';

use RapidBase\Core\Conn;
use RapidBase\Core\Gateway;
use RapidBase\Core\SQL;

echo "--- Ejecutando: Gateway ExistsTest (Integración con SQL v2) ---\n";

function assert_exists($name, $assertion, $details = "") {
    if ($assertion) {
        echo "\033[32m[OK]\033[0m $name\n";
    } else {
        echo "\033[31m[FAIL]\033[0m $name\n";
        if ($details) echo "  Detalle: $details\n";
        
        $status = Gateway::status();
        echo "  SQL ejecutado: " . ($status['sql'] ?? 'N/A') . "\n";
        echo "  Params enviados: " . json_encode($status['params'] ?? []) . "\n";
        echo "  Error reportado: " . ($status['error'] ?? 'Ninguno') . "\n";
        exit(1);
    }
}

// 2. SETUP: SQLite en Memoria
$tempDb = tempnam(sys_get_temp_dir(), 'rapidbase_exists_') . '.sqlite';
Conn::setup("sqlite:$tempDb", "", "", "main");
$pdo = Conn::get();
$pdo->exec("PRAGMA busy_timeout = 5000");
$pdo->exec("PRAGMA journal_mode = WAL");

// Crear tabla de prueba
$pdo->exec("CREATE TABLE partners (
    id INTEGER PRIMARY KEY AUTOINCREMENT, 
    slug TEXT, 
    active INTEGER
)");

// Insertar semilla
$pdo->exec("INSERT INTO partners (slug, active) VALUES ('ferrari', 1)");

// --- TEST 1: Registro que sí existe ---
SQL::reset();
$existsTrue = Gateway::exists('partners', ['slug' => 'ferrari']);
assert_exists(
    "Verificar registro existente", 
    $existsTrue === true, 
    "Se esperaba true para 'ferrari', se obtuvo false. Revisa si el SQL genera la columna 'check'."
);

// --- TEST 2: Registro que NO existe ---
SQL::reset();
$existsFalse = Gateway::exists('partners', ['slug' => 'mercedes']);
assert_exists(
    "Verificar registro inexistente", 
    $existsFalse === false, 
    "Se esperaba false para 'mercedes', se obtuvo true."
);

// --- TEST 3: Caso con múltiples condiciones ---
SQL::reset();
$existsActive = Gateway::exists('partners', ['slug' => 'ferrari', 'active' => 1]);
assert_exists(
    "Verificar con múltiples condiciones", 
    $existsActive === true
);

// --- TEST 4: Verificación de parámetros ---
// Resetear y ejecutar una consulta específica para verificar parámetros
SQL::reset();
$existsCheck = Gateway::exists('partners', ['slug' => 'ferrari', 'active' => 1]);
// Capturar el status inmediatamente después de la última operación
$status = Gateway::status();

$paramsOk = false;
$debugInfo = "";

if (!empty($status['params']) && is_array($status['params'])) {
    $debugInfo = "Keys: " . json_encode(array_keys($status['params'])) . ", Values: " . json_encode(array_values($status['params']));
    
    // Normalizar keys a enteros para comparación
    $normalizedParams = [];
    foreach ($status['params'] as $key => $value) {
        $normalizedKey = is_numeric($key) ? (int)$key : $key;
        $normalizedParams[$normalizedKey] = $value;
    }
    
    $debugInfo .= ", Normalized: " . json_encode($normalizedParams);
    
    // Verificar si los parámetros contienen 'ferrari' y 1 (usando array_values para mayor seguridad)
    $paramValues = array_values($normalizedParams);
    if (isset($paramValues[0]) && $paramValues[0] === 'ferrari' && isset($paramValues[1]) && $paramValues[1] === 1) {
        $paramsOk = true;
    }
    // O verificar named params
    elseif ((isset($status['params']['p0']) && $status['params']['p0'] === 'ferrari') || 
            (isset($status['params']['slug']) && $status['params']['slug'] === 'ferrari')) {
        $paramsOk = true;
    }
} else {
    $debugInfo = "Params vacíos o no es array";
}

assert_exists(
    "Integridad de parámetros en exists()", 
    $paramsOk,
    "Estructura recibida: " . json_encode($status['params'], JSON_FORCE_OBJECT) . " | Debug: $debugInfo"
);

// echo "\n\033[32m[SUCCESS]\033[0m Gateway::exists funciona correctamente con la infraestructura actual.\n";
echo "\n\033[32m[SUCCESS]\033[0m Gateway::exists funciona correctamente con la infraestructura actual.\n";

// Cleanup: eliminar archivo temporal
if (isset($tempDb) && file_exists($tempDb)) {
    @unlink($tempDb);
    @unlink($tempDb . '-wal');
    @unlink($tempDb . '-shm');
}
