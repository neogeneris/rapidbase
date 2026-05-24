<?php

/**
 * SchemaMap Test Suite
 * Tests SchemaMap API: reading, accessing, and querying schema metadata.
 * Uses a manually constructed map to isolate from SchemaMapper/Discovery.
 */

require_once __DIR__ . '/../../../bin/RapidBase.php';

use RapidBase\Core\SchemaMap;
use RapidBase\Core\SQL\ConditionMatrix;

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
echo "SCHEMA MAP TEST SUITE\n";
echo "==================================================\n";

// ========================================================================
// BUILD A MANUAL SCHEMA MAP FOR TESTING
// ========================================================================
section("Setup: Building manual schema map");

$testMap = [
    'connection' => 'test_conn',
    'driver'     => 'sqlite',
    'checksum'   => 'abc123',
    'generated_at' => date('Y-m-d H:i:s'),
    'features' => [
        'driver'           => 'sqlite',
        'driver_version'   => '3.39.2',
        'window_functions' => true,
        'atomic_upsert'    => true,
        'cte'              => true,
        'transactions'     => true,
        'limit_on_update'  => false,
    ],
    'relationships' => [
        'from' => [
            'users' => [
                'profiles' => [
                    'type'        => 'hasOne',
                    'local_key'   => 'id',
                    'foreign_key' => 'user_id',
                ],
                'posts' => [
                    'type'        => 'hasMany',
                    'local_key'   => 'id',
                    'foreign_key' => 'user_id',
                ],
            ],
            'posts' => [
                'users' => [
                    'type'        => 'belongsTo',
                    'local_key'   => 'user_id',
                    'foreign_key' => 'id',
                ],
                'categories' => [
                    'type'        => 'belongsTo',
                    'local_key'   => 'category_id',
                    'foreign_key' => 'id',
                ],
            ],
            'post_tags' => [
                'posts' => [
                    'type'        => 'belongsTo',
                    'local_key'   => 'post_id',
                    'foreign_key' => 'id',
                ],
                'tags' => [
                    'type'        => 'belongsTo',
                    'local_key'   => 'tag_id',
                    'foreign_key' => 'id',
                ],
            ],
            'categories' => [
                'categories' => [
                    'type'        => 'belongsTo',
                    'local_key'   => 'parent_id',
                    'foreign_key' => 'id',
                ],
            ],
        ],
        'to' => [
            'profiles' => [
                'users' => [
                    'type'        => 'belongsTo',
                    'local_key'   => 'user_id',
                    'foreign_key' => 'id',
                ],
            ],
            'posts' => [
                'post_tags' => [
                    'type'        => 'hasMany',
                    'local_key'   => 'id',
                    'foreign_key' => 'post_id',
                ],
            ],
            'tags' => [
                'post_tags' => [
                    'type'        => 'hasMany',
                    'local_key'   => 'id',
                    'foreign_key' => 'tag_id',
                ],
            ],
            'categories' => [
                'posts' => [
                    'type'        => 'hasMany',
                    'local_key'   => 'id',
                    'foreign_key' => 'category_id',
                ],
                'categories' => [
                    'type'        => 'hasMany',
                    'local_key'   => 'id',
                    'foreign_key' => 'parent_id',
                ],
            ],
        ],
    ],
    'tables' => [
        'users' => [
            'id' => [
                'type'     => 'INTEGER',
                'primary'  => true,
                'foreign'  => false,
                'nullable' => false,
                'default'  => null,
            ],
            'name' => [
                'type'     => 'VARCHAR(100)',
                'primary'  => false,
                'foreign'  => false,
                'nullable' => false,
                'default'  => null,
            ],
            'email' => [
                'type'     => 'VARCHAR(150)',
                'primary'  => false,
                'foreign'  => false,
                'nullable' => false,
                'default'  => null,
            ],
            'role' => [
                'type'     => 'VARCHAR(20)',
                'primary'  => false,
                'foreign'  => false,
                'nullable' => false,
                'default'  => 'user',
            ],
            'created_at' => [
                'type'     => 'DATETIME',
                'primary'  => false,
                'foreign'  => false,
                'nullable' => true,
                'default'  => null,
            ],
        ],
        'profiles' => [
            'id' => [
                'type'     => 'INTEGER',
                'primary'  => true,
                'foreign'  => false,
                'nullable' => false,
                'default'  => null,
            ],
            'user_id' => [
                'type'       => 'INTEGER',
                'primary'    => false,
                'foreign'    => true,
                'nullable'   => false,
                'default'    => null,
                'references' => ['table' => 'users', 'column' => 'id'],
            ],
            'bio' => [
                'type'     => 'TEXT',
                'primary'  => false,
                'foreign'  => false,
                'nullable' => true,
                'default'  => null,
            ],
            'phone' => [
                'type'     => 'VARCHAR(20)',
                'primary'  => false,
                'foreign'  => false,
                'nullable' => true,
                'default'  => null,
            ],
        ],
        'posts' => [
            'id' => [
                'type'     => 'INTEGER',
                'primary'  => true,
                'foreign'  => false,
                'nullable' => false,
                'default'  => null,
            ],
            'user_id' => [
                'type'       => 'INTEGER',
                'primary'    => false,
                'foreign'    => true,
                'nullable'   => false,
                'default'    => null,
                'references' => ['table' => 'users', 'column' => 'id'],
            ],
            'category_id' => [
                'type'       => 'INTEGER',
                'primary'    => false,
                'foreign'    => true,
                'nullable'   => true,
                'default'    => null,
                'references' => ['table' => 'categories', 'column' => 'id'],
            ],
            'title' => [
                'type'     => 'VARCHAR(200)',
                'primary'  => false,
                'foreign'  => false,
                'nullable' => false,
                'default'  => null,
            ],
            'content' => [
                'type'     => 'TEXT',
                'primary'  => false,
                'foreign'  => false,
                'nullable' => true,
                'default'  => null,
            ],
            'published' => [
                'type'     => 'TINYINT',
                'primary'  => false,
                'foreign'  => false,
                'nullable' => false,
                'default'  => 0,
            ],
        ],
        'tags' => [
            'id' => [
                'type'     => 'INTEGER',
                'primary'  => true,
                'foreign'  => false,
                'nullable' => false,
                'default'  => null,
            ],
            'name' => [
                'type'     => 'VARCHAR(50)',
                'primary'  => false,
                'foreign'  => false,
                'nullable' => false,
                'default'  => null,
            ],
        ],
        'post_tags' => [
            'post_id' => [
                'type'       => 'INTEGER',
                'primary'    => true,
                'foreign'    => true,
                'nullable'   => false,
                'default'    => null,
                'references' => ['table' => 'posts', 'column' => 'id'],
            ],
            'tag_id' => [
                'type'       => 'INTEGER',
                'primary'    => true,
                'foreign'    => true,
                'nullable'   => false,
                'default'    => null,
                'references' => ['table' => 'tags', 'column' => 'id'],
            ],
        ],
        'categories' => [
            'id' => [
                'type'     => 'INTEGER',
                'primary'  => true,
                'foreign'  => false,
                'nullable' => false,
                'default'  => null,
            ],
            'name' => [
                'type'     => 'VARCHAR(100)',
                'primary'  => false,
                'foreign'  => false,
                'nullable' => false,
                'default'  => null,
            ],
            'parent_id' => [
                'type'       => 'INTEGER',
                'primary'    => false,
                'foreign'    => true,
                'nullable'   => true,
                'default'    => null,
                'references' => ['table' => 'categories', 'column' => 'id'],
            ],
        ],
        'description' => [  // Table named 'description' to test special key collision
            'id' => [
                'type'     => 'INTEGER',
                'primary'  => true,
                'foreign'  => false,
                'nullable' => false,
                'default'  => null,
            ],
            'description' => [  // Column also named 'description'
                'type'     => 'TEXT',
                'primary'  => false,
                'foreign'  => false,
                'nullable' => true,
                'default'  => null,
            ],
        ],
    ],
];

