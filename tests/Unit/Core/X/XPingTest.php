<?php

require_once __DIR__ . '/bootstrap.php';

use RapidBase\Core\X;
use RapidBase\Core\Conn;
use RapidBase\Core\Gateway;

// 1. Configuracion inicial: SQLite en memoria para la prueba exitosa
\RapidBase\Core\DB::setup('sqlite::memory:', '', '', 'ping_ok');

$pass = true;

echo "--- Iniciando XPingTest ---\n";

// TEST 1: Ping Exitoso
// ---------------------------------------------------------------------
try {
    echo "Test 1: Ping a conexion valida... ";
    $res = X::con('ping_ok')->ping(1); // 1 reintento permitido
    
    if ($res['success'] === true && $res['attempts'] === 1) {
        echo "[OK]\n";
    } else {
        echo "[ERROR]\n";
        echo "  Pista: Se esperaba success=true y attempts=1.\n";
        echo "  Obtenido: success=" . ($res['success'] ? 'true' : 'false') . ", attempts=" . $res['attempts'] . "\n";
        $pass = false;
    }
} catch (\Exception $e) {
    echo "[ERROR]\n";
    echo "  Pista: Excepcion inesperada: " . $e->getMessage() . "\n";
    $pass = false;
}

// TEST 2: Ping Fallido con Reintentos y Delay
// ---------------------------------------------------------------------
try {
    echo "Test 2: Verificando reintentos... ";
    
    // 1. En lugar de usar una ruta de archivo rota que hace explotar a Conn::add,
    // usamos una configuracion de red que PDO acepte pero que no responda.
    // Esto permitira que Conn::add pase, pero que Gateway::ping falle y reintente.
    $badConfig = [
        'driver' => 'mysql',
        'dsn'    => 'mysql:host=1.2.3.4;port=9999;connect_timeout=1',
        'user'   => 'root',
        'pass'   => 'root'
    ];

    // Simulamos que esta configuracion esta registrada bajo el ID 'ping_fail'
    // Para no modificar Conn.php, podemos inyectarla manualmente en el Gateway para la prueba
    
    $start = microtime(true);
    $retries = 2;
    $delay = 100;
    
    // Llamamos al Gateway directamente con el array para validar el bucle de reintentos
    $res = Gateway::ping('mysql', $badConfig, $retries, $delay);
    
    $elapsed = (microtime(true) - $start) * 1000;

    $checkAttempts = ($res['attempts'] === 3); // 1 original + 2 reintentos
    $checkTime = ($elapsed >= 200); // 2 esperas de 100ms

    if ($res['success'] === false && $checkAttempts && $checkTime) {
        echo "[OK]\n";
        echo "  Info: Reintentos ejecutados: " . $res['attempts'] . " en " . round($elapsed) . "ms\n";
    } else {
        echo "[ERROR]\n";
        echo "  Pista: Intentos: " . $res['attempts'] . ", Tiempo: " . round($elapsed) . "ms\n";
        $pass = false;
    }
} catch (\Exception $e) {
    echo "[ERROR]\n";
    echo "  Pista: Excepcion: " . $e->getMessage() . "\n";
    $pass = false;
}

echo "---------------------------\n";

if ($pass) {
    echo "Resultado: All XPingTest passed.\n";
} else {
    echo "Resultado: Some XPingTest failed.\n";
    exit(1);
}