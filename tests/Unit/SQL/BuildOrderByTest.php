<?php
namespace Tests\Unit\SQL;
include_once "../../../src/RapidBase/Core/SQL.php";
use RapidBase\Core\SQL;

echo "--- Ejecutando: BuildOrderByTest.php (Sintaxis Compacta) ---\n";

function assert_order($name, $expected, $actual) {
    if ($expected === $actual) {
        echo "[OK] $name\n";
    } else {
        echo "[FAIL] $name\n";
        echo "  Esperado: '$expected'\n";
        echo "  Obtenido: '$actual'\n";
        exit(1);
    }
}

// Caso 1: Sintaxis de prefijos (La nueva "Ley" de RapidBase)
assert_order("Descendente con '-'", " ORDER BY `id` DESC", SQL::buildOrderBy(['-id']));
assert_order("Ascendente sin prefijo", " ORDER BY `name` ASC", SQL::buildOrderBy(['name']));
SQL::setDriver('mysql');
// Caso 2: Múltiples campos combinados
$res = SQL::buildOrderBy(['-priority', 'name', '-created_at']);
assert_order("Múltiples campos mixtos", " ORDER BY `priority` DESC, `name` ASC, `created_at` DESC", $res);

// Caso 3: Manejo de vacíos
assert_order("Array vacío", "", SQL::buildOrderBy([]));

// Caso 4: Compatibilidad con Alias (Si qualifyColumn está activo)
// Si el motor detecta que 'id' pertenece a 'u', debería inyectarlo
// assert_order("Alias automático", " ORDER BY `u`.`id` DESC", SQL::buildOrderBy(['-id']));

echo "\n[SUCCESS] La sintaxis compacta de Ordenamiento funciona perfectamente.\n";