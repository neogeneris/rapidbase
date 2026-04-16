<?php
require '/workspace/src/RapidBase/Core/SQL.php';
use RapidBase\Core\SQL;

// Configurar MySQL
SQL::setDriver('mysql');

echo "=== PRUEBAS UNITARIAS buildSelect() ===\n\n";
$passed = 0;
$failed = 0;

// Test 1: Consulta básica
SQL::reset();
$result = SQL::buildSelect('*', 'users', ['active' => 1]);
$expected = 'SELECT * FROM `users` WHERE `active` = :p0 LIMIT 10 OFFSET 0';
if ($result[0] === $expected) {
    echo "Test 1 (Básico): ✅ PASS\n";
    $passed++;
} else {
    echo "Test 1 (Básico): ❌ FAIL\n";
    echo "  Esperado: $expected\n";
    echo "  Obtenido: {$result[0]}\n";
    $failed++;
}

// Test 2: WHERE con operadores
SQL::reset();
$result = SQL::buildSelect('id, name', 'products', ['price' => ['>' => 100]]);
$expected = 'SELECT id, name FROM `products` WHERE `price` > :p0 LIMIT 10 OFFSET 0';
if ($result[0] === $expected) {
    echo "Test 2 (WHERE operador): ✅ PASS\n";
    $passed++;
} else {
    echo "Test 2 (WHERE operador): ❌ FAIL\n";
    echo "  Esperado: $expected\n";
    echo "  Obtenido: {$result[0]}\n";
    $failed++;
}

// Test 3: ORDER BY
SQL::reset();
$result = SQL::buildSelect('*', 'products', [], [], [], ['name' => 'DESC']);
$expected = 'SELECT * FROM `products` ORDER BY name DESC LIMIT 10 OFFSET 0';
if ($result[0] === $expected) {
    echo "Test 3 (ORDER BY): ✅ PASS\n";
    $passed++;
} else {
    echo "Test 3 (ORDER BY): ❌ FAIL\n";
    echo "  Esperado: $expected\n";
    echo "  Obtenido: {$result[0]}\n";
    $failed++;
}

// Test 4: GROUP BY
SQL::reset();
$result = SQL::buildSelect('category, COUNT(*) as c', 'products', [], ['category']);
$expected = 'SELECT category, COUNT(*) as c FROM `products` GROUP BY `category` LIMIT 10 OFFSET 0';
if ($result[0] === $expected) {
    echo "Test 4 (GROUP BY): ✅ PASS\n";
    $passed++;
} else {
    echo "Test 4 (GROUP BY): ❌ FAIL\n";
    echo "  Esperado: $expected\n";
    echo "  Obtenido: {$result[0]}\n";
    $failed++;
}

// Test 5: HAVING
SQL::reset();
$result = SQL::buildSelect('category, COUNT(*) as c', 'products', [], ['category'], ['c' => ['>' => 5]]);
$expected = 'SELECT category, COUNT(*) as c FROM `products` GROUP BY `category` HAVING `c` > :p0 LIMIT 10 OFFSET 0';
if ($result[0] === $expected && $result[1] === ['p0' => 5]) {
    echo "Test 5 (HAVING): ✅ PASS\n";
    $passed++;
} else {
    echo "Test 5 (HAVING): ❌ FAIL\n";
    echo "  Esperado: $expected, params: [p0 => 5]\n";
    echo "  Obtenido: {$result[0]}, params: " . json_encode($result[1]) . "\n";
    $failed++;
}

// Test 6: Paginación
SQL::reset();
$result = SQL::buildSelect('*', 'products', [], [], [], [], 3, 20);
$expected = 'SELECT * FROM `products` LIMIT 20 OFFSET 40';
if ($result[0] === $expected) {
    echo "Test 6 (Paginación): ✅ PASS\n";
    $passed++;
} else {
    echo "Test 6 (Paginación): ❌ FAIL\n";
    echo "  Esperado: $expected\n";
    echo "  Obtenido: {$result[0]}\n";
    $failed++;
}

// Test 7: WHERE múltiple con operadores
SQL::reset();
$result = SQL::buildSelect('*', 'products', ['price' => ['>' => 100, '<' => 500], 'status' => 'active']);
$expected = 'SELECT * FROM `products` WHERE `price` > :p0 AND `price` < :p1 AND `status` = :p2 LIMIT 10 OFFSET 0';
if ($result[0] === $expected && isset($result[1]['p0']) && $result[1]['p0'] === 100) {
    echo "Test 7 (WHERE múltiple): ✅ PASS\n";
    $passed++;
} else {
    echo "Test 7 (WHERE múltiple): ❌ FAIL\n";
    echo "  Esperado: $expected\n";
    echo "  Obtenido: {$result[0]}\n";
    echo "  Params: " . json_encode($result[1]) . "\n";
    $failed++;
}

echo "\n=== Resumen: $passed pasaron, $failed fallaron ===\n";
exit($failed > 0 ? 1 : 0);
