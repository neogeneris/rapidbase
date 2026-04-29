<?php
/**
 * Q::select() Tests
 * 
 * Tests for the Q::select() method.
 */

require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/Q.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/JoinResolver.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/ConditionMatrix.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/ConditionParser.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/SqlCompiler.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/DeterministicJoin.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/JoinStrategy.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SchemaMap.php';

use RapidBase\Core\SQL\Q;

echo "--- Ejecutando: QSelectTest.php ---\n\n";

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

// Setup relations
Q::setRelationsMap([
    'from' => [
        'users' => [
            'posts' => ['local_key' => 'id', 'foreign_key' => 'user_id']
        ]
    ]
]);

// Test 1: Simple select with default fields
try {
    $result = Q::from('users')->select();
    assert_sql("Simple select with default fields", 
        "SELECT * FROM `users`", 
        $result
    );
} catch (Throwable $e) {
    assert_test("Simple select with default fields", false, $e->getMessage());
}

// Test 2: Select with specific fields
try {
    $result = Q::from('users')->select(['id', 'name']);
    assert_sql("Select with specific fields", 
        "SELECT `id`, `name` FROM `users`", 
        $result
    );
} catch (Throwable $e) {
    assert_test("Select with specific fields", false, $e->getMessage());
}

// Test 3: Select with WHERE condition
try {
    $result = Q::from('users', ['status' => 'active'])->select();
    assert_sql("Select with WHERE condition", 
        "SELECT * FROM `users` WHERE `status` = :p0", 
        $result
    );
    assert_test("  - Has correct params", $result[1] === ['p0' => 'active']);
} catch (Throwable $e) {
    assert_test("Select with WHERE condition", false, $e->getMessage());
}

// Test 4: Select with ORDER BY
try {
    $result = Q::from('users')->select('*', null, ['name']);
    assert_sql("Select with ORDER BY ASC", 
        "SELECT * FROM `users` ORDER BY `name` ASC", 
        $result
    );
} catch (Throwable $e) {
    assert_test("Select with ORDER BY", false, $e->getMessage());
}

// Test 5: Select with ORDER BY DESC
try {
    $result = Q::from('users')->select('*', null, ['-created_at']);
    assert_sql("Select with ORDER BY DESC", 
        "SELECT * FROM `users` ORDER BY `created_at` DESC", 
        $result
    );
} catch (Throwable $e) {
    assert_test("Select with ORDER BY DESC", false, $e->getMessage());
}

// Test 6: Select with LIMIT
try {
    $result = Q::from('users')->select('*', 10);
    assert_sql("Select with LIMIT", 
        "SELECT * FROM `users` LIMIT ?", 
        $result
    );
    assert_test("  - Has correct limit param", $result[1] === [10]);
} catch (Throwable $e) {
    assert_test("Select with LIMIT", false, $e->getMessage());
}

// Test 7: Select with pagination
try {
    $result = Q::from('users')->select('*', [2, 20]);
    assert_sql("Select with pagination", 
        "SELECT * FROM `users` LIMIT ? OFFSET ?", 
        $result
    );
    assert_test("  - Has correct pagination params", $result[1] === [20, 20]);
} catch (Throwable $e) {
    assert_test("Select with pagination", false, $e->getMessage());
}

// Test 8: Select using static page helper
try {
    $page = Q::page(3, 15);
    $result = Q::from('users')->select('*', $page);
    assert_sql("Select using static page helper", 
        "SELECT * FROM `users` LIMIT ? OFFSET ?", 
        $result
    );
    assert_test("  - Has correct page params", $result[1] === [15, 30]);
} catch (Throwable $e) {
    assert_test("Select using static page helper", false, $e->getMessage());
}

// Test 9: Fluent interface - fields
try {
    $result = Q::from('users')->fields(['id', 'email'])->select();
    assert_sql("Fluent interface - fields", 
        "SELECT `id`, `email` FROM `users`", 
        $result
    );
} catch (Throwable $e) {
    assert_test("Fluent interface - fields", false, $e->getMessage());
}

// Test 10: Fluent interface - orderBy
try {
    $result = Q::from('users')->orderBy('name')->select();
    assert_sql("Fluent interface - orderBy", 
        "SELECT * FROM `users` ORDER BY `name` ASC", 
        $result
    );
} catch (Throwable $e) {
    assert_test("Fluent interface - orderBy", false, $e->getMessage());
}

// Test 11: Fluent interface - limit
try {
    $result = Q::from('users')->limit(25)->select();
    assert_sql("Fluent interface - limit", 
        "SELECT * FROM `users` LIMIT ?", 
        $result
    );
} catch (Throwable $e) {
    assert_test("Fluent interface - limit", false, $e->getMessage());
}

echo "\n";
echo "Results: $passed passed, $failed failed\n";

if ($failed > 0) {
    exit(1);
}
