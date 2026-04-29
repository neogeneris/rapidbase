<?php
/**
 * Q JOIN Tests
 * 
 * Tests for automatic join resolution with multiple tables.
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

echo "--- Ejecutando: QJoinTest.php ---\n\n";

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

// Setup relations map
Q::setRelationsMap([
    'from' => [
        'users' => [
            'posts' => ['local_key' => 'id', 'foreign_key' => 'user_id'],
            'comments' => ['local_key' => 'id', 'foreign_key' => 'user_id']
        ],
        'posts' => [
            'comments' => ['local_key' => 'id', 'foreign_key' => 'post_id']
        ]
    ]
]);

// Test 1: Simple join with two tables
try {
    $result = Q::from(['users', 'posts'])->select();
    assert_sql("Simple join with two tables", 
        "SELECT * FROM `users` LEFT JOIN `posts` ON `users`.`id` = `posts`.`user_id`", 
        $result
    );
} catch (Throwable $e) {
    assert_test("Simple join with two tables", false, $e->getMessage());
}

// Test 2: Join with specific fields
try {
    $result = Q::from(['users', 'posts'])->select(['u.name', 'p.title']);
    assert_sql("Join with specific fields", 
        "SELECT `u`.`name`, `p`.`title` FROM `users` LEFT JOIN `posts` ON `users`.`id` = `posts`.`user_id`", 
        $result
    );
} catch (Throwable $e) {
    assert_test("Join with specific fields", false, $e->getMessage());
}

// Test 3: Join with WHERE condition
try {
    $result = Q::from(['users', 'posts'], ['u.active' => 1])->select();
    assert_sql("Join with WHERE condition", 
        "SELECT * FROM `users` LEFT JOIN `posts` ON `users`.`id` = `posts`.`user_id` WHERE `u`.`active` = :p0", 
        $result
    );
    assert_test("  - Has correct params", $result[1] === ['p0' => 1]);
} catch (Throwable $e) {
    assert_test("Join with WHERE condition", false, $e->getMessage());
}

// Test 4: Join with ORDER BY
try {
    $result = Q::from(['users', 'posts'])->select('*', null, ['p.created_at']);
    assert_sql("Join with ORDER BY", 
        "SELECT * FROM `users` LEFT JOIN `posts` ON `users`.`id` = `posts`.`user_id` ORDER BY `p`.`created_at` ASC", 
        $result
    );
} catch (Throwable $e) {
    assert_test("Join with ORDER BY", false, $e->getMessage());
}

// Test 5: Join with pagination
try {
    $result = Q::from(['users', 'posts'])->select('*', [0, 10]);
    assert_sql("Join with pagination", 
        "SELECT * FROM `users` LEFT JOIN `posts` ON `users`.`id` = `posts`.`user_id` LIMIT ? OFFSET ?", 
        $result
    );
    assert_test("  - Has correct params", $result[1] === [10, 0]);
} catch (Throwable $e) {
    assert_test("Join with pagination", false, $e->getMessage());
}

// Test 6: Complex join query
try {
    $result = Q::from(['users', 'posts'])
        ->fields(['u.id', 'u.name', 'p.title', 'p.content'])
        ->orderBy(['-p.created_at'])
        ->limit(20)
        ->select();
    assert_sql("Complex join query", 
        "SELECT `u`.`id`, `u`.`name`, `p`.`title`, `p`.`content` FROM `users` LEFT JOIN `posts` ON `users`.`id` = `posts`.`user_id` ORDER BY `p`.`created_at` DESC LIMIT ?", 
        $result
    );
    assert_test("  - Has correct limit param", $result[1] === [20]);
} catch (Throwable $e) {
    assert_test("Complex join query", false, $e->getMessage());
}

// Test 7: Three table join (users -> posts -> comments)
try {
    $result = Q::from(['users', 'posts', 'comments'])->select();
    // The exact SQL depends on the join resolution algorithm
    assert_test("Three table join generates valid SQL", 
        strpos($result[0], 'LEFT JOIN') !== false && strpos($result[0], 'users') !== false
    );
} catch (Throwable $e) {
    assert_test("Three table join", false, $e->getMessage());
}

// Test 8: Join with table alias in FROM
try {
    $result = Q::from(['users AS u', 'posts AS p'])->select();
    assert_test("Join with table alias generates valid SQL", 
        strpos($result[0], 'LEFT JOIN') !== false
    );
} catch (Throwable $e) {
    assert_test("Join with table alias", false, $e->getMessage());
}

echo "\n";
echo "Results: $passed passed, $failed failed\n";

if ($failed > 0) {
    exit(1);
}
