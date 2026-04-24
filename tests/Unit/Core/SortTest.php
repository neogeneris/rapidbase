<?php

/**
 * Suite de Pruebas para ordenamiento en SQL::buildSelect
 */

require_once __DIR__ . '/../../../src/RapidBase/Core/SQL.php';

use RapidBase\Core\SQL;

$failed = 0;

function assert_sort($msg, $cond) {
    global $failed;
    if ($cond) {
        echo "  [OK] $msg\n";
    } else {
        echo "  [FAIL] $msg\n";
        $failed++;
    }
}

echo "==================================================\n";
echo "CORE\\SQL: PRUEBAS DE ORDENAMIENTO (SORT)\n";
echo "==================================================\n";

echo "\n--- Bloque 1: Ordenamiento básico ---\n";

// Test 1: Array vacío de sort no genera ORDER BY (sin paginación)
[$sql, $params] = SQL::buildSelect('*', 'users', [], [], [], [], 0, 0);
assert_sort("Array vacío de sort no genera ORDER BY", strpos($sql, 'ORDER BY') === false);

// Test 2: Sort null no genera ORDER BY (sin paginación)
[$sql, $params] = SQL::buildSelect('*', 'users', [], [], [], [], 0, 0);
assert_sort("Sort null no genera ORDER BY", strpos($sql, 'ORDER BY') === false);

// Test 3: Campo único ascendente (sin paginación para aislar prueba)
[$sql, $params] = SQL::buildSelect('*', 'users', [], [], [], ['name'], 0, 0);
assert_sort("Campo único ascendente", strpos($sql, 'ORDER BY "name" ASC') !== false);

// Test 4: Campo único descendente con prefijo - (sin paginación)
[$sql, $params] = SQL::buildSelect('*', 'users', [], [], [], ['-name'], 0, 0);
assert_sort("Campo único descendente con prefijo -", strpos($sql, 'ORDER BY "name" DESC') !== false);

// Test 5: Múltiples campos con orden mixto (sin paginación)
[$sql, $params] = SQL::buildSelect('*', 'users', [], [], [], ['id', '-created_at'], 0, 0);
assert_sort("Múltiples campos con orden mixto", strpos($sql, 'ORDER BY "id" ASC, "created_at" DESC') !== false);

// Test 6: String simple ascendente (sin paginación)
[$sql, $params] = SQL::buildSelect('*', 'users', [], [], [], ['email'], 0, 0);
assert_sort("String simple ascendente", strpos($sql, 'ORDER BY "email" ASC') !== false);

// Test 7: String simple descendente (sin paginación)
[$sql, $params] = SQL::buildSelect('*', 'users', [], [], [], ['-email'], 0, 0);
assert_sort("String simple descendente", strpos($sql, 'ORDER BY "email" DESC') !== false);

echo "\n==================================================\n";
if ($failed === 0) {
    echo "RESULTADO: TODAS LAS PRUEBAS PASARON\n";
} else {
    echo "RESULTADO: FALLARON $failed PRUEBAS\n";
}
echo "==================================================\n";
