<?php
/**
 * Q Pagination Tests
 * 
 * Tests for pagination functionality (limit and offset).
 */

require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/Q.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/JoinResolver.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/ConditionMatrix.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/ConditionParser.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/SqlCompiler.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SchemaMap.php';

use RapidBase\Core\SQL\Q;

echo "--- Ejecutando: QPaginationTest.php ---\n\n";

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

// Test 1: Simple limit as integer
try {
    $result = Q::from('users')->select('*', 10);
    assert_sql("Simple limit as integer", 
        "SELECT * FROM `users` LIMIT ?", 
        $result
    );
    assert_test("  - Has correct limit param", $result[1] === [10]);
} catch (Throwable $e) {
    assert_test("Simple limit as integer", false, $e->getMessage());
}

// Test 2: Limit using fluent interface
try {
    $result = Q::from('users')->limit(25)->select();
    assert_sql("Limit using fluent interface", 
        "SELECT * FROM `users` LIMIT ?", 
        $result
    );
    assert_test("  - Has correct limit param", $result[1] === [25]);
} catch (Throwable $e) {
    assert_test("Limit using fluent interface", false, $e->getMessage());
}

// Test 3: Pagination with array [offset, limit]
try {
    $result = Q::from('users')->select('*', [5, 20]);
    assert_sql("Pagination with array [offset, limit]", 
        "SELECT * FROM `users` LIMIT ? OFFSET ?", 
        $result
    );
    assert_test("  - Has correct params", $result[1] === [20, 5]);
} catch (Throwable $e) {
    assert_test("Pagination with array [offset, limit]", false, $e->getMessage());
}

// Test 4: Static page helper - page 1
try {
    $page = Q::page(1, 10);
    assert_test("Static page helper - page 1 returns [0, 10]", $page === [0, 10]);
} catch (Throwable $e) {
    assert_test("Static page helper - page 1", false, $e->getMessage());
}

// Test 5: Static page helper - page 3
try {
    $page = Q::page(3, 15);
    assert_test("Static page helper - page 3 returns [30, 15]", $page === [30, 15]);
} catch (Throwable $e) {
    assert_test("Static page helper - page 3", false, $e->getMessage());
}

// Test 6: Static page helper - page 0 (should be treated as 1)
try {
    $page = Q::page(0, 10);
    assert_test("Static page helper - page 0 returns [0, 10]", $page === [0, 10]);
} catch (Throwable $e) {
    assert_test("Static page helper - page 0", false, $e->getMessage());
}

// Test 7: Using static page helper in select
try {
    $page = Q::page(2, 20);
    $result = Q::from('users')->select('*', $page);
    assert_sql("Using static page helper in select", 
        "SELECT * FROM `users` LIMIT ? OFFSET ?", 
        $result
    );
    assert_test("  - Has correct page params", $result[1] === [20, 20]);
} catch (Throwable $e) {
    assert_test("Using static page helper in select", false, $e->getMessage());
}

// Test 8: Pagination overrides fluent limit
try {
    $result = Q::from('users')->limit(5)->select('*', [10, 50]);
    assert_sql("Pagination overrides fluent limit", 
        "SELECT * FROM `users` LIMIT ? OFFSET ?", 
        $result
    );
    assert_test("  - Has correct pagination params", $result[1] === [50, 10]);
} catch (Throwable $e) {
    assert_test("Pagination overrides fluent limit", false, $e->getMessage());
}

// Test 9: Complex query with pagination
try {
    $result = Q::from('users', ['status' => 'active'])
        ->orderBy('-created_at')
        ->select(['id', 'name'], [2, 10]);
    assert_sql("Complex query with pagination", 
        "SELECT `id`, `name` FROM `users` WHERE `status` = :p0 ORDER BY `created_at` DESC LIMIT ? OFFSET ?", 
        $result
    );
    assert_test("  - Has correct params count", count($result[1]) === 3);
} catch (Throwable $e) {
    assert_test("Complex query with pagination", false, $e->getMessage());
}

echo "\n";
echo "Results: $passed passed, $failed failed\n";

if ($failed > 0) {
    exit(1);
}
