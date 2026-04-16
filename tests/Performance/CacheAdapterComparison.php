<?php

/**
 * Cache Adapter Performance Comparison Benchmark
 * 
 * Compares all cache adapters: Memory, Redis, Memcached, SQLite Memory, Directory, Zip
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use RapidBase\Core\Cache\Adapters\MemoryCacheAdapter;
use RapidBase\Core\Cache\Adapters\RedisCacheAdapter;
use RapidBase\Core\Cache\Adapters\MemcachedCacheAdapter;
use RapidBase\Core\Cache\Adapters\SQLiteMemoryCacheAdapter;
use RapidBase\Core\Cache\Adapters\DirectoryCacheAdapter;

echo "==============================================\n";
echo "  CACHE ADAPTER PERFORMANCE BENCHMARK\n";
echo "==============================================\n\n";

$iterations = 10000;
$data = [
    'user_id' => 12345,
    'username' => 'test_user',
    'email' => 'test@example.com',
    'roles' => ['admin', 'user'],
    'metadata' => ['created' => time(), 'last_login' => time()]
];

$results = [];

// 1. Memory Cache Adapter
echo "Testing MemoryCacheAdapter...\n";
$memory = new MemoryCacheAdapter(['prefix' => 'bench_']);
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $memory->set("key_$i", $data, 3600);
    $memory->get("key_$i");
}
$time = (microtime(true) - $start) / $iterations * 1000;
$results['Memory'] = $time;
echo "✓ Memory: " . number_format($time, 4) . " ms/op\n\n";

// 2. Redis Cache Adapter
echo "Testing RedisCacheAdapter...\n";
try {
    $redis = new RedisCacheAdapter(['prefix' => 'bench_', 'host' => '127.0.0.1', 'port' => 6379]);
    $start = microtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $redis->set("key_$i", $data, 3600);
        $redis->get("key_$i");
    }
    $time = (microtime(true) - $start) / $iterations * 1000;
    $results['Redis'] = $time;
    echo "✓ Redis: " . number_format($time, 4) . " ms/op\n\n";
} catch (Exception $e) {
    echo "✗ Redis failed: " . $e->getMessage() . "\n\n";
}

// 3. Memcached Cache Adapter
echo "Testing MemcachedCacheAdapter...\n";
try {
    $memcached = new MemcachedCacheAdapter(['prefix' => 'bench_']);
    $start = microtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $memcached->set("key_$i", $data, 3600);
        $memcached->get("key_$i");
    }
    $time = (microtime(true) - $start) / $iterations * 1000;
    $results['Memcached'] = $time;
    echo "✓ Memcached: " . number_format($time, 4) . " ms/op\n\n";
} catch (Exception $e) {
    echo "✗ Memcached failed: " . $e->getMessage() . "\n\n";
}

// 4. SQLite Memory Cache Adapter
echo "Testing SQLiteMemoryCacheAdapter...\n";
$sqlite = new SQLiteMemoryCacheAdapter(['prefix' => 'bench_']);
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $sqlite->set("key_$i", $data, 3600);
    $sqlite->get("key_$i");
}
$time = (microtime(true) - $start) / $iterations * 1000;
$results['SQLite Memory'] = $time;
echo "✓ SQLite Memory: " . number_format($time, 4) . " ms/op\n\n";

// 5. Directory Cache Adapter
echo "Testing DirectoryCacheAdapter...\n";
$dir = new DirectoryCacheAdapter(sys_get_temp_dir() . '/rb_cache_bench', 3600);
$start = microtime(true);
for ($i = 0; $i < min($iterations, 1000); $i++) { // Limited to 1000 for disk I/O
    $dir->set("key_$i", $data, 3600);
    $result = $dir->get("key_$i");
}
$time = (microtime(true) - $start) / min($iterations, 1000) * 1000;
$results['Directory'] = $time;
echo "✓ Directory (1k ops): " . number_format($time, 4) . " ms/op\n\n";

// Results Summary
echo "==============================================\n";
echo "  RESULTS (milliseconds per operation)\n";
echo "==============================================\n";
asort($results);
foreach ($results as $adapter => $time) {
    $percentage = ($time / reset($results)) * 100;
    echo sprintf("%-20s: %8.4f ms (%.1f%%)\n", $adapter, $time, $percentage);
}

echo "\n==============================================\n";
echo "  CONCLUSION\n";
echo "==============================================\n";
$fastest = array_key_first($results);
echo "Fastest: $fastest (" . number_format(reset($results), 4) . " ms/op)\n";
echo "Use Memory for single-request caching\n";
echo "Use Redis/Memcached for distributed caching\n";
echo "Use SQLite Memory for SQL-based cache queries\n";
echo "Use Directory for persistent file-based caching\n";
