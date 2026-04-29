<?php
/**
 * Q::groupBy() Tests
 * 
 * Tests for the groupBy() method and GROUP BY clause generation.
 */

require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/Q.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/JoinResolver.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/ConditionMatrix.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/ConditionParser.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/SqlCompiler.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SchemaMap.php';

use RapidBase\Core\SQL\Q;

echo "--- Ejecutando: QGroupByTest.php ---\n\n";

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

// Test 1: Single field GROUP BY using fluent interface
try {
    $result = Q::from('users')->groupBy('status')->select();
    assert_sql("Single field GROUP BY using fluent interface", 
        "SELECT * FROM `users` GROUP BY `status`", 
        $result
    );
} catch (Throwable $e) {
    assert_test("Single field GROUP BY using fluent interface", false, $e->getMessage());
}

// Test 2: Multiple fields GROUP BY using array
try {
    $result = Q::from('users')->groupBy(['status', 'country'])->select();
    assert_sql("Multiple fields GROUP BY using array", 
        "SELECT * FROM `users` GROUP BY `status`, `country`", 
        $result
    );
} catch (Throwable $e) {
    assert_test("Multiple fields GROUP BY using array", false, $e->getMessage());
}

// Test 3: Multiple fields GROUP BY using string
try {
    $result = Q::from('users')->groupBy('status, country')->select();
    assert_sql("Multiple fields GROUP BY using string", 
        "SELECT * FROM `users` GROUP BY status, country", 
        $result
    );
} catch (Throwable $e) {
    assert_test("Multiple fields GROUP BY using string", false, $e->getMessage());
}

// Test 4: GROUP BY with aggregate function in select
try {
    $result = Q::from('users')->groupBy('status')->select(['status', 'COUNT(*) as total']);
    assert_sql("GROUP BY with aggregate function in select", 
        "SELECT `status`, COUNT(*) as total FROM `users` GROUP BY `status`", 
        $result
    );
} catch (Throwable $e) {
    assert_test("GROUP BY with aggregate function in select", false, $e->getMessage());
}

// Test 5: GROUP BY with WHERE condition
try {
    $result = Q::from('users', ['active' => 1])->groupBy('country')->select();
    assert_sql("GROUP BY with WHERE condition", 
        "SELECT * FROM `users` WHERE `active` = :p0 GROUP BY `country`", 
        $result
    );
    assert_test("  - Has correct params", $result[1] === ['p0' => 1]);
} catch (Throwable $e) {
    assert_test("GROUP BY with WHERE condition", false, $e->getMessage());
}

// Test 6: GROUP BY with ORDER BY
try {
    $result = Q::from('users')->groupBy('status')->orderBy('status')->select();
    assert_sql("GROUP BY with ORDER BY", 
        "SELECT * FROM `users` GROUP BY `status` ORDER BY `status` ASC", 
        $result
    );
} catch (Throwable $e) {
    assert_test("GROUP BY with ORDER BY", false, $e->getMessage());
}

// Test 7: GROUP BY with table prefix
try {
    $result = Q::from('users')->groupBy('u.status')->select();
    assert_sql("GROUP BY with table prefix", 
        "SELECT * FROM `users` GROUP BY `u`.`status`", 
        $result
    );
} catch (Throwable $e) {
    assert_test("GROUP BY with table prefix", false, $e->getMessage());
}

echo "\n";
echo "Results: $passed passed, $failed failed\n";

if ($failed > 0) {
    exit(1);
}
