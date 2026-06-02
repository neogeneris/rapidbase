<?php

/**
 * Autoloader Storage Benchmark
 * 
 * Compara dos técnicas de persistencia para el cache del Autoloader:
 * 1. Array serializado (.dat) - serialize() / unserialize()
 * 2. Archivos PHP includibles - var_export() / include (con OPcache)
 * 
 * Métricas medidas:
 * - Write Performance (guardar cache)
 * - Read Performance (cargar cache)
 * - Memory Usage (pico de memoria)
 * - Escalabilidad con diferentes tamaños de datos
 */

declare(strict_types=1);

// Bootstrap manual sin autoloaders para evitar interferencias
require_once __DIR__ . '/../../vendor/autoload.php';

echo "==============================================\n";
echo "  AUTOLOADER STORAGE BENCHMARK\n";
echo "  Serialize (.dat) vs PHP Include Files\n";
echo "==============================================\n\n";

// Configuración
$testDir = sys_get_temp_dir() . '/autoloader_bench_' . uniqid();
if (!is_dir($testDir)) {
    mkdir($testDir, 0775, true);
}

$datFile = $testDir . '/cache.dat';
$phpFile = $testDir . '/cache.php';

// Datos de prueba simulando mapeo de clases
function generateClassMap(int $size): array
{
    $data = [];
    for ($i = 0; $i < $size; $i++) {
        $className = "RapidBase\\Module" . rand(1, 100) . "\\Controller\\Class" . $i;
        $filePath = "/var/www/project/src/Module" . rand(1, 100) . "/Controller/Class$i.php";
        $data[$className] = $filePath;
    }
    return $data;
}

$sizes = [100, 500, 1000, 5000]; // Diferentes tamaños de cache
$results = [
    'write' => ['serialize' => [], 'php_include' => []],
    'read' => ['serialize' => [], 'php_include' => []],
    'memory' => ['serialize' => [], 'php_include' => []]
];

foreach ($sizes as $size) {
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "  Testing with $size classes in cache\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    
    $testData = generateClassMap($size);
    
    // ==========================================
    // PRUEBA DE ESCRITURA
    // ==========================================
    echo "WRITE PERFORMANCE:\n";
    
    // Técnica 1: Serialize
    $start = microtime(true);
    $memStart = memory_get_usage(true);
    file_put_contents($datFile, serialize($testData), LOCK_EX);
    $memPeak = memory_get_peak_usage(true) - $memStart;
    $timeSerialize = (microtime(true) - $start) * 1000;
    $results['write']['serialize'][$size] = $timeSerialize;
    $results['memory']['serialize'][$size] = $memPeak;
    echo "  Serialize (.dat):     " . number_format($timeSerialize, 4) . " ms (Mem: " . number_format($memPeak / 1024, 2) . " KB)\n";
    
    // Técnica 2: PHP Include File
    $start = microtime(true);
    $memStart = memory_get_usage(true);
    $content = "<?php\nreturn " . var_export($testData, true) . ";\n";
    file_put_contents($phpFile, $content, LOCK_EX);
    $memPeak = memory_get_peak_usage(true) - $memStart;
    $timePhp = (microtime(true) - $start) * 1000;
    $results['write']['php_include'][$size] = $timePhp;
    $results['memory']['php_include'][$size] = $memPeak;
    echo "  PHP Include File:     " . number_format($timePhp, 4) . " ms (Mem: " . number_format($memPeak / 1024, 2) . " KB)\n";
    
    $writeWinner = $timeSerialize < $timePhp ? 'Serialize' : 'PHP Include';
    echo "  → Winner: $writeWinner (" . number_format(abs($timeSerialize - $timePhp), 4) . " ms diff)\n\n";
    
    // ==========================================
    // PRUEBA DE LECTURA
    // ==========================================
    echo "READ PERFORMANCE:\n";
    
    // Reset OPcache para pruebas justas
    if (function_exists('opcache_reset')) {
        opcache_reset();
    }
    
    // Técnica 1: Unserialize
    $iterations = 100;
    $start = microtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $loadedData = unserialize(file_get_contents($datFile)) ?: [];
    }
    $timeUnserialize = ((microtime(true) - $start) * 1000) / $iterations;
    $results['read']['serialize'][$size] = $timeUnserialize;
    echo "  Unserialize:          " . number_format($timeUnserialize, 4) . " ms/op (avg of $iterations)\n";
    
    // Técnica 2: Include (con OPcache activo después de la primera carga)
    // Primera carga (sin OPcache)
    $start = microtime(true);
    $loadedDataFirst = include $phpFile;
    $firstLoadTime = (microtime(true) - $start) * 1000;
    echo "  PHP Include (1st):    " . number_format($firstLoadTime, 4) . " ms (cold)\n";
    
    // Lecturas subsecuentes (con OPcache)
    $start = microtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $loadedData = include $phpFile;
    }
    $timeInclude = ((microtime(true) - $start) * 1000) / $iterations;
    $results['read']['php_include'][$size] = $timeInclude;
    echo "  PHP Include (cached): " . number_format($timeInclude, 4) . " ms/op (avg of $iterations, with OPcache)\n";
    
    $readWinner = $timeUnserialize < $timeInclude ? 'Serialize' : 'PHP Include';
    echo "  → Winner (cached): $readWinner (" . number_format(abs($timeUnserialize - $timeInclude), 4) . " ms diff)\n\n";
    
    // Verificar integridad de datos
    if ($loadedData === $testData) {
        echo "  ✓ Data integrity verified\n";
    } else {
        echo "  ✗ DATA INTEGRITY ERROR!\n";
    }
    
    echo "\n";
}

