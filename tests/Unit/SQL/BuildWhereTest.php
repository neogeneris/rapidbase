<?php


use RapidBase\Core\SQL;
require_once __DIR__ . "/../../../src/RapidBase/Core/SQL.php";
echo "--- Ejecutando: BuildWhereTest.php ---\n";

/**
 * Función auxiliar corregida para parámetros nombrados
 */
function assert_where($name, $expectedSql, $expectedParams, $actual) {
    if ($actual['sql'] === $expectedSql && $actual['params'] === $expectedParams) {
        echo "[OK] $name\n";
    } else {
        echo "[FAIL] $name\n";
        echo "  Esperado SQL: '$expectedSql' | Params: " . json_encode($expectedParams) . "\n";
        echo "  Obtenido SQL: '{$actual['sql']}' | Params: " . json_encode($actual['params']) . "\n";
        exit(1);
    }
}

SQL::setDriver('mysql');

// Caso 1: Array vacío (El neutro para el WHERE)
$res = SQL::buildWhere([]);
assert_where("Filtro vacío (Neutro)", "1", [], $res);

// Caso 2: Filtro simple de igualdad
// Importante: Reiniciamos o prevemos el índice estático de la clase SQL si es necesario
$res = SQL::buildWhere(['active' => 1]);
assert_where("Filtro simple", "`active` = :p0", ["p0" => 1], $res);

// Caso 3: Múltiples condiciones (AND implícito)
// Nota: El nuevo motor Flat usa índices base 0 (:p0, :p1)
$res = SQL::buildWhere(['type' => 'admin', 'deleted' => 0]);
assert_where("Múltiples filtros (AND)", "`type` = :p0 AND `deleted` = :p1", ["p0" => 'admin', "p1" => 0], $res);

// Caso 4: Filtro con tabla especificada (u.id)
// Nota: El índice se reinicia para cada llamada, por lo que también es :p0
$res = SQL::buildWhere(['u.id' => 50]);
assert_where("Filtro con alias de tabla", "`u`.`id` = :p0", ["p0" => 50], $res);

echo "\n[SUCCESS] Todos los casos de BuildWhere pasaron.\n";