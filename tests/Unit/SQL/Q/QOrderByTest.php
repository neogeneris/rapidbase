<?php
/**
 * Q::orderBy() Tests
 * 
 * Tests for the orderBy() method and ORDER BY clause generation.
 */

require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/Q.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/JoinResolver.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/ConditionMatrix.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/ConditionParser.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/SqlCompiler.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SchemaMap.php';

use RapidBase\Core\SQL\Q;

echo "--- Ejecutando: QOrderByTest.php ---\n\n";

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

// Test 1: Single field ASC using select parameter
try {
    $result = Q::from('users')->select('*', null, ['name']);
    assert_sql("Single field ASC using select parameter", 
        "SELECT * FROM `users` ORDER BY `name` ASC", 
        $result
    );
} catch (Throwable $e) {
    assert_test("Single field ASC using select parameter", false, $e->getMessage());
}

// Test 2: Single field DESC using select parameter
try {
    $result = Q::from('users')->select('*', null, ['-created_at']);
    assert_sql("Single field DESC using select parameter", 
        "SELECT * FROM `users` ORDER BY `created_at` DESC", 
        $result
    );
} catch (Throwable $e) {
    assert_test("Single field DESC using select parameter", false, $e->getMessage());
}

// Test 3: Multiple fields using select parameter
try {
    $result = Q::from('users')->select('*', null, ['name', '-created_at']);
    assert_sql("Multiple fields using select parameter", 
        "SELECT * FROM `users` ORDER BY `name` ASC, `created_at` DESC", 
        $result
    );
} catch (Throwable $e) {
    assert_test("Multiple fields using select parameter", false, $e->getMessage());
}

// Test 4: Single field ASC using fluent interface
try {
    $result = Q::from('users')->orderBy('name')->select();
    assert_sql("Single field ASC using fluent interface", 
        "SELECT * FROM `users` ORDER BY `name` ASC", 
        $result
    );
} catch (Throwable $e) {
    assert_test("Single field ASC using fluent interface", false, $e->getMessage());
}

// Test 5: Single field DESC using fluent interface
try {
    $result = Q::from('users')->orderBy('-created_at')->select();
    assert_sql("Single field DESC using fluent interface", 
        "SELECT * FROM `users` ORDER BY `created_at` DESC", 
        $result
    );
} catch (Throwable $e) {
    assert_test("Single field DESC using fluent interface", false, $e->getMessage());
}

// Test 6: Multiple fields using fluent interface (array)
try {
    $result = Q::from('users')->orderBy(['name', '-created_at'])->select();
    assert_sql("Multiple fields using fluent interface (array)", 
        "SELECT * FROM `users` ORDER BY `name` ASC, `created_at` DESC", 
        $result
    );
} catch (Throwable $e) {
    assert_test("Multiple fields using fluent interface (array)", false, $e->getMessage());
}

// Test 7: Multiple fields using fluent interface (string)
try {
    $result = Q::from('users')->orderBy('name, -created_at')->select();
    assert_sql("Multiple fields using fluent interface (string)", 
        "SELECT * FROM `users` ORDER BY `name` ASC, `created_at` DESC", 
        $result
    );
} catch (Throwable $e) {
    assert_test("Multiple fields using fluent interface (string)", false, $e->getMessage());
}

// Test 8: Select parameter overrides fluent interface
try {
    $result = Q::from('users')->orderBy('name')->select('*', null, ['-updated_at']);
    assert_sql("Select parameter overrides fluent interface", 
        "SELECT * FROM `users` ORDER BY `updated_at` DESC", 
        $result
    );
} catch (Throwable $e) {
    assert_test("Select parameter overrides fluent interface", false, $e->getMessage());
}

// Test 9: Field with table prefix
try {
    $result = Q::from('users')->select('*', null, ['u.name']);
    assert_sql("Field with table prefix", 
        "SELECT * FROM `users` ORDER BY `u`.`name` ASC", 
        $result
    );
} catch (Throwable $e) {
    assert_test("Field with table prefix", false, $e->getMessage());
}

echo "\n";
echo "Results: $passed passed, $failed failed\n";

if ($failed > 0) {
    exit(1);
}
