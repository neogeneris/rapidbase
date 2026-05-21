<?php

require_once __DIR__ . '/bootstrap.php';

use RapidBase\Core\X;
use RapidBase\Core\DB;

$pass = true;

echo "--- Iniciando XPingSqlsrvTest ---\n";

// Configuración desde variables de entorno (con valores por defecto)
$host     = $_ENV['SQLSRV_HOST']     ?? 'localhost';
$port     = $_ENV['SQLSRV_PORT']     ?? '1433';
$user     = $_ENV['SQLSRV_USER']     ?? 'sa';
$password = $_ENV['SQLSRV_PASSWORD'] ?? 'manager';
$database = $_ENV['SQLSRV_DATABASE'] ?? 'master';

$serverStr = $host;
if ($port) {
    $serverStr .= ',' . $port;
}
$dsn = "sqlsrv:Server={$serverStr};Database={$database};Encrypt=0;TrustServerCertificate=1";
$connectionId = 'sqlsrv_ping_test';

echo "Registrando conexión SQL Server ($host:$port, user:$user, db:$database)... ";
try {
    DB::setup($dsn, $user, $password, $connectionId);
    echo "OK\n";
} catch (\Throwable $e) {
    echo "[SKIP] No se pudo conectar a SQL Server: " . $e->getMessage() . "\n";
    echo "Resultado: XPingSqlsrvTest omitido.\n";
    exit(0);
}

echo "Test: Ping a SQL Server ($connectionId)... ";
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
    echo "Resultado: All XPingSqlsrvTest passed.\n";
} else {
    echo "Resultado: Some XPingSqlsrvTest failed.\n";
    exit(1);
}