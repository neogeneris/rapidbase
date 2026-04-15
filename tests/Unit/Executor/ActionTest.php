<?php
namespace Tests\Unit\Executor;

require_once __DIR__ . "/../../../src/Core/Conn.php";
require_once __DIR__ . "/../../../src/Core/Executor.php";

use Core\Conn;
use Core\Executor;

echo "--- Test: Executor::action ---\n";

Conn::setup("sqlite::memory:", "", "", "test");
$pdo = Conn::get("test");
$pdo->exec("CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT)");

$res = Executor::action( "INSERT INTO items (name) VALUES (?)", ["Laptop"]);

if ($res['success'] && $res['count'] === 1 && $res['lastId'] == 1) {
    echo "[OK] Inserción y LastID correctos.\n";
} else {
    echo "[FAIL] Error en la respuesta de escritura.\n";
    exit(1);
}