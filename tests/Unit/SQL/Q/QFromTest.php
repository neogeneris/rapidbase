<?php
/**
 * Q::from() Tests
 * 
 * Tests for the Q::from() method and basic state initialization.
 */

require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/Q.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/JoinResolver.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/ConditionMatrix.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/ConditionParser.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/SqlCompiler.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SchemaMap.php';

use RapidBase\Core\SQL\Q;

echo "--- Ejecutando: QFromTest.php ---\n\n";

$passed = 0;
$failed = 0;

function assert_test($name, $condition, $message = '') {
    global $passed, $failed;
    if ($condition) {
        echo "\033[32m[OK]\033[0m $name\n";
        $passed++;
    } else {
        echo "\033[31m[FAIL]\033[0m $name\n";
        if ($message) {
            echo "  Message: $message\n";
        }
        $failed++;
    }
}

// Test 1: Q::from with single table
try {
    $q = Q::from('users');
    assert_test("Q::from with single table", true);
} catch (Throwable $e) {
    assert_test("Q::from with single table", false, $e->getMessage());
}

// Test 2: Q::from with filter
try {
    $q = Q::from('users', ['status' => 'active']);
    assert_test("Q::from with filter", true);
} catch (Throwable $e) {
    assert_test("Q::from with filter", false, $e->getMessage());
}

// Test 3: Q::from with multiple tables
try {
    $q = Q::from(['users', 'posts']);
    assert_test("Q::from with multiple tables", true);
} catch (Throwable $e) {
    assert_test("Q::from with multiple tables", false, $e->getMessage());
}

// Test 4: Q::from returns Q instance
try {
    $q = Q::from('users');
    assert_test("Q::from returns Q instance", $q instanceof Q);
} catch (Throwable $e) {
    assert_test("Q::from returns Q instance", false, $e->getMessage());
}

// Test 5: Q::from is chainable
try {
    $q = Q::from('users')->orderBy('name');
    assert_test("Q::from is chainable", $q instanceof Q);
} catch (Throwable $e) {
    assert_test("Q::from is chainable", false, $e->getMessage());
}

echo "\n";
echo "Results: $passed passed, $failed failed\n";

if ($failed > 0) {
    exit(1);
}
