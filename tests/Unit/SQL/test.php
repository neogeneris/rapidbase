<?php

/**
 * test.php - Ejecuta una prueba unitaria de Q por nombre o archivo.
 *
 * Uso:
 *   php test.php Write           → ejecuta WriteTest.php
 *   php test.php WriteTest.php   → ejecuta WriteTest.php
 *   php test.php                  → muestra las pruebas disponibles
 */

if ($argc < 2) {
    echo "Pruebas disponibles:\n";
    $files = glob(__DIR__ . '/*Test.php');
    foreach ($files as $f) {
        $name = basename($f, 'Test.php');
        echo "  $name  ($f)\n";
    }
    exit(0);
}

$arg = $argv[1];

// Si ya contiene ".php", lo tomamos como nombre exacto de archivo
if (str_contains($arg, '.php')) {
    $file = __DIR__ . '/' . $arg;
} else {
    $name = ucfirst($arg);
    $file = __DIR__ . "/{$name}Test.php";
}

if (!file_exists($file)) {
    echo "Prueba no encontrada: $arg\n";
    echo "Usa 'php test.php' para ver la lista de pruebas disponibles.\n";
    exit(1);
}

echo "Ejecutando: " . basename($file) . "\n";

ob_start();
$passed = 0;
$failed = 0;

try {
    require $file;
} catch (Throwable $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
    $failed++;
}

$output = ob_get_clean();
echo $output;

if (preg_match('/(\d+)\s+pasaron/', $output, $mPass)) {
    $passed = (int)$mPass[1];
}
if (preg_match('/(\d+)\s+fallaron/', $output, $mFail)) {
    $failed = (int)$mFail[1];
}

echo "\n----------------------------------------\n";
echo "Total:  " . ($passed + $failed) . "\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";