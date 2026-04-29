<?php
/**
 * Q Class Unit Tests - Bootstrap
 * 
 * Execute all Q class unit tests.
 */

echo "==================================================\n";
echo "Q CLASS UNIT TESTS SUITE\n";
echo "==================================================\n\n";

$testDir = __DIR__;
$tests = [
    'QFromTest.php',
    'QSelectTest.php',
    'QInsertTest.php',
    'QUpdateTest.php',
    'QDeleteTest.php',
    'QCountTest.php',
    'QExistsTest.php',
    'QOrderByTest.php',
    'QPaginationTest.php',
    'QGroupByTest.php',
    'QHavingTest.php',
    'QJoinTest.php',
];

$passed = 0;
$failed = 0;

foreach ($tests as $test) {
    $testFile = $testDir . '/' . $test;
    if (file_exists($testFile)) {
        echo "Running: $test\n";
        // Execute each test in isolation to avoid function redeclaration
        $output = [];
        $returnVar = 0;
        exec("php " . escapeshellarg($testFile) . " 2>&1", $output, $returnVar);
        echo implode("\n", $output) . "\n";
        if ($returnVar === 0) {
            $passed++;
        } else {
            $failed++;
        }
        echo "\n";
    } else {
        echo "\033[33m[SKIP]\033[0m $test (not found)\n\n";
    }
}

echo "==================================================\n";
echo "SUMMARY: $passed passed, $failed failed\n";
echo "==================================================\n";

exit($failed > 0 ? 1 : 0);
