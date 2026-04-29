<?php
/**
 * Q::exists() Tests
 * 
 * Tests for the Q::exists() method.
 */

require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/Q.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/JoinResolver.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/ConditionMatrix.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/ConditionParser.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/SqlCompiler.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SchemaMap.php';

use RapidBase\Core\SQL\Q;

echo "--- Ejecutando: QExistsTest.php ---\n\n";

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

// Test 1: Simple exists without conditions
try {
    $result = Q::from('users')->exists();
    assert_sql("Simple exists without conditions", 
        "SELECT EXISTS(SELECT 1 FROM `users`) AS exists_flag", 
        $result
    );
    assert_test("  - Has empty params", $result[1] === []);
} catch (Throwable $e) {
    assert_test("Simple exists without conditions", false, $e->getMessage());
}

// Test 2: Exists with ID condition
try {
    $result = Q::from('users', ['id' => 5])->exists();
    assert_sql("Exists with ID condition", 
        "SELECT EXISTS(SELECT 1 FROM `users` WHERE `id` = ?) AS exists_flag", 
        $result
    );
    assert_test("  - Has correct params", $result[1] === [5]);
} catch (Throwable $e) {
    assert_test("Exists with ID condition", false, $e->getMessage());
}

// Test 3: Exists with string condition
try {
    $result = Q::from('users', ['status' => 'active'])->exists();
    assert_sql("Exists with string condition", 
        "SELECT EXISTS(SELECT 1 FROM `users` WHERE `status` = ?) AS exists_flag", 
        $result
    );
    assert_test("  - Has correct params", $result[1] === ['active']);
} catch (Throwable $e) {
    assert_test("Exists with string condition", false, $e->getMessage());
}

// Test 4: Exists with multiple tables should fail
try {
    $result = Q::from(['users', 'posts'])->exists();
    assert_test("Exists with multiple tables throws exception", false, "Should have thrown exception");
} catch (Throwable $e) {
    assert_test("Exists with multiple tables throws exception", true);
}

echo "\n";
echo "Results: $passed passed, $failed failed\n";

if ($failed > 0) {
    exit(1);
}
