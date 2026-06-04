<?php

/**
 * Suite de Pruebas para paginación en el nuevo motor Q
 */

// El bootstrap ya cargó el autoloader, solo necesitamos importar las clases
use RapidBase\Core\SQL\Q;

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
echo "CORE\\SQL: PRUEBAS DE PAGINACIÓN (Q ENGINE)\n";
echo "==================================================\n";

echo "\n--- Bloque 1: Paginación básica ---\n";

// Test 1: No retorna LIMIT ni OFFSET si no hay paginación
$compiled = Q::from('users')->select('*');
$sql = $compiled->getSql();
assert_pagination("Sin paginación no retorna LIMIT", strpos($sql, 'LIMIT') === false);

// Test 2: Page 1 (offset 0)
// Q::page(1, 10) -> [0, 10]
$compiled = Q::from('users')->select('*', Q::page(1, 10));
$sql = $compiled->getSql();
$params = $compiled->getParams();
assert_pagination("Page 1 tiene LIMIT ?", strpos($sql, 'LIMIT ?') !== false);
assert_pagination("Page 1 tiene OFFSET ?", strpos($sql, 'OFFSET ?') !== false);
// En Q.php: [(int)$limit[1], (int)$limit[0]] -> [10, 0]
assert_pagination("Param 1 es limit (10)", $params[0] === 10);
assert_pagination("Param 2 es offset (0)", $params[1] === 0);

// Test 3: Page 2 (offset 10)
$compiled = Q::from('users')->select('*', Q::page(2, 10));
$params = $compiled->getParams();
assert_pagination("Page 2 param offset es 10", $params[1] === 10);

// Test 4: Page 3 con limit 20
$compiled = Q::from('users')->select('*', Q::page(3, 20));
$params = $compiled->getParams();
assert_pagination("Page 3 param limit es 20", $params[0] === 20);
assert_pagination("Page 3 param offset es 40", $params[1] === 40);

echo "\n==================================================\n";
if ($failed === 0) {
    echo "RESULTADO: TODAS LAS PRUEBAS PASARON\n";
} else {
    echo "RESULTADO: FALLARON $failed PRUEBAS\n";
}
echo "==================================================\n";
