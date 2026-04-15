<?php



include_once "../../../src/RapidBase/Core/SQL.php";
use RapidBase\Core\SQL;

echo "--- Ejecutando: BuildDeleteTest.php (Generación de DELETE) ---\n";

/**
 * Utilidad para validar la construcción del DELETE
 */
function assert_delete($name, $expectedSql, $expectedParams, $actual) {
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

// Reseteamos el contador global para asegurar consistencia
SQL::reset();

// --- CASO 1: Borrado Simple por ID ---
$where = ['id' => 5];
$actual = SQL::buildDelete('users', $where);

$expectedSql = "DELETE FROM `users` WHERE `id` = :p0";
$expectedParams = ["p0" => 5];

assert_delete("Borrado Simple (ID)", $expectedSql, $expectedParams, $actual);


// --- CASO 2: Borrado con Múltiples Condiciones ---
SQL::reset();
$whereMulti = [
    'status' => 'inactive',
    'last_login' => ['<' => '2023-01-01']
];
$actualMulti = SQL::buildDelete('sessions', $whereMulti);

$expectedSqlMulti = "DELETE FROM `sessions` WHERE `status` = :p0 AND `last_login` < :p1";
$expectedParamsMulti = ["p0" => "inactive", "p1" => "2023-01-01"];

assert_delete("Borrado Multicondición", $expectedSqlMulti, $expectedParamsMulti, $actualMulti);


// --- CASO 3: PROTECCIÓN CONTRA BORRADO MASIVO ---
try {
    echo "Probando protección contra DELETE sin WHERE... ";
    SQL::buildDelete('logs', []); 
    echo "\033[31m[FAIL]\033[0m Debería haber lanzado una RuntimeException.\n";
    exit(1);
} catch (\RuntimeException $e) {
    echo "\033[32m[OK]\033[0m Bloqueo de seguridad activo (Previene TRUNCATE accidental).\n";
}

SQL::setDriver('mysql');
// --- CASO 4: FORZAR BORRADO MASIVO ---
SQL::reset();
$actualForce = SQL::buildDelete('temp_data', [], true); // true = force

$expectedSqlForce = "DELETE FROM `temp_data` WHERE 1";
$expectedParamsForce = [];

assert_delete("Borrado Forzado (Masivo)", $expectedSqlForce, $expectedParamsForce, $actualForce);

echo "\n\033[32m[SUCCESS]\033[0m Pruebas de BuildDelete finalizadas.\n";