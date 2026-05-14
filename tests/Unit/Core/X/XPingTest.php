<?php

require_once __DIR__ . '/bootstrap.php';

use RapidBase\Core\X;
use RapidBase\Core\DB;

$pass = true;

echo "--- Iniciando XPingSqliteTest ---\n";

$dbFile = __DIR__ . '/../../../tmp/demo.sqlite'; // relativo a tests/Unit/Core/X/

if (!file_exists($dbFile)) {
    echo "[SKIP] No se encontró $dbFile.\n";
    echo "Resultado: XPingSqliteTest omitido.\n";
    exit(0);
}

$connectionId = 'demo_ping';

echo "Registrando conexión SQLite ($dbFile)... ";
try {
    DB::setup("sqlite:$dbFile", '', '', $connectionId);
    echo "OK\n";
} catch (\Throwable $e) {
    echo "[ERROR] No se pudo conectar: " . $e->getMessage() . "\n";
    exit(1);
}

echo "Test: Ping a SQLite ($connectionId)... ";
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
    echo "Resultado: All XPingSqliteTest passed.\n";
} else {
    echo "Resultado: Some XPingSqliteTest failed.\n";
    exit(1);
}