<?php

/**
 * Suite de Pruebas para paginación en SQL::buildSelect
 */

require_once __DIR__ . '/../../../src/RapidBase/Core/SQL.php';

use RapidBase\Core\SQL;

$failed = 0;

function assert_pagination($msg, $cond) {
    global $failed;
    if ($cond) {
        echo "  [OK] $msg\n";
    } else {
        echo "  [FAIL] $msg\n";
        $failed++;
    }
}

echo "==================================================\n";
echo "CORE\\SQL: PRUEBAS DE PAGINACIÓN\n";
echo "==================================================\n";

echo "\n--- Bloque 1: Paginación básica ---\n";

// Test 1: Page 0 no retorna LIMIT ni OFFSET (perPage=10)
[$sql, $params] = SQL::buildSelect('*', 'users', [], [], [], [], 0, 10);
assert_pagination("Page 0 no retorna LIMIT", strpos($sql, 'LIMIT') === false);
assert_pagination("Page 0 no retorna OFFSET", strpos($sql, 'OFFSET') === false);

// Test 2: Page 1 empieza en offset 0
[$sql, $params] = SQL::buildSelect('*', 'users', [], [], [], [], 1, 10);
assert_pagination("Page 1 tiene LIMIT 10", strpos($sql, 'LIMIT 10') !== false);
assert_pagination("Page 1 tiene OFFSET 0", strpos($sql, 'OFFSET 0') !== false);

// Test 3: Page 2 empieza en offset 10 (con limit 10)
[$sql, $params] = SQL::buildSelect('*', 'users', [], [], [], [], 2, 10);
assert_pagination("Page 2 tiene LIMIT 10", strpos($sql, 'LIMIT 10') !== false);
assert_pagination("Page 2 tiene OFFSET 10", strpos($sql, 'OFFSET 10') !== false);

// Test 4: Page 3 con limit 20
[$sql, $params] = SQL::buildSelect('*', 'users', [], [], [], [], 3, 20);
assert_pagination("Page 3 tiene LIMIT 20", strpos($sql, 'LIMIT 20') !== false);
assert_pagination("Page 3 tiene OFFSET 40", strpos($sql, 'OFFSET 40') !== false);

// Test 5: Default limit es 10
[$sql, $params] = SQL::buildSelect('*', 'users', [], [], [], [], 1);
assert_pagination("Default limit es 10", strpos($sql, 'LIMIT 10') !== false);
assert_pagination("Page 1 con default limit tiene OFFSET 0", strpos($sql, 'OFFSET 0') !== false);

echo "\n==================================================\n";
if ($failed === 0) {
    echo "RESULTADO: TODAS LAS PRUEBAS PASARON\n";
} else {
    echo "RESULTADO: FALLARON $failed PRUEBAS\n";
}
echo "==================================================\n";
