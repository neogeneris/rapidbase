<?php
// tests/Unit/Core/test-all.php

// Cargamos las dependencias en el orden correcto
require_once __DIR__ . '/../../../src/RapidBase/Core/SQL.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/SQL/Builders/Field.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/SQL/Builders/Table.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/SQL/Builders/Join.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/SQL/Builders/WhereTrait.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/SQL/Builders/SelectBuilder.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/SQL/Builders/InsertBuilder.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/SQL/Builders/UpdateBuilder.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/SQL/Builders/DeleteBuilder.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/Executor.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/DBInterface.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/DB.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/Gateway.php';

use RapidBase\Core\DB;

// Función global de ayuda para aserciones
function assert_core($name, $condition, $details = "") {
    if ($condition) {
        echo "\033[32m[OK]\033[0m $name\n";
    } else {
        echo "\033[31m[FAIL]\033[0m $name\n";
        if ($details) echo "  Detalles: $details\n";
        exit(1);
    }
}

// Lista de archivos de prueba de Core
$coreTests = [
    'DBTest.php',
    'JoinTest.php',
];

foreach ($coreTests as $test) {
    echo "\n--- Ejecutando Core Unit: $test ---\n";
    include __DIR__ . "/" . $test;
}

echo "\n\033[32mFelicidades, el núcleo de RapidBase es funcional.\033[0m\n";
