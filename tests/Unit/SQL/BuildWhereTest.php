<?php
namespace Tests\Unit\SQL;
use RapidBase\Core\SQL;
include_once "../../../src/RapidBase/Core/SQL.php";
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
// Nota: p1 y p2 asumiendo que el índice estático sigue aumentando
$res = SQL::buildWhere(['type' => 'admin', 'deleted' => 0]);
assert_where("Múltiples filtros (AND)", "`type` = :p1 AND `deleted` = :p2", ["p1" => 'admin', "p2" => 0], $res);

// Caso 4: Filtro con tabla especificada (u.id)
$res = SQL::buildWhere(['u.id' => 50]);
assert_where("Filtro con alias de tabla", "`u`.`id` = :p3", ["p3" => 50], $res);

echo "\n[SUCCESS] Todos los casos de BuildWhere pasaron.\n";