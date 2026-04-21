<?php
// tests/Unit/Cache/test-all.php

// Cargamos las dependencias en el orden correcto
require_once __DIR__ . '/../../../src/RapidBase/Core/Event.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/Cache/CacheService.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/Cache/Adapters/DirectoryCacheAdapter.php';

use RapidBase\Core\Cache\CacheService;

// Función global de ayuda para aserciones
function assert_cache($name, $condition, $details = "") {
    if ($condition) {
        echo "\033[32m[OK]\033[0m $name\n";
    } else {
        echo "\033[31m[FAIL]\033[0m $name\n";
        if ($details) echo "  Detalles: $details\n";
        exit(1);
    }
}

// Lista de archivos de prueba de Cache
$cacheTests = [
    'CacheTest.php',
];

foreach ($cacheTests as $test) {
    echo "\n--- Ejecutando Cache Unit: $test ---\n";
    include __DIR__ . "/" . $test;
}

echo "\n\033[32mFelicidades, el sistema de Caché de RapidBase es funcional.\033[0m\n";
