<?php
/**
 * Q::having() Tests
 * 
 * Tests for the having() method and HAVING clause generation.
 */

require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/Q.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/JoinResolver.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/ConditionMatrix.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/ConditionParser.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/SqlCompiler.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SchemaMap.php';

use RapidBase\Core\SQL\Q;

echo "--- Ejecutando: QHavingTest.php ---\n\n";

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

// Test 1: Simple HAVING with equality
try {
    $result = Q::from('users')->groupBy('status')->having(['total' => 5])->select();
    assert_sql("Simple HAVING with equality", 
        "SELECT * FROM `users` GROUP BY `status` HAVING `total` = :p0", 
        $result
    );
    assert_test("  - Has correct params", $result[1] === ['p0' => 5]);
} catch (Throwable $e) {
    assert_test("Simple HAVING with equality", false, $e->getMessage());
}

// Test 2: HAVING with greater than operator
try {
    $result = Q::from('users')->groupBy('status')->having(['total' => ['>' => 5]])->select();
    assert_sql("HAVING with greater than operator", 
        "SELECT * FROM `users` GROUP BY `status` HAVING `total` > :p0", 
        $result
    );
    assert_test("  - Has correct params", $result[1] === ['p0' => 5]);
} catch (Throwable $e) {
    assert_test("HAVING with greater than operator", false, $e->getMessage());
}

// Test 3: HAVING with less than operator
try {
    $result = Q::from('users')->groupBy('status')->having(['count' => ['<' => 10]])->select();
    assert_sql("HAVING with less than operator", 
        "SELECT * FROM `users` GROUP BY `status` HAVING `count` < :p0", 
        $result
    );
    assert_test("  - Has correct params", $result[1] === ['p0' => 10]);
} catch (Throwable $e) {
    assert_test("HAVING with less than operator", false, $e->getMessage());
}

// Test 4: HAVING with greater than or equal operator
try {
    $result = Q::from('users')->groupBy('status')->having(['total' => ['>=' => 3]])->select();
    assert_sql("HAVING with greater than or equal operator", 
        "SELECT * FROM `users` GROUP BY `status` HAVING `total` >= :p0", 
        $result
    );
    assert_test("  - Has correct params", $result[1] === ['p0' => 3]);
} catch (Throwable $e) {
    assert_test("HAVING with greater than or equal operator", false, $e->getMessage());
}

// Test 5: HAVING with multiple conditions
try {
    $result = Q::from('users')->groupBy('status')->having([
        'total' => ['>' => 5],
        'avg_age' => ['<' => 50]
    ])->select();
    assert_sql("HAVING with multiple conditions", 
        "SELECT * FROM `users` GROUP BY `status` HAVING `total` > :p0 AND `avg_age` < :p1", 
        $result
    );
    assert_test("  - Has correct params count", count($result[1]) === 2);
} catch (Throwable $e) {
    assert_test("HAVING with multiple conditions", false, $e->getMessage());
}

// Test 6: Complete query with GROUP BY and HAVING
try {
    $result = Q::from('orders')
        ->groupBy('customer_id')
        ->having(['total_amount' => ['>' => 1000]])
        ->orderBy('-total_amount')
        ->select(['customer_id', 'SUM(amount) as total_amount']);
    assert_sql("Complete query with GROUP BY and HAVING", 
        "SELECT `customer_id`, SUM(amount) as total_amount FROM `orders` GROUP BY `customer_id` HAVING `total_amount` > :p0 ORDER BY `total_amount` DESC", 
        $result
    );
    assert_test("  - Has correct params", $result[1] === ['p0' => 1000]);
} catch (Throwable $e) {
    assert_test("Complete query with GROUP BY and HAVING", false, $e->getMessage());
}

// Test 7: HAVING without GROUP BY (should still work)
try {
    $result = Q::from('users')->having(['total' => ['>' => 5]])->select();
    assert_sql("HAVING without GROUP BY", 
        "SELECT * FROM `users` HAVING `total` > :p0", 
        $result
    );
} catch (Throwable $e) {
    assert_test("HAVING without GROUP BY", false, $e->getMessage());
}

echo "\n";
echo "Results: $passed passed, $failed failed\n";

if ($failed > 0) {
    exit(1);
}
