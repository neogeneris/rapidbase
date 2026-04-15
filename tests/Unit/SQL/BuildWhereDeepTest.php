<?php
// tests/Unit/SQL/BuildWhereDeepTest.php

require_once __DIR__ . '/../../../src/RapidBase/Core/SQL.php';

use RapidBase\Core\SQL;

function assertEquals($expected, $actual, $label) {
    if ($expected === $actual) {
        echo "[OK] $label\n";
    } else {
        echo "[FAIL] $label\n";
        echo "  Esperado: " . json_encode($expected) . "\n";
        echo "  Obtenido: " . json_encode($actual) . "\n";
        exit(1);
    }
}

echo "--- Iniciando Test Completo: SQL::buildWhere ---\n";
SQL::setDriver('mysql');

// 1. Filtro Simple
SQL::reset();
$res = SQL::buildWhere(['status' => 'active', 'role' => 'admin']);
assertEquals("`status` = :p0 AND `role` = :p1", $res['sql'], "Filtro Simple (SQL)");
assertEquals(["p0" => "active", "p1" => "admin"], $res['params'], "Filtro Simple (Params)");

// 2. Operador Mayor Que
SQL::reset();
$res = SQL::buildWhere(['age' => ['>' => 18]]);
assertEquals("`age` > :p0", $res['sql'], "Operador Mayor Que");
assertEquals(["p0" => 18], $res['params'], "Operador Params");

// 3. Manejo de NULL
SQL::reset();
$res = SQL::buildWhere(['deleted_at' => null]);
assertEquals("`deleted_at` IS NULL", $res['sql'], "Manejo de NULL");
assertEquals([], $res['params'], "NULL no debe generar params");

// ========== NUEVAS PRUEBAS PARA NOTACIÓN MATRICIAL (OR) ==========

// 4. Un solo grupo (AND dentro de un solo OR) ? debe generar paréntesis pero sin OR extra
SQL::reset();
$res = SQL::buildWhere([['status' => 'active', 'role' => 'admin']]);
assertEquals("(`status` = :p0 AND `role` = :p1)", $res['sql'], "Grupo único (AND)");
assertEquals(["p0" => "active", "p1" => "admin"], $res['params'], "Parámetros grupo único");

// 5. Dos grupos (OR entre grupos)
SQL::reset();
$res = SQL::buildWhere([
    ['status' => 'active'],
    ['role' => 'admin']
]);
assertEquals("(`status` = :p0) OR (`role` = :p1)", $res['sql'], "Dos grupos (OR)");
assertEquals(["p0" => "active", "p1" => "admin"], $res['params'], "Parámetros dos grupos");

// 6. Tres grupos con condiciones múltiples dentro de algunos
SQL::reset();
$res = SQL::buildWhere([
    ['status' => 'active', 'deleted' => 0],
    ['role' => 'admin'],
    ['age' => ['>' => 18]]
]);
$expectedSql = "(`status` = :p0 AND `deleted` = :p1) OR (`role` = :p2) OR (`age` > :p3)";
assertEquals($expectedSql, $res['sql'], "Tres grupos mixtos (OR)");
assertEquals(["p0" => "active", "p1" => 0, "p2" => "admin", "p3" => 18], $res['params'], "Parámetros tres grupos");

// 7. Grupo con operador y valor nulo (IS NULL)
SQL::reset();
$res = SQL::buildWhere([
    ['status' => 'active'],
    ['deleted_at' => null]
]);
assertEquals("(`status` = :p0) OR (`deleted_at` IS NULL)", $res['sql'], "Grupo con IS NULL");
assertEquals(["p0" => "active"], $res['params'], "Parámetros grupo con IS NULL");

// 8. Lista IN dentro de un grupo (no es OR, pero comprueba que no se rompe)
SQL::reset();
$res = SQL::buildWhere([['id' => [1, 2, 3]]]);
assertEquals("(`id` IN (:p0, :p1, :p2))", $res['sql'], "IN dentro de grupo");
assertEquals(["p0" => 1, "p1" => 2, "p2" => 3], $res['params'], "Parámetros IN");

// 9. Grupo vacío (debe ignorarse o tratarse como 1)
SQL::reset();
$res = SQL::buildWhere([[]]);
assertEquals("(1)", $res['sql'], "Grupo vacío produce (1)");
assertEquals([], $res['params'], "Grupo vacío sin parámetros");

echo "--- ¡Todas las pruebas pasaron con éxito! ---\n";