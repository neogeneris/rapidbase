<?php

require_once __DIR__ . '/../../../../src/RapidBase/Core/DBInterface.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/Conn.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/DB.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/Executor.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/Gateway.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SchemaMap.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/ConditionMatrix.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/Q.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/JoinResolver.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/SqlCompiler.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/SQL/CompiledQuery.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/Cache/CacheService.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/Cache/Adapters/DirectoryCacheAdapter.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/Cache/CountCache.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/X.php';
require_once __DIR__ . '/../../../../src/RapidBase/Core/XResponse.php';

use RapidBase\Core\X;

// Setup
\RapidBase\Core\Conn::add('test_x', 'sqlite::memory:');
\RapidBase\Core\Conn::select('test_x');
$pdo = \RapidBase\Core\Conn::get('test_x');
$pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT)");
$pdo->exec("INSERT INTO users (name, email) VALUES ('Alice', 'alice@test.com'), ('Bob', 'bob@test.com')");

$x = X::con('test_x');
$pass = true;

// Delete with condition
$res = $x->from('users', ['name' => 'Alice'])->delete();
if ($res->success !== true) {
    echo "[ERROR] delete should succeed\n";
    $pass = false;
}
if ($res->affected !== 1) {
    echo "[ERROR] affected should be 1\n";
    $pass = false;
}
$count = $x->from('users')->count();
if ($count !== 1) {
    echo "[ERROR] count should be 1 after delete\n";
    $pass = false;
}
if ($pass) echo "  [OK] delete()\n";

// Delete with limit
$resLimit = $x->from('users', ['name' => 'Bob'])->delete(1);
if ($resLimit->success !== true) {
    echo "[ERROR] limited delete should succeed\n";
    $pass = false;
}
if ($resLimit->affected !== 1) {
    echo "[ERROR] limited delete affected should be 1\n";
    $pass = false;
}
$count2 = $x->from('users')->count();
if ($count2 !== 0) {
    echo "[ERROR] count should be 0 after limited delete\n";
    $pass = false;
}
if ($pass) echo "  [OK] delete() with limit\n";

if ($pass) {
    echo "All XDeleteTest passed.\n";
} else {
    exit(1);
}	