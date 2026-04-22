<?php
/**
 * Detailed Performance Test with Telemetry Metrics
 * 
 * This test captures detailed metrics for different query combinations:
 * - Simple SELECT
 * - SELECT with WHERE conditions
 * - SELECT with ORDER BY
 * - SELECT with GROUP BY
 * - SELECT with JOINs (2, 3, 4 tables)
 * - Complex queries with multiple clauses
 * 
 * Usage: php tests/Performance/SqlTelemetryTest.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use RapidBase\Core\SQL;
use RapidBase\Core\DB;

// Configuration
$dbPath = __DIR__ . '/../tmp/telemetry_test.sqlite';
$cacheDir = __DIR__ . '/../tmp/telemetry_cache';

// Clean up previous test data
if (file_exists($dbPath)) {
    unlink($dbPath);
}
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0777, true);
}
array_map('unlink', glob("$cacheDir/*"));

echo "==================================================\n";
echo "DETAILED SQL TELEMETRY PERFORMANCE TEST\n";
echo "==================================================\n\n";

// Create database and tables
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "Setting up database schema...\n";
$pdo->exec("
    CREATE TABLE users (
        id INTEGER PRIMARY KEY,
        name TEXT NOT NULL,
        email TEXT UNIQUE,
        status TEXT DEFAULT 'active',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    
    CREATE TABLE posts (
        id INTEGER PRIMARY KEY,
        user_id INTEGER,
        title TEXT,
        content TEXT,
        views INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id)
    );
    
    CREATE TABLE categories (
        id INTEGER PRIMARY KEY,
        name TEXT UNIQUE,
        parent_id INTEGER
    );
    
    CREATE TABLE post_category (
        post_id INTEGER,
        category_id INTEGER,
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
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (post_id) REFERENCES posts(id),
        FOREIGN KEY (user_id) REFERENCES users(id)
    );
");

// Insert test data
echo "Inserting test data...\n";

// Users (1,000 records)
$stmt = $pdo->prepare("INSERT INTO users (name, email, status) VALUES (?, ?, ?)");
$pdo->beginTransaction();
for ($i = 1; $i <= 1000; $i++) {
    $status = $i % 10 === 0 ? 'inactive' : 'active';
    $stmt->execute(["User $i", "user$i@example.com", $status]);
}
$pdo->commit();

// Categories (50 records)
$stmt = $pdo->prepare("INSERT INTO categories (name, parent_id) VALUES (?, ?)");
$pdo->beginTransaction();
for ($i = 1; $i <= 50; $i++) {
    $parentId = $i > 25 ? ($i % 25) + 1 : null;
    $stmt->execute(["Category $i", $parentId]);
}
$pdo->commit();

// Posts (10,000 records)
$stmt = $pdo->prepare("INSERT INTO posts (user_id, title, content, views) VALUES (?, ?, ?, ?)");
$pdo->beginTransaction();
for ($i = 1; $i <= 10000; $i++) {
    $stmt->execute([
        ($i % 1000) + 1,
        "Post Title $i",
        "Content for post number $i with some text",
        rand(0, 10000)
    ]);
}
$pdo->commit();

// Post-Category relations (15,000 records with unique combinations)
$stmt = $pdo->prepare("INSERT OR IGNORE INTO post_category (post_id, category_id) VALUES (?, ?)");
$pdo->beginTransaction();
for ($i = 1; $i <= 15000; $i++) {
    $postId = (($i - 1) % 300) + 1;  // Use only first 300 posts to avoid duplicates
    $categoryId = ((int)(($i - 1) / 300)) + 1;  // Distribute across categories
    $stmt->execute([$postId, $categoryId]);
}
$pdo->commit();

// Comments (50,000 records)
$stmt = $pdo->prepare("INSERT INTO comments (post_id, user_id, content, rating) VALUES (?, ?, ?, ?)");
$pdo->beginTransaction();
for ($i = 1; $i <= 50000; $i++) {
    $stmt->execute([
        ($i % 10000) + 1,
        ($i % 1000) + 1,
        "Comment $i content",
        rand(1, 5)
    ]);
}
$pdo->commit();

echo "Database ready with:\n";
echo "  - 1,000 users\n";
echo "  - 50 categories\n";
echo "  - 10,000 posts\n";
echo "  - 15,000 post_category relations\n";
echo "  - 50,000 comments\n\n";

// Initialize RapidBase
DB::setup('sqlite:' . $dbPath, '', '', 'main');

// Helper function to run benchmark with telemetry
function runWithTelemetry(string $name, callable $queryFn, int $iterations = 10): array {
    SQL::setTelemetryEnabled(true);
    SQL::clearMetrics();
    
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
        'step_metrics' => $avgMetrics
    ];
}

// Pretty print results
function printResults(array $results) {
    echo str_pad($results['name'], 50) . ": " . number_format($results['avg_total_time_ms'], 4) . " ms (avg)\n";
    echo str_repeat("-", 80) . "\n";
    
    if (!empty($results['step_metrics'])) {
        echo sprintf("%-15s | %-15s | %-15s\n", "Step", "Time (ms)", "Memory (bytes)");
        echo str_repeat("-", 50) . "\n";
        
        foreach ($results['step_metrics'] as $step => $data) {
            echo sprintf("%-15s | %-15.4f | %-15.2f\n", 
                $step, 
                $data['avg_time_ms'],
                $data['avg_mem_bytes'] ?? 0
            );
        }
    }
    echo "\n";
}

echo "==================================================\n";
echo "SCENARIO 1: Simple SELECT Queries\n";
echo "==================================================\n\n";

// 1.1: Simple SELECT *
$result1 = runWithTelemetry("Simple SELECT * FROM users LIMIT 50", function() use ($pdo) {
    SQL::buildSelect('*', 'users', [], [], [], [], 0, 50);
}, 20);
printResults($result1);

// 1.2: SELECT with specific fields
$result2 = runWithTelemetry("SELECT specific fields FROM users", function() {
    SQL::buildSelect(['id', 'name', 'email'], 'users', [], [], [], [], 0, 50);
}, 20);
printResults($result2);

echo "==================================================\n";
echo "SCENARIO 2: SELECT with WHERE Conditions\n";
echo "==================================================\n\n";

// 2.1: Single WHERE condition
$result3 = runWithTelemetry("WHERE status = 'active'", function() {
    SQL::buildSelect('*', 'users', ['status' => 'active'], [], [], [], 0, 50);
}, 20);
printResults($result3);

// 2.2: Multiple WHERE conditions with operators
$result4 = runWithTelemetry("WHERE views > 100 AND views < 5000", function() {
    SQL::buildSelect('*', 'posts', ['views' => ['>' => 100, '<' => 5000]], [], [], [], 0, 50);
}, 20);
printResults($result4);

// 2.3: WHERE with IN clause
$result5 = runWithTelemetry("WHERE id IN (1,2,3,4,5)", function() {
    SQL::buildSelect('*', 'users', ['id' => [1, 2, 3, 4, 5]], [], [], [], 0, 50);
}, 20);
printResults($result5);

echo "==================================================\n";
echo "SCENARIO 3: SELECT with ORDER BY\n";
echo "==================================================\n\n";

// 3.1: Single ORDER BY
$result6 = runWithTelemetry("ORDER BY created_at DESC", function() {
    SQL::buildSelect('*', 'posts', [], [], [], ['-created_at'], 0, 50);
}, 20);
printResults($result6);

// 3.2: Multiple ORDER BY
$result7 = runWithTelemetry("ORDER BY views DESC, created_at ASC", function() {
    SQL::buildSelect('*', 'posts', [], [], [], ['-views', 'created_at'], 0, 50);
}, 20);
printResults($result7);

echo "==================================================\n";
echo "SCENARIO 4: SELECT with GROUP BY\n";
echo "==================================================\n\n";

// 4.1: Simple GROUP BY
$result8 = runWithTelemetry("GROUP BY user_id with COUNT", function() {
    SQL::buildSelect(['user_id', 'COUNT(*) as post_count'], 'posts', [], ['user_id'], [], [], 0, 100);
}, 20);
printResults($result8);

// 4.2: GROUP BY with HAVING
$result9 = runWithTelemetry("GROUP BY user_id HAVING COUNT > 5", function() {
    SQL::buildSelect(
        ['user_id', 'COUNT(*) as post_count'], 
        'posts', 
        [], 
        ['user_id'], 
        [['COUNT(*)' => ['>' => 5]]], 
        [], 
        0, 
        100
    );
}, 20);
printResults($result9);

echo "==================================================\n";
echo "SCENARIO 5: JOIN Queries (2 Tables)\n";
echo "==================================================\n\n";

// 5.1: Simple JOIN
$result10 = runWithTelemetry("JOIN posts WITH users", function() {
    SQL::buildSelect(
        ['p.id', 'p.title', 'u.name as user_name'],
        [['posts AS p', ['users AS u' => ['local_key' => 'user_id', 'foreign_key' => 'id']]]],
        [],
        [],
        [],
        ['-p.created_at'],
        0,
        50
    );
}, 10);
printResults($result10);

echo "==================================================\n";
echo "SCENARIO 6: JOIN Queries (3 Tables)\n";
echo "==================================================\n\n";

// 6.1: Three table JOIN
$result11 = runWithTelemetry("JOIN posts, users, comments", function() {
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
}, 10);
printResults($result11);

echo "==================================================\n";
echo "SCENARIO 7: Complex Queries (All Clauses)\n";
echo "==================================================\n\n";

// 7.1: Full complex query
$result12 = runWithTelemetry("Complex: WHERE + GROUP BY + HAVING + ORDER BY + LIMIT", function() {
    SQL::buildSelect(
        ['u.status', 'COUNT(p.id) as total_posts', 'AVG(p.views) as avg_views'],
        ['users AS u', ['posts AS p' => ['local_key' => 'id', 'foreign_key' => 'user_id']]],
        ['u.status' => 'active'],
        ['u.status'],
        [['COUNT(p.id)' => ['>' => 5]]],
        ['-avg_views'],
        1,
        20
    );
}, 10);
printResults($result12);

// 7.2: Complex query with pagination
$result13 = runWithTelemetry("Complex with pagination (page 5, 20 per page)", function() {
    SQL::buildSelect(
        ['p.*', 'u.name', 'c.content'],
        [
            'posts AS p',
            ['users AS u' => ['local_key' => 'user_id', 'foreign_key' => 'id']],
            ['comments AS c' => ['local_key' => 'id', 'foreign_key' => 'post_id']]
        ],
        ['p.views' => ['>' => 500]],
        [],
        [],
        ['-p.views', 'c.created_at'],
        5,
        20
    );
}, 10);
printResults($result13);

echo "==================================================\n";
echo "SUMMARY COMPARISON\n";
echo "==================================================\n\n";

$allResults = [
    $result1, $result2, $result3, $result4, $result5,
    $result6, $result7, $result8, $result9, $result10,
    $result11, $result12, $result13
];

echo sprintf("%-50s | %-12s\n", "Query Type", "Avg Time (ms)");
echo str_repeat("-", 65) . "\n";

usort($allResults, function($a, $b) {
    return $b['avg_total_time_ms'] <=> $a['avg_total_time_ms'];
});

foreach ($allResults as $r) {
    echo sprintf("%-50s | %-12.4f\n", substr($r['name'], 0, 48), $r['avg_total_time_ms']);
}

echo "\n==================================================\n";
echo "TELEMETRY STATISTICS\n";
echo "==================================================\n";
print_r(SQL::getTelemetryStats());

echo "\n✅ Detailed telemetry test completed.\n";

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