echo "  [OK] Manual schema map created with " . count($testMap['tables']) . " tables\n";

// ========================================================================
// TEST 1: setMap / getMap
// ========================================================================
section("Test 1: setMap / getMap");

SchemaMap::clear();
SchemaMap::setMap($testMap, 'test_conn');
SchemaMap::setDefaultConnection('test_conn');

$retrieved = SchemaMap::getMap('test_conn');
assert_test("getMap returns array", is_array($retrieved));
assert_test("getMap matches original", $retrieved === $testMap);
assert_test("getMap without param uses default", SchemaMap::getMap() === $testMap);

// ========================================================================
// TEST 2: hasTable
// ========================================================================
section("Test 2: hasTable");

assert_test("hasTable('users')", SchemaMap::hasTable('users'));
assert_test("hasTable('posts')", SchemaMap::hasTable('posts'));
assert_test("hasTable('profiles')", SchemaMap::hasTable('profiles'));
assert_test("hasTable('tags')", SchemaMap::hasTable('tags'));
assert_test("hasTable('post_tags')", SchemaMap::hasTable('post_tags'));
assert_test("hasTable('categories')", SchemaMap::hasTable('categories'));
assert_test("hasTable('description')", SchemaMap::hasTable('description'));
assert_test("hasTable('nonexistent') returns false", !SchemaMap::hasTable('nonexistent'));

