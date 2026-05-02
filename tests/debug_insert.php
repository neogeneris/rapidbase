<?php
// Test script para debuggear INSERT

require __DIR__ . '/../src/RapidBase/Core/SQL/QType.php';
require __DIR__ . '/../src/RapidBase/Core/SQL/JoinStrategy.php';
require __DIR__ . '/../src/RapidBase/Core/SQL/DeterministicJoin.php';
require __DIR__ . '/../src/RapidBase/Core/SQL/ConditionParser.php';
require __DIR__ . '/../src/RapidBase/Core/SQL/SqlCompiler.php';
require __DIR__ . '/../src/RapidBase/Core/SQL/Q.php';

use RapidBase\Core\SQL\Q;
use RapidBase\Core\SQL\QType;

echo "=== Probando detección de array simple vs multi ===\n\n";

// Caso 1: Array simple (un solo registro)
$data1 = ['name' => 'Bob', 'points' => 35];
echo "Caso 1 - Array simple:\n";
var_dump($data1);
$isSimple = !empty($data1) && !isset($data1[0]) && !is_array(reset($data1));
echo "Es simple? " . ($isSimple ? 'SI' : 'NO') . "\n";
if ($isSimple) {
    $converted = [$data1];
    echo "Convertido a multi:\n";
    var_dump($converted);
}
echo "\n";

// Caso 2: Array multi (múltiples registros)
$data2 = [
    ['name' => 'Bob', 'points' => 35],
    ['name' => 'Carol', 'points' => 40]
];
echo "Caso 2 - Array multi:\n";
var_dump($data2);
$isSimple2 = !empty($data2) && !isset($data2[0]) && !is_array(reset($data2));
echo "Es simple? " . ($isSimple2 ? 'SI' : 'NO') . "\n";
echo "\n";

// Caso 3: Array vacío
$data3 = [];
echo "Caso 3 - Array vacío:\n";
var_dump($data3);
$isSimple3 = !empty($data3) && !isset($data3[0]) && !is_array(reset($data3));
echo "Es simple? " . ($isSimple3 ? 'SI' : 'NO') . "\n";
echo "\n";

// Probar Q::build directamente
echo "=== Probando Q::from()->build() ===\n";
try {
    $result = Q::from('players')->build(QType::INSERT, [['name' => 'Test', 'points' => 10]]);
    echo "INSERT exitoso:\n";
    var_dump($result);
} catch (Exception $e) {
    echo "ERROR en INSERT: " . $e->getMessage() . "\n";
    var_dump($e->getTraceAsString());
}
