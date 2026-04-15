<?php
namespace Tests\Unit\Executor;

require_once __DIR__ . "/../../../src/RapidBase/Core/Conn.php";
require_once __DIR__ . "/../../../src/RapidBase/Core/Executor.php";

use RapidBase\Core\Conn;
use RapidBase\Core\Executor;

echo "--- Test: Executor::batch (Bulk Insert) ---\n";

Conn::setup("sqlite::memory:", "", "", "test");
$pdo = Conn::get("test");
$pdo->exec("CREATE TABLE logs (msg TEXT)");

$data = [
    ["Mensaje 1"],
    ["Mensaje 2"],
    ["Mensaje 3"]
];

$affected = Executor::batch("INSERT INTO logs (msg) VALUES (?)", $data);

if ($affected === 3) {
    echo "[OK] Batch procesó las 3 filas en una sola transacción.\n";
} else {
    echo "[FAIL] Cantidad de filas afectadas incorrecta: $affected\n";
    exit(1);
}