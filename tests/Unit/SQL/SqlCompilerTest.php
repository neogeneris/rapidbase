<?php

/**
 * SqlCompiler Test Suite
 * Tests SQL generation for SELECT, INSERT, UPDATE, DELETE, COUNT, EXISTS.
 * Tests the ORIGINAL SqlCompiler behavior (FROM and LIMIT include keywords).
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use RapidBase\Core\SQL\SqlCompiler;
use RapidBase\Core\SQL\ConditionMatrix;

$failed = 0;
function assert_test(string $msg, bool $cond): void
{
    global $failed;
    if ($cond) {
        echo "  [PASS] $msg\n";
    } else {
        echo "  [FAIL] $msg\n";
        $failed++;
    }
}

function section(string $title): void
{
    echo "\n--- $title ---\n";
}

ConditionMatrix::setDriver('sqlite');

echo "==================================================\n";
echo "SQL COMPILER TEST SUITE\n";
echo "==================================================\n";

// ========================================================================
// TEST 1: compileSelect - Basic
// ========================================================================
section("Test 1: compileSelect - Basic");

[$sql, $params, $map] = SqlCompiler::compileSelect([
    SqlCompiler::SEL    => '*',
    SqlCompiler::FROM   => 'FROM "users"',  // FROM included
    SqlCompiler::WHERE  => '',
    SqlCompiler::GROUP  => '',
    SqlCompiler::HAVING => '',
    SqlCompiler::ORDER  => '',
    SqlCompiler::LIMIT  => '',
    SqlCompiler::PARAMS => [],
]);

echo "  SQL: $sql\n";
assert_test("Basic SELECT *", strpos(trim($sql), 'SELECT * FROM "users"') === 0);
assert_test("Empty params", $params === []);
assert_test("Has projection map", is_array($map));

// ========================================================================
// TEST 2: compileSelect - With WHERE
// ========================================================================
section("Test 2: compileSelect - With WHERE");

[$sql, $params, $map] = SqlCompiler::compileSelect([
    SqlCompiler::SEL    => 'id, name, email',
    SqlCompiler::FROM   => 'FROM "users"',
    SqlCompiler::WHERE  => '"users"."active" = ?',
    SqlCompiler::GROUP  => '',
    SqlCompiler::HAVING => '',
    SqlCompiler::ORDER  => '',
    SqlCompiler::LIMIT  => '',
    SqlCompiler::PARAMS => [1],
]);

echo "  SQL: $sql\n";
assert_test("SELECT with WHERE", strpos($sql, 'WHERE "users"."active" = ?') !== false);
assert_test("Params contain 1", $params === [1]);

// ========================================================================
// TEST 3: compileSelect - With ORDER BY
// ========================================================================
section("Test 3: compileSelect - With ORDER BY");

[$sql, $params, $map] = SqlCompiler::compileSelect([
    SqlCompiler::SEL    => '*',
    SqlCompiler::FROM   => 'FROM "users"',
    SqlCompiler::WHERE  => '',
    SqlCompiler::GROUP  => '',
    SqlCompiler::HAVING => '',
    SqlCompiler::ORDER  => '"name" ASC',
    SqlCompiler::LIMIT  => '',
    SqlCompiler::PARAMS => [],
]);

echo "  SQL: $sql\n";
assert_test("SELECT with ORDER BY", strpos($sql, 'ORDER BY "name" ASC') !== false);

// ========================================================================
// TEST 4: compileSelect - With LIMIT (compiler adds "LIMIT" keyword)
// ========================================================================
section("Test 4: compileSelect - With LIMIT");

[$sql, $params, $map] = SqlCompiler::compileSelect([
    SqlCompiler::SEL    => '*',
    SqlCompiler::FROM   => 'FROM "users"',
    SqlCompiler::WHERE  => '',
    SqlCompiler::GROUP  => '',
    SqlCompiler::HAVING => '',
    SqlCompiler::ORDER  => '',
    SqlCompiler::LIMIT  => '10 OFFSET 0',  // Without "LIMIT", compiler adds it
    SqlCompiler::PARAMS => [],
]);

echo "  SQL: $sql\n";
assert_test("SELECT with LIMIT", strpos($sql, 'LIMIT 10 OFFSET 0') !== false);
assert_test("No double LIMIT", substr_count($sql, 'LIMIT') === 1);

// ========================================================================
// TEST 5: compileSelect - With LIMIT only (no OFFSET)
// ========================================================================
section("Test 5: compileSelect - LIMIT only");

[$sql, $params, $map] = SqlCompiler::compileSelect([
    SqlCompiler::SEL    => '*',
    SqlCompiler::FROM   => 'FROM "users"',
    SqlCompiler::WHERE  => '',
    SqlCompiler::GROUP  => '',
    SqlCompiler::HAVING => '',
    SqlCompiler::ORDER  => '',
    SqlCompiler::LIMIT  => '5',  // Just the number
    SqlCompiler::PARAMS => [],
]);

echo "  SQL: $sql\n";
assert_test("LIMIT only, no OFFSET", strpos($sql, 'LIMIT 5') !== false);
assert_test("No OFFSET keyword", strpos($sql, 'OFFSET') === false);
assert_test("No double LIMIT", substr_count($sql, 'LIMIT') === 1);

// ========================================================================
// TEST 6: compileSelect - Full query
// ========================================================================
section("Test 6: compileSelect - Full query");

[$sql, $params, $map] = SqlCompiler::compileSelect([
    SqlCompiler::SEL    => '*, COUNT(*) OVER() AS "_total"',
    SqlCompiler::FROM   => 'FROM "users"',
    SqlCompiler::WHERE  => '"users"."active" = ? AND "users"."role" = ?',
    SqlCompiler::GROUP  => '',
    SqlCompiler::HAVING => '',
    SqlCompiler::ORDER  => '"users"."name" ASC',
    SqlCompiler::LIMIT  => '20 OFFSET 0',
    SqlCompiler::PARAMS => [1, 'admin'],
]);

echo "  SQL: $sql\n";
assert_test("Contains COUNT OVER", strpos($sql, 'COUNT(*) OVER()') !== false);
assert_test("Contains WHERE", strpos($sql, 'WHERE') !== false);
assert_test("Contains ORDER BY", strpos($sql, 'ORDER BY') !== false);
assert_test("Contains LIMIT", strpos($sql, 'LIMIT 20') !== false);
assert_test("No double LIMIT", substr_count($sql, 'LIMIT') === 1);

// ========================================================================
// TEST 7: compileSelect - With GROUP BY and HAVING
// ========================================================================
section("Test 7: compileSelect - GROUP BY / HAVING");

[$sql, $params, $map] = SqlCompiler::compileSelect([
    SqlCompiler::SEL    => '"role", COUNT(*) as cnt',
    SqlCompiler::FROM   => 'FROM "users"',
    SqlCompiler::WHERE  => '"users"."active" = ?',
    SqlCompiler::GROUP  => '"role"',
    SqlCompiler::HAVING => 'COUNT(*) > ?',
    SqlCompiler::ORDER  => 'cnt DESC',
    SqlCompiler::LIMIT  => '',
    SqlCompiler::PARAMS => [1, 5],
]);

echo "  SQL: $sql\n";
assert_test("Contains GROUP BY", strpos($sql, 'GROUP BY') !== false);
assert_test("Contains HAVING", strpos($sql, 'HAVING') !== false);
assert_test("HAVING before ORDER BY", strpos($sql, 'HAVING') < strpos($sql, 'ORDER BY'));

// ========================================================================
// TEST 8: compileSelect - Array fields
// ========================================================================
section("Test 8: compileSelect - Array fields");

[$sql, $params, $map] = SqlCompiler::compileSelect([
    SqlCompiler::SEL    => ['id', 'name', 'email'],
    SqlCompiler::FROM   => 'FROM "users"',
    SqlCompiler::WHERE  => '',
    SqlCompiler::GROUP  => '',
    SqlCompiler::HAVING => '',
    SqlCompiler::ORDER  => '',
    SqlCompiler::LIMIT  => '',
    SqlCompiler::PARAMS => [],
]);

echo "  SQL: $sql\n";
assert_test("Array fields joined", strpos($sql, 'id, name, email') !== false);

// ========================================================================
// TEST 9: compileInsert
// ========================================================================
section("Test 9: compileInsert");

[$sql, $params] = SqlCompiler::compileInsert(
    [
        SqlCompiler::FROM   => '"users"',
        SqlCompiler::PARAMS => [],
    ],
    [
        ['name' => 'John', 'email' => 'john@test.com'],
        ['name' => 'Jane', 'email' => 'jane@test.com'],
    ]
);

echo "  SQL: $sql\n";
assert_test("INSERT with multiple rows", strpos($sql, 'INSERT INTO "users"') === 0);
assert_test("Has VALUES", strpos($sql, 'VALUES') !== false);
assert_test("2 rows = 4 params", count($params) === 4);
assert_test("Params contain names", in_array('John', $params) && in_array('Jane', $params));

// ========================================================================
// TEST 10: compileInsert - Single row
// ========================================================================
section("Test 10: compileInsert - Single row");

[$sql, $params] = SqlCompiler::compileInsert(
    [
        SqlCompiler::FROM   => '"products"',
        SqlCompiler::PARAMS => [],
    ],
    [
        'name' => 'Widget', 'price' => 9.99, 'stock' => 100,
    ]
);

echo "  SQL: $sql\n";
assert_test("Single row INSERT", strpos($sql, 'INSERT INTO "products"') === 0);
assert_test("3 columns", substr_count($sql, '?') === 3);
assert_test("Params count", count($params) === 3);

// ========================================================================
// TEST 11: compileUpdate
// ========================================================================
section("Test 11: compileUpdate");

[$sql, $params] = SqlCompiler::compileUpdate(
    [
        SqlCompiler::FROM   => '"users"',
        SqlCompiler::WHERE  => '"users"."id" = ?',
        SqlCompiler::PARAMS => [42],
    ],
    ['name' => 'Updated', 'email' => 'updated@test.com']
);

echo "  SQL: $sql\n";
assert_test("UPDATE with SET", strpos($sql, 'UPDATE "users" SET') === 0);
assert_test("Has WHERE", strpos($sql, 'WHERE') !== false);
assert_test("SET params first, WHERE params last", $params === ['Updated', 'updated@test.com', 42]);

// ========================================================================
// TEST 12: compileDelete
// ========================================================================
section("Test 12: compileDelete");

[$sql, $params] = SqlCompiler::compileDelete([
    SqlCompiler::FROM   => '"users"',
    SqlCompiler::WHERE  => '"users"."active" = ?',
    SqlCompiler::PARAMS => [0],
]);

echo "  SQL: $sql\n";
assert_test("DELETE FROM", strpos($sql, 'DELETE FROM "users"') === 0);
assert_test("Has WHERE", strpos($sql, 'WHERE') !== false);
assert_test("Params", $params === [0]);

// ========================================================================
// TEST 13: compileCount
// ========================================================================
section("Test 13: compileCount");

[$sql, $params] = SqlCompiler::compileCount([
    SqlCompiler::FROM   => '"users"',
    SqlCompiler::WHERE  => '"users"."active" = ?',
    SqlCompiler::PARAMS => [1],
]);

echo "  SQL: $sql\n";
assert_test("SELECT COUNT(*)", strpos($sql, 'SELECT COUNT(*) FROM "users"') === 0);
assert_test("Has WHERE", strpos($sql, 'WHERE') !== false);

// ========================================================================
// TEST 14: compileExists
// ========================================================================
section("Test 14: compileExists");

[$sql, $params] = SqlCompiler::compileExists([
    SqlCompiler::FROM   => '"users"',
    SqlCompiler::WHERE  => '"users"."email" = ?',
    SqlCompiler::PARAMS => ['test@test.com'],
]);

echo "  SQL: $sql\n";
assert_test("SELECT EXISTS", strpos($sql, 'SELECT EXISTS(SELECT 1 FROM "users"') === 0);
assert_test("Has WHERE", strpos($sql, 'WHERE') !== false);

// ========================================================================
// TEST 15: Edge cases
// ========================================================================
section("Test 15: Edge cases");

[$sql, $params, $map] = SqlCompiler::compileSelect([
    SqlCompiler::SEL    => '*',
    SqlCompiler::FROM   => 'FROM "users"',
    SqlCompiler::WHERE  => '',
    SqlCompiler::GROUP  => '',
    SqlCompiler::HAVING => '',
    SqlCompiler::ORDER  => '',
    SqlCompiler::LIMIT  => '',
    SqlCompiler::PARAMS => [],
]);
assert_test("No WHERE when empty", strpos($sql, 'WHERE') === false);
assert_test("No LIMIT when empty", strpos($sql, 'LIMIT') === false);
assert_test("No ORDER BY when empty", strpos($sql, 'ORDER BY') === false);

// Empty insert
[$sql, $params] = SqlCompiler::compileInsert(
    [SqlCompiler::FROM => '"users"', SqlCompiler::PARAMS => []],
    []
);
assert_test("Empty insert returns empty SQL", empty($params));

// ========================================================================
// TEST 16: SQL Injection prevention
// ========================================================================
section("Test 16: SQL Injection prevention");

[$sql, $params] = SqlCompiler::compileSelect([
    SqlCompiler::SEL    => '*',
    SqlCompiler::FROM   => 'FROM "users"',
    SqlCompiler::WHERE  => '"users"."name" = ?',
    SqlCompiler::GROUP  => '',
    SqlCompiler::HAVING => '',
    SqlCompiler::ORDER  => '',
    SqlCompiler::LIMIT  => '',
    SqlCompiler::PARAMS => ["Robert'; DROP TABLE users;--"],
]);

echo "  SQL: $sql\n";
assert_test("Uses prepared statement placeholder", strpos($sql, '?') !== false);
assert_test("Malicious SQL is in params, not SQL", $params[0] === "Robert'; DROP TABLE users;--");
assert_test("DROP not in SQL", strpos(strtoupper($sql), 'DROP') === false);

// ========================================================================
// TEST 17: LIMIT with large values
// ========================================================================
section("Test 17: LIMIT with large values");

[$sql, $params, $map] = SqlCompiler::compileSelect([
    SqlCompiler::SEL    => '*',
    SqlCompiler::FROM   => 'FROM "users"',
    SqlCompiler::WHERE  => '',
    SqlCompiler::GROUP  => '',
    SqlCompiler::HAVING => '',
    SqlCompiler::ORDER  => '',
    SqlCompiler::LIMIT  => '1000 OFFSET 50000',
    SqlCompiler::PARAMS => [],
]);

echo "  SQL: $sql\n";
assert_test("Large LIMIT works", strpos($sql, 'LIMIT 1000') !== false);
assert_test("Large OFFSET works", strpos($sql, 'OFFSET 50000') !== false);
assert_test("No double LIMIT", substr_count($sql, 'LIMIT') === 1);

// ========================================================================
// TEST 18: MySQL backtick quoting
// ========================================================================
section("Test 18: MySQL backtick quoting");

ConditionMatrix::setDriver('mysql');

[$sql, $params, $map] = SqlCompiler::compileSelect([
    SqlCompiler::SEL    => '*',
    SqlCompiler::FROM   => 'FROM `users`',
    SqlCompiler::WHERE  => '`users`.`active` = ?',
    SqlCompiler::GROUP  => '',
    SqlCompiler::HAVING => '',
    SqlCompiler::ORDER  => '`users`.`name` ASC',
    SqlCompiler::LIMIT  => '10',
    SqlCompiler::PARAMS => [1],
]);

echo "  SQL: $sql\n";
assert_test("MySQL backticks in FROM", strpos($sql, 'FROM `users`') !== false);
assert_test("MySQL backticks in WHERE", strpos($sql, '`users`.`active`') !== false);
assert_test("No double LIMIT", substr_count($sql, 'LIMIT') === 1);

ConditionMatrix::setDriver('sqlite');

// ========================================================================
// TEST 19: Complex field expressions
// ========================================================================
section("Test 19: Complex field expressions");

[$sql, $params, $map] = SqlCompiler::compileSelect([
    SqlCompiler::SEL    => 'u.id, u.name, COUNT(p.id) AS post_count, MAX(p.created_at) AS latest',
    SqlCompiler::FROM   => 'FROM "users" u LEFT JOIN "posts" p ON u.id = p.user_id',
    SqlCompiler::WHERE  => 'u.active = ?',
    SqlCompiler::GROUP  => 'u.id, u.name',
    SqlCompiler::HAVING => 'COUNT(p.id) > ?',
    SqlCompiler::ORDER  => 'post_count DESC',
    SqlCompiler::LIMIT  => '10 OFFSET 0',
    SqlCompiler::PARAMS => [1, 3],
]);

echo "  SQL: $sql\n";
assert_test("JOIN in FROM", strpos($sql, 'LEFT JOIN') !== false);
assert_test("AS alias in SELECT", strpos($sql, 'AS post_count') !== false);
assert_test("Functions in SELECT", strpos($sql, 'COUNT(p.id)') !== false);
assert_test("GROUP BY", strpos($sql, 'GROUP BY') !== false);
assert_test("HAVING", strpos($sql, 'HAVING') !== false);
assert_test("Alias in ORDER BY", strpos($sql, 'post_count DESC') !== false);
assert_test("No double LIMIT", substr_count($sql, 'LIMIT') === 1);

// ========================================================================
// TEST 20: LIMIT keyword - no duplicates
// ========================================================================
section("Test 20: LIMIT keyword - no duplicates");

$testCases = [
    ['input' => '1 OFFSET 0',   'expect' => 1],
    ['input' => '10',            'expect' => 1],
    ['input' => '100 OFFSET 200','expect' => 1],
    ['input' => '50 OFFSET 0',   'expect' => 1],
    ['input' => '',              'expect' => 0],
];

foreach ($testCases as $tc) {
    [$sql, $params, $map] = SqlCompiler::compileSelect([
        SqlCompiler::SEL    => '*',
        SqlCompiler::FROM   => 'FROM "users"',
        SqlCompiler::WHERE  => '',
        SqlCompiler::GROUP  => '',
        SqlCompiler::HAVING => '',
        SqlCompiler::ORDER  => '',
        SqlCompiler::LIMIT  => $tc['input'],
        SqlCompiler::PARAMS => [],
    ]);
    
    $limitCount = substr_count($sql, 'LIMIT');
    $label = $tc['input'] ?: '(empty)';
    assert_test("LIMIT count = {$tc['expect']} for '$label'", $limitCount === $tc['expect']);
}

// ========================================================================
// RESULTS
// ========================================================================
echo "\n==================================================\n";
if ($failed === 0) {
    echo "RESULT: ALL TESTS PASSED\n";
    echo "SqlCompiler is working correctly!\n";
    exit(0);
} else {
    echo "RESULT: $failed TEST(S) FAILED\n";
    exit(1);
}