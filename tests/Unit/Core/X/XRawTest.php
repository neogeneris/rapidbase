<?php

require_once __DIR__ . '/bootstrap.php';

use RapidBase\Core\X;

// Setup
\RapidBase\Core\DB::setup('sqlite::memory:', '', '', 'test_x');
$pdo = \RapidBase\Core\Conn::get('test_x');
$pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)");
$pdo->exec("INSERT INTO users (name) VALUES ('Alice'), ('Bob')");

$x = X::con('test_x');
$pass = true;

// raw SELECT
$res = $x->raw("SELECT * FROM users");
if ($res->data[0][1] !== 'Alice') {
    echo "[ERROR] raw SELECT data mismatch\n";
    $pass = false;
}
if ($res->total !== 2) {
    echo "[ERROR] raw SELECT total should be 2\n";
    $pass = false;
}
if ($pass) echo "  [OK] raw() SELECT\n";

// raw INSERT
$resInsert = $x->raw("INSERT INTO users (name) VALUES ('Carlos')");
if ($resInsert->affected !== 1) {
    echo "[ERROR] raw INSERT affected should be 1\n";
    $pass = false;
}
if ($resInsert->success !== true) {
    echo "[ERROR] raw INSERT should succeed\n";
    $pass = false;
}
if ($pass) echo "  [OK] raw() INSERT\n";

// raw UPDATE
$resUpdate = $x->raw("UPDATE users SET name = 'Alicia' WHERE name = 'Alice'");
if ($resUpdate->affected !== 1) {
    echo "[ERROR] raw UPDATE affected should be 1\n";
    $pass = false;
}
if ($pass) echo "  [OK] raw() UPDATE\n";

// raw DELETE
$resDelete = $x->raw("DELETE FROM users WHERE name = 'Bob'");
if ($resDelete->affected !== 1) {
    echo "[ERROR] raw DELETE affected should be 1\n";
    $pass = false;
}
if ($pass) echo "  [OK] raw() DELETE\n";

if ($pass) {
    echo "All XRawTest passed.\n";
} else {
    echo "Some XRawTest failed.\n";
    exit(1);
}