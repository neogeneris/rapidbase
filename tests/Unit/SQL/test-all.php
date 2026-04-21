<?php
// RapidBase/tests/test_all.php
require_once __DIR__."/../../../vendor/autoload.php";

$tests = [
    'BuildFromTest.php',
    'BuildWhereTest.php',
    'BuildWhereDeepTest.php',
    'BuildSelectTest.php',
    'BuildCountTest.php',
    'BuildCountDeepTest.php',
    'BuildExistsTest.php',
    'BuildInsertTest.php',
    'BuildUpdateTest.php',
    'BuildDeleteTest.php',

];

foreach ($tests as $test) {
    echo "\n--- Running: $test ---\n";
        \RapidBase\Core\SQL::reset();
    include __DIR__ . '/' . $test;

}

echo "\nCongratulations, the SQL foundry is ready for production.\n";
