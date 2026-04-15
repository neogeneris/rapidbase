<?php

namespace Tests\Unit\Core;

// 1. Carga de dependencias
require_once __DIR__ . '/../../../src/Core/SQL.php';
require_once __DIR__ . '/../../../src/Core/Conn.php';
require_once __DIR__ . '/../../../src/Core/Executor.php';
require_once __DIR__ . '/../../../src/Core/Gateway.php';
require_once __DIR__ . '/../../../src/Core/DB.php'; // La fachada a probar

use Core\Conn;
use Core\DB;
use Core\Gateway;
use Core\SQL;

echo "--- Ejecutando: Core\DB::count Test (Fachada Estática) ---\n";

function assert_db($name, $assertion, $details = "") {
    if ($assertion) {
        echo "\033[32m[OK]\033[0m $name\n";
    } else {
        echo "\033[31m[FAIL]\033[0m $name\n";
        if ($details) echo "  Detalle: $details\n";
        exit(1);
    }
}

// 2. SETUP
Conn::setup("sqlite::memory:", "", "", "main");
$pdo = Conn::get();

$pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT)");
$pdo->exec("INSERT INTO users (role) VALUES ('admin'), ('editor'), ('editor')");

// --- TEST 1: Llamada básica a través de la fachada ---
SQL::reset();
$total = DB::count('users');
assert_db("DB::count total", $total === 3, "Esperaba 3, obtuvo $total");

// --- TEST 2: Llamada con filtros (Validación de parámetros) ---
SQL::reset();
$editors = DB::count('users', ['role' => 'editor']);
assert_db("DB::count filtrado", $editors === 2, "Esperaba 2, obtuvo $editors");

// --- TEST 3: Integridad del estado en Gateway ---
// Verificamos que DB realmente usó Gateway internamente
$status = Gateway::status();
assert_db(
    "Verificación de trazabilidad", 
    $status['table'] === 'users' && $status['type'] === 'count',
    "El Gateway no registró la operación enviada por DB."
);

echo "\n\033[32m[SUCCESS]\033[0m La fachada DB::count está correctamente vinculada al Gateway.\n";
