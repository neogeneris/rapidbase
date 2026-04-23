<?php



require_once __DIR__ . "/../../../src/RapidBase/Core/SQL.php";
use RapidBase\Core\SQL;

echo "--- Ejecutando: BuildInsertTest.php (Generación de INSERT) ---\n";

/**
 * Función de utilidad para validar la construcción del INSERT
 */
function assert_insert($name, $expectedSql, $expectedParams, $actual) {
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
SQL::setDriver('mysql');
// --- CASO 1: Inserción Estándar ---
$data = [
    'username' => 'neogeneris',
    'email'    => 'dev@example.com',
    'role_id'  => 2
];

$actual = SQL::buildInsert('users', $data);

// Nota: Los placeholders (:p0, :p1...) dependen del estado del contador global de SQL
$expectedSql = "INSERT INTO `users` (`username`, `email`, `role_id`) VALUES (:p0, :p1, :p2)";
$expectedParams = ["p0" => "neogeneris", "p1" => "dev@example.com", "p2" => 2];

assert_insert("Inserción Estándar", $expectedSql, $expectedParams, $actual);


// --- CASO 2: Inserción con valores NULL y vacíos ---
$dataNull = [
    'title'   => 'Test Project',
    'content' => null,
    'status'  => ''
];

$actualNull = SQL::buildInsert('posts', $dataNull);
$expectedSqlNull = "INSERT INTO `posts` (`title`, `content`, `status`) VALUES (:p3, :p4, :p5)";
$expectedParamsNull = ["p3" => "Test Project", "p4" => null, "p5" => null];

assert_insert("Manejo de NULL/Vacíos", $expectedSqlNull, $expectedParamsNull, $actualNull);


// --- CASO 3: PROTECCIÓN (El "rompe-motores") ---
try {
    echo "Probando protección contra datos vacíos... ";
    SQL::buildInsert('test_users', []);
    echo "\033[31m[FAIL]\033[0m Debería haber lanzado una excepción.\n";
    exit(1);
} catch (\InvalidArgumentException $e) {
    echo "\033[32m[OK]\033[0m Excepción capturada: " . $e->getMessage() . "\n";
}

echo "\n\033[32m[SUCCESS]\033[0m Pruebas de BuildInsert finalizadas.\n";