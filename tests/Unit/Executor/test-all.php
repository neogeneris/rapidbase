<?php
// tests/Unit/Executor/test-all.php

// Cargamos las dependencias en el orden correcto
require_once __DIR__ . '/../../../src/RapidBase/Core/SQL.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/Executor.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/DBInterface.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/DB.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/Gateway.php';

use RapidBase\Core\DB;

// Función global de ayuda para aserciones
function assert_executor($name, $condition, $details = "") {
    if ($condition) {
        echo "\033[32m[OK]\033[0m $name\n";
    } else {
        echo "\033[31m[FAIL]\033[0m $name\n";
        if ($details) echo "  Detalles: $details\n";
        exit(1);
    }
}

// Lista de archivos de prueba de Executor
$executorTests = [
    'ActionTest.php',
    'BatchTest.php',
    'StreamTest.php',
    'TransactionTest.php',
];

foreach ($executorTests as $test) {
    echo "\n--- Ejecutando Executor Unit: $test ---\n";
    include __DIR__ . "/" . $test;
}

echo "\n\033[32mFelicidades, el Executor de RapidBase es funcional.\033[0m\n";
