<?php
// tests/Unit/Core/test-all.php

// El bootstrap centralizado ya cargó el autoloader y las clases necesarias
// Solo necesitamos importar las clases específicas que no se cargan automáticamente

use RapidBase\Core\DB;

// Lista de archivos de prueba de Core
$coreTests = [
    'DBTest.php',
    'JoinTest.php',
    'SortTest.php',
    'PaginationTest.php',
];

foreach ($coreTests as $test) {
    echo "\n--- Ejecutando Core Unit: $test ---\n";
    include __DIR__ . "/" . $test;
}

echo "\n\033[32mFelicidades, el núcleo de RapidBase es funcional.\033[0m\n";
