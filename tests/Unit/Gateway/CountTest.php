<?php

namespace Tests\Unit\Gateway;

// 1. Carga de infraestructura (Rutas relativas según tu árbol)
require_once __DIR__ . '/../../../src/RapidBase/Core/SQL.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/Conn.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/Executor.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/Gateway.php';

use RapidBase\Core\Conn;
use RapidBase\Core\Gateway;
use RapidBase\Core\SQL;

echo "--- Ejecutando: Gateway CountTest (Integración End-to-End) ---\n";

function assert_count($name, $assertion, $details = "") {
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
$pdo->exec("CREATE TABLE leads (
    id INTEGER PRIMARY KEY AUTOINCREMENT, 
    email TEXT, 
    status TEXT
)");

// Insertar semillas
$pdo->exec("INSERT INTO leads (email, status) VALUES ('test1@rb.com', 'pending')");
$pdo->exec("INSERT INTO leads (email, status) VALUES ('test2@rb.com', 'pending')");
$pdo->exec("INSERT INTO leads (email, status) VALUES ('test3@rb.com', 'converted')");

// --- TEST 1: Conteo Total (Sin filtros) ---
SQL::reset();
$total = Gateway::count('leads');
assert_count("Conteo total de registros", $total === 3, "Se esperaban 3, se obtuvo $total");

// --- TEST 2: Conteo con Filtro Simple (Named Parameters) ---
SQL::reset();
$pending = Gateway::count('leads', ['status' => 'pending']);
assert_count("Conteo con filtro 'pending'", $pending === 2, "Se esperaban 2, se obtuvo $pending");

// --- TEST 3: Conteo con Filtro Inexistente ---
SQL::reset();
$ghosts = Gateway::count('leads', ['status' => 'rejected']);
assert_count("Conteo de registros inexistentes", $ghosts === 0, "Se esperaba 0, se obtuvo $ghosts");

// --- TEST 4: Verificación de Parámetros en Gateway::status ---
// Esto confirma que Gateway realmente pasó ':p0' al Executor
$status = Gateway::status();
$hasParam = isset($status['params']['p0']) && $status['params']['p0'] === 'rejected';
assert_count("Integridad de parámetros en el despacho", $hasParam, "Los parámetros no llegaron correctamente al estado del Gateway");

echo "\n\033[32m[SUCCESS]\033[0m Gateway::count está despachando y retornando valores correctamente.\n";
