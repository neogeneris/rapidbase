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
echo "L3 PLAN CACHE TEST\n";
echo "==================================================\n\n";

// Setup - Use a temp file for SQLite since :memory: with persistent connections is tricky
$tempDb = tempnam(sys_get_temp_dir(), 'l3test_') . '.db';
DB::setup('sqlite:' . $tempDb, '', '', 'main');
DB::exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT)");
DB::exec("INSERT INTO users (name, email) VALUES ('Alice', 'alice@test.com')");
DB::exec("INSERT INTO users (name, email) VALUES ('Bob', 'bob@test.com')");

// Enable L3 Cache
SQL::setQueryCacheEnabled(true);
SQL::clearQueryCache();

$statsBefore = SQL::getQueryCacheStats();
echo "Initial Stats: " . json_encode($statsBefore) . "\n\n";

// --- Test 1: First Call (MISS) ---
echo "--- Test 1: First Call (Expected MISS) ---\n";
$result1 = SQL::buildSelect(['*'], 'users', [], [], [], [], [], 0, 10);
$stats1 = SQL::getQueryCacheStats();

if ($stats1['misses'] === 1 && $stats1['hits'] === 0) {
    echo "[OK] First call registered as MISS\n";
} else {
    echo "[FAIL] Expected 1 miss, got: " . json_encode($stats1) . "\n";
}

// --- Test 2: Second Call (HIT) ---
echo "\n--- Test 2: Second Call (Expected HIT) ---\n";
$result2 = SQL::buildSelect(['*'], 'users', [], [], [], [], [], 0, 10);
$stats2 = SQL::getQueryCacheStats();

if ($stats2['hits'] === 1 && $stats2['misses'] === 1) {
    echo "[OK] Second call registered as HIT\n";
} else {
    echo "[FAIL] Expected 1 hit, got: " . json_encode($stats2) . "\n";
}

// Verify plan consistency (returns array: [sql, params, projectionMap])
if (isset($result1[0]) && isset($result2[0]) && 
    $result1[0] === $result2[0] && 
    isset($result1[2]) && isset($result2[2]) &&
    $result1[2] === $result2[2]) {
    echo "[OK] Plan consistency verified (SQL + Map identical)\n";
} else {
    echo "[FAIL] Plans differ between calls\n";
    // Debug output
    echo "  Result1[0]: " . ($result1[0] ?? 'N/A') . "\n";
    echo "  Result2[0]: " . ($result2[0] ?? 'N/A') . "\n";
    echo "  Result1[2]: " . json_encode($result1[2] ?? null) . "\n";
    echo "  Result2[2]: " . json_encode($result2[2] ?? null) . "\n";
}

// --- Test 3: Disable Cache ---
echo "\n--- Test 3: Disable Cache (Expected No Count) ---\n";
SQL::setQueryCacheEnabled(false);
$result3 = SQL::buildSelect(['*'], 'users', [], [], [], [], [], 0, 10);
$stats3 = SQL::getQueryCacheStats();

// When cache is disabled, no hits or misses should be counted
if ($stats3['misses'] === 1 && $stats3['hits'] === 1) {
    echo "[OK] Cache bypass working (no stats counted when disabled)\n";
} else {
    echo "[FAIL] Expected 1 miss, 1 hit (no change), got: " . json_encode($stats3) . "\n";
}

// --- Test 4: Different Parameters (New MISS) ---
echo "\n--- Test 4: Different Parameters (Expected New MISS) ---\n";
SQL::setQueryCacheEnabled(true);
// Change page parameter instead of limit to ensure different cache key
$result4 = SQL::buildSelect(['*'], 'users', [], [], [], [], [], 1, 10); // Page changed to 1
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

$total = $finalStats['hits'] + $finalStats['misses'];
$expectedHitRate = ($total > 0) ? ($finalStats['hits'] / $total) * 100 : 0;
echo "Hit Rate: " . round($expectedHitRate, 2) . "%\n";

// Expected: 1 hit (test2), 2 misses (test1 + test4 new params)
if ($finalStats['hits'] === 1 && $finalStats['misses'] === 2) {
    echo "[OK] Statistics are accurate\n";
} else {
    echo "[FAIL] Statistics mismatch (expected 1 hit, 2 misses)\n";
}

// Cleanup
SQL::clearQueryCache();
RapidBase\Core\Conn::close();
unlink($tempDb); // Clean up temp file

echo "\n==================================================\n";
echo "L3 Cache Test Completed.\n";
echo "==================================================\n";
