<?php

require_once __DIR__ . '/bootstrap.php';

use RapidBase\Core\X;
use RapidBase\Core\XResponse;

// Setup
\RapidBase\Core\DB::setup('sqlite::memory:', '', '', 'test_x');
$pdo = \RapidBase\Core\Conn::get('test_x');
$pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT)");
$pdo->exec("INSERT INTO users (name, email) VALUES ('Alice', 'alice@test.com'), ('Bob', 'bob@test.com')");

$x = X::con('test_x');
$pass = true;

// ------------------------------------------------------------------
// 1. select() básico (sin paginación)
// ------------------------------------------------------------------
$res = $x->from('users')->select();
if (!($res instanceof XResponse)) {
    echo "[ERROR] select() should return XResponse\n";
    $pass = false;
}
if (count($res->data) !== 2) {
    echo "[ERROR] select() should return 2 rows, got " . count($res->data) . "\n";
    $pass = false;
}
if ($res->total !== 2) {
    echo "[ERROR] total should be 2, got {$res->total}\n";
    $pass = false;
}
if ($res->columns !== ['id', 'name', 'email']) {
    echo "[ERROR] columns mismatch\n";
    $pass = false;
}
if ($res->titles !== ['Id', 'Name', 'Email']) {
    echo "[ERROR] titles mismatch\n";
    $pass = false;
}
if (strpos($res->sql, 'SELECT') === false) {
    echo "[ERROR] sql should contain SELECT\n";
    $pass = false;
}
if ($res->durationMs <= 0) {
    echo "[ERROR] durationMs should be > 0\n";
    $pass = false;
}
if ($res->page < 1) {
    echo "[ERROR] page should be >= 1, got {$res->page}\n";
    $pass = false;
}
if ($res->limit < 1) {
    echo "[ERROR] limit should be >= 1, got {$res->limit}\n";
    $pass = false;
}
if ($res->lastPage !== 1) {
    echo "[ERROR] lastPage should be 1, got {$res->lastPage}\n";
    $pass = false;
}
if ($res->nextPage !== null) {
    echo "[ERROR] nextPage should be null, got {$res->nextPage}\n";
    $pass = false;
}
if ($res->prevPage !== null) {
    echo "[ERROR] prevPage should be null, got {$res->prevPage}\n";
    $pass = false;
}
if ($res->success !== true) {
    echo "[ERROR] success should be true\n";
    $pass = false;
}
if ($pass) echo "  [OK] basic select()\n";

// ------------------------------------------------------------------
// 2. select() con paginación [0, 1]
// ------------------------------------------------------------------
$resPage1 = $x->from('users')->select('*', [0, 1]);
if (count($resPage1->data) !== 1) {
    echo "[ERROR] page 1 should have 1 row, got " . count($resPage1->data) . "\n";
    $pass = false;
}
if ($resPage1->total !== 2) {
    echo "[ERROR] total should still be 2, got {$resPage1->total}\n";
    $pass = false;
}
if ($resPage1->page !== 1) {
    echo "[ERROR] page should be 1, got {$resPage1->page}\n";
    $pass = false;
}
if ($resPage1->limit !== 1) {
    echo "[ERROR] limit should be 1, got {$resPage1->limit}\n";
    $pass = false;
}
if ($resPage1->lastPage !== 2) {
    echo "[ERROR] lastPage should be 2, got {$resPage1->lastPage}\n";
    $pass = false;
}
if ($resPage1->nextPage !== 2) {
    echo "[ERROR] nextPage should be 2, got {$resPage1->nextPage}\n";
    $pass = false;
}
if ($resPage1->prevPage !== null) {
    echo "[ERROR] prevPage should be null, got {$resPage1->prevPage}\n";
    $pass = false;
}
if ($pass) echo "  [OK] paginated select()\n";

// ------------------------------------------------------------------
// 3. select() con ordenamiento
// ------------------------------------------------------------------
$resSorted = $x->from('users')->select('*', null, '-name');
if ($resSorted->data[0][1] === 'Bob') {
    echo "  [OK] sorted select()\n";
} else {
    echo "[ERROR] sorted select() - expected Bob first\n";
    $pass = false;
}

// ------------------------------------------------------------------
// 4. first()
// ------------------------------------------------------------------
$first = $x->from('users')->first();
if ($first !== null && $first['name'] === 'Alice') {
    echo "  [OK] first()\n";
} else {
    echo "[ERROR] first() should be Alice\n";
    $pass = false;
}

// ------------------------------------------------------------------
// 5. first() sin resultados
// ------------------------------------------------------------------
$empty = $x->from('users', ['id' => 999])->first();
if ($empty === null) {
    echo "  [OK] first() empty\n";
} else {
    echo "[ERROR] first() empty should be null\n";
    $pass = false;
}

// ------------------------------------------------------------------
// 6. grid()
// ------------------------------------------------------------------
$grid = $x->from('users')->grid('*', 1, 10);
if ($grid['data'] !== $res->data) {
    echo "[ERROR] grid data mismatch\n";
    $pass = false;
}
if ($grid['total'] !== 2) {
    echo "[ERROR] grid total should be 2, got {$grid['total']}\n";
    $pass = false;
}
if (!isset($grid['debug']['sql'])) {
    echo "[ERROR] grid should have debug.sql\n";
    $pass = false;
}
if (!isset($grid['stats']['duration'])) {
    echo "[ERROR] grid should have stats.duration\n";
    $pass = false;
} else {
    echo "  [OK] grid()\n";
}

// ------------------------------------------------------------------
// Resultado final
// ------------------------------------------------------------------
if ($pass) {
    echo "All XSelectTest passed.\n";
} else {
    echo "Some XSelectTest failed.\n";
    exit(1);
}