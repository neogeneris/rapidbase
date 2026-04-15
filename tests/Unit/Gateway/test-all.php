<?php
// tests/Unit/Gateway/test-gateway-all.php

// Cargamos las dependencias en el orden correcto
require_once __DIR__ . '/../../../src/RapidBase/Core/SQL.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/Executor.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/DB.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/Gateway.php';

use RapidBase\Core\DB;


// Función global de ayuda para aserciones (estilo la que ya usas)
function assert_gateway($name, $condition, $details = "") {
    if ($condition) {
        echo "\033[32m[OK]\033[0m $name\n";
    } else {
        echo "\033[31m[FAIL]\033[0m $name\n";
        if ($details) echo "  Detalles: $details\n";
        exit(1);
    }
}

// Lista de archivos de prueba del Gateway
$gatewayTests = [
    'ActionTest.php','EventTest.php','EventLogTest.php','CountTest.php','SelectTest.php',
];

foreach ($gatewayTests as $test) {
    echo "\n--- Ejecutando Gateway Unit: $test ---\n";
    include __DIR__ . "/" . $test;
}

echo "\n\033[32mFelicidades, el Gateway de RapidBase es impenetrable y funcional.\033[0m\n";
