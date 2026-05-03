<?php

/**
 * test-all.php - Ejecuta todos los tests unitarios de Q (sin exec).
 *
 * Incluye cada test directamente y extrae el resumen de su salida.
 * Colócalo en tests/Unit/SQL/ y ejecútalo con:
 *   php test-all.php
 */

$tests = [
    'SelectTest'        => 'SelectTest.php',
    'UpsertTest'        => 'UpsertTest.php',
    'WriteTest'         => 'WriteTest.php',
    'InsertSelectTest'  => 'InsertSelectTest.php',
    'UpdateFromTest'    => 'UpdateFromTest.php',
    'JoinRelTest'       => 'JoinRelTest.php',
    'JoinResolverTest'  => 'JoinResolverTest.php',
    'ReadTest'          => 'ReadTest.php',
    'FromValuesTest'    => 'FromValuesTest.php',
    'IntoInsertTest'    => 'IntoInsertTest.php',
    'SubqueryTest'      => 'SubqueryTest.php',
];

$totalPassed = 0;
$totalFailed = 0;

echo "================================================\n";
echo "     Q UNIT TESTS RUNNER\n";
echo "================================================\n\n";

foreach ($tests as $name => $file) {
    echo "Running: $file...\n";

    // Capturar la salida del script que vamos a incluir
    ob_start();
    $passed = 0;
    $failed = 0;

    try {
        require __DIR__ . '/' . $file;
    } catch (Throwable $e) {
        echo "FATAL ERROR: " . $e->getMessage() . "\n";
        $failed++;
    }

    $output = ob_get_clean();
    echo $output;

    // Extraer el resumen del propio test
    if (preg_match('/(\d+)\s+pasaron/', $output, $mPass)) {
        $passed = (int)$mPass[1];
    }
    if (preg_match('/(\d+)\s+fallaron/', $output, $mFail)) {
        $failed = (int)$mFail[1];
    }

    $totalPassed += $passed;
    $totalFailed += $failed;

    echo "\n----------------------------------------\n\n";
}

echo "================================================\n";
echo "Summary:\n";
echo "  Total:  " . ($totalPassed + $totalFailed) . "\n";
echo "  Passed: $totalPassed\n";
echo "  Failed: $totalFailed\n";
echo "================================================\n";