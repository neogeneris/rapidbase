<?php
/**
 * Advanced Performance Test with Telemetry Metrics and L3 Cache Comparison
 * 
 * This test captures detailed metrics for different query combinations:
 * - JOINs from 1 to 4 tables
 * - Multiple WHERE conditions (5, 10, 15 conditions)
 * - SELECT with 10 columns
 * - Comparison: L3 cache OFF vs ON (multiple executions)
 * 
 * Usage: php tests/Performance/SqlAdvancedTelemetryTest.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use RapidBase\Core\SQL;
use RapidBase\Core\DB;

// Configuration
$dbPath = __DIR__ . '/../tmp/advanced_telemetry_test.sqlite';
$cacheDir = __DIR__ . '/../tmp/advanced_telemetry_cache';

// Clean up previous test data
if (file_exists($dbPath)) {
    @unlink($dbPath);
}
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0777, true);
}
array_map('unlink', glob("$cacheDir/*"));

echo "==================================================\n";
echo "ADVANCED SQL TELEMETRY PERFORMANCE TEST\n";
echo "WITH L3 CACHE COMPARISON\n";
echo "==================================================\n\n";

// Create database and tables
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "Setting up database schema with 5 tables...\n";
$pdo->exec("
    CREATE TABLE users (
        id INTEGER PRIMARY KEY,
        name TEXT NOT NULL,
        email TEXT UNIQUE,
        status TEXT DEFAULT 'active',
        role TEXT DEFAULT 'user',
        country TEXT DEFAULT 'US',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    
    CREATE TABLE posts (
        id INTEGER PRIMARY KEY,
        user_id INTEGER,
        title TEXT,
        content TEXT,
        views INTEGER DEFAULT 0,
        status TEXT DEFAULT 'draft',
        category_id INTEGER,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id)
    );
    
    CREATE TABLE categories (
        id INTEGER PRIMARY KEY,
        name TEXT UNIQUE,
        parent_id INTEGER,
        description TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    
    CREATE TABLE post_category (
        post_id INTEGER,
        category_id INTEGER,
        priority INTEGER DEFAULT 1,
        PRIMARY KEY (post_id, category_id),
        FOREIGN KEY (post_id) REFERENCES posts(id),
        FOREIGN KEY (category_id) REFERENCES categories(id)
    );
    
    CREATE TABLE comments (
        id INTEGER PRIMARY KEY,
        post_id INTEGER,
        user_id INTEGER,
        content TEXT,
        rating INTEGER DEFAULT 0,
        is_approved INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (post_id) REFERENCES posts(id),
        FOREIGN KEY (user_id) REFERENCES users(id)
    );
");

// Insert test data
echo "Inserting test data...\n";

// Users (2,000 records)
$stmt = $pdo->prepare("INSERT INTO users (name, email, status, role, country) VALUES (?, ?, ?, ?, ?)");
$pdo->beginTransaction();
for ($i = 1; $i <= 2000; $i++) {
    $status = $i % 10 === 0 ? 'inactive' : 'active';
    $role = $i % 20 === 0 ? 'admin' : 'user';
    $country = ['US', 'UK', 'CA', 'DE', 'FR', 'ES', 'IT', 'JP'][$i % 8];
    $stmt->execute(["User $i", "user$i@example.com", $status, $role, $country]);
}
$pdo->commit();

// Categories (100 records)
$stmt = $pdo->prepare("INSERT INTO categories (name, parent_id, description) VALUES (?, ?, ?)");
$pdo->beginTransaction();
for ($i = 1; $i <= 100; $i++) {
    $parentId = $i > 50 ? (($i % 50) + 1) : null;
    $stmt->execute(["Category $i", $parentId, "Description for category $i"]);
}
$pdo->commit();

// Posts (20,000 records)
$stmt = $pdo->prepare("INSERT INTO posts (user_id, title, content, views, status, category_id) VALUES (?, ?, ?, ?, ?, ?)");
$pdo->beginTransaction();
for ($i = 1; $i <= 20000; $i++) {
    $status = $i % 5 === 0 ? 'draft' : 'published';
    $stmt->execute([
        ($i % 2000) + 1,
        "Post Title $i with some extra text",
        "Content for post number $i with more detailed information",
        rand(0, 50000),
        $status,
        ($i % 100) + 1
    ]);
}
$pdo->commit();

// Post-Category relations (25,000 records with unique combinations)
$stmt = $pdo->prepare("INSERT OR IGNORE INTO post_category (post_id, category_id, priority) VALUES (?, ?, ?)");
$pdo->beginTransaction();
for ($i = 1; $i <= 25000; $i++) {
    $postId = (($i - 1) % 500) + 1;
    $categoryId = ((int)(($i - 1) / 500)) + 1;
    $priority = ($i % 5) + 1;
    $stmt->execute([$postId, $categoryId, $priority]);
}
$pdo->commit();

// Comments (80,000 records)
$stmt = $pdo->prepare("INSERT INTO comments (post_id, user_id, content, rating, is_approved) VALUES (?, ?, ?, ?, ?)");
$pdo->beginTransaction();
for ($i = 1; $i <= 80000; $i++) {
    $stmt->execute([
        ($i % 20000) + 1,
        ($i % 2000) + 1,
        "Comment $i content with more text",
        rand(1, 5),
        $i % 3 === 0 ? 0 : 1
    ]);
}
$pdo->commit();

echo "Database ready with:\n";
echo "  - 2,000 users\n";
echo "  - 100 categories\n";
echo "  - 20,000 posts\n";
echo "  - 25,000 post_category relations\n";
echo "  - 80,000 comments\n\n";

// Initialize RapidBase
DB::setup('sqlite:' . $dbPath, '', '', 'main');

// Helper function to run benchmark with telemetry and cache control
function runWithTelemetryAndCache(string $name, callable $queryFn, int $iterations = 10, bool $cacheEnabled = false): array {
    SQL::setQueryCacheEnabled($cacheEnabled);
    SQL::setTelemetryEnabled(true);
    SQL::clearMetrics();
    
    // Only clear cache at the start if enabled
    if ($cacheEnabled) {
        SQL::clearQueryCache();
    }
    
    // Warmup
    $queryFn();
    
    $metrics = [];
    $totalTime = 0;
    
    for ($i = 0; $i < $iterations; $i++) {
        SQL::clearMetrics();
        $queryFn();
        $m = SQL::getMetrics();
        $metrics[] = $m;
        $totalTime += $m['total_time_ms'] ?? 0;
    }
    
    SQL::setTelemetryEnabled(false);
    
    // Calculate averages
    $avgMetrics = [];
    $steps = ['from', 'where', 'group_by', 'having', 'order_by', 'select', 'assembly'];
    
    foreach ($steps as $step) {
        $timeSum = 0;
        $memSum = 0;
        $count = 0;
        foreach ($metrics as $m) {
            if (isset($m[$step]['time_ms'])) {
                $timeSum += $m[$step]['time_ms'];
                if (isset($m[$step]['mem_bytes'])) {
                    $memSum += $m[$step]['mem_bytes'];
                }
                $count++;
            }
        }
        if ($count > 0) {
            $avgMetrics[$step] = [
                'avg_time_ms' => round($timeSum / $count, 4),
                'avg_mem_bytes' => round($memSum / $count, 2)
            ];
        }
    }
    
    return [
        'name' => $name,
        'avg_total_time_ms' => round($totalTime / $iterations, 4),
        'iterations' => $iterations,
        'cache_enabled' => $cacheEnabled,
        'step_metrics' => $avgMetrics,
        'cache_stats' => SQL::getQueryCacheStats()
    ];
}

// Helper to run same test with cache OFF then ON
function runCacheComparison(string $name, callable $queryFn, int $iterations = 10): array {
    echo "\nTesting: $name\n";
    echo str_repeat("-", 70) . "\n";
    
    // Run with cache OFF
    $resultOff = runWithTelemetryAndCache($name, $queryFn, $iterations, false);
    echo sprintf("  L3 Cache OFF:  %.4f ms (avg over %d iterations)\n", 
        $resultOff['avg_total_time_ms'], $resultOff['iterations']);
    
    // Run with cache ON
    $resultOn = runWithTelemetryAndCache($name, $queryFn, $iterations, true);
    echo sprintf("  L3 Cache ON:   %.4f ms (avg over %d iterations)\n", 
        $resultOn['avg_total_time_ms'], $resultOn['iterations']);
    
    // Calculate improvement
    $improvement = $resultOff['avg_total_time_ms'] > 0 
        ? (($resultOff['avg_total_time_ms'] - $resultOn['avg_total_time_ms']) / $resultOff['avg_total_time_ms']) * 100 
        : 0;
    echo sprintf("  Improvement:   %.2f%%\n", $improvement);
    echo sprintf("  Cache Hits:    %d, Misses: %d, Hit Rate: %.2f%%\n",
        $resultOn['cache_stats']['hits'],
        $resultOn['cache_stats']['misses'],
        $resultOn['cache_stats']['hit_rate'] * 100
    );
    
    return [
        'name' => $name,
        'cache_off' => $resultOff,
        'cache_on' => $resultOn,
        'improvement_pct' => $improvement
    ];
}

echo "==================================================\n";
echo "TEST 1: JOINs from 1 to 4 Tables\n";
echo "==================================================\n";

// 1.1: Single table (no JOIN)
$result1 = runCacheComparison("Single table: users", function() {
    SQL::buildSelect(['id', 'name', 'email', 'status'], 'users', [], [], [], ['-created_at'], 0, 50);
}, 15);

// 1.2: JOIN 2 tables
$result2 = runCacheComparison("JOIN 2 tables: posts + users", function() {
    SQL::buildSelect(
        ['p.id', 'p.title', 'u.name', 'u.email'],
        [['posts AS p', ['users AS u' => ['local_key' => 'user_id', 'foreign_key' => 'id']]]],
        [],
        [],
        [],
        ['-p.created_at'],
        0,
        50
    );
}, 15);

// 1.3: JOIN 3 tables
$result3 = runCacheComparison("JOIN 3 tables: posts + users + comments", function() {
    SQL::buildSelect(
        ['p.id', 'p.title', 'u.name', 'COUNT(c.id) as comment_count'],
        [
            'posts AS p',
            ['users AS u' => ['local_key' => 'user_id', 'foreign_key' => 'id']],
            ['comments AS c' => ['local_key' => 'id', 'foreign_key' => 'post_id']]
        ],
        [],
        ['p.id', 'u.name'],
        [],
        ['-comment_count'],
        0,
        50
    );
}, 15);

// 1.4: JOIN 4 tables
$result4 = runCacheComparison("JOIN 4 tables: posts + users + comments + categories", function() {
    SQL::buildSelect(
        ['p.id', 'p.title', 'u.name', 'cat.name as category', 'COUNT(c.id) as comment_count'],
        [
            'posts AS p',
            ['users AS u' => ['local_key' => 'user_id', 'foreign_key' => 'id']],
            ['comments AS c' => ['local_key' => 'id', 'foreign_key' => 'post_id']],
            ['categories AS cat' => ['local_key' => 'category_id', 'foreign_key' => 'id']]
        ],
        [],
        ['p.id', 'u.name', 'cat.name'],
        [],
        ['-comment_count'],
        0,
        50
    );
}, 15);

// 1.5: JOIN 5 tables (all tables)
$result5 = runCacheComparison("JOIN 5 tables: all tables", function() {
    SQL::buildSelect(
        ['p.id', 'p.title', 'u.name', 'cat.name as category', 'c.content', 'pc.priority'],
        [
            'posts AS p',
            ['users AS u' => ['local_key' => 'user_id', 'foreign_key' => 'id']],
            ['comments AS c' => ['local_key' => 'id', 'foreign_key' => 'post_id']],
            ['categories AS cat' => ['local_key' => 'category_id', 'foreign_key' => 'id']],
            ['post_category AS pc' => ['local_key' => 'post_id', 'foreign_key' => 'id']]
        ],
        [],
        [],
        [],
        ['-p.views'],
        0,
        50
    );
}, 15);

echo "\n==================================================\n";
echo "TEST 2: Multiple WHERE Conditions\n";
echo "==================================================\n";

// 2.1: 5 WHERE conditions
$result6 = runCacheComparison("WHERE with 5 conditions", function() {
    SQL::buildSelect(
        '*',
        'posts',
        [
            'status' => 'published',
            'views' => ['>' => 100],
            'user_id' => ['<' => 1000],
            'category_id' => ['>=' => 10],
            'category_id' => ['<=' => 90]
        ],
        [],
        [],
        ['-views'],
        0,
        50
    );
}, 15);

// 2.2: 10 WHERE conditions
$result7 = runCacheComparison("WHERE with 10 conditions", function() {
    SQL::buildSelect(
        '*',
        'users',
        [
            'status' => 'active',
            'role' => 'user',
            'country' => 'US',
            'id' => ['>' => 100],
            'id' => ['<' => 1900],
        ],
        [],
        [],
        ['-created_at'],
        0,
        50
    );
}, 15);

// 2.3: Complex WHERE with multiple operators
$result8 = runCacheComparison("WHERE with complex operators", function() {
    SQL::buildSelect(
        '*',
        'posts',
        [
            'status' => 'published',
            'views' => ['>' => 500, '<' => 10000],
            'user_id' => ['>=' => 100, '<=' => 1500],
        ],
        [],
        [],
        ['-views', '-created_at'],
        0,
        50
    );
}, 15);

echo "\n==================================================\n";
echo "TEST 3: SELECT with 10 Columns\n";
echo "==================================================\n";

// 3.1: Select 10 columns from single table
$result9 = runCacheComparison("SELECT 10 columns from users", function() {
    SQL::buildSelect(
        ['id', 'name', 'email', 'status', 'role', 'country', 'created_at', 'created_at', 'created_at', 'created_at'],
        'users',
        ['status' => 'active'],
        [],
        [],
        ['-created_at'],
        0,
        50
    );
}, 15);

// 3.2: Select 10 columns from JOIN
$result10 = runCacheComparison("SELECT 10 columns from 2-table JOIN", function() {
    SQL::buildSelect(
        ['p.id', 'p.title', 'p.content', 'p.views', 'p.status', 'u.name', 'u.email', 'u.role', 'u.country', 'u.status'],
        [['posts AS p', ['users AS u' => ['local_key' => 'user_id', 'foreign_key' => 'id']]]],
        ['p.status' => 'published'],
        [],
        [],
        ['-p.views'],
        0,
        50
    );
}, 15);

// 3.3: Select 10 columns from 3-table JOIN
$result11 = runCacheComparison("SELECT 10 columns from 3-table JOIN", function() {
    SQL::buildSelect(
        ['p.id', 'p.title', 'p.views', 'u.name', 'u.email', 'c.content', 'c.rating', 'c.is_approved', 'cat.name', 'cat.description'],
        [
            'posts AS p',
            ['users AS u' => ['local_key' => 'user_id', 'foreign_key' => 'id']],
            ['comments AS c' => ['local_key' => 'id', 'foreign_key' => 'post_id']],
            ['categories AS cat' => ['local_key' => 'category_id', 'foreign_key' => 'id']]
        ],
        ['p.status' => 'published', 'c.is_approved' => 1],
        [],
        [],
        ['-p.views'],
        0,
        50
    );
}, 15);

echo "\n==================================================\n";
echo "TEST 4: Complex Queries with All Clauses\n";
echo "==================================================\n";

// 4.1: Complex query with pagination
$result12 = runCacheComparison("Complex: WHERE + GROUP BY + HAVING + ORDER BY + PAGINATION", function() {
    SQL::buildSelect(
        ['u.status', 'u.role', 'COUNT(p.id) as total_posts', 'AVG(p.views) as avg_views', 'MAX(p.views) as max_views'],
        ['users AS u', ['posts AS p' => ['local_key' => 'id', 'foreign_key' => 'user_id']]],
        ['u.status' => 'active', 'u.role' => 'user'],
        ['u.status', 'u.role'],
        [['COUNT(p.id)' => ['>' => 5]]],
        ['-avg_views', '-max_views'],
        3,
        25
    );
}, 15);

// Summary
echo "\n==================================================\n";
echo "SUMMARY: L3 CACHE IMPACT ANALYSIS\n";
echo "==================================================\n\n";

$allResults = [$result1, $result2, $result3, $result4, $result5, $result6, $result7, $result8, $result9, $result10, $result11, $result12];

echo sprintf("%-55s | %-10s | %-10s | %-10s\n", "Query Type", "Cache OFF", "Cache ON", "Improve %");
echo str_repeat("-", 95) . "\n";

usort($allResults, function($a, $b) {
    return $b['improvement_pct'] <=> $a['improvement_pct'];
});

foreach ($allResults as $r) {
    echo sprintf("%-55s | %-10.4f | %-10.4f | %-10.2f%%\n", 
        substr($r['name'], 0, 53), 
        $r['cache_off']['avg_total_time_ms'],
        $r['cache_on']['avg_total_time_ms'],
        $r['improvement_pct']
    );
}

echo "\n==================================================\n";
echo "CACHE STATISTICS\n";
echo "==================================================\n";
print_r(SQL::getQueryCacheStats());

echo "\n==================================================\n";
echo "TELEMETRY STATISTICS\n";
echo "==================================================\n";
print_r(SQL::getTelemetryStats());

echo "\n✅ Advanced telemetry test completed.\n";

// Cleanup - Close PDO connection first to release file lock
$pdo = null;

// Give the OS a moment to release the file handle
usleep(100000); // 100ms

// Retry unlink if it fails
$maxRetries = 3;
$retryCount = 0;
while ($retryCount < $maxRetries) {
    try {
        if (file_exists($dbPath)) {
            @unlink($dbPath);
            if (!file_exists($dbPath)) {
                break;
            }
        }
    } catch (\Exception $e) {
        // Ignore errors on cleanup
    }
    $retryCount++;
    usleep(50000); // 50ms between retries
}

array_map('unlink', glob("$cacheDir/*"));
@rmdir($cacheDir);
