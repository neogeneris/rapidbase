<?php
/**
 * MySQL Performance Benchmark: PDO vs RapidBase (No Cache) vs RapidBase (With Cache)
 * Measures execution time for Simple Select, 2-Table Join, and 3-Table Join.
 * 
 * Requires: mysql-test-setup.php to be run first (for base tables)
 * This script will add additional records for benchmarking.
 */

// Load MySQL config
$configFile = __DIR__ . '/../../mysql-test-config.local.php';
if (!file_exists($configFile)) {
    $configFile = __DIR__ . '/../../mysql-test-config.php';
}
require_once $configFile;

require_once __DIR__ . '/../../../examples/querybrowser/RapidBase.php';

use RapidBase\Core\DB;
use RapidBase\Core\Gateway;
use RapidBase\Core\Cache\CacheService;
use RapidBase\Core\SchemaMap;
use RapidBase\Meta\SchemaMapper;

$prefix = TEST_PREFIX;
$cacheDir = __DIR__ . '/../../tmp/cache';

// Clean cache
if (is_dir($cacheDir)) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($cacheDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        if ($file->isDir()) rmdir($file->getRealPath());
        else unlink($file->getRealPath());
    }
} else {
    mkdir($cacheDir, 0777, true);
}

echo "==================================================\n";
echo "MYSQL PERFORMANCE BENCHMARK\n";
echo "PDO vs RapidBase (No Cache) vs RapidBase (Cache)\n";
echo "==================================================\n";
echo "Host: " . MYSQL_HOST . "\n";
echo "DB:   " . MYSQL_DB . "\n\n";