// Case insensitivity
assert_test("hasTable('USERS') case insensitive", SchemaMap::hasTable('USERS'));
assert_test("hasTable('Users') case insensitive", SchemaMap::hasTable('Users'));

// ========================================================================
// TEST 3: getTable
// ========================================================================
section("Test 3: getTable");

$usersTable = SchemaMap::getTable('users');
assert_test("getTable('users') returns array", is_array($usersTable));
assert_test("getTable('users') has columns", count($usersTable) >= 4);
assert_test("getTable('nonexistent') returns null", SchemaMap::getTable('nonexistent') === null);

// ========================================================================
// TEST 4: getColumns
// ========================================================================
section("Test 4: getColumns");

$userColumns = SchemaMap::getColumns('users');
echo "  Users columns: " . implode(', ', array_keys($userColumns)) . "\n";

assert_test("getColumns returns 5 columns", count($userColumns) === 5);
assert_test("has 'id'", isset($userColumns['id']));
assert_test("has 'name'", isset($userColumns['name']));
assert_test("has 'email'", isset($userColumns['email']));
assert_test("has 'role'", isset($userColumns['role']));
assert_test("has 'created_at'", isset($userColumns['created_at']));
assert_test("'id' has type INTEGER", $userColumns['id']['type'] === 'INTEGER');
assert_test("'id' is primary", $userColumns['id']['primary'] === true);
assert_test("'name' is not nullable", $userColumns['name']['nullable'] === false);
assert_test("'role' has default 'user'", $userColumns['role']['default'] === 'user');
assert_test("'created_at' is nullable", $userColumns['created_at']['nullable'] === true);

// Test table with column named 'description' (collision with special key)
$descColumns = SchemaMap::getColumns('description');
echo "  Description table columns: " . implode(', ', array_keys($descColumns)) . "\n";
assert_test("Table 'description' has column 'description'", isset($descColumns['description']));
assert_test("Column 'description' has type TEXT", ($descColumns['description']['type'] ?? '') === 'TEXT');

// Test pivot table (composite PK)
$ptColumns = SchemaMap::getColumns('post_tags');
echo "  post_tags columns: " . implode(', ', array_keys($ptColumns)) . "\n";
assert_test("post_tags has 2 columns", count($ptColumns) === 2);
assert_test("post_id is primary", $ptColumns['post_id']['primary'] === true);
assert_test("tag_id is primary", $ptColumns['tag_id']['primary'] === true);
assert_test("post_id is foreign", $ptColumns['post_id']['foreign'] === true);
assert_test("tag_id is foreign", $ptColumns['tag_id']['foreign'] === true);

