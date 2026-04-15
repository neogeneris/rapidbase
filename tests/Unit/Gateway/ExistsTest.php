<?php

namespace Tests\Unit\Gateway;

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
Conn::setup("sqlite::memory:", "", "", "main");
$pdo = Conn::get();

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

// --- TEST 4: Verificación de parámetros (Named Params) ---
$status = Gateway::status();
assert_exists(
    "Integridad de parámetros en exists()", 
    isset($status['params']['p0']) && $status['params']['p0'] === 'ferrari'
);

// echo "\n\033[32m[SUCCESS]\033[0m Gateway::exists funciona correctamente con la infraestructura actual.\n";