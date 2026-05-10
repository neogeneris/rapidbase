<?php
/**
 * Performance Benchmark: PDO vs Gateway vs X (fluent wrapper)
 * Incluye comparación de estrategias de total: window vs separate
 */

require_once __DIR__ . '/bootstrap.php';

use RapidBase\Core\DB;
use RapidBase\Core\Gateway;
use RapidBase\Core\X;
use RapidBase\Core\Cache\CacheService;
use RapidBase\Core\SQL\Q;
use RapidBase\Core\Conn;

// ==================== CONFIGURATION ====================
$dsn = 'sqlite:' . __DIR__ . '/../../../tmp/x_benchmark.sqlite';
$cacheDir = __DIR__ . '/../../../tmp/x_cache';

// Clean previous state
if (file_exists(__DIR__ . '/../../../tmp/x_benchmark.sqlite')) {
    unlink(__DIR__ . '/../../../tmp/x_benchmark.sqlite');
}
if (is_dir($cacheDir)) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($cacheDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
    }
} else {
    mkdir($cacheDir, 0777, true);
}

echo "==================================================\n";
echo "PERFORMANCE BENCHMARK – X (FLUENT WRAPPER)\n";
echo "PDO vs Gateway vs X\n";
echo "==================================================\n\n";

// ==================== DATABASE SETUP ====================
$pdo = new PDO($dsn);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "Setting up database with 10,000 users, 50,000 posts, 100 tags, 50,000 relations...\n";

