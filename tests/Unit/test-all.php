<?php
// tests/Unit/test-all.php
// Ejecuta todos los test-all.php de cada subcarpeta

echo "\n========================================\n";
echo "EJECUTANDO TODAS LAS PRUEBAS UNITARIAS\n";
echo "========================================\n\n";

$unitDirs = [
    'Cache',
    'Core',
    'Executor',
    'Gateway',
    'ORM',
    'SQL',
];

foreach ($unitDirs as $dir) {
    $testFile = __DIR__ . "/$dir/test-all.php";
    if (file_exists($testFile)) {
        echo "\n>>> EJECUTANDO PRUEBAS DE: $dir <<<\n";
        include $testFile;
    } else {
        echo "\033[33m[WARNING]\033[0m No se encontró test-all.php en $dir\n";
    }
}

echo "\n\n========================================\n";
echo "\033[32mTODAS LAS PRUEBAS UNITARIAS COMPLETADAS\033[0m\n";
echo "========================================\n";
