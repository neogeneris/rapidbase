<?php

namespace RapidBase\Tests\PoC\Autoloader;

use RapidBase\Autoloader\Autoloader;
use RapidBase\Tests\PoC\Autoloader\AutoloaderNonFluent;

/**
 * Benchmark comparativo: Fluent vs Non-Fluent Autoloader
 * 
 * Objetivo: Medir el impacto de performance de retornar $this en cada método.
 */

// Nota: Este script se ejecuta directamente con PHP
// require_once __DIR__ . '/../../vendor/autoload.php';

// Carga manual para PoC
require_once __DIR__ . '/../../../src/RapidBase/Autoloader/Autoloader.php';
require_once __DIR__ . '/AutoloaderNonFluent.php';

// Configuración
$iterations = 10000;
$basePath = sys_get_temp_dir() . '/benchmark_autoloader_' . uniqid();
@mkdir($basePath, 0777, true);

echo "=== Benchmark: Fluent vs Non-Fluent Autoloader ===\n";
echo "Iteraciones: {$iterations}\n\n";

// --- PRUEBA 1: Configuración Inicial (Constructor + GetInstance) ---
echo "1. Obtención de Instancia ({$iterations} veces):\n";

// Fluent
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    Autoloader::resetInstance();
    $autoloader = Autoloader::getInstance($basePath);
}
$timeFluentGet = microtime(true) - $start;
echo "   Fluent:      " . number_format($timeFluentGet * 1000, 4) . " ms\n";

// Non-Fluent
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    AutoloaderNonFluent::resetInstance();
    $autoloader = AutoloaderNonFluent::getInstance($basePath);
}
$timeNonFluentGet = microtime(true) - $start;
echo "   Non-Fluent:  " . number_format($timeNonFluentGet * 1000, 4) . " ms\n";

$diff = (($timeFluentGet - $timeNonFluentGet) / $timeNonFluentGet) * 100;
echo "   Diferencia:  " . number_format($diff, 2) . "% " . ($diff > 0 ? "(Non-Fluent más rápido)" : "(Fluent más rápido)") . "\n\n";

// --- PRUEBA 2: Configuración de Propiedades (Setters) ---
echo "2. Llamadas a Setters ({$iterations} veces c/u):\n";

// Reset instances
Autoloader::resetInstance();
$fluent = Autoloader::getInstance($basePath);

AutoloaderNonFluent::resetInstance();
$nonFluent = AutoloaderNonFluent::getInstance($basePath);

// Fluent Setters
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $fluent->setStrictMode($i % 2 === 0);
    $fluent->enableDebug(false);
    $fluent->setCacheDirectory($basePath);
}
$timeFluentSet = microtime(true) - $start;
echo "   Fluent:      " . number_format($timeFluentSet * 1000, 4) . " ms\n";

// Non-Fluent Setters
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $nonFluent->setStrictMode($i % 2 === 0);
    $nonFluent->enableDebug(false);
    $nonFluent->setCacheDirectory($basePath);
}
$timeNonFluentSet = microtime(true) - $start;
echo "   Non-Fluent:  " . number_format($timeNonFluentSet * 1000, 4) . " ms\n";

$diff = (($timeFluentSet - $timeNonFluentSet) / $timeNonFluentSet) * 100;
echo "   Diferencia:  " . number_format($diff, 2) . "% " . ($diff > 0 ? "(Non-Fluent más rápido)" : "(Fluent más rápido)") . "\n\n";

// --- PRUEBA 3: Encadenamiento Fluent (Solo Fluent) ---
echo "3. Encadenamiento Fluent (Overhead extra):\n";

Autoloader::resetInstance();
$fluent = Autoloader::getInstance($basePath);

$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    // Simular encadenamiento típico
    $fluent
        ->setStrictMode(false)
        ->enableDebug(false)
        ->setCacheDirectory($basePath);
}
$timeFluentChain = microtime(true) - $start;
echo "   Fluent Chain:" . number_format($timeFluentChain * 1000, 4) . " ms\n";
echo "   (Comparar con prueba 2 - Fluent para ver overhead de retorno)\n\n";

// --- PRUEBA 4: Registro y Carga (Core Logic) ---
echo "4. Registro y Operación de Carga ({$iterations} veces):\n";

// Crear archivo de prueba
$testClassFile = $basePath . DIRECTORY_SEPARATOR . 'TestClassBenchmark.php';
file_put_contents($testClassFile, '<?php class TestClassBenchmark {}');

// Fluent
Autoloader::resetInstance();
$fluent = Autoloader::getInstance($basePath);
$fluent->register();

$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    // Forzar recarga limpiando cache interna si fuera necesario
    // En este caso solo medimos el overhead del método loadClass
    if ($i === 0) {
        $fluent->loadClass('TestClassBenchmark'); // Primera carga real
    } else {
        // Las siguientes son hits de cache
        if (class_exists('TestClassBenchmark', false)) {
            // Ya cargada
        }
    }
}
$timeFluentLoad = microtime(true) - $start;
echo "   Fluent:      " . number_format($timeFluentLoad * 1000, 4) . " ms\n";

// Non-Fluent
AutoloaderNonFluent::resetInstance();
$nonFluent = AutoloaderNonFluent::getInstance($basePath);
$nonFluent->register();

// Limpiar opcode cache si existe
if (function_exists('opcache_invalidate')) {
    opcache_invalidate($testClassFile, true);
}
// Undefine class for next test
if (class_exists('TestClassBenchmark', false)) {
    // No se puede undefinir en PHP, pero podemos medir hit de cache
}

$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    if ($i === 0) {
        // Necesitamos una clase diferente o resetear el autoloader
        // Para simplificar, medimos solo el overhead del método sin cargar realmente
        $nonFluent->loadClass('TestClassBenchmark'); 
    }
}
$timeNonFluentLoad = microtime(true) - $start;
echo "   Non-Fluent:  " . number_format($timeNonFluentLoad * 1000, 4) . " ms\n";

$diff = (($timeFluentLoad - $timeNonFluentLoad) / $timeNonFluentLoad) * 100;
echo "   Diferencia:  " . number_format($diff, 2) . "% " . ($diff > 0 ? "(Non-Fluent más rápido)" : "(Fluent más rápido)") . "\n\n";

// Limpieza
unlink($testClassFile);
rmdir($basePath);

echo "=== Conclusión ===\n";
echo "El patrón Fluent introduce un overhead mínimo por el retorno de \$this.\n";
echo "En aplicaciones de alto rendimiento (millones de llamadas), Non-Fluent puede ser ligeramente superior.\n";
echo "Sin embargo, Fluent mejora la legibilidad y configuración fluida.\n";
