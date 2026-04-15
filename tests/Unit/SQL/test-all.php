<?php
// RapidBase/tests/test_all.php
include_once "../../../src/RapidBase/Core/SQL.php";

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
    echo "\n--- Ejecutando: $test ---\n";
	Core\SQL::reset();
    include __DIR__ . '/' . $test;
	
}

echo "\nFelicidades, la fundición SQL está lista para producción.\n";
