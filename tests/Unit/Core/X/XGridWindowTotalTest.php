<?php

require_once __DIR__ . '/bootstrap.php';

use RapidBase\Core\X;
use RapidBase\Core\Conn;
use RapidBase\Core\DB;

$pass = true;

echo "--- Iniciando XGridWindowTotalTest ---\n";

// 1. Configurar base de datos y datos de prueba
DB::setup('sqlite::memory:', '', '', 'grid_test');
$pdo = Conn::get('grid_test');
$pdo->exec("CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT)");

// Insertar 100 registros
$stmt = $pdo->prepare("INSERT INTO items (name) VALUES (?)");
for ($i = 1; $i <= 100; $i++) {
    $stmt->execute(["item_$i"]);
}

// Forzar estrategia 'window' (como se usa en el endpoint para MySQL)
echo "Test: Total con window strategy en página vacía (página 3, 50 registros/pág)... ";
try {
    $x = X::con('grid_test')
        ->from('items')
        ->totalStrategy('window');

    $result = $x->grid('*', [3, 50]);

    $total = $result['total'] ?? -1;
    $rows  = count($result['data'] ?? []);

    if ($total === 100 && $rows === 0) {
        echo "[OK] total=$total, rows=$rows\n";
    } else {
        echo "[ERROR] Esperado total=100 y 0 filas. Obtenido total=$total, filas=$rows\n";
        $pass = false;
    }
} catch (\Throwable $e) {
    echo "[ERROR] Excepción: " . $e->getMessage() . "\n";
    $pass = false;
}

// 2. Repetir con 'separate' para comparar
echo "Test: Total con separate strategy en misma página vacía... ";
try {
    $x2 = X::con('grid_test')
        ->from('items')
        ->totalStrategy('separate');

    $result2 = $x2->grid('*', [3, 50]);

    $total2 = $result2['total'] ?? -1;
    $rows2  = count($result2['data'] ?? []);

    if ($total2 === 100 && $rows2 === 0) {
        echo "[OK] total=$total2, rows=$rows2\n";
    } else {
        echo "[ERROR] Esperado total=100 y 0 filas. Obtenido total=$total2, filas=$rows2\n";
        $pass = false;
    }
} catch (\Throwable $e) {
    echo "[ERROR] Excepción: " . $e->getMessage() . "\n";
    $pass = false;
}

echo "---------------------------\n";

if ($pass) {
    echo "Resultado: All XGridWindowTotalTest passed.\n";
} else {
    echo "Resultado: Some XGridWindowTotalTest failed.\n";
    exit(1);
}