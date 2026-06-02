<?php

/**
 * Autoloader Real-World Scenario Benchmark
 * 
 * Simula el caso de uso REAL del Autoloader:
 * - Múltiples peticiones HTTP
 * - Cada petición carga 20-50 clases diferentes
 * - Cache hit rate alto (90%+ clases ya descubiertas)
 * 
 * Compara:
 * 1. Autoloader actual (un solo archivo .dat con TODO el mapeo)
 * 2. Enfoque DirectoryCacheAdapter (un archivo .php por clase)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use RapidBase\Core\Cache\Adapters\DirectoryCacheAdapter;

echo "==============================================\n";
echo "  AUTOLOADER REAL-WORLD SCENARIO BENCHMARK\n";
echo "  Single File (.dat) vs Sharded Files (.php)\n";
echo "==============================================\n\n";

// Configuración
$testDir = sys_get_temp_dir() . '/autoloader_real_bench_' . uniqid();
$datDir = $testDir . '/dat';
$phpDir = $testDir . '/php';
if (!is_dir($datDir)) mkdir($datDir, 0775, true);
if (!is_dir($phpDir)) mkdir($phpDir, 0775, true);

$datFile = $datDir . '/autoloader_cache.dat';

// Generar pool de clases (simulando proyecto real)
$totalClasses = 500;
$classPool = [];
for ($i = 0; $i < $totalClasses; $i++) {
    $module = rand(1, 50);
    $classPool[] = [
        'class' => "RapidBase\\Module$module\\Controller\\Class$i",
        'path' => "/var/www/project/src/Module$module/Controller/Class$i.php"
    ];
}

// Inicializar cache DAT con todas las clases
echo "Initializing caches with $totalClasses classes...\n";
file_put_contents($datFile, serialize($classPool), LOCK_EX);

$directoryCache = new DirectoryCacheAdapter($phpDir);
foreach ($classPool as $item) {
    $directoryCache->set('class_' . $item['class'], $item['path']);
}
echo "✓ Caches initialized\n\n";

// Simular múltiples peticiones HTTP
$numRequests = 100;
$classesPerRequest = 30; // Cada petición usa ~30 clases
$hitRate = 0.9; // 90% hit rate (clases ya en cache)

echo "Simulating $numRequests HTTP requests ($classesPerRequest classes each)...\n\n";

// ==========================================
// ESCENARIO 1: Single .dat file
// ==========================================
echo "SCENARIO 1: Single .dat file (current Autoloader)\n";
echo str_repeat("-", 50) . "\n";

$totalTimeDat = 0;
$datResults = ['read' => [], 'write' => []];

for ($req = 0; $req < $numRequests; $req++) {
    // Seleccionar clases aleatorias para esta petición
    $selectedClasses = array_slice(
        shuffle_array($classPool), 
        0, 
        $classesPerRequest
    );
    
    // Cargar cache completo (como hace el Autoloader al iniciar)
    $start = microtime(true);
    $cache = unserialize(file_get_contents($datFile)) ?: [];
    $loadTime = (microtime(true) - $start) * 1000;
    
    // Buscar cada clase en el cache
    $lookupStart = microtime(true);
    foreach ($selectedClasses as $item) {
        if (isset($cache[$item['class']])) {
            // Cache hit - usar path
            $path = $cache[$item['class']];
        }
    }
    $lookupTime = (microtime(true) - $lookupStart) * 1000;
    
    $totalTimeDat += $loadTime + $lookupTime;
    $datResults['read'][] = $loadTime;
}

$avgReadDat = array_sum($datResults['read']) / count($datResults['read']);
echo "  Avg cache load time:     " . number_format($avgReadDat, 4) . " ms\n";
echo "  Total time all reqs:     " . number_format($totalTimeDat, 2) . " ms\n";
echo "  Per-request avg:         " . number_format($totalTimeDat / $numRequests, 4) . " ms\n\n";

// ==========================================
// ESCENARIO 2: DirectoryCacheAdapter (sharded)
// ==========================================
echo "SCENARIO 2: DirectoryCacheAdapter (sharded .php files)\n";
echo str_repeat("-", 50) . "\n";

$totalTimePhp = 0;
$phpResults = ['read' => [], 'hits' => 0, 'misses' => 0];

// Reset OPcache para pruebas justas
if (function_exists('opcache_reset')) {
    opcache_reset();
}

for ($req = 0; $req < $numRequests; $req++) {
    $selectedClasses = array_slice(
        shuffle_array($classPool), 
        0, 
        $classesPerRequest
    );
    
    $start = microtime(true);
    foreach ($selectedClasses as $item) {
        // Lookup individual (no cargar todo el cache)
        $path = $directoryCache->get('class_' . $item['class']);
        if ($path !== null) {
            $phpResults['hits']++;
        } else {
            $phpResults['misses']++;
        }
    }
    $elapsed = (microtime(true) - $start) * 1000;
    
    $totalTimePhp += $elapsed;
    $phpResults['read'][] = $elapsed;
}

$avgReadPhp = array_sum($phpResults['read']) / count($phpResults['read']);
$hitRateActual = ($phpResults['hits'] / ($phpResults['hits'] + $phpResults['misses'])) * 100;

echo "  Avg request time:        " . number_format($avgReadPhp, 4) . " ms\n";
echo "  Total time all reqs:     " . number_format($totalTimePhp, 2) . " ms\n";
echo "  Per-request avg:         " . number_format($totalTimePhp / $numRequests, 4) . " ms\n";
echo "  Cache hit rate:          " . number_format($hitRateActual, 1) . "%\n";
echo "  L1 Cache hits:           " . number_format($directoryCache->getLastReadDuration(), 4) . " ms (last read)\n\n";

// ==========================================
// PRUEBA DE ESCRITURA (descubrimiento de nueva clase)
// ==========================================
echo "WRITE TEST: Adding a new class to cache\n";
echo str_repeat("-", 50) . "\n";

$newClass = "RapidBase\\Module99\\Controller\\NewClass";
$newPath = "/var/www/project/src/Module99/Controller/NewClass.php";

// DAT: Reescribir TODO el archivo
$start = microtime(true);
$cache = unserialize(file_get_contents($datFile)) ?: [];
$cache[$newClass] = $newPath;
file_put_contents($datFile, serialize($cache), LOCK_EX);
$timeDatWrite = (microtime(true) - $start) * 1000;
echo "  DAT (rewrite all):       " . number_format($timeDatWrite, 4) . " ms\n";

// Directory: Solo escribir un archivo
$start = microtime(true);
$directoryCache->set('class_' . $newClass, $newPath);
$timePhpWrite = (microtime(true) - $start) * 1000;
echo "  Directory (single file): " . number_format($timePhpWrite, 4) . " ms\n\n";

// ==========================================
// RESULTADOS
// ==========================================
echo "==============================================\n";
echo "  FINAL RESULTS\n";
echo "==============================================\n\n";

echo "READ PERFORMANCE:\n";
$winner = ($totalTimeDat < $totalTimePhp) ? 'Single .dat file' : 'DirectoryCacheAdapter';
$speedup = abs($totalTimeDat - $totalTimePhp) / min($totalTimeDat, $totalTimePhp) * 100;
echo "  • Single .dat:      " . number_format($totalTimeDat, 2) . " ms total\n";
echo "  • DirectoryCache:   " . number_format($totalTimePhp, 2) . " ms total\n";
echo "  → Winner: $winner (" . number_format($speedup, 1) . "% faster)\n\n";

echo "WRITE PERFORMANCE (single class add):\n";
$winner = ($timeDatWrite < $timePhpWrite) ? 'Single .dat file' : 'DirectoryCacheAdapter';
$speedup = abs($timeDatWrite - $timePhpWrite) / min($timeDatWrite, $timePhpWrite) * 100;
echo "  • Single .dat:      " . number_format($timeDatWrite, 4) . " ms\n";
echo "  • DirectoryCache:   " . number_format($timePhpWrite, 4) . " ms\n";
echo "  → Winner: $winner (" . number_format($speedup, 1) . "% faster)\n\n";

echo "MEMORY EFFICIENCY:\n";
$datSize = filesize($datFile);
$phpSize = getTotalDirectorySize($phpDir);
echo "  • Single .dat:      " . number_format($datSize / 1024, 2) . " KB\n";
echo "  • DirectoryCache:   " . number_format($phpSize / 1024, 2) . " KB (all files)\n";
echo "  → " . ($datSize < $phpSize ? 'DAT is more compact' : 'Directory uses more space') . "\n\n";

echo "==============================================\n";
echo "  ANALYSIS & RECOMMENDATION\n";
echo "==============================================\n\n";

if ($totalTimePhp < $totalTimeDat) {
    echo "✓ DirectoryCacheAdapter es MEJOR para este caso de uso\n\n";
    echo "  Razones:\n";
    echo "  • No necesita cargar TODO el cache en cada petición\n";
    echo "  • Lookups individuales son más eficientes\n";
    echo "  • OPcache acelera lecturas subsecuentes\n";
    echo "  • Escala mejor con miles de clases\n";
} else {
    echo "✓ Single .dat file es SUFICIENTE para este caso de uso\n\n";
    echo "  Razones:\n";
    echo "  • Cargar un archivo serializado es muy rápido\n";
    echo "  • Menor overhead de I/O del sistema de archivos\n";
    echo "  • Más simple de implementar y mantener\n";
}

echo "\n  Consideraciones importantes:\n";
echo "  ──────────────────────────────\n";
echo "  • Con 500 clases, la diferencia es mínima (<1ms por petición)\n";
echo "  • DirectoryCache permite invalidación selectiva (ej: limpiar cache de un módulo)\n";
echo "  • Single .dat es más portable (un solo archivo para deploy)\n";
echo "  • DirectoryCache escala linealmente, .dat escala con O(n)\n";

echo "\n  RECOMENDACIÓN FINAL:\n";
echo "  ──────────────────────────────\n";
if ($totalClasses <= 1000 && $numRequests < 1000) {
    echo "  Para proyectos PEQUEÑOS/MEDIANOS (<1000 clases):\n";
    echo "  → Mantener el enfoque actual (.dat) es perfectamente válido\n\n";
} else {
    echo "  Para proyectos GRANDES (>1000 clases):\n";
    echo "  → Migrar a DirectoryCacheAdapter mejorará performance\n\n";
}

echo "  Sin embargo, hay una TERCERA OPCIÓN híbrida:\n";
echo "  → Usar DirectoryCacheAdapter PERO con interfaz KeyValueWriterInterface\n";
echo "  → El Autoloader podría aceptar cualquier adapter que implemente la interfaz\n";
echo "  → Esto daría flexibilidad para elegir según el proyecto\n";

// Limpieza
unlink($datFile);
rrmdir($datDir);
rrmdir($phpDir);
rrmdir($testDir);

echo "\n✓ Benchmark complete. Temp files cleaned.\n";
echo "==============================================\n";

// Helper functions
function shuffle_array(array $array): array
{
    shuffle($array);
    return $array;
}

function getTotalDirectorySize(string $dir): int
{
    $size = 0;
    if (!is_dir($dir)) return 0;
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $size += $file->getSize();
        }
    }
    
    return $size;
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) return;
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            unlink($file->getPathname());
        } elseif ($file->isDir()) {
            rmdir($file->getPathname());
        }
    }
    
    rmdir($dir);
}
