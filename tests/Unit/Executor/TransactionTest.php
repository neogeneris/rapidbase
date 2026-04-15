<?php
namespace Tests\Unit\Executor;

require_once __DIR__ . "/../../../src/Core/Conn.php";
require_once __DIR__ . "/../../../src/Core/Executor.php";

use Core\Conn;
use Core\Executor;

echo "--- Test: Executor::transaction (Atomicity) ---\n";

Conn::setup("sqlite::memory:", "", "", "test");
$pdo = Conn::get("test");
$pdo->exec("CREATE TABLE accounts (id INTEGER PRIMARY KEY, balance INT)");

Executor::action( "INSERT INTO accounts VALUES (1, 100)", []);

try {
    Executor::transaction( function($p) {
        Executor::action("UPDATE accounts SET balance = 50 WHERE id = 1", []);
        throw new \Exception("Error inesperado en mitad del proceso");
    });
} catch (\Exception $e) {
    $stmt = Executor::query( "SELECT balance FROM accounts WHERE id = 1");
    $row = $stmt->fetch();
    
    if ($row['balance'] == 100) {
        echo "[OK] Rollback exitoso: El balance no cambió tras el error.\n";
    } else {
        echo "[FAIL] La transacción no se revirtió.\n";
        exit(1);
    }
}