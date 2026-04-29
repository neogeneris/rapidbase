<?php
/**
 * Q::delete() Tests
 * 
 * Tests for the Q::delete() method.
 */

require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/Q.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/JoinResolver.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/ConditionMatrix.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/ConditionParser.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/SqlCompiler.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SchemaMap.php';

use RapidBase\Core\SQL\Q;

echo "--- Ejecutando: QDeleteTest.php ---\n\n";

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

// Test 1: Simple delete with ID condition
try {
    $result = Q::from('users', ['id' => 5])->delete();
    assert_sql("Simple delete with ID condition", 
        "DELETE FROM `users` WHERE `id` = ?", 
        $result
    );
    assert_test("  - Has correct params", $result[1] === [5]);
} catch (Throwable $e) {
    assert_test("Simple delete with ID condition", false, $e->getMessage());
}

// Test 2: Delete with string condition
try {
    $result = Q::from('users', ['status' => 'inactive'])->delete();
    assert_sql("Delete with string condition", 
        "DELETE FROM `users` WHERE `status` = ?", 
        $result
    );
    assert_test("  - Has correct params", $result[1] === ['inactive']);
} catch (Throwable $e) {
    assert_test("Delete with string condition", false, $e->getMessage());
}

// Test 3: Delete without WHERE condition (uses 1)
try {
    $result = Q::from('users')->delete();
    assert_sql("Delete without WHERE condition", 
        "DELETE FROM `users` WHERE 1", 
        $result
    );
    assert_test("  - Has empty params", $result[1] === []);
} catch (Throwable $e) {
    assert_test("Delete without WHERE condition", false, $e->getMessage());
}

// Test 4: Delete with multiple tables should fail
try {
    $result = Q::from(['users', 'posts'])->delete();
    assert_test("Delete with multiple tables throws exception", false, "Should have thrown exception");
} catch (Throwable $e) {
    assert_test("Delete with multiple tables throws exception", true);
}

echo "\n";
echo "Results: $passed passed, $failed failed\n";

if ($failed > 0) {
    exit(1);
}
