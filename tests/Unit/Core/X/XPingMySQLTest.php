<?php

require_once __DIR__ . '/bootstrap.php';

use RapidBase\Core\X;
use RapidBase\Core\DB;

$pass = true;

echo "--- Iniciando XPingMysqlTest ---\n";

$host     = 'localhost';
$port     = 3306;
$user     = 'root';
$password = '';
$database = 'test';
$dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
$connectionId = 'mysql_ping_test';

echo "Registrando conexión MySQL ($host:$port, user:$user, db:$database)... ";
try {
    DB::setup($dsn, $user, $password, $connectionId);
    echo "OK\n";
} catch (\Throwable $e) {
    echo "[SKIP] No se pudo conectar a MySQL: " . $e->getMessage() . "\n";
    echo "Resultado: XPingMysqlTest omitido.\n";
    exit(0);
}

echo "Test: Ping a MySQL ($connectionId)... ";
try {
    $res = X::con($connectionId)->ping();

    if ($res['success'] === true && isset($res['latency']) && $res['latency'] > 0) {
        echo "[OK] ({$res['latency']}ms)\n";
    } else {
        echo "[ERROR]\n";
        echo "  Respuesta: " . json_encode($res) . "\n";
        $pass = false;
    }
} catch (\Throwable $e) {
    echo "[ERROR] Excepción: " . $e->getMessage() . "\n";
    $pass = false;
}

echo "---------------------------\n";

if ($pass) {
    echo "Resultado: All XPingMysqlTest passed.\n";
} else {
    echo "Resultado: Some XPingMysqlTest failed.\n";
    exit(1);
}