// ========================================================================
// TEST 5: hasColumn
// ========================================================================
section("Test 5: hasColumn");

assert_test("hasColumn('users', 'email')", SchemaMap::hasColumn('users', 'email'));
assert_test("hasColumn('users', 'name')", SchemaMap::hasColumn('users', 'name'));
assert_test("hasColumn('users', 'id')", SchemaMap::hasColumn('users', 'id'));
assert_test("hasColumn('users', 'nonexistent') returns false", !SchemaMap::hasColumn('users', 'nonexistent'));
assert_test("hasColumn('posts', 'user_id')", SchemaMap::hasColumn('posts', 'user_id'));
assert_test("hasColumn('posts', 'title')", SchemaMap::hasColumn('posts', 'title'));

// ========================================================================
// TEST 6: getPrimaryKeys
// ========================================================================
section("Test 6: getPrimaryKeys");

$userPKs = [];
foreach (SchemaMap::getColumns('users') as $col => $def) {
    if (!empty($def['primary'])) $userPKs[] = $col;
}
echo "  Users PKs: " . implode(', ', $userPKs) . "\n";
assert_test("Users has 'id' as PK", in_array('id', $userPKs));
assert_test("Users has single PK", count($userPKs) === 1);

$ptPKs = [];
foreach (SchemaMap::getColumns('post_tags') as $col => $def) {
    if (!empty($def['primary'])) $ptPKs[] = $col;
}
echo "  post_tags PKs: " . implode(', ', $ptPKs) . "\n";
assert_test("post_tags has composite PK", count($ptPKs) === 2);
assert_test("post_tags PK includes post_id", in_array('post_id', $ptPKs));
assert_test("post_tags PK includes tag_id", in_array('tag_id', $ptPKs));

// ========================================================================
// TEST 7: getForeignKeys
// ========================================================================
section("Test 7: getForeignKeys");

// Extract FKs from column metadata
function getFKs(string $table): array {
    $fks = [];
    foreach (SchemaMap::getColumns($table) as $col => $def) {
        if (!empty($def['foreign']) && !empty($def['references'])) {
            $fks[$col] = $def['references'];
        }
    }
    return $fks;
}

$profileFKs = getFKs('profiles');
echo "  profiles FKs: " . json_encode($profileFKs) . "\n";
assert_test("profiles.user_id -> users.id", 
    ($profileFKs['user_id']['table'] ?? '') === 'users' &&
    ($profileFKs['user_id']['column'] ?? '') === 'id'
);

$postFKs = getFKs('posts');
echo "  posts FKs: " . json_encode($postFKs) . "\n";
assert_test("posts has 2 FKs", count($postFKs) === 2);
assert_test("posts.user_id -> users.id", ($postFKs['user_id']['table'] ?? '') === 'users');
assert_test("posts.category_id -> categories.id", ($postFKs['category_id']['table'] ?? '') === 'categories');

$catFKs = getFKs('categories');
echo "  categories FKs: " . json_encode($catFKs) . "\n";
assert_test("categories.parent_id self-reference", 
    ($catFKs['parent_id']['table'] ?? '') === 'categories'
);

// ========================================================================
// TEST 8: getFeatures / getFeature
// ========================================================================
section("Test 8: getFeatures / getFeature");

$features = SchemaMap::getFeatures('test_conn');
assert_test("getFeatures returns array", is_array($features));
assert_test("Features has 'driver'", isset($features['driver']));
assert_test("Features has 'window_functions'", isset($features['window_functions']));

