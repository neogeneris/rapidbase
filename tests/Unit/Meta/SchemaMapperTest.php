<?php

/**
 * SchemaMapper & SchemaMap Test Suite
 * Tests schema discovery, mapping, feature detection, and SchemaMap API.
 * Uses SQLite in-memory database.
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use RapidBase\Core\SchemaMap;
use RapidBase\Core\SQL\ConditionMatrix;
use RapidBase\Meta\SchemaMapper;
use PDO;

$failed = 0;
function assert_test(string $msg, bool $cond): void
{
    global $failed;
    if ($cond) {
        echo "  [PASS] $msg\n";
    } else {
        echo "  [FAIL] $msg\n";
        $failed++;
    }
}

function section(string $title): void
{
    echo "\n--- $title ---\n";
}

echo "==================================================\n";
echo "SCHEMA MAPPER & SCHEMA MAP TEST SUITE\n";
echo "==================================================\n";

// ========================================================================
// SETUP
// ========================================================================
section("Setup: Creating SQLite in-memory test database");

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("PRAGMA foreign_keys = ON");

$pdo->exec("
    CREATE TABLE roles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        description TEXT
    );
    
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        email TEXT UNIQUE NOT NULL,
        role_id INTEGER,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (role_id) REFERENCES roles(id)
    );
    
    CREATE TABLE posts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        title TEXT NOT NULL,
        content TEXT,
        published INTEGER DEFAULT 0,
        FOREIGN KEY (user_id) REFERENCES users(id)
    );
    
    CREATE TABLE tags (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE
    );
    
    CREATE TABLE post_tags (
        post_id INTEGER NOT NULL,
        tag_id INTEGER NOT NULL,
        PRIMARY KEY (post_id, tag_id),
        FOREIGN KEY (post_id) REFERENCES posts(id),
        FOREIGN KEY (tag_id) REFERENCES tags(id)
    );
    
    CREATE TABLE categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        parent_id INTEGER,
        FOREIGN KEY (parent_id) REFERENCES categories(id)
    );
");

$tableCount = count($pdo->query(
    "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"
)->fetchAll(PDO::FETCH_COLUMN));

assert_test("Database created with 6 tables", $tableCount === 6);

// ========================================================================
// TEST 1: SchemaMapper::generate()
// ========================================================================
section("Test 1: SchemaMapper::generate()");

$outputDir = __DIR__ . '/../../tmp';
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0777, true);
}
$outputPath = $outputDir . '/test_schema_map.php';

if (file_exists($outputPath)) {
    unlink($outputPath);
}

SchemaMapper::setOutputFile($outputPath);
$result = SchemaMapper::generate($pdo, 'main', null, 'test_conn');

assert_test("SchemaMapper::generate() returns true", $result === true);
assert_test("Schema map file was created", file_exists($outputPath));

$map = include $outputPath;

assert_test("Generated map is an array", is_array($map));
assert_test("Map has 'connection' key", isset($map['connection']));
assert_test("Connection name is 'test_conn'", $map['connection'] === 'test_conn');
assert_test("Map has 'driver' key", isset($map['driver']));
assert_test("Driver is 'sqlite'", $map['driver'] === 'sqlite');
assert_test("Map has 'features' key", isset($map['features']));
assert_test("Map has 'relationships' key", isset($map['relationships']));
assert_test("Map has 'tables' key", isset($map['tables']));

$features = $map['features'];
assert_test("Features: driver is 'sqlite'", ($features['driver'] ?? '') === 'sqlite');
assert_test("Features: window_functions is true", ($features['window_functions'] ?? false) === true);

echo "\n  Features detected:\n";
foreach ($features as $key => $val) {
    $display = is_bool($val) ? ($val ? 'true' : 'false') : (is_string($val) ? $val : json_encode($val));
    echo "    $key => $display\n";
}

// ========================================================================
// TEST 2: SchemaMap - Load and Access
// ========================================================================
section("Test 2: SchemaMap - Load and Access");

SchemaMap::setMap($map, 'test_conn');
SchemaMap::setDefaultConnection('test_conn');

$retrievedMap = SchemaMap::getMap('test_conn');
assert_test("SchemaMap::getMap() returns array", is_array($retrievedMap));
assert_test("Retrieved map matches original", $retrievedMap === $map);

assert_test("getFeature('window_functions') is true",
    SchemaMap::getFeature('window_functions', false, 'test_conn') === true
);
assert_test("getFeature('driver') returns 'sqlite'",
    SchemaMap::getFeature('driver', '', 'test_conn') === 'sqlite'
);
assert_test("getFeature('nonexistent') returns default",
    SchemaMap::getFeature('nonexistent', 'DEFAULT', 'test_conn') === 'DEFAULT'
);

// ========================================================================
// TEST 3: SchemaMap - Table Discovery
// ========================================================================
section("Test 3: SchemaMap - Table Discovery");

$tables = array_keys($map['tables']);
echo "  Tables discovered: " . implode(', ', $tables) . "\n";

assert_test("hasTable('users')", SchemaMap::hasTable('users'));
assert_test("hasTable('posts')", SchemaMap::hasTable('posts'));
assert_test("hasTable('roles')", SchemaMap::hasTable('roles'));
assert_test("hasTable('tags')", SchemaMap::hasTable('tags'));
assert_test("hasTable('post_tags')", SchemaMap::hasTable('post_tags'));
assert_test("hasTable('categories')", SchemaMap::hasTable('categories'));
assert_test("hasTable('nonexistent') returns false", !SchemaMap::hasTable('nonexistent'));

// ========================================================================
// TEST 4: SchemaMap - Column Discovery
// ========================================================================
section("Test 4: SchemaMap - Column Discovery");

$userColumns = SchemaMap::getColumns('users', 'test_conn');
echo "  Users columns: " . implode(', ', array_keys($userColumns)) . "\n";

assert_test("Users has 'id' column", isset($userColumns['id']));
assert_test("Users has 'name' column", isset($userColumns['name']));
assert_test("Users has 'email' column", isset($userColumns['email']));
assert_test("Users has 'role_id' column", isset($userColumns['role_id']));
assert_test("Users has 'created_at' column", isset($userColumns['created_at']));
assert_test("hasColumn('users', 'email')", SchemaMap::hasColumn('users', 'email'));
assert_test("hasColumn('users', 'nonexistent') returns false", !SchemaMap::hasColumn('users', 'nonexistent'));

// Check column metadata directly from the map
assert_test("id column has 'type'", isset($userColumns['id']['type']));
assert_test("id column has 'primary'", isset($userColumns['id']['primary']));
assert_test("id column is primary key", ($userColumns['id']['primary'] ?? false) === true);
assert_test("name column has 'nullable'", isset($userColumns['name']['nullable']));

// ========================================================================
// TEST 5: Primary Keys (from column metadata)
// ========================================================================
section("Test 5: Primary Keys from Column Metadata");

// Find primary keys by inspecting columns
$userPKs = [];
foreach ($userColumns as $colName => $colDef) {
    if (!empty($colDef['primary'])) {
        $userPKs[] = $colName;
    }
}
assert_test("Users primary key found via columns", in_array('id', $userPKs));

// post_tags composite PK
$ptColumns = SchemaMap::getColumns('post_tags', 'test_conn');
$ptPKs = [];
foreach ($ptColumns as $colName => $colDef) {
    if (!empty($colDef['primary'])) {
        $ptPKs[] = $colName;
    }
}
echo "  post_tags PKs: " . implode(', ', $ptPKs) . "\n";
assert_test("post_tags has composite primary key", count($ptPKs) >= 2);
assert_test("post_tags PK includes post_id", in_array('post_id', $ptPKs));
assert_test("post_tags PK includes tag_id", in_array('tag_id', $ptPKs));

// ========================================================================
// TEST 6: Foreign Keys (from column metadata)
// ========================================================================
section("Test 6: Foreign Keys from Column Metadata");

// Find FKs by inspecting columns for 'foreign' and 'references'
$userFKs = [];
foreach ($userColumns as $colName => $colDef) {
    if (!empty($colDef['foreign']) && !empty($colDef['references'])) {
        $userFKs[$colName] = $colDef['references'];
    }
}
echo "  Users FKs: " . json_encode($userFKs) . "\n";
assert_test("Users has foreign key on role_id", isset($userFKs['role_id']));

$postColumns = SchemaMap::getColumns('posts', 'test_conn');
$postFKs = [];
foreach ($postColumns as $colName => $colDef) {
    if (!empty($colDef['foreign']) && !empty($colDef['references'])) {
        $postFKs[$colName] = $colDef['references'];
    }
}
echo "  Posts FKs: " . json_encode($postFKs) . "\n";
assert_test("Posts has foreign key on user_id", isset($postFKs['user_id']));

// ========================================================================
// TEST 7: Relationships
// ========================================================================
section("Test 7: Relationships");

$rels = $map['relationships'];
assert_test("Relationships has 'from' key", isset($rels['from']));
assert_test("Relationships has 'to' key", isset($rels['to']));

echo "\n  FROM relationships:\n";
foreach ($rels['from'] as $source => $targets) {
    foreach ($targets as $target => $rel) {
        $type = $rel['type'] ?? '?';
        $localKey = $rel['local_key'] ?? '?';
        $foreignKey = $rel['foreign_key'] ?? '?';
        echo "    $source -> $target [$type] ($localKey -> $foreignKey)\n";
    }
}

echo "\n  TO relationships:\n";
foreach ($rels['to'] as $source => $targets) {
    foreach ($targets as $target => $rel) {
        $type = $rel['type'] ?? '?';
        $localKey = $rel['local_key'] ?? '?';
        $foreignKey = $rel['foreign_key'] ?? '?';
        echo "    $source <- $target [$type] ($localKey <- $foreignKey)\n";
    }
}

// Check posts -> users relationship
$postToUser = $rels['from']['posts']['users'] ?? null;
assert_test("posts -> users relationship exists", $postToUser !== null);

// Check users <- posts relationship (inverse)
$userFromPosts = $rels['to']['users']['posts'] ?? null;
assert_test("users <- posts inverse relationship exists", $userFromPosts !== null);

// Check self-referencing categories
$catToCat = $rels['from']['categories']['categories'] ?? null;
assert_test("categories self-reference exists", $catToCat !== null);

// ========================================================================
// TEST 8: SchemaMap - Multiple Connections
// ========================================================================
section("Test 8: SchemaMap - Multiple Connections");

SchemaMap::setMap(['tables' => ['test_table' => []]], 'second_conn');
assert_test("Second connection has table", SchemaMap::hasTable('test_table', 'second_conn'));
assert_test("Default connection still has users", SchemaMap::hasTable('users'));
SchemaMap::clear();
assert_test("After clear, default connection is empty", empty(SchemaMap::getMap()));

// Re-set for remaining tests
SchemaMap::setMap($map, 'test_conn');
SchemaMap::setDefaultConnection('test_conn');

// ========================================================================
// TEST 9: ConditionMatrix Integration
// ========================================================================
section("Test 9: ConditionMatrix Integration");

ConditionMatrix::setDriver('sqlite');

$quoted = ConditionMatrix::quote('users');
assert_test("quote('users') works", $quoted === '"users"');

$quotedCol = ConditionMatrix::quote('users.email');
assert_test("quote('users.email') works", $quotedCol === '"users"."email"');

// Parse conditions using schema context
$parsed = ConditionMatrix::parse(
    ['email' => 'alice@test.com'],
    ['u' => 'users'],
    'u',
    $map
);
assert_test("ConditionMatrix::parse with schema context",
    strpos($parsed['sql'], 'email') !== false
);

// ========================================================================
// TEST 10: SchemaMap loadFromFile
// ========================================================================
section("Test 10: SchemaMap loadFromFile");

SchemaMap::loadFromFile($outputPath, 'from_file');
assert_test("loadFromFile loads tables", SchemaMap::hasTable('users', 'from_file'));
assert_test("loadFromFile loads features", !empty(SchemaMap::getFeatures('from_file')));

// ========================================================================
// TEST 11: Verify MySQLDiscovery compatibility
// ========================================================================
section("Test 11: DiscoveryFactory creates correct discovery");

$discovery = \RapidBase\Meta\Discovery\DiscoveryFactory::create($pdo);
assert_test("DiscoveryFactory creates instance for sqlite", $discovery !== null);

$allTables = $discovery->getTables('main');
assert_test("Discovery getTables returns tables", count($allTables) >= 6);

$columns = $discovery->discoverColumns('users', 'main');
assert_test("Discovery discoverColumns works", isset($columns['id']));
assert_test("Discovery detects primary key", ($columns['id']['primary'] ?? false) === true);

// ========================================================================
// CLEANUP
// ========================================================================
section("Cleanup");

if (file_exists($outputPath)) {
    unlink($outputPath);
    assert_test("Test schema map file removed", !file_exists($outputPath));
}

// ========================================================================
// RESULTS
// ========================================================================
echo "\n==================================================\n";
if ($failed === 0) {
    echo "RESULT: ALL TESTS PASSED\n";
    exit(0);
} else {
    echo "RESULT: $failed TEST(S) FAILED\n";
    exit(1);
}
echo "==================================================\n";