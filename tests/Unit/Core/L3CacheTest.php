<?php
/**
 * L3 Plan Cache Test
 * Validates the memoization layer for SQL query plans.
 */

// Simple autoloader for testing without composer
spl_autoload_register(function ($class) {
    $prefix = 'RapidBase\\';
    $base_dir = __DIR__ . '/../../../src/RapidBase/';
    
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    
    if (file_exists($file)) {
        require $file;
    }
});

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

// Verify plan consistency
if ($result1['sql'] === $result2['sql'] && $result1['projectionMap'] === $result2['projectionMap']) {
    echo "[OK] Plan consistency verified (SQL + Map identical)\n";
} else {
    echo "[FAIL] Plans differ between calls\n";
}

// --- Test 3: Disable Cache ---
echo "\n--- Test 3: Disable Cache (Expected MISS) ---\n";
SQL::setQueryCacheEnabled(false);
$result3 = SQL::buildSelect(['*'], 'users', [], [], [], [], [], 0, 10);
$stats3 = SQL::getQueryCacheStats();

// Miss count should increase, hits should stay same
if ($stats3['misses'] === 2 && $stats3['hits'] === 1) {
    echo "[OK] Cache bypass working (registered as MISS)\n";
} else {
    echo "[FAIL] Expected 2 misses, got: " . json_encode($stats3) . "\n";
}

// --- Test 4: Different Parameters (New MISS) ---
echo "\n--- Test 4: Different Parameters (Expected New MISS) ---\n";
SQL::setQueryCacheEnabled(true);
$result4 = SQL::buildSelect(['*'], 'users', [], [], [], [], [], 0, 20); // Limit changed to 20
$stats4 = SQL::getQueryCacheStats();

if ($stats4['misses'] === 3 && $stats4['hits'] === 1) {
    echo "[OK] Parameter change detected (new MISS)\n";
} else {
    echo "[FAIL] Expected 3 misses, got: " . json_encode($stats4) . "\n";
}

// --- Test 5: Statistics Summary ---
echo "\n--- Test 5: Statistics Summary ---\n";
$finalStats = SQL::getQueryCacheStats();
echo "Final Stats: " . json_encode($finalStats) . "\n";

$total = $finalStats['hits'] + $finalStats['misses'];
$expectedHitRate = ($finalStats['hits'] / $total) * 100;
echo "Hit Rate: " . round($expectedHitRate, 2) . "%\n";

if ($finalStats['hits'] === 1 && $finalStats['misses'] === 3) {
    echo "[OK] Statistics are accurate\n";
} else {
    echo "[FAIL] Statistics mismatch\n";
}

// Cleanup
SQL::clearQueryCache();
DB::disconnect();
unlink($tempDb); // Clean up temp file

echo "\n==================================================\n";
echo "L3 Cache Test Completed.\n";
echo "==================================================\n";
