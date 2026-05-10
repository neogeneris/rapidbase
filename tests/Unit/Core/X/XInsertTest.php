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

$x = X::con('test_x');
$pass = true;

// Insert
$res = $x->from('users')->insert(['name' => 'Carlos', 'email' => 'carlos@test.com']);
if ($res->success !== true) {
    echo "[ERROR] insert should succeed\n";
    $pass = false;
}
if ($res->lastId <= 0) {
    echo "[ERROR] lastId should be > 0\n";
    $pass = false;
}
if ($res->affected <= 0) {
    echo "[ERROR] affected should be > 0\n";
    $pass = false;
}
if ($pass) echo "  [OK] insert()\n";

// Count after insert
$count = $x->from('users')->count();
if ($count === 1) {
    echo "  [OK] count after insert\n";
} else {
    echo "[ERROR] count should be 1, got $count\n";
    $pass = false;
}

if ($pass) {
    echo "All XInsertTest passed.\n";
} else {
    exit(1);
}