<?php

/**
 * RapidBase - Stress Test Final
 * Estructura: src/Core/Cache/
 */


include_once("../../src/Core/CacheInterface.php");
include_once("../../src/Core/Cache/Adapters/DirectoryCacheAdapter.php");
include_once("../../src/Core/Cache/Adapters/ZipCacheAdapter.php");

// 2. Importaciones con los Namespaces correctos según tu 'tree'
use Core\Cache\Adapters\DirectoryCacheAdapter;
use Core\Cache\Adapters\ZipCacheAdapter;

// 3. Configuración de Rutas Temporales
$tmpPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR;
if (!is_dir($tmpPath)) {
    mkdir($tmpPath, 0777, true);
}

// 4. Parámetros del Test (Grid de 20 registros)
$iterations = 100;
$table = 'test_stress_table';
$sampleData = array_fill(0, 20, [
    'id' => rand(1, 99),
    'name' => 'Data Row',
    'timestamp' => microtime(true)
]);

echo "--- INICIANDO BENCHMARK ---\n";

$dir = new DirectoryCacheAdapter($tmpPath);
$zip = new ZipCacheAdapter($tmpPath);

// --- FASE 1: ESCRITURA ---
echo "Poblando caches ($iterations archivos)... ";
for ($i = 0; $i < $iterations; $i++) {
    $key = "p$i";
    $dir->set($table, $key, $sampleData);
    $zip->set($table, $key, $sampleData);
}
echo "OK\n";

// --- FASE 2: LECTURA DIRECTORY ---
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $res = $dir->get($table, "p$i");
}
$timeDir = microtime(true) - $start;

// --- FASE 3: LECTURA ZIP ---
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $res = $zip->get($table, "p$i");
}
$timeZip = microtime(true) - $start;

// --- REPORTE ---
echo "\nRESULTADOS:\n";
echo str_repeat("=", 40) . "\n";
echo "Directory (.php): " . number_format($timeDir * 1000, 2) . " ms\n";
echo "Zip (.zip):       " . number_format($timeZip * 1000, 2) . " ms\n";
echo str_repeat("-", 40) . "\n";

$factor = $timeZip / $timeDir;
echo "El ZIP es " . number_format($factor, 2) . "x más " . ($factor > 1 ? "lento" : "rápido") . "\n";
echo str_repeat("=", 40) . "\n";