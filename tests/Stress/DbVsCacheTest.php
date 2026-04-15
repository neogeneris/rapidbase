<?php
use Core\Cache\Adapters\DirectoryCacheAdapter;


include_once("../../src/Core/CacheInterface.php");
include_once("../../src/Core/Cache/Adapters/DirectoryCacheAdapter.php");


$tmpPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR;
if (!is_dir($tmpPath)) {
    mkdir($tmpPath, 0777, true);
}


// 1. PREPARACIÓN (Fuera del cronómetro)
$dsn = "mysql:host=localhost;dbname=infy_care;charset=utf8mb4";
$pdo = new PDO($dsn, 'root', '', [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$cache = new DirectoryCacheAdapter($tmpPath);

$data = $pdo->query("SELECT * FROM countries")->fetchAll();
//$cache->set('bench_countries', $data);

// CALENTAMIENTO: Forzamos a OpCache a registrar el archivo
for($i=0; $i<5; $i++) { $cache->get('bench_countries'); }

$iterations = 1000; // Subimos iteraciones para mayor precisión
echo "--- PRUEBA DE LECTURA PURA (1000 ejecuciones) ---" . PHP_EOL;

// 2. MEDICIÓN MYSQL
$startDb = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $dummy = $pdo->query("SELECT * FROM countries")->fetchAll();
}
$timeDb = (microtime(true) - $startDb) / $iterations * 1000;

// 3. MEDICIÓN CACHÉ (RAM/OpCache)
$startCache = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $dummy = $cache->get('bench_countries');
}
$timeCache = (microtime(true) - $startCache) / $iterations * 1000;

echo "Promedio MySQL: " . number_format($timeDb, 4) . " ms" . PHP_EOL;
echo "Promedio Caché: " . number_format($timeCache, 4) . " ms" . PHP_EOL;
echo "Diferencia: " . number_format($timeDb / $timeCache, 1) . "x más rápido." . PHP_EOL;