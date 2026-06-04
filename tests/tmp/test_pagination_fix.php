<?php
// Autoloader manual mínimo para pruebas
spl_autoload_register(function ($class) {
    $projectRoot = dirname(__DIR__) . '/..';
    if (strpos($class, 'RapidBase\\') === 0) {
        $file = $projectRoot . '/src/' . str_replace('\\', '/', $class) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

use RapidBase\Core\SQL\Q;

echo "=== Pruebas de Q::page() ===\n\n";

// Prueba 1: page = 0 (debe retornar null)
$result = Q::page(0, 10);
echo "Q::page(0, 10) = ";
var_dump($result);
echo "Es null? " . ($result === null ? "SI" : "NO") . "\n\n";

// Prueba 2: page = -1 (debe retornar null)
$result = Q::page(-1, 10);
echo "Q::page(-1, 10) = ";
var_dump($result);
echo "Es null? " . ($result === null ? "SI" : "NO") . "\n\n";

// Prueba 3: page = [0, 10] (debe retornar null porque page[0] = 0)
$result = Q::page([0, 10], 10);
echo "Q::page([0, 10], 10) = ";
var_dump($result);
echo "Es null? " . ($result === null ? "SI" : "NO") . "\n\n";

// Prueba 4: page = [null, 10] (debe retornar null)
$result = Q::page([null, 10], 10);
echo "Q::page([null, 10], 10) = ";
var_dump($result);
echo "Es null? " . ($result === null ? "SI" : "NO") . "\n\n";

// Prueba 5: page = [] (debe retornar null)
$result = Q::page([], 10);
echo "Q::page([], 10) = ";
var_dump($result);
echo "Es null? " . ($result === null ? "SI" : "NO") . "\n\n";

// Prueba 6: page = 1 (debe retornar [0, 10])
$result = Q::page(1, 10);
echo "Q::page(1, 10) = ";
var_dump($result);
echo "Es [0, 10]? " . ($result === [0, 10] ? "SI" : "NO") . "\n\n";

// Prueba 7: page = 2 (debe retornar [10, 10])
$result = Q::page(2, 10);
echo "Q::page(2, 10) = ";
var_dump($result);
echo "Es [10, 10]? " . ($result === [10, 10] ? "SI" : "NO") . "\n\n";

echo "=== Todas las pruebas completadas ===\n";
