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

use RapidBase\Core\X;

// Setup
\RapidBase\Core\Conn::add('test_x', 'sqlite::memory:');
\RapidBase\Core\Conn::select('test_x');
$pdo = \RapidBase\Core\Conn::get('test_x');
$pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)");
$pdo->exec("INSERT INTO users (name) VALUES ('Alice'), ('Bob'), ('Charlie')");

$x = X::con('test_x');
$pass = true;

$total = $x->from('users')->count();
if ($total !== 3) {
    echo "[ERROR] count should be 3, got $total\n";
    $pass = false;
}
$filtered = $x->from('users', ['name' => 'Alice'])->count();
if ($filtered !== 1) {
    echo "[ERROR] filtered count should be 1\n";
    $pass = false;
}
$empty = $x->from('users', ['name' => 'Nadie'])->count();
if ($empty !== 0) {
    echo "[ERROR] empty count should be 0\n";
    $pass = false;
}
if ($pass) echo "  [OK] count()\n";

if ($pass) {
    echo "All XCountTest passed.\n";
} else {
    exit(1);
}