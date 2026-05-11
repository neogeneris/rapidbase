<?php

/**
 * Gateway SelectTest - Integration with GroupBy, metadata verification,
 * and both pagination modes (Q::page and [offset, limit]).
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use RapidBase\Core\Conn;
use RapidBase\Core\Gateway;
use RapidBase\Core\SQL\Q;
use RapidBase\Core\SQL\ConditionMatrix;

// -- Setup --
$tempDb = tempnam(sys_get_temp_dir(), "rapidbase_test_") . ".sqlite";
Conn::setup("sqlite:$tempDb", "", "", "main");
$pdo = Conn::get("main");
$pdo->exec("CREATE TABLE leads (id INTEGER PRIMARY KEY, source TEXT, status TEXT)");
$pdo->exec("INSERT INTO leads (source, status) VALUES ('Facebook', 'pending'), ('Facebook', 'approved'), ('Google', 'pending')");

ConditionMatrix::setDriver('sqlite');

// -- Assertion helper --
function assert_select($name, $assertion, $details = "") {
    if ($assertion) {
        echo "  [OK] $name\n";
    } else {
        echo "  [FAIL] $name\n";
        if ($details) echo "    Detail: $details\n";
        $status = Gateway::status();
        echo "    Generated SQL: " . ($status["sql"] ?? "N/A") . "\n";
        exit(1);
    }
}

echo "=== Gateway Select with GroupBy ===\n";

// -- 1. GROUP BY without pagination --
$result = Gateway::select(
    "source, COUNT(*) as total",
    "leads",
    [],
    ["source"],
    [], [], null, false, \PDO::FETCH_ASSOC
);

$data = $result["data"] ?? [];
assert_select("GroupBy count by source", count($data) === 2, "Expected 2 groups");

$facebook = array_filter($data, fn($r) => $r["source"] === "Facebook");
$facebook = reset($facebook);
assert_select("Data validation (Facebook)", $facebook && $facebook["total"] == 2);

// -- 2. GROUP BY with WHERE --
$resultFiltered = Gateway::select(
    "source, COUNT(*) as total",
    "leads",
    ["status" => "pending"],
    ["source"],
    [], [], null, false, \PDO::FETCH_ASSOC
);

$dataFiltered = $resultFiltered["data"] ?? [];
assert_select("GroupBy with WHERE filter", count($dataFiltered) === 2);
$statusGroupBy = Gateway::status();
$totalPending = array_sum(array_column($dataFiltered, "total"));
assert_select("Total pending records", $totalPending == 2);

// -- 3. Pagination with Q::page() --
echo "\n--- Pagination with Q::page() ---\n";
$resultPaged = Gateway::select(
    "*",
    "leads",
    [],
    [], [], [], Q::page(1, 2), false, \PDO::FETCH_ASSOC
);

$dataPaged = $resultPaged["data"] ?? [];
assert_select("Page 1 returns 2 records", count($dataPaged) === 2);
assert_select("Page 1 has LIMIT 2", $resultPaged["limit"] === 2);
assert_select("Page number is 1", $resultPaged["page"] === 1);

// -- 4. Pagination with [offset, limit] (infinite scroll style) --
echo "\n--- Pagination with [offset, limit] ---\n";
$resultOffset = Gateway::select(
    "*",
    "leads",
    [], [], [], [], [1, 2], false, \PDO::FETCH_ASSOC
);

$dataOffset = $resultOffset["data"] ?? [];
assert_select("Offset 1 returns 2 records", count($dataOffset) === 2);
assert_select("Limit is 2", $resultOffset["limit"] === 2);
// Offset 1, limit 2 -> page = floor(1/2) + 1 = 1
assert_select("Page calculated from offset is 1", $resultOffset["page"] === 1);

// -- 5. Metadata verification (columns) --
$metadata = $resultFiltered['metadata'] ?? [];
assert_select("Metadata contains columns", !empty($metadata['cols']), "cols is empty");
assert_select("Correct columns", $metadata['cols'] === ['source', 'total'], "Got: " . json_encode($metadata['cols']));

// -- 6. SQL syntax verification --
$status = Gateway::status();
$status = $statusGroupBy;

assert_select("SQL contains GROUP BY", str_contains(strtoupper($status["sql"]), "GROUP BY"));
assert_select("Parameters in status", isset($status["params"][0]) && $status["params"][0] === "pending");

echo "\nGateway::select handles grouping, filters, pagination and metadata correctly.\n";

// -- Cleanup --
if (isset($tempDb) && file_exists($tempDb)) {
    @unlink($tempDb);
    @unlink($tempDb . "-wal");
    @unlink($tempDb . "-shm");
}