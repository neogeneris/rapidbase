<?php

namespace Tests\Unit\Gateway;

require_once __DIR__ . "/../../../src/RapidBase/Core/SQL.php";
require_once __DIR__ . "/../../../src/RapidBase/Core/Conn.php";
require_once __DIR__ . "/../../../src/RapidBase/Core/Executor.php";
require_once __DIR__ . "/../../../src/RapidBase/Core/Gateway.php";

use RapidBase\Core\Conn;
use RapidBase\Core\Gateway;
use RapidBase\Core\SQL;

echo "--- Ejecutando: Gateway SelectTest (Integración con GroupBy) ---\n";

function assert_select($name, $assertion, $details = "") {
    if ($assertion) {
        echo "\033[32m[OK]\033[0m $name\n";
    } else {
        echo "\033[31m[FAIL]\033[0m $name\n";
        if ($details) echo "  Detalle: $details\n";
        $status = Gateway::status();
        echo "  SQL generado: " . ($status["sql"] ?? "N/A") . "\n";
        exit(1);
    }
}

$tempDb = tempnam(sys_get_temp_dir(), "rapidbase_test_") . ".sqlite";
Conn::setup("sqlite:$tempDb", "", "", "main");
$pdo = Conn::get();
$pdo->exec("PRAGMA busy_timeout = 5000");
$pdo->exec("PRAGMA journal_mode = WAL");
$pdo->exec("DROP TABLE IF EXISTS leads");
$pdo->exec("CREATE TABLE leads (id INTEGER PRIMARY KEY, source TEXT, status TEXT)");

$pdo->exec("INSERT INTO leads (source, status) VALUES ('Facebook', 'pending'), ('Facebook', 'approved'), ('Google', 'pending')");

SQL::reset();

$result = Gateway::select(
    "source, COUNT(*) as total",
    "leads",
    [],
    ["source"],
    [], [], 1, false, \PDO::FETCH_ASSOC
);

$data = $result["data"] ?? [];
assert_select("Conteo agrupado por source", count($data) === 2);

$facebook = array_filter($data, fn($r) => $r["source"] === "Facebook");
$facebook = reset($facebook);
assert_select("Validación de datos (Facebook)", $facebook && $facebook["total"] == 2);

SQL::reset();
$resultFiltered = Gateway::select(
    "source, COUNT(*) as total",
    "leads",
    ["status" => "pending"],
    ["source"],
    [], [], 1, false, \PDO::FETCH_ASSOC
);

$dataFiltered = $resultFiltered["data"] ?? [];
assert_select("Group By con filtro WHERE", count($dataFiltered) === 2);

$totalPending = array_sum(array_column($dataFiltered, "total"));
assert_select("Suma total de registros filtrados", $totalPending == 2);

$status = Gateway::status();
assert_select("Sintaxis SQL (Cláusula GROUP BY)", str_contains(strtoupper($status["sql"]), "GROUP BY"));
assert_select("Persistencia de parámetros", isset($status["params"][0]) && $status["params"][0] === "pending");

echo "\n\033[32m[SUCCESS]\033[0m Gateway::select maneja agrupamientos y filtros correctamente.\n";

if (isset($tempDb) && file_exists($tempDb)) {
    @unlink($tempDb);
    @unlink($tempDb . "-wal");
    @unlink($tempDb . "-shm");
}