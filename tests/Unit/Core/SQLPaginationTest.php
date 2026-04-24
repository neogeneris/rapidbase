<?php

require_once __DIR__ . '/../../../src/RapidBase/Core/SQL.php';

use RapidBase\Core\SQL;

echo "==================================================\n";
echo "CORE\\SQL: PRUEBAS DE PAGINACIÓN\n";
echo "==================================================\n\n";

// Configurar driver para usar comillas de MySQL
SQL::setDriver('mysql');

function assert_pagination($name, $condition, $details = "") {
    if ($condition) {
        echo "  \033[32m[OK]\033[0m $name\n";
    } else {
        echo "  \033[31m[FAIL]\033[0m $name\n";
        if ($details) echo "  Detalles: $details\n";
        exit(1);
    }
}

echo "--- Bloque 1: Paginación básica ---\n";

// Test 1: Page 0 no retorna LIMIT
[$sql, $params] = SQL::buildSelect('*', 'users', [], [], [], [], 0);
assert_pagination("Page 0 no retorna LIMIT", strpos($sql, 'LIMIT') === false, "SQL: $sql");

// Test 2: Page 0 no retorna OFFSET
assert_pagination("Page 0 no retorna OFFSET", strpos($sql, 'OFFSET') === false, "SQL: $sql");

// Test 3: Page 1 tiene LIMIT 10
[$sql, $params] = SQL::buildSelect('*', 'users', [], [], [], [], 1);
assert_pagination("Page 1 tiene LIMIT 10", strpos($sql, 'LIMIT 10') !== false, "SQL: $sql");

// Test 4: Page 1 tiene OFFSET 0
assert_pagination("Page 1 tiene OFFSET 0", strpos($sql, 'OFFSET 0') !== false, "SQL: $sql");

// Test 5: Page 2 tiene LIMIT 10
[$sql, $params] = SQL::buildSelect('*', 'users', [], [], [], [], 2);
assert_pagination("Page 2 tiene LIMIT 10", strpos($sql, 'LIMIT 10') !== false, "SQL: $sql");

// Test 6: Page 2 tiene OFFSET 10
assert_pagination("Page 2 tiene OFFSET 10", strpos($sql, 'OFFSET 10') !== false, "SQL: $sql");

// Test 7: Page 3 con limit 20 tiene OFFSET 40
[$sql, $params] = SQL::buildSelect('*', 'users', [], [], [], [], [3, 20]);
assert_pagination("Page 3 tiene LIMIT 20", strpos($sql, 'LIMIT 20') !== false, "SQL: $sql");
assert_pagination("Page 3 tiene OFFSET 40", strpos($sql, 'OFFSET 40') !== false, "SQL: $sql");

// Test 8: Default limit es 10 (cuando se pasa solo página como entero)
[$sql, $params] = SQL::buildSelect('*', 'users', [], [], [], [], 1);
assert_pagination("Default limit es 10", strpos($sql, 'LIMIT 10') !== false, "SQL: $sql");

// Test 9: Page 1 con default limit tiene OFFSET 0
assert_pagination("Page 1 con default limit tiene OFFSET 0", strpos($sql, 'OFFSET 0') !== false, "SQL: $sql");

echo "\n==================================================\n";
echo "RESULTADO: TODAS LAS PRUEBAS PASARON\n";
echo "==================================================\n";
