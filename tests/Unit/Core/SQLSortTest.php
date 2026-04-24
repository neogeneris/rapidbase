<?php

require_once __DIR__ . '/../../../src/RapidBase/Core/SQL.php';

use RapidBase\Core\SQL;

echo "==================================================\n";
echo "CORE\\SQL: PRUEBAS DE ORDENAMIENTO (SORT)\n";
echo "==================================================\n\n";

// Configurar driver para usar comillas de MySQL
SQL::setDriver('mysql');

function assert_sort($name, $condition, $details = "") {
    if ($condition) {
        echo "  \033[32m[OK]\033[0m $name\n";
    } else {
        echo "  \033[31m[FAIL]\033[0m $name\n";
        if ($details) echo "  Detalles: $details\n";
        exit(1);
    }
}

echo "--- Bloque 1: Ordenamiento básico ---\n";

// Test 1: Array vacío de sort no genera ORDER BY
[$sql, $params] = SQL::buildSelect('*', 'users', [], [], [], []);
assert_sort("Array vacío de sort no genera ORDER BY", strpos($sql, 'ORDER BY') === false);

// Test 2: Sort null no genera ORDER BY
[$sql, $params] = SQL::buildSelect('*', 'users', [], [], [], []);
assert_sort("Sort null no genera ORDER BY", strpos($sql, 'ORDER BY') === false);

// Test 3: Campo único ascendente
[$sql, $params] = SQL::buildSelect('*', 'users', [], [], [], ['name']);
assert_sort("Campo único ascendente", strpos($sql, 'ORDER BY `name` ASC') !== false, "SQL: $sql");

// Test 4: Campo único descendente con prefijo -
[$sql, $params] = SQL::buildSelect('*', 'users', [], [], [], ['-name']);
assert_sort("Campo único descendente con prefijo -", strpos($sql, 'ORDER BY `name` DESC') !== false, "SQL: $sql");

// Test 5: Múltiples campos con orden mixto
[$sql, $params] = SQL::buildSelect('*', 'users', [], [], [], ['id', '-created_at']);
assert_sort("Múltiples campos con orden mixto", strpos($sql, 'ORDER BY `id` ASC, `created_at` DESC') !== false, "SQL: $sql");

// Test 6: String simple ascendente (convertido a array internamente)
[$sql, $params] = SQL::buildSelect('*', 'users', [], [], [], ['email']);
assert_sort("String simple ascendente", strpos($sql, 'ORDER BY `email` ASC') !== false, "SQL: $sql");

// Test 7: String simple descendente
[$sql, $params] = SQL::buildSelect('*', 'users', [], [], [], ['-email']);
assert_sort("String simple descendente", strpos($sql, 'ORDER BY `email` DESC') !== false, "SQL: $sql");

echo "\n==================================================\n";
echo "RESULTADO: TODAS LAS PRUEBAS PASARON\n";
echo "==================================================\n";
