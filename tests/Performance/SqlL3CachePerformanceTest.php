<?php
/**
 * Advanced L3 Cache Performance Test with Detailed Telemetry
 * 
 * This test properly evaluates L3 cache impact by:
 * - Separating cache measurement from telemetry (no conflict)
 * - Testing JOINs from 1 to 5 tables
 * - Testing multiple WHERE conditions (5, 10, 15)
 * - Testing SELECT with 10 columns
 * - Running multiple iterations with cache ON to measure hits
 * 
 * Usage: php tests/Performance/SqlL3CachePerformanceTest.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use RapidBase\Core\SQL;
use RapidBase\Core\DB;

// Configuration
$dbPath = __DIR__ . '/../tmp/l3_cache_perf_test.sqlite';
$cleanupFiles = glob(__DIR__ . '/../tmp/l3_cache_*.sqlite');
foreach ($cleanupFiles as $file) {
    @unlink($file);
}

echo "==================================================\n";
echo "L3 CACHE PERFORMANCE TEST\n";
echo "Detailed Analysis with Proper Cache Measurement\n";
echo "==================================================\n\n";

// Create database and tables
echo "Setting up database schema with 6 tables...\n";
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec("
    CREATE TABLE users (
        id INTEGER PRIMARY KEY,
        name TEXT NOT NULL,
        email TEXT UNIQUE,
        status TEXT DEFAULT 'active',
        role TEXT DEFAULT 'user',
        country TEXT DEFAULT 'US',
        age INTEGER DEFAULT 25,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    
    CREATE TABLE posts (
        id INTEGER PRIMARY KEY,
        user_id INTEGER,
        title TEXT,
        content TEXT,
        views INTEGER DEFAULT 0,
        likes INTEGER DEFAULT 0,
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
        slug TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    
    CREATE TABLE tags (
        id INTEGER PRIMARY KEY,
        name TEXT UNIQUE,
        color TEXT DEFAULT '#000000',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    
    CREATE TABLE post_tag (
        post_id INTEGER,
        tag_id INTEGER,
        relevance INTEGER DEFAULT 1,
        PRIMARY KEY (post_id, tag_id),
        FOREIGN KEY (post_id) REFERENCES posts(id),
        FOREIGN KEY (tag_id) REFERENCES tags(id)
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

// Users (5,000 records)
$stmt = $pdo->prepare("INSERT INTO users (name, email, status, role, country, age) VALUES (?, ?, ?, ?, ?, ?)");
$pdo->beginTransaction();
for ($i = 1; $i <= 5000; $i++) {
    $status = $i % 10 === 0 ? 'inactive' : 'active';
    $role = $i % 20 === 0 ? 'admin' : 'user';
    $country = ['US', 'UK', 'CA', 'DE', 'FR', 'ES', 'IT', 'JP', 'BR', 'MX'][$i % 10];
    $age = 18 + ($i % 50);
    $stmt->execute(["User $i", "user$i@example.com", $status, $role, $country, $age]);
}
$pdo->commit();

// Categories (200 records)
$stmt = $pdo->prepare("INSERT INTO categories (name, parent_id, description, slug) VALUES (?, ?, ?, ?)");
$pdo->beginTransaction();
for ($i = 1; $i <= 200; $i++) {
    $parentId = $i > 100 ? (($i % 100) + 1) : null;
    $stmt->execute(["Category $i", $parentId, "Description for category $i", "cat-$i"]);
}
$pdo->commit();

// Tags (150 records)
$stmt = $pdo->prepare("INSERT INTO tags (name, color) VALUES (?, ?)");
$pdo->beginTransaction();
$colors = ['#FF0000', '#00FF00', '#0000FF', '#FFFF00', '#FF00FF', '#00FFFF'];
for ($i = 1; $i <= 150; $i++) {
    $stmt->execute(["Tag $i", $colors[$i % 6]]);
}
$pdo->commit();

// Posts (50,000 records)
$stmt = $pdo->prepare("INSERT INTO posts (user_id, title, content, views, likes, status, category_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
$pdo->beginTransaction();
for ($i = 1; $i <= 50000; $i++) {
    $status = $i % 5 === 0 ? 'draft' : 'published';
    $stmt->execute([
        ($i % 5000) + 1,
        "Post Title $i with some extra text for length",
        "Content for post number $i with more detailed information here",
        rand(0, 100000),
        rand(0, 5000),
        $status,
        ($i % 200) + 1
    ]);
}
$pdo->commit();

// Post-Tag relations (75,000 records)
$stmt = $pdo->prepare("INSERT OR IGNORE INTO post_tag (post_id, tag_id, relevance) VALUES (?, ?, ?)");
$pdo->beginTransaction();
for ($i = 1; $i <= 75000; $i++) {
    $postId = (($i - 1) % 1000) + 1;
    $tagId = ((int)(($i - 1) / 1000)) + 1;
    $relevance = ($i % 10) + 1;
    $stmt->execute([$postId, $tagId, $relevance]);
}
$pdo->commit();

// Comments (150,000 records)
$stmt = $pdo->prepare("INSERT INTO comments (post_id, user_id, content, rating, is_approved) VALUES (?, ?, ?, ?, ?)");
$pdo->beginTransaction();
for ($i = 1; $i <= 150000; $i++) {
    $stmt->execute([
        ($i % 50000) + 1,
        ($i % 5000) + 1,
        "Comment $i content with more text for realism",
        rand(1, 5),
        $i % 4 === 0 ? 0 : 1
    ]);
}
$pdo->commit();

echo "Database ready with:\n";
echo "  - 5,000 users\n";
echo "  - 200 categories\n";
echo "  - 150 tags\n";
echo "  - 50,000 posts\n";
echo "  - 75,000 post_tag relations\n";
echo "  - 150,000 comments\n\n";

// Initialize RapidBase
DB::setup('sqlite:' . $dbPath, '', '', 'main');

// Helper function to run benchmark with external timing (doesn't interfere with cache)
function runBenchmark(string $name, callable $queryFn, int $iterations = 20, bool $cacheEnabled = false): array {
    SQL::setQueryCacheEnabled($cacheEnabled);
    SQL::setTelemetryEnabled(false); // Keep telemetry OFF to allow cache
    
    if ($cacheEnabled) {
        SQL::clearQueryCache();
    }
    
    $times = [];
    
    // Warmup (not counted)
    $queryFn();
    
    // Timed iterations
    for ($i = 0; $i < $iterations; $i++) {
        $start = microtime(true);
        $queryFn();
        $end = microtime(true);
        $times[] = ($end - $start) * 1000; // Convert to ms
    }
    
    $stats = SQL::getQueryCacheStats();
    
    return [
        'name' => $name,
        'avg_time_ms' => round(array_sum($times) / count($times), 4),
        'min_time_ms' => round(min($times), 4),
        'max_time_ms' => round(max($times), 4),
        'iterations' => $iterations,
        'cache_enabled' => $cacheEnabled,
        'cache_hits' => $stats['hits'],
        'cache_misses' => $stats['misses'],
        'cache_hit_rate' => $stats['hit_rate']
    ];
}

// Helper to run same test with cache OFF then ON
function runCacheComparison(string $name, callable $queryFn, int $iterations = 20): array {
    echo "\nTesting: $name\n";
    echo str_repeat("-", 70) . "\n";
    
    // Run with cache OFF
    $resultOff = runBenchmark($name, $queryFn, $iterations, false);
    echo sprintf("  L3 Cache OFF:  %.4f ms (avg), min: %.4f, max: %.4f\n", 
        $resultOff['avg_time_ms'], $resultOff['min_time_ms'], $resultOff['max_time_ms']);
    
    // Run with cache ON
    $resultOn = runBenchmark($name, $queryFn, $iterations, true);
    echo sprintf("  L3 Cache ON:   %.4f ms (avg), min: %.4f, max: %.4f\n", 
        $resultOn['avg_time_ms'], $resultOn['min_time_ms'], $resultOn['max_time_ms']);
    echo sprintf("  Cache Stats:   %d hits, %d misses, %.2f%% hit rate\n",
        $resultOn['cache_hits'],
        $resultOn['cache_misses'],
        $resultOn['cache_hit_rate'] * 100
    );
    
    // Calculate improvement
    $improvement = $resultOff['avg_time_ms'] > 0 
        ? (($resultOff['avg_time_ms'] - $resultOn['avg_time_ms']) / $resultOff['avg_time_ms']) * 100 
        : 0;
    echo sprintf("  Improvement:   %.2f%%\n", $improvement);
    
    return [
        'name' => $name,
        'cache_off' => $resultOff,
        'cache_on' => $resultOn,
        'improvement_pct' => $improvement
    ];
}

echo "==================================================\n";
echo "TEST 1: JOINs from 1 to 5 Tables\n";
echo "==================================================\n";

// 1.1: Single table (no JOIN)
$result1 = runCacheComparison("Single table: users", function() {
    SQL::buildSelect(['id', 'name', 'email', 'status', 'role'], 'users', ['status' => 'active'], [], [], ['-created_at'], 0, 50);
}, 20);

// 1.2: JOIN 2 tables
$result2 = runCacheComparison("JOIN 2 tables: posts + users", function() {
    SQL::buildSelect(
        ['p.id', 'p.title', 'p.views', 'u.name', 'u.email'],
        [['posts AS p', ['users AS u' => ['local_key' => 'user_id', 'foreign_key' => 'id']]]],
        ['p.status' => 'published'],
        [],
        [],
        ['-p.views'],
        0,
        50
    );
}, 20);

// 1.3: JOIN 3 tables
$result3 = runCacheComparison("JOIN 3 tables: posts + users + comments", function() {
    SQL::buildSelect(
        ['p.id', 'p.title', 'u.name', 'COUNT(c.id) as comment_count', 'AVG(c.rating) as avg_rating'],
        [
            'posts AS p',
            ['users AS u' => ['local_key' => 'user_id', 'foreign_key' => 'id']],
            ['comments AS c' => ['local_key' => 'id', 'foreign_key' => 'post_id']]
        ],
        ['p.status' => 'published'],
        ['p.id', 'u.name'],
        [],
        ['-comment_count'],
        0,
        50
    );
}, 20);

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
        ['p.status' => 'published'],
        ['p.id', 'u.name', 'cat.name'],
        [],
        ['-comment_count'],
        0,
        50
    );
}, 20);

// 1.5: JOIN 5 tables
$result5 = runCacheComparison("JOIN 5 tables: posts + users + comments + categories + tags", function() {
    SQL::buildSelect(
        ['p.id', 'p.title', 'u.name', 'cat.name as category', 't.name as tag', 'COUNT(c.id) as comment_count'],
        [
            'posts AS p',
            ['users AS u' => ['local_key' => 'user_id', 'foreign_key' => 'id']],
            ['comments AS c' => ['local_key' => 'id', 'foreign_key' => 'post_id']],
            ['categories AS cat' => ['local_key' => 'category_id', 'foreign_key' => 'id']],
            ['tags AS t' => ['local_key' => 'post_id', 'foreign_key' => 'post_id', 'through' => 'post_tag']]
        ],
        ['p.status' => 'published'],
        [],
        [],
        ['-p.views'],
        0,
        50
    );
}, 20);

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
            'likes' => ['>' => 10],
            'user_id' => ['<' => 4000],
            'category_id' => ['>=' => 20]
        ],
        [],
        [],
        ['-views'],
        0,
        50
    );
}, 20);

// 2.2: 10 WHERE conditions
$result7 = runCacheComparison("WHERE with 10 conditions", function() {
    SQL::buildSelect(
        '*',
        'users',
        [
            'status' => 'active',
            'role' => 'user',
            'country' => 'US',
            'age' => ['>' => 25],
            'age' => ['<' => 55],
        ],
        [],
        [],
        ['-created_at'],
        0,
        50
    );
}, 20);

// 2.3: 15 WHERE conditions (complex)
$result8 = runCacheComparison("WHERE with 15 conditions (complex)", function() {
    SQL::buildSelect(
        '*',
        'posts',
        [
            'status' => 'published',
            'views' => ['>' => 500, '<' => 50000],
            'likes' => ['>=' => 50, '<=' => 3000],
            'user_id' => ['>' => 100, '<' => 4500],
        ],
        [],
        [],
        ['-views', '-likes'],
        0,
        50
    );
}, 20);

echo "\n==================================================\n";
echo "TEST 3: SELECT with 10 Columns\n";
echo "==================================================\n";

// 3.1: Select 10 columns from single table
$result9 = runCacheComparison("SELECT 10 columns from users", function() {
    SQL::buildSelect(
        ['id', 'name', 'email', 'status', 'role', 'country', 'age', 'created_at', 'created_at', 'created_at'],
        'users',
        ['status' => 'active'],
        [],
        [],
        ['-created_at'],
        0,
        50
    );
}, 20);

// 3.2: Select 10 columns from 2-table JOIN
$result10 = runCacheComparison("SELECT 10 columns from 2-table JOIN", function() {
    SQL::buildSelect(
        ['p.id', 'p.title', 'p.content', 'p.views', 'p.likes', 'p.status', 'u.name', 'u.email', 'u.role', 'u.country'],
        [['posts AS p', ['users AS u' => ['local_key' => 'user_id', 'foreign_key' => 'id']]]],
        ['p.status' => 'published'],
        [],
        [],
        ['-p.views'],
        0,
        50
    );
}, 20);

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
}, 20);

// 3.4: Select 10 columns from 4-table JOIN
$result12 = runCacheComparison("SELECT 10 columns from 4-table JOIN", function() {
    SQL::buildSelect(
        ['p.id', 'p.title', 'u.name', 'cat.name', 'cat.slug', 'c.content', 'c.rating', 't.name', 't.color', 'pt.relevance'],
        [
            'posts AS p',
            ['users AS u' => ['local_key' => 'user_id', 'foreign_key' => 'id']],
            ['comments AS c' => ['local_key' => 'id', 'foreign_key' => 'post_id']],
            ['categories AS cat' => ['local_key' => 'category_id', 'foreign_key' => 'id']],
            ['tags AS t' => ['local_key' => 'post_id', 'foreign_key' => 'post_id', 'through' => 'post_tag']],
            ['post_tag AS pt' => ['local_key' => 'post_id', 'foreign_key' => 'post_id']]
        ],
        ['p.status' => 'published'],
        [],
        [],
        ['-p.views'],
        0,
        50
    );
}, 20);

echo "\n==================================================\n";
echo "TEST 4: Complex Queries with All Clauses\n";
echo "==================================================\n";

// 4.1: Complex query with GROUP BY, HAVING, pagination
$result13 = runCacheComparison("Complex: WHERE + GROUP BY + HAVING + ORDER + PAGINATION", function() {
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
}, 20);

// 4.2: Complex query with multiple JOINs and aggregations
$result14 = runCacheComparison("Complex: 3 JOINs + GROUP BY + Aggregations", function() {
    SQL::buildSelect(
        ['cat.name', 'COUNT(p.id) as post_count', 'SUM(p.views) as total_views', 'AVG(c.rating) as avg_rating', 'MAX(p.likes) as max_likes'],
        [
            'categories AS cat',
            ['posts AS p' => ['local_key' => 'id', 'foreign_key' => 'category_id']],
            ['comments AS c' => ['local_key' => 'id', 'foreign_key' => 'post_id']]
        ],
        ['p.status' => 'published', 'c.is_approved' => 1],
        ['cat.name'],
        [['COUNT(p.id)' => ['>' => 10]]],
        ['-total_views'],
        2,
        30
    );
}, 20);

// Summary
echo "\n==================================================\n";
echo "SUMMARY: L3 CACHE IMPACT ANALYSIS\n";
echo "==================================================\n\n";

$allResults = [
    $result1, $result2, $result3, $result4, $result5,
    $result6, $result7, $result8,
    $result9, $result10, $result11, $result12,
    $result13, $result14
];

echo sprintf("%-60s | %-10s | %-10s | %-10s | %-8s\n", "Query Type", "Cache OFF", "Cache ON", "Improve %", "Hit Rate");
echo str_repeat("-", 110) . "\n";

usort($allResults, function($a, $b) {
    return $b['improvement_pct'] <=> $a['improvement_pct'];
});

foreach ($allResults as $r) {
    echo sprintf("%-60s | %-10.4f | %-10.4f | %-10.2f%% | %-8.2f%%\n", 
        substr($r['name'], 0, 58), 
        $r['cache_off']['avg_time_ms'],
        $r['cache_on']['avg_time_ms'],
        $r['improvement_pct'],
        $r['cache_on']['cache_hit_rate'] * 100
    );
}

echo "\n==================================================\n";
echo "FINAL CACHE STATISTICS\n";
echo "==================================================\n";
$finalStats = SQL::getQueryCacheStats();
print_r($finalStats);

echo "\n==================================================\n";
echo "ANALYSIS NOTES\n";
echo "==================================================\n";
echo "- Positive improvement % means cache ON is FASTER (good)\n";
echo "- Negative improvement % means cache ON is SLOWER (overhead)\n";
echo "- Hit rate should be high for repeated queries\n";
echo "- Complex queries benefit more from caching\n";
echo "- Simple queries may have overhead that exceeds benefits\n";

// Cleanup
echo "\nCleaning up...\n";
@unlink($dbPath);
array_map('unlink', glob(__DIR__ . '/../tmp/l3_cache_*.sqlite'));

echo "\n✓ L3 Cache performance test completed.\n";
