<?php

require_once __DIR__ . '/bootstrap.php';

use RapidBase\Core\XResponse;

$res = new XResponse(
    data: [['id' => 1, 'name' => 'Alice']],
    sql: 'SELECT * FROM users',
    durationMs: 0.5,
    total: 1,
    page: 1,
    limit: 30,
    columns: ['id', 'name'],
    titles: ['Id', 'Name']
);

$pass = true;

if ($res->total !== 1) {
    echo "[ERROR] total should be 1, got {$res->total}\n";
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
// Corregido: data es asociativo
if (($res->data[0]['name'] ?? '') !== 'Alice') {
    echo "[ERROR] data should contain Alice\n";
    $pass = false;
}

$json = json_encode($res);
if (strpos($json, '"sql":"SELECT * FROM users"') !== false) {
    echo "  [OK] XResponse JSON\n";
} else {
    echo "[ERROR] XResponse JSON should contain sql\n";
    $pass = false;
}

// Test con limit = 0 (sin paginacion real)
$resNoLimit = new XResponse(
    data: [['id' => 1]],
    sql: 'SELECT * FROM users',
    durationMs: 0.1,
    total: 5,
    page: 1,
    limit: 0,
    columns: ['id'],
    titles: ['Id']
);

if ($resNoLimit->lastPage !== 1) {
    echo "[ERROR] lastPage with limit=0 should be 1, got {$resNoLimit->lastPage}\n";
    $pass = false;
}
if ($resNoLimit->nextPage !== null) {
    echo "[ERROR] nextPage with limit=0 should be null, got {$resNoLimit->nextPage}\n";
    $pass = false;
}
if ($resNoLimit->prevPage !== null) {
    echo "[ERROR] prevPage with limit=0 should be null, got {$resNoLimit->prevPage}\n";
    $pass = false;
}
if ($pass) echo "  [OK] XResponse with limit=0\n";

if ($pass) {
    echo "All XResponseTest passed.\n";
} else {
    echo "Some XResponseTest failed.\n";
    exit(1);
}