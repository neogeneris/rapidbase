<?php
/**
 * Q::count() Tests
 * 
 * Tests for the Q::count() method.
 */

require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/Q.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/JoinResolver.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/ConditionMatrix.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/ConditionParser.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/SqlCompiler.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SchemaMap.php';

use RapidBase\Core\SQL\Q;

echo "--- Ejecutando: QCountTest.php ---\n\n";

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

function assert_sql($name, $expectedSql, $actual) {
    global $passed, $failed;
    $actualSql = preg_replace('/\s+/', ' ', trim($actual[0]));
    $expectedSql = preg_replace('/\s+/', ' ', trim($expectedSql));
    
    if ($actualSql === $expectedSql) {
        echo "\033[32m[OK]\033[0m $name\n";
        $passed++;
    } else {
        echo "\033[31m[FAIL]\033[0m $name\n";
        echo "  Expected: $expectedSql\n";
        echo "  Got:      $actualSql\n";
        $failed++;
    }
}

// Test 1: Simple count without conditions
try {
    $result = Q::from('users')->count();
    assert_sql("Simple count without conditions", 
        "SELECT COUNT(*) AS total FROM `users`", 
        $result
    );
    assert_test("  - Has empty params", $result[1] === []);
} catch (Throwable $e) {
    assert_test("Simple count without conditions", false, $e->getMessage());
}

// Test 2: Count with ID condition
try {
    $result = Q::from('users', ['id' => 5])->count();
    assert_sql("Count with ID condition", 
        "SELECT COUNT(*) AS total FROM `users` WHERE `id` = ?", 
        $result
    );
    assert_test("  - Has correct params", $result[1] === [5]);
} catch (Throwable $e) {
    assert_test("Count with ID condition", false, $e->getMessage());
}

// Test 3: Count with string condition
try {
    $result = Q::from('users', ['status' => 'active'])->count();
    assert_sql("Count with string condition", 
        "SELECT COUNT(*) AS total FROM `users` WHERE `status` = ?", 
        $result
    );
    assert_test("  - Has correct params", $result[1] === ['active']);
} catch (Throwable $e) {
    assert_test("Count with string condition", false, $e->getMessage());
}

// Test 4: Count with multiple tables should fail
try {
    $result = Q::from(['users', 'posts'])->count();
    assert_test("Count with multiple tables throws exception", false, "Should have thrown exception");
} catch (Throwable $e) {
    assert_test("Count with multiple tables throws exception", true);
}

echo "\n";
echo "Results: $passed passed, $failed failed\n";

if ($failed > 0) {
    exit(1);
}