assert_test("getFeature('driver') returns 'sqlite'", 
    SchemaMap::getFeature('driver', '', 'test_conn') === 'sqlite'
);
assert_test("getFeature('window_functions') returns true", 
    SchemaMap::getFeature('window_functions', false, 'test_conn') === true
);
assert_test("getFeature('cte') returns true", 
    SchemaMap::getFeature('cte', false, 'test_conn') === true
);
assert_test("getFeature('limit_on_update') returns false", 
    SchemaMap::getFeature('limit_on_update', true, 'test_conn') === false
);
assert_test("getFeature('nonexistent') returns default", 
    SchemaMap::getFeature('nonexistent', 'DEFAULT_VAL', 'test_conn') === 'DEFAULT_VAL'
);

// ========================================================================
// TEST 9: Multiple Connections
// ========================================================================
section("Test 9: Multiple Connections");

// Set a second connection with different data
$secondMap = [
    'connection' => 'second_conn',
    'driver'     => 'mysql',
    'tables'     => [
        'products' => [
            'id'   => ['type' => 'INT', 'primary' => true, 'foreign' => false, 'nullable' => false, 'default' => null],
            'name' => ['type' => 'VARCHAR', 'primary' => false, 'foreign' => false, 'nullable' => false, 'default' => null],
        ],
    ],
    'features' => ['driver' => 'mysql'],
    'relationships' => ['from' => [], 'to' => []],
    'checksum' => '',
    'generated_at' => '',
];

SchemaMap::setMap($secondMap, 'second_conn');

// Default connection still has original data
assert_test("Default still has 'users'", SchemaMap::hasTable('users'));
assert_test("Default does NOT have 'products'", !SchemaMap::hasTable('products'));

// Second connection has its own data
assert_test("Second has 'products'", SchemaMap::hasTable('products', 'second_conn'));
assert_test("Second does NOT have 'users'", !SchemaMap::hasTable('users', 'second_conn'));

// Features are isolated
assert_test("Default driver is sqlite", SchemaMap::getFeature('driver', '', 'test_conn') === 'sqlite');
assert_test("Second driver is mysql", SchemaMap::getFeature('driver', '', 'second_conn') === 'mysql');

// Switch default
SchemaMap::setDefaultConnection('second_conn');
assert_test("After switch, default has 'products'", SchemaMap::hasTable('products'));
assert_test("After switch, default does NOT have 'users'", !SchemaMap::hasTable('users'));

// Restore
SchemaMap::setDefaultConnection('test_conn');

// ========================================================================
// TEST 10: clear()
// ========================================================================
section("Test 10: clear()");

SchemaMap::clear();
assert_test("After clear, default is empty", empty(SchemaMap::getMap()));
assert_test("After clear, getFeatures returns empty", empty(SchemaMap::getFeatures()));

// Re-set for remaining tests
SchemaMap::setMap($testMap, 'test_conn');
SchemaMap::setDefaultConnection('test_conn');

// ========================================================================
// TEST 11: loadFromFile
// ========================================================================
section("Test 11: loadFromFile");

// Write map to temp file
$tmpFile = __DIR__ . '/../../tmp/test_schema_map_load.php';
$tmpDir = dirname($tmpFile);
if (!is_dir($tmpDir)) {
    mkdir($tmpDir, 0777, true);
}

$export = var_export($testMap, true);
$export = preg_replace('/array \(/', '[', $export);
$export = preg_replace('/\),/', '],', $export);
$export = preg_replace('/\)$/', ']', $export);
file_put_contents($tmpFile, "<?php\nreturn " . $export . ";\n");

assert_test("Temp file created", file_exists($tmpFile));

// Load it
SchemaMap::loadFromFile($tmpFile, 'from_file');
assert_test("loadFromFile loads tables", SchemaMap::hasTable('users', 'from_file'));
assert_test("loadFromFile loads features", SchemaMap::getFeature('driver', '', 'from_file') === 'sqlite');
assert_test("loadFromFile loads relationships", 
    !empty(SchemaMap::getMap('from_file')['relationships']['from'])
);

// Cleanup
unlink($tmpFile);
assert_test("Temp file cleaned", !file_exists($tmpFile));

