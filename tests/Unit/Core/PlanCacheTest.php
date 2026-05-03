<?php
/**
 * L3 Plan Cache Test
 * Validates the memoization layer for SQL query plans.
 */

// Load required classes manually (in dependency order)
require_once __DIR__ . '/../../../src/RapidBase/Core/DBInterface.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/Conn.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/QueryResponse.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/Executor.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/SQL.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/DB.php';

use RapidBase\Core\SQL;
use RapidBase\Core\DB;

echo "==================================================\n";
echo "Q PLAN CACHE TEST (L1 RAM)\n";
echo "==================================================\n\n";

// Setup - Use a temp file for SQLite since :memory: with persistent connections is tricky
$tempDb = tempnam(sys_get_temp_dir(), 'plantest_') . '.db';
DB::setup('sqlite:' . $tempDb, '', '', 'main');
DB::exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT)");
DB::exec("INSERT INTO users (name, email) VALUES ('Alice', 'alice@test.com')");
DB::exec("INSERT INTO users (name, email) VALUES ('Bob', 'bob@test.com')");

// Enable Q Plan Cache (L1)
SQL::setQueryCacheEnabled(true);
SQL::clearQueryCache();

$statsBefore = SQL::getQueryCacheStats();
echo "Initial Stats: " . json_encode($statsBefore) . "\n\n";

// --- Test 1: First Call (MISS) ---
echo "--- Test 1: First Call (Expected MISS) ---\n";
// Important: pagination must be non-empty or null to avoid the [] bug we fixed
$result1 = SQL::buildSelect(['*'], 'users', [], [], [], [], 1); 
$stats1 = SQL::getQueryCacheStats();

if ($stats1['misses'] === 1 && $stats1['hits'] === 0) {
    echo "[OK] First call registered as MISS\n";
} else {
    echo "[FAIL] Expected 1 miss, got: " . json_encode($stats1) . "\n";
}

// --- Test 2: Second Call (HIT) ---
echo "\n--- Test 2: Second Call (Expected HIT) ---\n";
$result2 = SQL::buildSelect(['*'], 'users', [], [], [], [], 1);
$stats2 = SQL::getQueryCacheStats();

if ($stats2['hits'] === 1 && $stats2['misses'] === 1) {
    echo "[OK] Second call registered as HIT\n";
} else {
    echo "[FAIL] Expected 1 hit, got: " . json_encode($stats2) . "\n";
}

// Verify plan consistency (returns array: [sql, params, projectionMap])
if (isset($result1[0]) && isset($result2[0]) && 
    $result1[0] === $result2[0]) {
    echo "[OK] Plan consistency verified (SQL identical)\n";
} else {
    echo "[FAIL] Plans differ between calls\n";
}

// --- Test 3: Disable Cache ---
echo "\n--- Test 3: Disable Cache (Expected No Change) ---\n";
SQL::setQueryCacheEnabled(false);
$result3 = SQL::buildSelect(['*'], 'users', [], [], [], [], 1);
$stats3 = SQL::getQueryCacheStats();

if ($stats3['misses'] === 1 && $stats3['hits'] === 1) {
    echo "[OK] Cache bypass working\n";
} else {
    echo "[FAIL] Stats changed while cache disabled: " . json_encode($stats3) . "\n";
}

// --- Test 4: Different Parameters (New MISS) ---
echo "\n--- Test 4: Different Parameters (Expected New MISS) ---\n";
SQL::setQueryCacheEnabled(true);
$result4 = SQL::buildSelect(['*'], 'users', [], [], [], [], 2); 
$stats4 = SQL::getQueryCacheStats();

if ($stats4['misses'] === 2 && $stats4['hits'] === 1) {
    echo "[OK] Parameter change detected (new MISS)\n";
} else {
    echo "[FAIL] Expected 2 misses, 1 hit, got: " . json_encode($stats4) . "\n";
}

// --- Test 5: Statistics Summary ---
echo "\n--- Test 5: Statistics Summary ---\n";
$finalStats = SQL::getQueryCacheStats();
echo "Final Stats: " . json_encode($finalStats) . "\n";

if ($finalStats['hits'] === 1 && $finalStats['misses'] === 2) {
    echo "[OK] Statistics are accurate\n";
} else {
    echo "[FAIL] Statistics mismatch\n";
}

// Cleanup
SQL::clearQueryCache();
// We must close the connection to release the file lock on Windows
RapidBase\Core\Conn::close('main');
if (file_exists($tempDb)) {
    @unlink($tempDb); 
}

echo "\n==================================================\n";
echo "Plan Cache Test Completed.\n";
echo "==================================================\n";