// Connect
$dsn = "mysql:host=" . MYSQL_HOST . ";port=" . MYSQL_PORT . ";dbname=" . MYSQL_DB . ";charset=utf8mb4";
$pdo = new PDO($dsn, MYSQL_USER, MYSQL_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Create large benchmark tables
echo "Setting up benchmark tables with 100,000 records...\n";

$pdo->exec("DROP TABLE IF EXISTS `{$prefix}bench_users`");
$pdo->exec("DROP TABLE IF EXISTS `{$prefix}bench_posts`");
$pdo->exec("DROP TABLE IF EXISTS `{$prefix}bench_tags`");
$pdo->exec("DROP TABLE IF EXISTS `{$prefix}bench_post_tag`");

$pdo->exec("
    CREATE TABLE `{$prefix}bench_users` (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        email VARCHAR(150) NOT NULL,
        INDEX idx_email (email)
    ) ENGINE=InnoDB
");

$pdo->exec("
    CREATE TABLE `{$prefix}bench_posts` (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        title VARCHAR(200) NOT NULL,
        content TEXT,
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB
");

$pdo->exec("
    CREATE TABLE `{$prefix}bench_tags` (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(50) NOT NULL
    ) ENGINE=InnoDB
");

$pdo->exec("
    CREATE TABLE `{$prefix}bench_post_tag` (
        post_id INT UNSIGNED NOT NULL,
        tag_id INT UNSIGNED NOT NULL,
        PRIMARY KEY (post_id, tag_id)
    ) ENGINE=InnoDB
");

// Insert users (10,000)
echo "Inserting users...\n";
$stmt = $pdo->prepare("INSERT INTO `{$prefix}bench_users` (name, email) VALUES (?, ?)");
$pdo->beginTransaction();
for ($i = 1; $i <= 10000; $i++) {
    $stmt->execute(["User $i", "user$i@test.com"]);
    if ($i % 1000 === 0) {
        $pdo->commit();
        $pdo->beginTransaction();
        echo "  $i/10000\n";
    }
}
$pdo->commit();
echo "  Done.\n";

// Insert posts (50,000)
echo "Inserting posts...\n";
$stmt = $pdo->prepare("INSERT INTO `{$prefix}bench_posts` (user_id, title, content) VALUES (?, ?, ?)");
$pdo->beginTransaction();
for ($i = 1; $i <= 50000; $i++) {
    $stmt->execute([($i % 10000) + 1, "Post Title $i", "Content for post $i"]);
    if ($i % 5000 === 0) {
        $pdo->commit();
        $pdo->beginTransaction();
        echo "  $i/50000\n";
    }
}
$pdo->commit();
echo "  Done.\n";

// Insert tags (100)
echo "Inserting tags...\n";
$stmt = $pdo->prepare("INSERT INTO `{$prefix}bench_tags` (name) VALUES (?)");
$pdo->beginTransaction();
for ($i = 1; $i <= 100; $i++) {
    $stmt->execute(["Tag $i"]);
}
$pdo->commit();
echo "  Done.\n";

// Insert post_tag (50,000)
echo "Inserting post_tag relations...\n";
$stmt = $pdo->prepare("INSERT IGNORE INTO `{$prefix}bench_post_tag` (post_id, tag_id) VALUES (?, ?)");
$pdo->beginTransaction();
for ($i = 1; $i <= 50000; $i++) {
    $stmt->execute([$i, ($i % 100) + 1]);
    if ($i % 5000 === 0) {
        $pdo->commit();
        $pdo->beginTransaction();
        echo "  $i/50000\n";
    }
}
$pdo->commit();
echo "  Done.\n\n";

// Initialize RapidBase with MySQL
echo "Initializing RapidBase...\n";
DB::setup($dsn, MYSQL_USER, MYSQL_PASS, 'bench');
CacheService::init($cacheDir);
// Generate schema map for the bench tables
$pdo2 = new PDO($dsn, MYSQL_USER, MYSQL_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
SchemaMapper::setOutputFile(__DIR__ . '/../../tmp/bench_schema.php');
SchemaMapper::generate($pdo2, MYSQL_DB, null, 'bench');
if (file_exists(__DIR__ . '/../../tmp/bench_schema.php')) {
    $map = include __DIR__ . '/../../tmp/bench_schema.php';
    SchemaMap::setMap($map, 'bench');
}
echo "  Done.\n\n";

// Helper for timing
function benchmark($name, callable $fn, $iterations = 100) {
    echo "  $name: warming up...\n";
    // Warmup
    for ($i = 0; $i < 5; $i++) $fn();
    
    echo "  $name: running $iterations iterations...\n";
    $start = microtime(true);
    for ($i = 0; $i < $iterations; $i++) $fn();
    $end = microtime(true);
    
    $avg = (($end - $start) / $iterations) * 1000;
    echo sprintf("  %-30s: %.4f ms\n", $name, $avg);
    return $avg;
}

echo "--- SCENARIO 1: Simple Select (100 iterations) ---\n";
echo "Fetching 50 users...\n\n";

$timePdoSimple = benchmark("PDO Native", function() use ($pdo, $prefix) {
    $stmt = $pdo->query("SELECT * FROM `{$prefix}bench_users` LIMIT 50");
    $stmt->fetchAll(PDO::FETCH_ASSOC);
});

$timeRbNoCache = benchmark("RapidBase (No Cache)", function() use ($prefix) {
    CacheService::disable();
    Gateway::select('*', "{$prefix}bench_users", [], [], [], [], [1, 50]);
});

CacheService::enable();
Gateway::selectCached('*', "{$prefix}bench_users", [], [], [], [], [1, 50]);

$timeRbCache = benchmark("RapidBase (Cache Hit)", function() use ($prefix) {
    Gateway::selectCached('*', "{$prefix}bench_users", [], [], [], [], [1, 50]);
});

echo "\n--- SCENARIO 2: Join 2 Tables (50 iterations) ---\n";
echo "Fetching posts with users...\n\n";

$timePdoJoin2 = benchmark("PDO Native", function() use ($pdo, $prefix) {
    $stmt = $pdo->query("
        SELECT p.*, u.name as user_name 
        FROM `{$prefix}bench_posts` p 
        JOIN `{$prefix}bench_users` u ON p.user_id = u.id 
        LIMIT 50
    ");
    $stmt->fetchAll(PDO::FETCH_ASSOC);
});

$timeRbJoin2NoCache = benchmark("RapidBase (No Cache)", function() use ($prefix) {
    CacheService::disable();
    Gateway::select(
        ['p.*', 'u.name as user_name'],
        [
            "{$prefix}bench_posts AS p",
            ["{$prefix}bench_users" => ['local_key' => 'user_id', 'foreign_key' => 'id', 'as' => 'u']]
        ],
        [], [], [], [], [1, 50]
    );
});

CacheService::enable();
Gateway::selectCached(
    ['p.*', 'u.name as user_name'],
    [
        "{$prefix}bench_posts AS p",
        ["{$prefix}bench_users" => ['local_key' => 'user_id', 'foreign_key' => 'id', 'as' => 'u']]
    ],
    [], [], [], [], [1, 50]
);

$timeRbJoin2Cache = benchmark("RapidBase (Cache Hit)", function() use ($prefix) {
    Gateway::selectCached(
        ['p.*', 'u.name as user_name'],
        [
            "{$prefix}bench_posts AS p",
            ["{$prefix}bench_users" => ['local_key' => 'user_id', 'foreign_key' => 'id', 'as' => 'u']]
        ],
        [], [], [], [], [1, 50]
    );
});

echo "\n--- SCENARIO 3: Join 3 Tables (20 iterations) ---\n";
echo "Fetching posts with users and tags...\n\n";

$timePdoJoin3 = benchmark("PDO Native", function() use ($pdo, $prefix) {
    $stmt = $pdo->query("
        SELECT p.*, u.name as user_name, t.name as tag_name 
        FROM `{$prefix}bench_posts` p 
        JOIN `{$prefix}bench_users` u ON p.user_id = u.id 
        JOIN `{$prefix}bench_post_tag` pt ON p.id = pt.post_id 
        JOIN `{$prefix}bench_tags` t ON pt.tag_id = t.id 
        LIMIT 50
    ");
    $stmt->fetchAll(PDO::FETCH_ASSOC);
});

$timeRbJoin3NoCache = benchmark("RapidBase (No Cache)", function() use ($prefix) {
    CacheService::disable();
    Gateway::select(
        ['p.*', 'u.name as user_name', 't.name as tag_name'],
        [
            "{$prefix}bench_posts AS p",
            ["{$prefix}bench_users" => ['local_key' => 'user_id', 'foreign_key' => 'id', 'as' => 'u']],
            ["{$prefix}bench_post_tag" => ['local_key' => 'id', 'foreign_key' => 'post_id', 'as' => 'pt']],
            ["{$prefix}bench_tags" => ['local_key' => 'tag_id', 'foreign_key' => 'id', 'as' => 't']]
        ],
        [], [], [], [], [1, 50]
    );
});

CacheService::enable();
Gateway::selectCached(
    ['p.*', 'u.name as user_name', 't.name as tag_name'],
    [
        "{$prefix}bench_posts AS p",
        ["{$prefix}bench_users" => ['local_key' => 'user_id', 'foreign_key' => 'id', 'as' => 'u']],
        ["{$prefix}bench_post_tag" => ['local_key' => 'id', 'foreign_key' => 'post_id', 'as' => 'pt']],
        ["{$prefix}bench_tags" => ['local_key' => 'tag_id', 'foreign_key' => 'id', 'as' => 't']]
    ],
    [], [], [], [], [1, 50]
);

$timeRbJoin3Cache = benchmark("RapidBase (Cache Hit)", function() use ($prefix) {
    Gateway::selectCached(
        ['p.*', 'u.name as user_name', 't.name as tag_name'],
        [
            "{$prefix}bench_posts AS p",
            ["{$prefix}bench_users" => ['local_key' => 'user_id', 'foreign_key' => 'id', 'as' => 'u']],
            ["{$prefix}bench_post_tag" => ['local_key' => 'id', 'foreign_key' => 'post_id', 'as' => 'pt']],
            ["{$prefix}bench_tags" => ['local_key' => 'tag_id', 'foreign_key' => 'id', 'as' => 't']]
        ],
        [], [], [], [], [1, 50]
    );
});

echo "\n==================================================\n";
echo "SUMMARY (Relative to PDO = 1.0x)\n";
echo "==================================================\n";

function calcRatio($base, $compare) {
    return number_format($compare / $base, 2);
}

echo "\nSimple Select (100 iterations):\n";
echo "  PDO:              1.00x (Base)\n";
echo "  RapidBase No Cache: " . calcRatio($timePdoSimple, $timeRbNoCache) . "x\n";
echo "  RapidBase Cache:    " . calcRatio($timePdoSimple, $timeRbCache) . "x (" . number_format($timePdoSimple / $timeRbCache, 1) . "x FASTER than PDO)\n";

echo "\nJoin 2 Tables (50 iterations):\n";
echo "  PDO:              1.00x (Base)\n";
echo "  RapidBase No Cache: " . calcRatio($timePdoJoin2, $timeRbJoin2NoCache) . "x\n";
echo "  RapidBase Cache:    " . calcRatio($timePdoJoin2, $timeRbJoin2Cache) . "x (" . number_format($timePdoJoin2 / $timeRbJoin2Cache, 1) . "x FASTER than PDO)\n";

echo "\nJoin 3 Tables (20 iterations):\n";
echo "  PDO:              1.00x (Base)\n";
echo "  RapidBase No Cache: " . calcRatio($timePdoJoin3, $timeRbJoin3NoCache) . "x\n";
echo "  RapidBase Cache:    " . calcRatio($timePdoJoin3, $timeRbJoin3Cache) . "x (" . number_format($timePdoJoin3 / $timeRbJoin3Cache, 1) . "x FASTER than PDO)\n";

// Cleanup benchmark tables
echo "\n--- Cleanup ---\n";
$pdo->exec("DROP TABLE IF EXISTS `{$prefix}bench_users`");
$pdo->exec("DROP TABLE IF EXISTS `{$prefix}bench_posts`");
$pdo->exec("DROP TABLE IF EXISTS `{$prefix}bench_tags`");
$pdo->exec("DROP TABLE IF EXISTS `{$prefix}bench_post_tag`");
echo "Benchmark tables removed.\n";

echo "\nBenchmark completed.\n";