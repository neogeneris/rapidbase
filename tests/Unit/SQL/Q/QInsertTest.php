<?php
/**
 * Q::insert() Tests
 * 
 * Tests for the Q::insert() method.
 */

require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/Q.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/JoinResolver.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/ConditionMatrix.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/ConditionParser.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/SqlCompiler.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SchemaMap.php';

use RapidBase\Core\SQL\Q;

echo "--- Ejecutando: QInsertTest.php ---\n\n";

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

// Test 1: Simple insert with single row
try {
    $result = Q::from('users')->insert(['name' => 'John', 'email' => 'john@example.com']);
    assert_sql("Simple insert with single row", 
        "INSERT INTO `users` (`name`, `email`) VALUES (?, ?)", 
        $result
    );
    assert_test("  - Has correct params", $result[1] === ['John', 'john@example.com']);
} catch (Throwable $e) {
    assert_test("Simple insert with single row", false, $e->getMessage());
}

// Test 2: Insert with multiple rows
try {
    $rows = [
        ['name' => 'Alice', 'email' => 'alice@example.com'],
        ['name' => 'Bob', 'email' => 'bob@example.com']
    ];
    $result = Q::from('users')->insert($rows);
    assert_sql("Insert with multiple rows", 
        "INSERT INTO `users` (`name`, `email`) VALUES (?, ?), (?, ?)", 
        $result
    );
    assert_test("  - Has correct params", $result[1] === ['Alice', 'alice@example.com', 'Bob', 'bob@example.com']);
} catch (Throwable $e) {
    assert_test("Insert with multiple rows", false, $e->getMessage());
}

// Test 3: Insert with empty data should fail
try {
    $result = Q::from('users')->insert([]);
    assert_test("Insert with empty data throws exception", false, "Should have thrown exception");
} catch (Throwable $e) {
    assert_test("Insert with empty data throws exception", true);
}

// Test 4: Insert with multiple tables should fail
try {
    $result = Q::from(['users', 'posts'])->insert(['name' => 'Test']);
    assert_test("Insert with multiple tables throws exception", false, "Should have thrown exception");
} catch (Throwable $e) {
    assert_test("Insert with multiple tables throws exception", true);
}

echo "\n";
echo "Results: $passed passed, $failed failed\n";

if ($failed > 0) {
    exit(1);
}