$pdo->exec("
    CREATE TABLE users (
        id INTEGER PRIMARY KEY,
        name TEXT,
        email TEXT
    );
    CREATE TABLE posts (
        id INTEGER PRIMARY KEY,
        user_id INTEGER,
        title TEXT,
        content TEXT
    );
    CREATE TABLE tags (
        id INTEGER PRIMARY KEY,
        name TEXT
    );
    CREATE TABLE post_tag (
        post_id INTEGER,
        tag_id INTEGER,
        PRIMARY KEY (post_id, tag_id)
    );
");

// Insert users
$stmt = $pdo->prepare("INSERT INTO users (name, email) VALUES (?, ?)");
$pdo->beginTransaction();
for ($i = 1; $i <= 10000; $i++) {
    $stmt->execute(["User $i", "user$i@test.com"]);
}
$pdo->commit();

// Insert posts
$stmt = $pdo->prepare("INSERT INTO posts (user_id, title, content) VALUES (?, ?, ?)");
$pdo->beginTransaction();
for ($i = 1; $i <= 50000; $i++) {
    $stmt->execute([($i % 10000) + 1, "Post Title $i", "Content for post $i"]);
}
$pdo->commit();

// Insert tags
$stmt = $pdo->prepare("INSERT INTO tags (name) VALUES (?)");
$pdo->beginTransaction();
for ($i = 1; $i <= 100; $i++) {
    $stmt->execute(["Tag $i"]);
}
$pdo->commit();

// Insert pivot relations
$stmt = $pdo->prepare("INSERT INTO post_tag (post_id, tag_id) VALUES (?, ?)");
$pdo->beginTransaction();
for ($i = 1; $i <= 50000; $i++) {
    $stmt->execute([$i, ($i % 100) + 1]);
}
$pdo->commit();

echo "Database ready.\n\n";

// Initialize RapidBase and Cache
DB::setup($dsn, '', '', 'main');
CacheService::init($cacheDir);

// Helper: benchmark a closure with given iterations
function benchmark($name, callable $fn, $iterations = 100) {
    // Warmup (10 iteraciones sin medir)
    for ($i = 0; $i < 10; $i++) $fn();

    $start = microtime(true);
    for ($i = 0; $i < $iterations; $i++) $fn();
    $end = microtime(true);

    $avg = (($end - $start) / $iterations) * 1000; // milliseconds
    echo sprintf("%-40s: %.4f ms\n", $name, $avg);
    return $avg;
}

// ==================== SCENARIO 1: SIMPLE SELECT ====================
echo "--- SCENARIO 1: Simple Select (LIMIT 50, 100 iterations) ---\n";

$timePdoSimple = benchmark("PDO Native", function() use ($pdo) {
    $stmt = $pdo->query("SELECT * FROM users LIMIT 50");
    $stmt->fetchAll(PDO::FETCH_ASSOC);
});

// Gateway sin caché
CacheService::disable();
$timeGatewayNoCache = benchmark("Gateway (No Cache)", function() {
    Gateway::select('*', 'users', [], [], [], [], Q::page(1, 50));
});

// X sin caché
$timeXNoCache = benchmark("X (No Cache)", function() {
    X::con('main')->from('users')->select('*', Q::page(1, 50));
});

// Gateway con caché (warmup + medición)
CacheService::enable();
Gateway::selectCached('*', 'users', [], [], [], [], Q::page(1, 50), 3600);
$timeGatewayCache = benchmark("Gateway (Cache Hit)", function() {
    Gateway::selectCached('*', 'users', [], [], [], [], Q::page(1, 50), 3600);
});

// X con caché usando cached()
X::con('main')->from('users')->cached(3600)->select('*', Q::page(1, 50));
$timeXCache = benchmark("X (cached() - Cache Hit)", function() {
    X::con('main')->from('users')->cached(3600)->select('*', Q::page(1, 50));
});

// ==================== SCENARIO 2: FIRST() ====================
echo "\n--- SCENARIO 2: first() method (100 iterations) ---\n";

$timePdoFirst = benchmark("PDO Native (single row)", function() use ($pdo) {
    $stmt = $pdo->query("SELECT * FROM users LIMIT 1");
    $stmt->fetch(PDO::FETCH_ASSOC);
});

$timeGatewayFirst = benchmark("Gateway::one()", function() {
    Gateway::one('users', [], '*', null, false);
});

$timeXFirst = benchmark("X::first()", function() {
    X::con('main')->from('users')->first();
});

// ==================== SCENARIO 3: COUNT() ====================
echo "\n--- SCENARIO 3: count() method (100 iterations) ---\n";

$timePdoCount = benchmark("PDO Native COUNT(*)", function() use ($pdo) {
    $stmt = $pdo->query("SELECT COUNT(*) FROM users");
    $stmt->fetchColumn();
});

$timeGatewayCount = benchmark("Gateway::count()", function() {
    Gateway::count('users');
});

$timeXCount = benchmark("X::count()", function() {
    X::con('main')->from('users')->count();
});

// ==================== SCENARIO 4: JOIN 2 TABLES ====================
echo "\n--- SCENARIO 4: Join 2 Tables (posts + users, LIMIT 50, 50 iterations) ---\n";

$sqlJoin2 = "
    SELECT p.*, u.name as user_name
    FROM posts p
    JOIN users u ON p.user_id = u.id
    LIMIT 50
";

$timePdoJoin2 = benchmark("PDO Native", function() use ($pdo, $sqlJoin2) {
    $stmt = $pdo->query($sqlJoin2);
    $stmt->fetchAll(PDO::FETCH_ASSOC);
});

CacheService::disable();
$timeGatewayJoin2 = benchmark("Gateway (No Cache)", function() {
    Gateway::select(
        ['p.*', 'u.name as user_name'],
        ['posts AS p', ['users' => ['local_key' => 'user_id', 'foreign_key' => 'id', 'as' => 'u']]],
        [], [], [], [], Q::page(1, 50)
    );
});

$timeXJoin2 = benchmark("X (No Cache)", function() {
    X::con('main')
        ->from(['posts AS p', ['users' => ['local_key' => 'user_id', 'foreign_key' => 'id', 'as' => 'u']]])
        ->select(['p.*', 'u.name as user_name'], Q::page(1, 50));
});

CacheService::enable();
Gateway::selectCached(
    ['p.*', 'u.name as user_name'],
    ['posts AS p', ['users' => ['local_key' => 'user_id', 'foreign_key' => 'id', 'as' => 'u']]],
    [], [], [], [], Q::page(1, 50), 3600
);
$timeGatewayJoin2Cache = benchmark("Gateway (Cache Hit)", function() {
    Gateway::selectCached(
        ['p.*', 'u.name as user_name'],
        ['posts AS p', ['users' => ['local_key' => 'user_id', 'foreign_key' => 'id', 'as' => 'u']]],
        [], [], [], [], Q::page(1, 50), 3600
    );
});

X::con('main')
    ->from(['posts AS p', ['users' => ['local_key' => 'user_id', 'foreign_key' => 'id', 'as' => 'u']]])
    ->cached(3600)
    ->select(['p.*', 'u.name as user_name'], Q::page(1, 50));
$timeXJoin2Cache = benchmark("X (cached() - Cache Hit)", function() {
    X::con('main')
        ->from(['posts AS p', ['users' => ['local_key' => 'user_id', 'foreign_key' => 'id', 'as' => 'u']]])
        ->cached(3600)
        ->select(['p.*', 'u.name as user_name'], Q::page(1, 50));
});

// ==================== SCENARIO 5: JOIN 3 TABLES ====================
echo "\n--- SCENARIO 5: Join 3 Tables (posts + users + tags, LIMIT 50, 30 iterations) ---\n";

$sqlJoin3 = "
    SELECT p.*, u.name as user_name, t.name as tag_name
    FROM posts p
    JOIN users u ON p.user_id = u.id
    JOIN post_tag pt ON p.id = pt.post_id
    JOIN tags t ON pt.tag_id = t.id
    LIMIT 50
";

$timePdoJoin3 = benchmark("PDO Native", function() use ($pdo, $sqlJoin3) {
    $stmt = $pdo->query($sqlJoin3);
    $stmt->fetchAll(PDO::FETCH_ASSOC);
}, 30);

CacheService::disable();
$timeGatewayJoin3 = benchmark("Gateway (No Cache)", function() {
    Gateway::select(
        ['p.*', 'u.name as user_name', 't.name as tag_name'],
        [
            'posts AS p',
            ['users' => ['local_key' => 'user_id', 'foreign_key' => 'id', 'as' => 'u']],
            ['post_tag' => ['local_key' => 'id', 'foreign_key' => 'post_id', 'as' => 'pt']],
            ['tags' => ['local_key' => 'tag_id', 'foreign_key' => 'id', 'as' => 't']]
        ],
        [], [], [], [], Q::page(1, 50)
    );
}, 30);

$timeXJoin3 = benchmark("X (No Cache)", function() {
    X::con('main')
        ->from([
            'posts AS p',
            ['users' => ['local_key' => 'user_id', 'foreign_key' => 'id', 'as' => 'u']],
            ['post_tag' => ['local_key' => 'id', 'foreign_key' => 'post_id', 'as' => 'pt']],
            ['tags' => ['local_key' => 'tag_id', 'foreign_key' => 'id', 'as' => 't']]
        ])
        ->select(['p.*', 'u.name as user_name', 't.name as tag_name'], Q::page(1, 50));
}, 30);

CacheService::enable();
Gateway::selectCached(
    ['p.*', 'u.name as user_name', 't.name as tag_name'],
    [
        'posts AS p',
        ['users' => ['local_key' => 'user_id', 'foreign_key' => 'id', 'as' => 'u']],
        ['post_tag' => ['local_key' => 'id', 'foreign_key' => 'post_id', 'as' => 'pt']],
        ['tags' => ['local_key' => 'tag_id', 'foreign_key' => 'id', 'as' => 't']]
    ],
    [], [], [], [], Q::page(1, 50), 3600
);
$timeGatewayJoin3Cache = benchmark("Gateway (Cache Hit)", function() {
    Gateway::selectCached(
        ['p.*', 'u.name as user_name', 't.name as tag_name'],
        [
            'posts AS p',
            ['users' => ['local_key' => 'user_id', 'foreign_key' => 'id', 'as' => 'u']],
            ['post_tag' => ['local_key' => 'id', 'foreign_key' => 'post_id', 'as' => 'pt']],
            ['tags' => ['local_key' => 'tag_id', 'foreign_key' => 'id', 'as' => 't']]
        ],
        [], [], [], [], Q::page(1, 50), 3600
    );
}, 30);

X::con('main')
    ->from([
        'posts AS p',
        ['users' => ['local_key' => 'user_id', 'foreign_key' => 'id', 'as' => 'u']],
        ['post_tag' => ['local_key' => 'id', 'foreign_key' => 'post_id', 'as' => 'pt']],
        ['tags' => ['local_key' => 'tag_id', 'foreign_key' => 'id', 'as' => 't']]
    ])
    ->cached(3600)
    ->select(['p.*', 'u.name as user_name', 't.name as tag_name'], Q::page(1, 50));
$timeXJoin3Cache = benchmark("X (cached() - Cache Hit)", function() {
    X::con('main')
        ->from([
            'posts AS p',
            ['users' => ['local_key' => 'user_id', 'foreign_key' => 'id', 'as' => 'u']],
            ['post_tag' => ['local_key' => 'id', 'foreign_key' => 'post_id', 'as' => 'pt']],
            ['tags' => ['local_key' => 'tag_id', 'foreign_key' => 'id', 'as' => 't']]
        ])
        ->cached(3600)
        ->select(['p.*', 'u.name as user_name', 't.name as tag_name'], Q::page(1, 50));
}, 30);

// ==================== SCENARIO 6: GRID METHOD (actual) ====================
echo "\n--- SCENARIO 6: grid() method (pagination + total count, 50 iterations) ---\n";

$timeXGrid = benchmark("X::grid() (auto strategy - separate)", function() {
    X::con('main')->from('users')->grid('*', 1, 30);
});

$timeGatewayGrid = benchmark("Gateway::selectCached() + manual count", function() {
    $total = Gateway::count('users');
    Gateway::selectCached('*', 'users', [], [], [], [], Q::page(1, 30), 3600);
});

// ==================== SCENARIO 7: WINDOW vs SEPARATE (total strategies) ====================
echo "\n--- SCENARIO 7: Window function vs Separate COUNT (first page, 50 iterations) ---\n";

// Separate COUNT (estrategia que usa CountCache + select normal)
$timeSeparate = benchmark("Grid with separate COUNT (CountCache + LIMIT)", function() {
    X::con('main')->from('users')->totalStrategy('separate')->grid('*', 1, 30);
}, 50);

$timeWindow = benchmark("Grid with window FUNCTION (COUNT(*) OVER())", function() {
    X::con('main')->from('users')->totalStrategy('window')->grid('*', 1, 30);
}, 50);

// ==================== SUMMARY ====================
echo "\n==================================================\n";
echo "SUMMARY (PDO = 1.00x baseline)\n";
echo "==================================================\n";

function ratio($base, $compare) {
    return number_format($compare / $base, 2);
}
function faster($base, $compare) {
    return number_format($base / $compare, 1);
}

echo "\nSimple Select:\n";
echo "  PDO:               1.00x\n";
echo "  Gateway (no cache): " . ratio($timePdoSimple, $timeGatewayNoCache) . "x\n";
echo "  X (no cache):       " . ratio($timePdoSimple, $timeXNoCache) . "x\n";
echo "  Gateway (cache):    " . ratio($timePdoSimple, $timeGatewayCache) . "x (" . faster($timePdoSimple, $timeGatewayCache) . "x FASTER)\n";
echo "  X (cached()):       " . ratio($timePdoSimple, $timeXCache) . "x (" . faster($timePdoSimple, $timeXCache) . "x FASTER)\n";

echo "\nfirst() method:\n";
echo "  PDO:               1.00x\n";
echo "  Gateway::one():     " . ratio($timePdoFirst, $timeGatewayFirst) . "x\n";
echo "  X::first():         " . ratio($timePdoFirst, $timeXFirst) . "x\n";

echo "\ncount() method:\n";
echo "  PDO:               1.00x\n";
echo "  Gateway::count():   " . ratio($timePdoCount, $timeGatewayCount) . "x\n";
echo "  X::count():         " . ratio($timePdoCount, $timeXCount) . "x\n";

echo "\nJoin 2 Tables:\n";
echo "  PDO:               1.00x\n";
echo "  Gateway (no cache): " . ratio($timePdoJoin2, $timeGatewayJoin2) . "x\n";
echo "  X (no cache):       " . ratio($timePdoJoin2, $timeXJoin2) . "x\n";
echo "  Gateway (cache):    " . ratio($timePdoJoin2, $timeGatewayJoin2Cache) . "x (" . faster($timePdoJoin2, $timeGatewayJoin2Cache) . "x FASTER)\n";
echo "  X (cached()):       " . ratio($timePdoJoin2, $timeXJoin2Cache) . "x (" . faster($timePdoJoin2, $timeXJoin2Cache) . "x FASTER)\n";

echo "\nJoin 3 Tables:\n";
echo "  PDO:               1.00x\n";
echo "  Gateway (no cache): " . ratio($timePdoJoin3, $timeGatewayJoin3) . "x\n";
echo "  X (no cache):       " . ratio($timePdoJoin3, $timeXJoin3) . "x\n";
echo "  Gateway (cache):    " . ratio($timePdoJoin3, $timeGatewayJoin3Cache) . "x (" . faster($timePdoJoin3, $timeGatewayJoin3Cache) . "x FASTER)\n";
echo "  X (cached()):       " . ratio($timePdoJoin3, $timeXJoin3Cache) . "x (" . faster($timePdoJoin3, $timeXJoin3Cache) . "x FASTER)\n";

echo "\ngrid() method (auto strategy - separate, page 1, 30 rows):\n";
echo "  Gateway simulation: " . number_format($timeGatewayGrid, 4) . " ms\n";
echo "  X::grid():          " . number_format($timeXGrid, 4) . " ms\n";
echo "  X overhead:         " . ratio($timeGatewayGrid, $timeXGrid) . "x\n";

echo "\nTotal strategies comparison (first page of users):\n";
echo "  Separate COUNT:     " . number_format($timeSeparate, 4) . " ms\n";
echo "  Window FUNCTION:    " . number_format($timeWindow, 4) . " ms\n";
$ratioWindow = ($timeWindow > 0) ? number_format($timeSeparate / $timeWindow, 2) : 'N/A';
echo "  Ratio separate/window: " . $ratioWindow . "x\n";

echo "\n✅ Benchmark completed.\n";