// ========================================================================
// TEST 12: Edge Cases
// ========================================================================
section("Test 12: Edge Cases");

// Empty table
$emptyMap = ['tables' => ['empty_table' => []]];
SchemaMap::setMap($emptyMap, 'edge_conn');
assert_test("Empty table exists", SchemaMap::hasTable('empty_table', 'edge_conn'));
assert_test("Empty table has no columns", count(SchemaMap::getColumns('empty_table', 'edge_conn')) === 0);

// Table with only special keys (no columns)
$specialOnlyMap = [
    'tables' => [
        'special_table' => [
            'primary_key' => 'id',
            'foreign_keys' => ['user_id'],
            'indexes' => ['idx_name'],
        ],
    ],
];
SchemaMap::setMap($specialOnlyMap, 'special_conn');
assert_test("Special-only table exists", SchemaMap::hasTable('special_table', 'special_conn'));
assert_test("Special-only table has no columns", count(SchemaMap::getColumns('special_table', 'special_conn')) === 0);

// Non-existent connection returns empty/default
assert_test("Non-existent conn: hasTable returns false", !SchemaMap::hasTable('users', 'nonexistent_conn'));
assert_test("Non-existent conn: getMap returns empty", empty(SchemaMap::getMap('nonexistent_conn')));
assert_test("Non-existent conn: getFeatures returns empty", empty(SchemaMap::getFeatures('nonexistent_conn')));
assert_test("Non-existent conn: getFeature returns default", 
    SchemaMap::getFeature('driver', 'N/A', 'nonexistent_conn') === 'N/A'
);

// ========================================================================
// TEST 13: ConditionMatrix Integration
// ========================================================================
section("Test 13: ConditionMatrix Integration with SchemaMap");

SchemaMap::setMap($testMap, 'test_conn');
SchemaMap::setDefaultConnection('test_conn');
ConditionMatrix::setDriver('sqlite');

// Quote identifiers
assert_test("quote('users')", ConditionMatrix::quote('users') === '"users"');
assert_test("quote('users.email')", ConditionMatrix::quote('users.email') === '"users"."email"');
assert_test("quote('*')", ConditionMatrix::quote('*') === '*');

// Parse conditions with schema context
$parsed = ConditionMatrix::parse(
    ['email' => 'test@example.com', 'role' => 'admin'],
    ['u' => 'users'],
    'u',
    SchemaMap::getMap()
);
echo "  Parsed SQL: " . $parsed['sql'] . "\n";
assert_test("ConditionMatrix uses schema context", strpos($parsed['sql'], '"u"."email"') !== false);
assert_test("ConditionMatrix params count", count($parsed['params']) === 2);

// OR conditions
$parsedOr = ConditionMatrix::parse(
    ['OR' => [['status' => 'active'], ['status' => 'pending']]]
);
echo "  Parsed OR SQL: " . $parsedOr['sql'] . "\n";
assert_test("ConditionMatrix handles OR", strpos($parsedOr['sql'], 'OR') !== false);

// IN conditions
$parsedIn = ConditionMatrix::parse(
    ['id' => [1, 2, 3, 4, 5]]
);
echo "  Parsed IN SQL: " . $parsedIn['sql'] . "\n";
assert_test("ConditionMatrix handles IN", strpos($parsedIn['sql'], 'IN') !== false);
assert_test("ConditionMatrix IN params count", count($parsedIn['params']) === 5);

// NULL conditions
$parsedNull = ConditionMatrix::parse(
    ['deleted_at' => null]
);
assert_test("ConditionMatrix handles IS NULL", strpos($parsedNull['sql'], 'IS NULL') !== false);

// ========================================================================
// RESULTS
// ========================================================================
echo "\n==================================================\n";
if ($failed === 0) {
    echo "RESULT: ALL TESTS PASSED\n";
    echo "SchemaMap is working correctly!\n";
    exit(0);
} else {
    echo "RESULT: $failed TEST(S) FAILED\n";
    exit(1);
}
echo "==================================================\n";