// ==========================================
// RESUMEN DE RESULTADOS
// ==========================================
echo "==============================================\n";
echo "  SUMMARY: WRITE PERFORMANCE\n";
echo "==============================================\n";
printf("%-15s | %-15s | %-15s | %-10s\n", "Size", "Serialize", "PHP Include", "Winner");
echo str_repeat("-", 65) . "\n";
foreach ($sizes as $size) {
    $serializeTime = $results['write']['serialize'][$size];
    $phpTime = $results['write']['php_include'][$size];
    $winner = $serializeTime < $phpTime ? 'Serialize' : 'PHP Include';
    printf("%-15d | %-15.4f | %-15.4f | %-10s\n", 
           $size, $serializeTime, $phpTime, $winner);
}

echo "\n==============================================\n";
echo "  SUMMARY: READ PERFORMANCE (CACHED)\n";
echo "==============================================\n";
printf("%-15s | %-15s | %-15s | %-10s\n", "Size", "Unserialize", "PHP Include", "Winner");
echo str_repeat("-", 65) . "\n";
foreach ($sizes as $size) {
    $unserializeTime = $results['read']['serialize'][$size];
    $phpTime = $results['read']['php_include'][$size];
    $winner = $unserializeTime < $phpTime ? 'Unserialize' : 'PHP Include';
    printf("%-15d | %-15.4f | %-15.4f | %-10s\n", 
           $size, $unserializeTime, $phpTime, $winner);
}

echo "\n==============================================\n";
echo "  MEMORY USAGE PEAK (WRITE OPERATION)\n";
echo "==============================================\n";
printf("%-15s | %-15s | %-15s | %-10s\n", "Size", "Serialize", "PHP Include", "Winner");
echo str_repeat("-", 65) . "\n";
foreach ($sizes as $size) {
    $serializeMem = $results['memory']['serialize'][$size];
    $phpMem = $results['memory']['php_include'][$size];
    $winner = $serializeMem < $phpMem ? 'Serialize' : 'PHP Include';
    printf("%-15d | %-15.2f KB | %-15.2f KB | %-10s\n", 
           $size, $serializeMem / 1024, $phpMem / 1024, $winner);
}

echo "\n==============================================\n";
echo "  CONCLUSIONS\n";
echo "==============================================\n";

// Análisis de resultados
$serializeWriteAvg = array_sum($results['write']['serialize']) / count($results['write']['serialize']);
$phpWriteAvg = array_sum($results['write']['php_include']) / count($results['write']['php_include']);
$serializeReadAvg = array_sum($results['read']['serialize']) / count($results['read']['serialize']);
$phpReadAvg = array_sum($results['read']['php_include']) / count($results['read']['php_include']);

echo "\nWRITE Operations:\n";
if ($serializeWriteAvg < $phpWriteAvg) {
    $diff = (($phpWriteAvg - $serializeWriteAvg) / $phpWriteAvg) * 100;
    echo "  • Serialize is " . number_format($diff, 1) . "% faster for writing\n";
    echo "  • Reason: serialize() is more efficient than var_export()\n";
} else {
    $diff = (($serializeWriteAvg - $phpWriteAvg) / $serializeWriteAvg) * 100;
    echo "  • PHP Include is " . number_format($diff, 1) . "% faster for writing\n";
}

echo "\nREAD Operations (with OPcache):\n";
if ($serializeReadAvg < $phpReadAvg) {
    $diff = (($phpReadAvg - $serializeReadAvg) / $phpReadAvg) * 100;
    echo "  • Unserialize is " . number_format($diff, 1) . "% faster for reading\n";
} else {
    $diff = (($serializeReadAvg - $phpReadAvg) / $serializeReadAvg) * 100;
    echo "  • PHP Include is " . number_format($diff, 1) . "% faster for reading\n";
    echo "  • Reason: OPcache caches the bytecode, include becomes nearly instant\n";
}

echo "\nRECOMMENDATION FOR AUTOLOADER:\n";
echo "  ──────────────────────────────\n";
if ($phpReadAvg < $serializeReadAvg) {
    echo "  ✓ Use PHP Include Files (like DirectoryCacheAdapter)\n";
    echo "  • El Autoloader hace MUCHAS lecturas y pocas escrituras\n";
    echo "  • OPcache acelera dramáticamente las lecturas subsecuentes\n";
    echo "  • La escritura más lenta es aceptable (solo ocurre al descubrir nuevas clases)\n";
} else {
    echo "  ✓ Use Serialize (.dat) - performance similar en tu caso de uso\n";
}

echo "\n  Consideraciones adicionales:\n";
echo "  • PHP Include permite invalidación selectiva (delete un archivo)\n";
echo "  • Serialize requiere reescribir TODO el archivo en cada cambio\n";
echo "  • PHP Include es más compatible con sistemas de caché distribuidos\n";
echo "  • Serialize es más compacto en disco\n";

// Limpieza
unlink($datFile);
unlink($phpFile);
rmdir($testDir);

echo "\n✓ Benchmark complete. Temp files cleaned.\n";
echo "==============================================\n";
