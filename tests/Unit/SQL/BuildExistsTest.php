<?php



include_once "../../../src/RapidBase/Core/SQL.php";
use RapidBase\Core\SQL;

echo "--- Ejecutando: BuildExistsTest.php (Independiente) ---\n";

function assert_exists($name, $expectedSql, $expectedParams, $actual) {
    $actualSql = preg_replace('/\s+/', ' ', trim($actual[0]));
    $expectedSql = preg_replace('/\s+/', ' ', trim($expectedSql));

    if ($actualSql === $expectedSql && $actual[1] === $expectedParams) {
        echo "\033[32m[OK]\033[0m $name\n";
    } else {
        echo "\033[31m[FAIL]\033[0m $name\n";
        echo "  Obtenido SQL: '$actualSql'\n";
        echo "  Params obtenidos: " . json_encode($actual[1]) . "\n";
        exit(1);
    }
}

// --- CASO 1: Existencia simple ---
// Al ser un proceso nuevo, el índice empieza en :p0
$res = SQL::buildExists('usuarios', ['email' => 'admin@veon.com']);
$expectedSql = "SELECT EXISTS(SELECT 1 FROM `usuarios` WHERE `email` = :p0) AS `check` "; 
$expectedParams = ["p0" => "admin@veon.com"];

assert_exists("Existencia simple (Email)", $expectedSql, $expectedParams, $res);

SQL::setDriver('mysql');
// --- CASO 2: Múltiples condiciones ---
$resMult = SQL::buildExists('telemetria', [
    'sensor' => 'presion_freno',
    'valor'  => ['>' => 100]
]);

// El contador sigue a :p1 y :p2
$expectedSqlMult = "SELECT EXISTS(SELECT 1 FROM `telemetria` WHERE `sensor` = :p1 AND `valor` > :p2) AS `check` ";
$expectedParamsMult = ["p1" => "presion_freno", "p2" => 100];

assert_exists("Existencia compleja (Sensor & Rango)", $expectedSqlMult, $expectedParamsMult, $resMult);

echo "\n\033[32m[SUCCESS]\033[0m El motor EXISTS está validado al 100%.\n";