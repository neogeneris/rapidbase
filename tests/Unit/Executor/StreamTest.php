<?php
namespace Tests\Unit\Executor;

require_once __DIR__ . "/../../../src/RapidBase/Core/Conn.php";
require_once __DIR__ . "/../../../src/RapidBase/Core/Executor.php";

use RapidBase\Core\Conn;
use RapidBase\Core\Executor;

echo "--- Test: Executor::stream (Cursor) ---\n";

Conn::setup("sqlite::memory:", "", "", "test");
$pdo = Conn::get("test");
$pdo->exec("CREATE TABLE data (val TEXT)");

// Insertamos varios
foreach (['A', 'B', 'C'] as $v) Executor::action("INSERT INTO data VALUES (?)", [$v]);

$cursor = Executor::stream( "SELECT * FROM data", []);

$results = [];
foreach ($cursor as $row) {
    $results[] = $row['val'];
}

if (count($results) === 3 && $results[1] === 'B') {
    echo "[OK] Cursor iterado correctamente (Streaming activo).\n";
} else {
    echo "[FAIL] El generador no devolvió los datos esperados.\n";
    exit(1);
}