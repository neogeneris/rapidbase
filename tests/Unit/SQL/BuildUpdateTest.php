<?php



require_once __DIR__ . "/../../../src/RapidBase/Core/SQL.php";
use RapidBase\Core\SQL;

echo "--- Ejecutando: BuildUpdateTest.php (Generación de UPDATE) ---\n";

/**
 * Utilidad para validar la construcción del UPDATE
 */
function assert_update($name, $expectedSql, $expectedParams, $actual) {
    $actualSql = preg_replace('/\s+/', ' ', trim($actual[0]));
    $expectedSql = preg_replace('/\s+/', ' ', trim($expectedSql));

    if ($actualSql === $expectedSql && $actual[1] === $expectedParams) {
        echo "\033[32m[OK]\033[0m $name\n";
    } else {
        echo "\033[31m[FAIL]\033[0m $name\n";
        echo "  SQL Obtenido:  '$actualSql'\n";
        echo "  SQL Esperado:  '$expectedSql'\n";
        echo "  Params OK:     " . ($actual[1] === $expectedParams ? 'SÍ' : 'NO') . "\n";
        echo "  JSON Params:   " . json_encode($actual[1]) . "\n";
        exit(1);
    }
}

// Reseteamos para empezar siempre desde :p0
SQL::reset();

// --- CASO 1: Actualización Simple ---
$data = ['status' => 'active', 'login_count' => 10];
$where = ['id' => 1];

$actual = SQL::buildUpdate('users', $data, $where);

// Esperamos :p0, :p1 para el SET y :p2 para el WHERE
$expectedSql = "UPDATE `users` SET `status` = :p0, `login_count` = :p1 WHERE `id` = :p2";
$expectedParams = ["p0" => "active", "p1" => 10, "p2" => 1];

assert_update("Actualización Estándar", $expectedSql, $expectedParams, $actual);


// --- CASO 2: Actualización con valores NULL ---
SQL::reset();
$dataNull = ['bio' => null, 'last_login' => ''];
$whereNull = ['username' => 'neogeneris'];

$actualNull = SQL::buildUpdate('profiles', $dataNull, $whereNull);

$expectedSqlNull = "UPDATE `profiles` SET `bio` = :p0, `last_login` = :p1 WHERE `username` = :p2";
$expectedParamsNull = ["p0" => null, "p1" => null, "p2" => "neogeneris"]; // Asumiendo que '' -> null en tu SQL.php

assert_update("Manejo de NULL en Update", $expectedSqlNull, $expectedParamsNull, $actualNull);

SQL::setDriver('mysql');
// --- CASO 3: PROTECCIÓN CONTRA UPDATES MASIVOS ---
try {
    echo "Probando protección contra UPDATE sin WHERE... ";
    SQL::buildUpdate('users', ['active' => 0], []); 
    echo "\033[31m[FAIL]\033[0m Debería haber lanzado una RuntimeException.\n";
    exit(1);
} catch (\RuntimeException $e) {
    echo "\033[32m[OK]\033[0m Bloqueo de seguridad activo.\n";
}

// --- CASO 4: FORZAR UPDATE MASIVO ---
SQL::reset();
$actualForce = SQL::buildUpdate('settings', ['value' => 'global'], [], true); // true = force
$expectedSqlForce = "UPDATE `settings` SET `value` = :p0 WHERE 1";

assert_update("Update Forzado (Massive)", $expectedSqlForce, ["p0" => "global"], $actualForce);

echo "\n\033[32m[SUCCESS]\033[0m Pruebas de BuildUpdate finalizadas.\n";