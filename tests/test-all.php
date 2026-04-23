#!/usr/bin/env php
<?php
/**
 * Test Runner - Ejecuta todas las pruebas del proyecto RapidBase
 * 
 * Uso: php tests/test-all.php [--unit] [--integration] [--performance] [--verbose]
 */

declare(strict_types=1);

// Configuración inicial
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Colores para salida en consola
define('COLOR_RESET', "\033[0m");
define('COLOR_GREEN', "\033[32m");
define('COLOR_RED', "\033[31m");
define('COLOR_YELLOW', "\033[33m");
define('COLOR_BLUE', "\033[34m");
define('COLOR_CYAN', "\033[36m");

// Parsear argumentos
$args = $argv;
array_shift($args); // Remover nombre del script

$options = [
    'unit' => in_array('--unit', $args),
    'integration' => in_array('--integration', $args),
    'performance' => in_array('--performance', $args),
    'stress' => in_array('--stress', $args),
    'poc' => in_array('--poc', $args),
    'verbose' => in_array('--verbose', $args) || in_array('-v', $args),
];

// Si no se especifica ningún tipo, ejecutar todos
if (!array_filter($options)) {
    $options = array_fill_keys(array_keys($options), true);
}

// Autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Función para imprimir encabezados
function printHeader(string $title): void {
    echo PHP_EOL;
    echo str_repeat('=', 70) . PHP_EOL;
    echo COLOR_BLUE . "  $title" . COLOR_RESET . PHP_EOL;
    echo str_repeat('=', 70) . PHP_EOL;
}

// Función para ejecutar suite de pruebas
function runSuite(string $name, string $path, bool $verbose): array {
    if (!is_dir($path)) {
        return ['skipped' => true, 'message' => "Directorio no encontrado: $path"];
    }
    
    echo COLOR_CYAN . "\n▶ Ejecutando $name..." . COLOR_RESET . PHP_EOL;
    
    $command = sprintf(
        'php vendor/bin/phpunit %s --colors=always %s',
        $verbose ? '--testdox --verbose' : '--colors=always',
        escapeshellarg($path)
    );
    
    $output = [];
    $returnCode = 0;
    exec($command, $output, $returnCode);
    
    return [
        'success' => $returnCode === 0,
        'output' => implode(PHP_EOL, $output),
        'code' => $returnCode
    ];
}

// Inicio de la ejecución
printHeader("🧪 RAPIDBASE TEST SUITE");
echo "Fecha: " . date('Y-m-d H:i:s') . PHP_EOL;
echo "PHP Version: " . PHP_VERSION . PHP_EOL;

$startTime = microtime(true);
$results = [];
$failures = 0;

// Ejecutar Unit Tests
if ($options['unit']) {
    $result = runSuite('Unit Tests', __DIR__ . '/Unit', $options['verbose']);
    $results['unit'] = $result;
    if (!$result['success']) $failures++;
    
    echo $result['output'] . PHP_EOL;
    echo $result['success'] 
        ? COLOR_GREEN . "✅ Unit Tests: PASSED" . COLOR_RESET . PHP_EOL
        : COLOR_RED . "❌ Unit Tests: FAILED" . COLOR_RESET . PHP_EOL;
}

// Ejecutar Integration Tests
if ($options['integration']) {
    $result = runSuite('Integration Tests', __DIR__ . '/Integration', $options['verbose']);
    $results['integration'] = $result;
    if (!$result['success']) $failures++;
    
    echo $result['output'] . PHP_EOL;
    echo $result['success'] 
        ? COLOR_GREEN . "✅ Integration Tests: PASSED" . COLOR_RESET . PHP_EOL
        : COLOR_RED . "❌ Integration Tests: FAILED" . COLOR_RESET . PHP_EOL;
}

// Ejecutar Performance Tests
if ($options['performance']) {
    $result = runSuite('Performance Tests', __DIR__ . '/Performance', $options['verbose']);
    $results['performance'] = $result;
    if (!$result['success']) $failures++;
    
    echo $result['output'] . PHP_EOL;
    echo $result['success'] 
        ? COLOR_GREEN . "✅ Performance Tests: PASSED" . COLOR_RESET . PHP_EOL
        : COLOR_RED . "❌ Performance Tests: FAILED" . COLOR_RESET . PHP_EOL;
}

// Ejecutar Stress Tests
if ($options['stress']) {
    $result = runSuite('Stress Tests', __DIR__ . '/Stress', $options['verbose']);
    $results['stress'] = $result;
    if (!$result['success']) $failures++;
    
    echo $result['output'] . PHP_EOL;
    echo $result['success'] 
        ? COLOR_GREEN . "✅ Stress Tests: PASSED" . COLOR_RESET . PHP_EOL
        : COLOR_RED . "❌ Stress Tests: FAILED" . COLOR_RESET . PHP_EOL;
}

// Ejecutar PoC Tests
if ($options['poc']) {
    $result = runSuite('PoC Tests', __DIR__ . '/PoC', $options['verbose']);
    $results['poc'] = $result;
    if (!$result['success']) $failures++;
    
    echo $result['output'] . PHP_EOL;
    echo $result['success'] 
        ? COLOR_GREEN . "✅ PoC Tests: PASSED" . COLOR_RESET . PHP_EOL
        : COLOR_RED . "❌ PoC Tests: FAILED" . COLOR_RESET . PHP_EOL;
}

// Resumen final
$endTime = microtime(true);
$totalTime = round($endTime - $startTime, 3);

printHeader("📊 RESUMEN DE PRUEBAS");

$totalSuites = count(array_filter($options));
$passedSuites = $totalSuites - $failures;

echo "Suites ejecutadas: $totalSuites" . PHP_EOL;
echo "Suites aprobadas: " . COLOR_GREEN . "$passedSuites" . COLOR_RESET . PHP_EOL;
echo "Suites fallidas: " . ($failures > 0 ? COLOR_RED : "") . "$failures" . COLOR_RESET . PHP_EOL;
echo "Tiempo total: " . COLOR_CYAN . "{$totalTime}s" . COLOR_RESET . PHP_EOL;

if ($failures === 0) {
    echo PHP_EOL . COLOR_GREEN . "🎉 ¡Todas las pruebas aprobadas!" . COLOR_RESET . PHP_EOL;
    exit(0);
} else {
    echo PHP_EOL . COLOR_RED . "⚠️  Algunas pruebas fallaron. Revisa los logs arriba." . COLOR_RESET . PHP_EOL;
    exit(1);
}
