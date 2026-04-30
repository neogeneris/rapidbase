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
    $paramValues = array_values($status['params']);
    $debugInfo = "Params completos: " . json_encode($status['params']) . ", Values: " . json_encode($paramValues);
    
    // Verificación flexible: buscar los valores esperados en cualquier orden
    $hasFerrari = in_array('ferrari', $paramValues, true);
    $hasOne = in_array(1, $paramValues, true);
    
    if ($hasFerrari && $hasOne && count($paramValues) === 2) {
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
