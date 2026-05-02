<?php
/**
 * Test runner for Q class unit tests.
 * Executes all test files in the current directory.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0'); // Suppress warnings in output

// Shared assertion helpers with proper counting
class TestCounter {
    public static int $passed = 0;
    public static int $failed = 0;
}

function assertEq($actual, $expected, $message = ''): bool {
    if ($actual === $expected) {
        TestCounter::$passed++;
        echo "  ✓ $message\n";
        return true;
    }
    TestCounter::$failed++;
    echo "  ✗ $message\n";
    echo "    Expected: " . var_export($expected, true) . "\n";
    echo "    Got:      " . var_export($actual, true) . "\n";
    return false;
}

function assertContains($haystack, $needle, $message = ''): bool {
    if (strpos($haystack, $needle) !== false) {
        TestCounter::$passed++;
        echo "  ✓ $message\n";
        return true;
    }
    TestCounter::$failed++;
    echo "  ✗ $message\n";
    echo "    Expected to contain: $needle\n";
    echo "    Got: $haystack\n";
    return false;
}

function assertNotContains($haystack, $needle, $message = ''): bool {
    if (strpos($haystack, $needle) === false) {
        TestCounter::$passed++;
        echo "  ✓ $message\n";
        return true;
    }
    TestCounter::$failed++;
    echo "  ✗ $message\n";
    echo "    Expected NOT to contain: $needle\n";
    echo "    Got: $haystack\n";
    return false;
}

$testFiles = glob(__DIR__ . '/test_*.php');
sort($testFiles);

$totalTests = 0;
$passedTests = 0;
$failedTests = 0;

echo "\n";
echo "===========================================\n";
echo "       Q Class Unit Tests Runner\n";
echo "===========================================\n\n";

foreach ($testFiles as $file) {
    // Reset counters for each file
    TestCounter::$passed = 0;
    TestCounter::$failed = 0;
    
    echo "Running: " . basename($file) . "...\n";
    
    // Run test file
    include $file;
    
    $fileTotal = TestCounter::$passed + TestCounter::$failed;
    $totalTests += $fileTotal;
    $passedTests += TestCounter::$passed;
    $failedTests += TestCounter::$failed;
    
    if (TestCounter::$failed > 0) {
        echo "  ❌ FAILED: " . TestCounter::$passed . " passed, " . TestCounter::$failed . " failed\n";
    } else {
        echo "  ✅ PASSED: " . TestCounter::$passed . " tests\n";
    }
    echo "\n";
}

echo "===========================================\n";
echo "Summary:\n";
echo "  Total:  $totalTests\n";
echo "  Passed: $passedTests\n";
echo "  Failed: $failedTests\n";
echo "===========================================\n\n";

exit($failedTests > 0 ? 1 : 0);
