<?php

$dir = __DIR__;
$tests = [
    'XConnectTest.php',
    'XSelectTest.php',
    'XInsertTest.php',
    'XUpdateTest.php',
    'XDeleteTest.php',
    'XCountTest.php',
    'XRawTest.php',
    'XToSQLTest.php',
    'XResponseTest.php',
];

$failed = 0;

foreach ($tests as $test) {
    echo "Running $test...\n";
    ob_start();
    try {
        include "$dir/$test";
        $output = ob_get_clean();
        echo $output;
        if (strpos($output, '[ERROR]') !== false || strpos($output, 'Some') !== false) {
            $failed++;
            echo "  FAILED\n";
        }
    } catch (Throwable $e) {
        ob_end_clean();
        echo "  FATAL: " . $e->getMessage() . "\n";
        $failed++;
    }
    echo "\n";
}

if ($failed === 0) {
    echo "All X tests passed.\n";
} else {
    echo "$failed test(s) failed.\n";
    exit(1);
}