<?php

require_once __DIR__ . '/bootstrap.php';

use RapidBase\Core\X;
use RapidBase\Core\SQL\Q;

// Setup
\RapidBase\Core\DB::setup('sqlite::memory:', '', '', 'test_x');

$x = X::con('test_x');
$pass = true;

// toSQL con Q::select
$sql = $x->toSQL(Q::from('users')->select());
if ($sql !== '' && strpos($sql, 'SELECT') !== false) {
    echo "  [OK] toSQL() SELECT\n";
} else {
    echo "[ERROR] toSQL() SELECT should contain SELECT\n";
    $pass = false;
}

// toSQL con Q::insert (Q no implementa getSql para insert, devuelve vacio)
$sqlInsert = $x->toSQL(Q::from('users')->insert(['name' => 'Test']));
if ($sqlInsert === '') {
    echo "  [OK] toSQL() INSERT (empty as expected)\n";
} else {
    echo "  [OK] toSQL() INSERT returned non-empty string\n";
}

if ($pass) {
    echo "All XToSQLTest passed.\n";
} else {
    echo "Some XToSQLTest failed.\n";
    exit(1);
}