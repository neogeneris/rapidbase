<?php
/**
 * Q::update() Tests
 * 
 * Tests for the Q::update() method.
 */

require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/Q.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/JoinResolver.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/ConditionMatrix.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/ConditionParser.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/SqlCompiler.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SchemaMap.php';

use RapidBase\Core\SQL\Q;

echo "--- Ejecutando: QUpdateTest.php ---\n\n";

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

// Test 1: Simple update with ID condition
try {
    $result = Q::from('users', ['id' => 5])->update(['name' => 'John']);
    assert_sql("Simple update with ID condition", 
        "UPDATE `users` SET `name` = ? WHERE `id` = ?", 
        $result
    );
    assert_test("  - Has correct params", $result[1] === ['John', 5]);
} catch (Throwable $e) {
    assert_test("Simple update with ID condition", false, $e->getMessage());
}

// Test 2: Update with multiple fields
try {
    $result = Q::from('users', ['id' => 10])->update(['name' => 'Jane', 'email' => 'jane@example.com']);
    assert_sql("Update with multiple fields", 
        "UPDATE `users` SET `name` = ?, `email` = ? WHERE `id` = ?", 
        $result
    );
    assert_test("  - Has correct params", $result[1] === ['Jane', 'jane@example.com', 10]);
} catch (Throwable $e) {
    assert_test("Update with multiple fields", false, $e->getMessage());
}

// Test 3: Update with string condition
try {
    $result = Q::from('users', ['status' => 'active'])->update(['verified' => 1]);
    assert_sql("Update with string condition", 
        "UPDATE `users` SET `verified` = ? WHERE `status` = ?", 
        $result
    );
    assert_test("  - Has correct params", $result[1] === [1, 'active']);
} catch (Throwable $e) {
    assert_test("Update with string condition", false, $e->getMessage());
}

// Test 4: Update without WHERE condition (uses 1)
try {
    $result = Q::from('users')->update(['updated_at' => '2024-01-01']);
    assert_sql("Update without WHERE condition", 
        "UPDATE `users` SET `updated_at` = ? WHERE 1", 
        $result
    );
    assert_test("  - Has correct params", $result[1] === ['2024-01-01']);
} catch (Throwable $e) {
    assert_test("Update without WHERE condition", false, $e->getMessage());
}

// Test 5: Update with multiple tables should fail
try {
    $result = Q::from(['users', 'posts'])->update(['name' => 'Test']);
    assert_test("Update with multiple tables throws exception", false, "Should have thrown exception");
} catch (Throwable $e) {
    assert_test("Update with multiple tables throws exception", true);
}

// Test 6: Fluent interface with filter
try {
    $result = Q::from('users')->fields(['id'])->orderBy('name')->limit(10)->update(['active' => 1]);
    // Note: fluent methods like fields, orderBy, limit are ignored in update
    assert_sql("Fluent interface with filter (basic update)", 
        "UPDATE `users` SET `active` = ? WHERE 1", 
        $result
    );
} catch (Throwable $e) {
    assert_test("Fluent interface with filter", false, $e->getMessage());
}

echo "\n";
echo "Results: $passed passed, $failed failed\n";

if ($failed > 0) {
    exit(1);
}
