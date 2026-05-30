<?php

/**
 * GatewayUpsertTest - Prueba funcional de Gateway::upsert() y su Fallback.
 * 
 * Verifica que el upsert funcione correctamente tanto en modo nativo (atómico)
 * como en modo fallback (update-then-insert).
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use RapidBase\Core\DB;
use RapidBase\Core\Conn;
use RapidBase\Core\SchemaMap;
use RapidBase\Core\Gateway;

$passed = 0;
$failed = 0;

$test = function(string $description, callable $fn) use (&$passed, &$failed): void {
    try {
        if ($fn()) {
            echo "  [OK] $description\n";
            $passed++;
        } else {
            echo "  [FAIL] $description\n";
            $failed++;
        }
    } catch (Throwable $e) {
        echo "  [FAIL] $description\n";
        echo "         Error: " . $e->getMessage() . "\n";
        $failed++;
    }
};

echo "================================================\n";
echo "PRUEBA DE GATEWAY::UPSERT() Y FALLBACK\n";
echo "================================================\n\n";

// Configuración inicial (SQLite en memoria)
Conn::setup('sqlite::memory:', '', '', 'test_upsert');
$pdo = Conn::get('test_upsert');
$pdo->exec("CREATE TABLE IF NOT EXISTS items (
    id INTEGER PRIMARY KEY,
    code TEXT UNIQUE,
    stock INTEGER DEFAULT 0
)");

// --- ESCENARIO 1: Modo Atómico (Nativo) ---
echo "--- 1. Modo Atómico (Simulado) ---\n";
SchemaMap::setMap([
    'features' => ['atomic_upsert' => true],
    'tables' => []
], 'test_upsert');

$test("Inserta nuevo registro (Nativo)", function() use ($pdo) {
    $res = Gateway::upsert('items', ['id' => 1, 'code' => 'A01', 'stock' => 10], ['id']);
    $stock = $pdo->query("SELECT stock FROM items WHERE id=1")->fetchColumn();
    return $res['success'] && $stock == 10;
});

$test("Actualiza registro existente (Nativo)", function() use ($pdo) {
    $res = Gateway::upsert('items', ['id' => 1, 'code' => 'A01', 'stock' => 25], ['id']);
    $stock = $pdo->query("SELECT stock FROM items WHERE id=1")->fetchColumn();
    return $res['success'] && $stock == 25;
});

// --- ESCENARIO 2: Modo Fallback (Manual) ---
echo "\n--- 2. Modo Fallback (Update-then-Insert) ---\n";
SchemaMap::setMap([
    'features' => ['atomic_upsert' => false],
    'tables' => []
], 'test_upsert');

$test("Actualiza registro existente (Fallback)", function() use ($pdo) {
    // Ya existe ID 1 con stock 25. Vamos a subirlo a 50.
    $res = Gateway::upsert('items', ['id' => 1, 'code' => 'A01', 'stock' => 50], ['id']);
    $stock = $pdo->query("SELECT stock FROM items WHERE id=1")->fetchColumn();
    return $res['action'] === 'upsert_fallback_update' && $stock == 50;
});

$test("Inserta nuevo registro (Fallback)", function() use ($pdo) {
    $res = Gateway::upsert('items', ['id' => 2, 'code' => 'B02', 'stock' => 100], ['id']);
    $stock = $pdo->query("SELECT stock FROM items WHERE id=2")->fetchColumn();
    return $res['action'] === 'upsert_fallback_insert' && $stock == 100;
});

$test("Upsert por columna UNIQUE no PK (Fallback)", function() use ($pdo) {
    // Usamos 'code' como columna de conflicto
    $res = Gateway::upsert('items', ['code' => 'B02', 'stock' => 150], ['code']);
    $stock = $pdo->query("SELECT stock FROM items WHERE code='B02'")->fetchColumn();
    return $res['action'] === 'upsert_fallback_update' && $stock == 150;
});

// --- Limpieza ---
Conn::close('test_upsert');

echo "\n================================================\n";
echo "RESULTADO: $passed pasaron, $failed fallaron\n";
echo "================================================\n";
