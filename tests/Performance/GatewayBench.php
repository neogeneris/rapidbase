<?php
namespace Tests\Performance;

// 1. CARGA DE LA FUNDACIÓN
$basePath = __DIR__ . "/../../src/Core";
include_once "$basePath/Cache/CacheService.php";
include_once "$basePath/Cache/Adapters/DirectoryCacheAdapter.php";
include_once "$basePath/SQL.php";
include_once "$basePath/Conn.php";
include_once "$basePath/Executor.php";
include_once "$basePath/Gateway.php";

use Core\Gateway;
use Core\Conn;
use Core\Cache\CacheService;

/**
 * CONFIGURACIÓN DE CONEXIÓN
 */
Conn::setup("sqlite::memory:", "", ""); 
$pdo = Conn::get();

// Población de datos
$pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, active INTEGER)");
$stmt = $pdo->prepare("INSERT INTO users (name, active) VALUES (?, 1)");
for ($i = 1; $i <= 100; $i++) { $stmt->execute(["Usuario Test $i"]); }

// Setup de Caché
$cachePath = __DIR__ . DIRECTORY_SEPARATOR . 'temp_perf_test';
if (!is_dir($cachePath)) mkdir($cachePath, 0777, true);
CacheService::init($cachePath);

echo "--- RapidBase Performance Benchmark ---\n";

/**
 * MAPEO DE ARGUMENTOS (SEGÚN TU GATEWAY.PHP LÍNEA 21)
 * #1 mixed $fields
 * #2 mixed $table
 * #3 array $where
 * #4 array $sort
 * #5 int $page      <-- AQUÍ ESTABA EL ERROR
 * #6 int $perPage
 * #7 bool $withTotal (El nuevo que agregamos)
 */
$fields    = '*';
$table     = 'users';
$where     = ['active' => 1];
$sort      = [];
$page      = 1;
$perPage   = 10;
$withTotal = true;

/**
 * TEST 1: Gateway::select (Directo)
 */
$startSelect = microtime(true);
// Llamada limpia con el orden exacto de tu firma
$resPuro = Gateway::select($fields, $table, $where, $sort, $page, $perPage, $withTotal);
$timePuro = microtime(true) - $startSelect;

echo "[1] Gateway::select (Puro): " . number_format($timePuro, 6) . "s\n";

/**
 * TEST 2: Gateway::selectCached (L1/L2)
 */
// Calentamiento
Gateway::selectCached($fields, $table, $where, $sort, $page, $perPage, $withTotal);

gc_collect_cycles();
$iterations = 1000;
$startCached = microtime(true);

for ($i = 0; $i < $iterations; $i++) {
    Gateway::selectCached($fields, $table, $where, $sort, $page, $perPage, $withTotal);
}

$timeCachedAvg = (microtime(true) - $startCached) / $iterations;

echo "[2] Gateway::selectCached (Avg de $iterations): " . number_format($timeCachedAvg, 6) . "s\n\n";

/**
 * RESULTADO
 */
$speedup = ($timeCachedAvg > 0) ? ($timePuro / $timeCachedAvg) : 0;
echo "--- RESULTADO FINAL ---\n";
echo "La versión cacheada es " . number_format($speedup, 2) . "x más rápida.\n";