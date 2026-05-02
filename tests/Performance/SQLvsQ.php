<?php
/**
 * Performance Comparison: SQL (Legacy) vs Q (New)
 * 
 * This test compares the performance between the legacy SQL class
 * and the new Q fluent query builder.
 * 
 * Scenarios tested:
 * - Simple SELECT queries
 * - JOINs with 2, 3, 4, and 5 tables
 * - Subqueries
 * - Virtual tables (subqueries in FROM)
 * 
 * Usage: php tests/Performance/SQLvsQ.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . "/../../src/RapidBase/Core/SQL_Legacy.php";


use RapidBase\Core\SQL as SQL_Legacy;
use RapidBase\Core\SQL\Q;
use RapidBase\Core\DB;
use RapidBase\Core\SchemaMap;

// Configuration
$dbPath = __DIR__ . '/../tmp/sql_vs_q_test.sqlite';
$cacheDir = __DIR__ . '/../tmp/sql_vs_q_cache';

// Clean up previous test data
if (file_exists($dbPath)) {
    @unlink($dbPath);
}
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0777, true);
}
array_map('unlink', glob("$cacheDir/*"));

echo "==================================================\n";
echo "SQL (LEGACY) vs Q (NEW) PERFORMANCE COMPARISON\n";
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
    $role = $i % 5 === 0 ? 'admin' : 'user';
    $country = ['US', 'UK', 'CA', 'DE', 'FR'][$i % 5];
    $stmt->execute(["User $i", "user$i@example.com", $status, $role, $country]);
}
$pdo->commit();

// Categories (100 records)
$stmt = $pdo->prepare("INSERT INTO categories (name, parent_id, description) VALUES (?, ?, ?)");
$pdo->beginTransaction();
for ($i = 1; $i <= 100; $i++) {
    $parentId = $i > 10 ? ($i % 10) + 1 : null;
    $stmt->execute(["Category $i", $parentId, "Description for category $i"]);
}
$pdo->commit();

// Posts (10,000 records)
$stmt = $pdo->prepare("INSERT INTO posts (user_id, title, content, views, status, category_id) VALUES (?, ?, ?, ?, ?, ?)");
$pdo->beginTransaction();
for ($i = 1; $i <= 10000; $i++) {
    $userId = ($i % 2000) + 1;
    $categoryId = ($i % 100) + 1;
    $status = $i % 5 === 0 ? 'draft' : 'published';
    $stmt->execute([
        $userId,
        "Post Title $i",
        "Content for post $i...",
        $i * 10,
        $status,
        $categoryId
    ]);
}
$pdo->commit();

// Post-Category relationships (15,000 records)
$stmt = $pdo->prepare("INSERT OR IGNORE INTO post_category (post_id, category_id, priority) VALUES (?, ?, ?)");
$pdo->beginTransaction();
for ($i = 1; $i <= 15000; $i++) {
    $postId = (($i * 7) % 10000) + 1; // Use different distribution to avoid duplicates
    $categoryId = (($i * 3) % 100) + 1;
    $priority = ($i % 5) + 1;
    $stmt->execute([$postId, $categoryId, $priority]);
}
$pdo->commit();

// Comments (30,000 records)
$stmt = $pdo->prepare("INSERT INTO comments (post_id, user_id, content, rating, is_approved) VALUES (?, ?, ?, ?, ?)");
$pdo->beginTransaction();
for ($i = 1; $i <= 30000; $i++) {
    $postId = ($i % 10000) + 1;
    $userId = ($i % 2000) + 1;
    $rating = $i % 6;
    $approved = $i % 3 === 0 ? 0 : 1;
    $stmt->execute([$postId, $userId, "Comment content $i", $rating, $approved]);
}
$pdo->commit();

echo "Test data inserted successfully.\n\n";

// Setup DB and SchemaMap
DB::setup('sqlite:' . $dbPath, '', '', 'main');

// Load schema map for Q to work properly
$schemaMap = [
    'tables' => [
        'users' => [
            'id' => 'INTEGER',
            'name' => 'TEXT',
            'email' => 'TEXT',
            'status' => 'TEXT',
            'role' => 'TEXT',
            'country' => 'TEXT',
            'created_at' => 'DATETIME'
        ],
        'posts' => [
            'id' => 'INTEGER',
            'user_id' => 'INTEGER',
            'title' => 'TEXT',
            'content' => 'TEXT',
            'views' => 'INTEGER',
            'status' => 'TEXT',
            'category_id' => 'INTEGER',
            'created_at' => 'DATETIME'
        ],
        'categories' => [
            'id' => 'INTEGER',
            'name' => 'TEXT',
            'parent_id' => 'INTEGER',
            'description' => 'TEXT',
            'created_at' => 'DATETIME'
        ],
        'post_category' => [
            'post_id' => 'INTEGER',
            'category_id' => 'INTEGER',
            'priority' => 'INTEGER'
        ],
        'comments' => [
            'id' => 'INTEGER',
            'post_id' => 'INTEGER',
            'user_id' => 'INTEGER',
            'content' => 'TEXT',
            'rating' => 'INTEGER',
            'is_approved' => 'INTEGER',
            'created_at' => 'DATETIME'
        ]
    ],
    'relationships' => [
        'from' => [
            'posts' => [
                'users' => ['type' => 'belongsTo', 'local_key' => 'user_id', 'foreign_key' => 'id']
            ],
            'comments' => [
                'posts' => ['type' => 'belongsTo', 'local_key' => 'post_id', 'foreign_key' => 'id'],
                'users' => ['type' => 'belongsTo', 'local_key' => 'user_id', 'foreign_key' => 'id']
            ],
            'post_category' => [
                'posts' => ['type' => 'belongsTo', 'local_key' => 'post_id', 'foreign_key' => 'id'],
                'categories' => ['type' => 'belongsTo', 'local_key' => 'category_id', 'foreign_key' => 'id']
            ]
        ],
        'to' => [
            'users' => [
                'posts' => ['type' => 'hasMany', 'local_key' => 'user_id', 'foreign_key' => 'id'],
                'comments' => ['type' => 'hasMany', 'local_key' => 'user_id', 'foreign_key' => 'id']
            ],
            'posts' => [
                'comments' => ['type' => 'hasMany', 'local_key' => 'post_id', 'foreign_key' => 'id'],
                'post_category' => ['type' => 'hasMany', 'local_key' => 'post_id', 'foreign_key' => 'id']
            ],
            'categories' => [
                'post_category' => ['type' => 'hasMany', 'local_key' => 'category_id', 'foreign_key' => 'id']
            ]
        ]
    ]
];

SchemaMap::setMap($schemaMap, 'main');
SQL_Legacy::setRelationsMap($schemaMap);

// Disable cache for fair comparison
SQL_Legacy::setQueryCacheEnabled(false);

// Helper function to measure execution time
function benchmark(callable $fn, int $iterations = 100): array
{
    // Warmup
    $fn();
    
    $times = [];
    $startTotal = microtime(true);
    
    for ($i = 0; $i < $iterations; $i++) {
        $start = microtime(true);
        $fn();
        $end = microtime(true);
        $times[] = ($end - $start) * 1000; // Convert to milliseconds
    }
    
    $totalTime = (microtime(true) - $startTotal) * 1000;
    
    // Calculate statistics
    sort($times);
    $avg = array_sum($times) / count($times);
    $min = $times[0];
    $max = $times[count($times) - 1];
    $median = $times[floor(count($times) / 2)];
    
    return [
        'avg' => $avg,
        'min' => $min,
        'max' => $max,
        'median' => $median,
        'total' => $totalTime,
        'iterations' => $iterations
    ];
}

// Format results
function formatResults(string $testName, array $sqlResult, array $qResult): void
{
    echo str_repeat('-', 70) . "\n";
    echo "TEST: $testName\n";
    echo str_repeat('-', 70) . "\n";
    
    echo sprintf("SQL Legacy:  Avg: %8.4f ms | Median: %8.4f ms | Total: %8.2f ms\n",
        $sqlResult['avg'], $sqlResult['median'], $sqlResult['total']);
    echo sprintf("Q New:       Avg: %8.4f ms | Median: %8.4f ms | Total: %8.2f ms\n",
        $qResult['avg'], $qResult['median'], $qResult['total']);
    
    $diff = (($qResult['avg'] - $sqlResult['avg']) / $sqlResult['avg']) * 100;
    $speedup = $sqlResult['avg'] / $qResult['avg'];
    
    if ($diff < 0) {
        echo sprintf("✓ Q is %.2f%% FASTER (%.2fx speedup)\n", abs($diff), $speedup);
    } else {
        echo sprintf("✗ Q is %.2f%% SLOWER (%.2fx slowdown)\n", $diff, 1/$speedup);
    }
    echo "\n";
}

// ==========================================
// TEST 1: Simple SELECT (1 table)
// ==========================================
echo "\n=== TEST 1: SIMPLE SELECT (1 TABLE) ===\n";

$sqlFn = function() {
    SQL_Legacy::reset();
    SQL_Legacy::buildSelect(
        ['id', 'name', 'email', 'status'],
        'users',
        ['status' => 'active', 'country' => 'US'],
        [],
        [],
        ['-created_at'],
        0
    );
};

$qFn = function() {
    Q::from('users', ['status' => 'active', 'country' => 'US'])
        ->select(['id', 'name', 'email', 'status'], null, ['-created_at']);
};

$sqlResult = benchmark($sqlFn, 100);
$qResult = benchmark($qFn, 100);
formatResults("Simple SELECT with WHERE and ORDER BY", $sqlResult, $qResult);

// ==========================================
// TEST 2: JOIN 2 Tables
// ==========================================
echo "\n=== TEST 2: JOIN 2 TABLES ===\n";

$sqlFn = function() {
    SQL_Legacy::reset();
    SQL_Legacy::buildSelect(
        ['u.id', 'u.name', 'p.title', 'p.views'],
        ['users AS u', 'posts AS p'],
        ['u.status' => 'active', 'p.status' => 'published'],
        [],
        [],
        ['-p.views'],
        0
    );
};

$qFn = function() {
    Q::from(['users AS u', 'posts AS p'], ['u.status' => 'active', 'p.status' => 'published'])
        ->select(['u.id', 'u.name', 'p.title', 'p.views'], null, ['-p.views']);
};

$sqlResult = benchmark($sqlFn, 100);
$qResult = benchmark($qFn, 100);
formatResults("JOIN 2 Tables (users + posts)", $sqlResult, $qResult);

// ==========================================
// TEST 3: JOIN 3 Tables
// ==========================================
echo "\n=== TEST 3: JOIN 3 TABLES ===\n";

$sqlFn = function() {
    SQL_Legacy::reset();
    // Use posts -> users (belongsTo) and posts -> comments (hasMany)
    // This creates a valid join tree through posts
    SQL_Legacy::buildSelect(
        ['u.id', 'u.name', 'p.title', 'cm.content'],
        ['users AS u', 'posts AS p', 'comments AS cm'],
        ['u.status' => 'active', 'p.status' => 'published'],
        [],
        [],
        ['-p.views'],
        0
    );
};

$qFn = function() {
    Q::from(['users AS u', 'posts AS p', 'comments AS cm'], 
            ['u.status' => 'active', 'p.status' => 'published'])
        ->select(['u.id', 'u.name', 'p.title', 'cm.content'], null, ['-p.views']);
};

$sqlResult = benchmark($sqlFn, 100);
$qResult = benchmark($qFn, 100);
formatResults("JOIN 3 Tables (users + posts + comments)", $sqlResult, $qResult);

// ==========================================
// TEST 4: JOIN 4 Tables
// ==========================================
echo "\n=== TEST 4: JOIN 4 TABLES ===\n";

$sqlFn = function() {
    SQL_Legacy::reset();
    // Use posts -> users (belongsTo), posts -> comments (hasMany), comments -> users (belongsTo)
    // This creates a valid join tree through posts and comments
    SQL_Legacy::buildSelect(
        ['u.id', 'u.name', 'p.title', 'cm.content', 'cm.rating'],
        ['users AS u', 'posts AS p', 'comments AS cm', 'post_category AS pc'],
        ['u.status' => 'active', 'p.status' => 'published', 'cm.is_approved' => 1],
        [],
        [],
        ['-cm.rating', '-p.views'],
        0
    );
};

$qFn = function() {
    Q::from(['users AS u', 'posts AS p', 'comments AS cm', 'post_category AS pc'],
            ['u.status' => 'active', 'p.status' => 'published', 'cm.is_approved' => 1])
        ->select(['u.id', 'u.name', 'p.title', 'cm.content', 'cm.rating'],
                 null, ['-cm.rating', '-p.views']);
};

$sqlResult = benchmark($sqlFn, 100);
$qResult = benchmark($qFn, 100);
formatResults("JOIN 4 Tables (users + posts + comments + post_category)", $sqlResult, $qResult);

// ==========================================
// TEST 5: JOIN 5 Tables
// ==========================================
echo "\n=== TEST 5: JOIN 5 TABLES ===\n";

$sqlFn = function() {
    SQL_Legacy::reset();
    // All tables connected through posts as the central hub
    SQL_Legacy::buildSelect(
        ['u.id', 'u.name', 'p.title', 'cm.content', 'cm.rating', 'pc.priority'],
        ['users AS u', 'posts AS p', 'comments AS cm', 'post_category AS pc', 'categories AS cat'],
        ['u.status' => 'active', 'p.status' => 'published', 'cm.is_approved' => 1],
        [],
        [],
        ['-cm.rating', '-p.views'],
        0
    );
};

$qFn = function() {
    Q::from(['users AS u', 'posts AS p', 'comments AS cm', 'post_category AS pc', 'categories AS cat'],
            ['u.status' => 'active', 'p.status' => 'published', 'cm.is_approved' => 1])
        ->select(['u.id', 'u.name', 'p.title', 'cm.content', 'cm.rating', 'pc.priority'],
                 null, ['-cm.rating', '-p.views']);
};

$sqlResult = benchmark($sqlFn, 100);
$qResult = benchmark($qFn, 100);
formatResults("JOIN 5 Tables (full join)", $sqlResult, $qResult);

// ==========================================
// TEST 6: Subquery in WHERE (IN clause)
// ==========================================
echo "\n=== TEST 6: SUBQUERY IN WHERE (IN CLAUSE) ===\n";

$sqlFn = function() {
    SQL_Legacy::reset();
    // Legacy SQL doesn't have direct subquery support, so we simulate it
    $subquery = SQL_Legacy::buildSelect(
        'user_id',
        'posts',
        ['status' => 'published'],
        [],
        [],
        []
    );
    
    // Extract user IDs from subquery result (simulated)
    $userIds = range(1, 100); // Simulated result
    
    SQL_Legacy::buildSelect(
        ['id', 'name', 'email'],
        'users',
        ['id' => $userIds], // IN clause
        [],
        [],
        ['-created_at']
    );
};

$qFn = function() {
    // Q also doesn't have direct subquery in WHERE yet, using same approach
    $userIds = range(1, 100); // Simulated result
    
    Q::from('users', ['id' => $userIds])
        ->select(['id', 'name', 'email'], null, ['-created_at']);
};

$sqlResult = benchmark($sqlFn, 100);
$qResult = benchmark($qFn, 100);
formatResults("Subquery simulation (IN clause)", $sqlResult, $qResult);

// ==========================================
// TEST 7: Virtual Table (Subquery in FROM)
// ==========================================
echo "\n=== TEST 7: VIRTUAL TABLE (SUBQUERY IN FROM) ===\n";

$sqlFn = function() {
    SQL_Legacy::reset();
    // Using virtual table (subquery in FROM)
    $virtualTable = '(SELECT id, user_id, title, views FROM posts WHERE status = ?)';
    
    SQL_Legacy::buildSelect(
        ['vt.title', 'vt.views', 'u.name'],
        [$virtualTable . ' AS vt', 'users AS u'],
        ['u.status' => 'active', 'vt.views' => ['>' => 100]],
        [],
        [],
        ['-vt.views']
    );
};

$qFn = function() {
    // Q supports virtual tables via string in from()
    Q::from(['(SELECT id, user_id, title, views FROM posts WHERE status = \'published\') AS vt', 'users AS u'],
            ['u.status' => 'active', 'vt.views' => ['>' => 100]])
        ->select(['vt.title', 'vt.views', 'u.name'], null, ['-vt.views']);
};

$sqlResult = benchmark($sqlFn, 100);
$qResult = benchmark($qFn, 100);
formatResults("Virtual Table (subquery in FROM)", $sqlResult, $qResult);

// ==========================================
// TEST 8: COUNT Query
// ==========================================
echo "\n=== TEST 8: COUNT QUERY ===\n";

$sqlFn = function() {
    SQL_Legacy::reset();
    SQL_Legacy::buildSelect(
        'COUNT(*) AS total',
        'posts',
        ['status' => 'published'],
        [],
        [],
        []
    );
};

$qFn = function() {
    Q::from('posts', ['status' => 'published'])->count();
};

$sqlResult = benchmark($sqlFn, 100);
$qResult = benchmark($qFn, 100);
formatResults("COUNT Query", $sqlResult, $qResult);

// ==========================================
// SUMMARY
// ==========================================
echo "\n";
echo "==================================================\n";
echo "SUMMARY\n";
echo "==================================================\n";
echo "This test compared SQL (Legacy) vs Q (New) across:\n";
echo "  - Simple SELECT queries\n";
echo "  - JOINs with 2, 3, 4, and 5 tables\n";
echo "  - Subqueries (simulated IN clause)\n";
echo "  - Virtual tables (subqueries in FROM)\n";
echo "  - COUNT queries\n";
echo "\n";
echo "Both classes support:\n";
echo "  ✓ Matrix-based WHERE conditions\n";
echo "  ✓ Automatic JOIN resolution via SchemaMap\n";
echo "  ✓ Manual JOIN specifications\n";
echo "  ✓ ORDER BY with prefix notation (-field for DESC)\n";
echo "  ✓ Pagination\n";
echo "  ✓ Virtual tables (subqueries in FROM)\n";
echo "\n";
echo "Key differences:\n";
echo "  - SQL: Static methods, traditional API\n";
echo "  - Q:   Fluent interface, method chaining\n";
echo "\n";
echo "Note: Cache was disabled for fair comparison.\n";
echo "==================================================\n";

// Cleanup
echo "\nTest completed. Database file: $dbPath\n";
