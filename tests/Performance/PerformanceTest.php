<?php
/**
 * Performance Benchmark: PDO vs RapidBase (No Cache) vs RapidBase (With Cache)
 * Measures execution time for Simple Select, 2-Table Join, and 3-Table Join.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use RapidBase\Core\DB;
use RapidBase\Core\Cache\CacheService;
use RapidBase\Core\Cache\Adapters\DirectoryCacheAdapter;

// Configuration
$dsn = 'sqlite:' . __DIR__ . '/../tmp/benchmark.sqlite';
$cacheDir = __DIR__ . '/../tmp/cache';

// Ensure clean state
if (file_exists(__DIR__ . '/../tmp/benchmark.sqlite')) {
    unlink(__DIR__ . '/../tmp/benchmark.sqlite');
}
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0777, true);
}
array_map('unlink', glob("$cacheDir/*"));

echo "==================================================\n";
echo "PERFORMANCE BENCHMARK\n";
echo "PDO vs RapidBase (No Cache) vs RapidBase (Cache)\n";
echo "==================================================\n\n";

// 1. Setup Database & Data
$pdo = new PDO($dsn);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "Setting up database with 10,000 records...\n";
$pdo->exec("
    CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT);
    CREATE TABLE posts (id INTEGER PRIMARY KEY, user_id INTEGER, title TEXT, content TEXT);
    CREATE TABLE tags (id INTEGER PRIMARY KEY, name TEXT);
    CREATE TABLE post_tag (post_id INTEGER, tag_id INTEGER, PRIMARY KEY(post_id, tag_id));
");

// Insert Users
$stmt = $pdo->prepare("INSERT INTO users (name, email) VALUES (?, ?)");
for ($i = 1; $i <= 10000; $i++) {
    $stmt->execute(["User $i", "user$i@test.com"]);
}

// Insert Posts
$stmt = $pdo->prepare("INSERT INTO posts (user_id, title, content) VALUES (?, ?, ?)");
for ($i = 1; $i <= 50000; $i++) {
    $stmt->execute([$i % 10000 + 1, "Post Title $i", "Content for post $i"]);
}

// Insert Tags
$stmt = $pdo->prepare("INSERT INTO tags (name) VALUES (?)");
for ($i = 1; $i <= 100; $i++) {
    $stmt->execute(["Tag $i"]);
}

// Insert Post_Tags (Pivot)
$stmt = $pdo->prepare("INSERT INTO post_tag (post_id, tag_id) VALUES (?, ?)");
for ($i = 1; $i <= 50000; $i++) {
    $stmt->execute([$i, ($i % 100) + 1]);
}

echo "Database ready.\n\n";

// Initialize RapidBase
DB::connect($dsn);
CacheService::init(new DirectoryCacheAdapter($cacheDir));
CacheService::on(); // Enable cache

// Helper for timing
function benchmark($name, callable $fn, $iterations = 100) {
    // Warmup
    for ($i = 0; $i < 10; $i++) $fn();
    
    $start = microtime(true);
    for ($i = 0; $i < $iterations; $i++) $fn();
    $end = microtime(true);
    
    $avg = (($end - $start) / $iterations) * 1000; // ms
    echo sprintf("%-30s: %.4f ms\n", $name, $avg);
    return $avg;
}

echo "--- SCENARIO 1: Simple Select (100 iterations) ---\n";
echo "Fetching 50 users...\n";

$timePdoSimple = benchmark("PDO Native", function() use ($pdo) {
    $stmt = $pdo->query("SELECT * FROM users LIMIT 50");
    $stmt->fetchAll(PDO::FETCH_ASSOC);
});

$timeRbNoCache = benchmark("RapidBase (No Cache)", function() {
    CacheService::off();
    DB::table('users')->limit(50)->get();
});

CacheService::on();
// Run once to populate cache
DB::table('users')->limit(50)->get();

$timeRbCache = benchmark("RapidBase (Cache Hit)", function() {
    DB::table('users')->limit(50)->get();
});

echo "\n--- SCENARIO 2: Join 2 Tables (50 iterations) ---\n";
echo "Fetching posts with users...\n";

$timePdoJoin2 = benchmark("PDO Native", function() use ($pdo) {
    $stmt = $pdo->query("SELECT p.*, u.name as user_name FROM posts p JOIN users u ON p.user_id = u.id LIMIT 50");
    $stmt->fetchAll(PDO::FETCH_ASSOC);
});

$timeRbJoin2NoCache = benchmark("RapidBase (No Cache)", function() {
    CacheService::off();
    // Simulating join via relationship or raw query depending on API
    // Using raw SQL for fair comparison of overhead
    DB::query("SELECT p.*, u.name as user_name FROM posts p JOIN users u ON p.user_id = u.id LIMIT 50")->getAll();
});

CacheService::on();
DB::query("SELECT p.*, u.name as user_name FROM posts p JOIN users u ON p.user_id = u.id LIMIT 50")->getAll();

$timeRbJoin2Cache = benchmark("RapidBase (Cache Hit)", function() {
    DB::query("SELECT p.*, u.name as user_name FROM posts p JOIN users u ON p.user_id = u.id LIMIT 50")->getAll();
});

echo "\n--- SCENARIO 3: Join 3 Tables (20 iterations) ---\n";
echo "Fetching posts with users and tags...\n";

$timePdoJoin3 = benchmark("PDO Native", function() use ($pdo) {
    $stmt = $pdo->query("
        SELECT p.*, u.name as user_name, t.name as tag_name 
        FROM posts p 
        JOIN users u ON p.user_id = u.id 
        JOIN post_tag pt ON p.id = pt.post_id 
        JOIN tags t ON pt.tag_id = t.id 
        LIMIT 50
    ");
    $stmt->fetchAll(PDO::FETCH_ASSOC);
});

$timeRbJoin3NoCache = benchmark("RapidBase (No Cache)", function() {
    CacheService::off();
    DB::query("
        SELECT p.*, u.name as user_name, t.name as tag_name 
        FROM posts p 
        JOIN users u ON p.user_id = u.id 
        JOIN post_tag pt ON p.id = pt.post_id 
        JOIN tags t ON pt.tag_id = t.id 
        LIMIT 50
    ")->getAll();
});

CacheService::on();
DB::query("
    SELECT p.*, u.name as user_name, t.name as tag_name 
    FROM posts p 
    JOIN users u ON p.user_id = u.id 
    JOIN post_tag pt ON p.id = pt.post_id 
    JOIN tags t ON pt.tag_id = t.id 
    LIMIT 50
")->getAll();

$timeRbJoin3Cache = benchmark("RapidBase (Cache Hit)", function() {
    DB::query("
        SELECT p.*, u.name as user_name, t.name as tag_name 
        FROM posts p 
        JOIN users u ON p.user_id = u.id 
        JOIN post_tag pt ON p.id = pt.post_id 
        JOIN tags t ON pt.tag_id = t.id 
        LIMIT 50
    ")->getAll();
});

echo "\n==================================================\n";
echo "SUMMARY (Relative to PDO = 1.0x)\n";
echo "==================================================\n";

function calcRatio($base, $compare) {
    return number_format($compare / $base, 2);
}

echo "\nSimple Select:\n";
echo "  PDO: 1.00x (Base)\n";
echo "  RapidBase (No Cache): " . calcRatio($timePdoSimple, $timeRbNoCache) . "x\n";
echo "  RapidBase (Cache): " . calcRatio($timePdoSimple, $timeRbCache) . "x (" . number_format($timePdoSimple / $timeRbCache, 1) . "x FASTER than PDO)\n";

echo "\nJoin 2 Tables:\n";
echo "  PDO: 1.00x (Base)\n";
echo "  RapidBase (No Cache): " . calcRatio($timePdoJoin2, $timeRbJoin2NoCache) . "x\n";
echo "  RapidBase (Cache): " . calcRatio($timePdoJoin2, $timeRbJoin2Cache) . "x (" . number_format($timePdoJoin2 / $timeRbJoin2Cache, 1) . "x FASTER than PDO)\n";

echo "\nJoin 3 Tables:\n";
echo "  PDO: 1.00x (Base)\n";
echo "  RapidBase (No Cache): " . calcRatio($timePdoJoin3, $timeRbJoin3NoCache) . "x\n";
echo "  RapidBase (Cache): " . calcRatio($timePdoJoin3, $timeRbJoin3Cache) . "x (" . number_format($timePdoJoin3 / $timeRbJoin3Cache, 1) . "x FASTER than PDO)\n";

echo "\n✅ Benchmark completed.\n";
