<?php

require_once __DIR__ . '/bootstrap.php';

use RapidBase\Core\X;
use RapidBase\Core\Conn;

// Setup
\RapidBase\Core\DB::setup('sqlite::memory:', '', '', 'test_x');
$pdo = Conn::get('test_x');
$pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT)");
$pdo->exec("INSERT INTO users (name, email) VALUES ('Alice', 'alice@test.com')");

$pass = true;

// Test 1: valid instance
$x = X::con('test_x');
if ($x instanceof X) {
    echo "  [OK] X::con() returns X instance\n";
} else {
    echo "[ERROR] X::con() should return X instance\n";
    $pass = false;
}

// Test 2: invalid connection - debe lanzar excepcion al ejecutar
try {
    X::con('nonexistent')->from('users')->select();
    echo "[ERROR] Expected exception for missing connection\n";
    $pass = false;
} catch (\Throwable $e) {
    // Capturamos cualquier excepcion (RuntimeException o InvalidArgumentException)
    echo "  [OK] Exception thrown for missing connection\n";
}

if ($pass) {
    echo "All XConnectTest passed.\n";
} else {
    echo "Some XConnectTest failed.\n";
    exit(1);
}