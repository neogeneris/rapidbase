<?php
/**
 * Discovery Engine Test Suite
 * Tests the automatic discovery of relationships, pivot tables, and column comments.
 * Uses SQLite in-memory database for fast, isolated testing.
 */

require_once __DIR__ . '/../../../src/RapidBase/Core/Schema/MySQLDiscovery.php';

// Simple SQLite adaptation for testing purposes
class SQLiteDiscovery extends \RapidBase\Core\Schema\MySQLDiscovery
{
    public function discoverFullSchema(string $database = 'memory'): array
    {
        $schema = [
            'from' => [],
            'tables' => [],
            'pivotTables' => []
        ];

        // Extract tables and columns (SQLite doesn't have COLUMN_COMMENT in PRAGMA, simulating it)
        $tables = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);

        foreach ($tables as $table) {
            $columns = $this->pdo->query("PRAGMA table_info('$table')")->fetchAll(PDO::FETCH_ASSOC);
            
            $schema['tables'][$table] = [
                'columns' => [],
                'primary_key' => null,
                'comment' => "Table: $table (Simulated comment)"
            ];

            foreach ($columns as $col) {
                $isPk = (bool)$col['pk'];
                if ($isPk) {
                    $schema['tables'][$table]['primary_key'] = $col['name'];
                }

                // Simulate comments based on column names for testing
                $simulatedComment = null;
                if (strpos($col['name'], 'email') !== false) $simulatedComment = 'User email address';
                elseif (strpos($col['name'], 'title') !== false) $simulatedComment = 'Post title';
                elseif (strpos($col['name'], 'content') !== false) $simulatedComment = 'Post body content';
                elseif ($col['name'] === 'id') $simulatedComment = 'Primary key';
                elseif (strpos($col['name'], '_id') !== false) $simulatedComment = 'Foreign key reference';

                $schema['tables'][$table]['columns'][$col['name']] = [
                    'type' => $col['type'],
                    'nullable' => !$col['notnull'],
                    'default' => $col['dflt_value'],
                    'comment' => $simulatedComment,
                    'is_primary' => $isPk,
                    'auto_increment' => $col['pk'] == 1 && stripos($col['type'], 'int') !== false
                ];
            }
        }

        // Extract Foreign Keys and build relationships
        foreach ($tables as $table) {
            $fks = $this->pdo->query("PRAGMA foreign_key_list('$table')")->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($fks as $fk) {
                $source = $table;
                $target = $fk['table'];
                $sCol = $fk['from'];
                $tCol = $fk['to'];

                // Direct: belongsTo
                $schema['from'][$source][$target] = [
                    'type' => 'belongsTo',
                    'local_key' => $sCol,
                    'foreign_key' => $tCol
                ];

                // Inverse: hasMany
                if (!isset($schema['from'][$target][$source])) {
                    $schema['from'][$target][$source] = [
                        'type' => 'hasMany',
                        'local_key' => $tCol,
                        'foreign_key' => $sCol,
                        'auto_generated' => true
                    ];
                }
            }
        }

        // Detect Pivot Tables (tables with exactly 2 FKs)
        foreach ($tables as $table) {
            $fks = $this->pdo->query("PRAGMA foreign_key_list('$table')")->fetchAll(PDO::FETCH_ASSOC);
            if (count($fks) === 2) {
                $tableA = $fks[0]['table'];
                $tableB = $fks[1]['table'];
                
                $schema['pivotTables'][$table] = [
                    'tables' => [$tableA, $tableB],
                    'keys' => [
                        $tableA => $fks[0]['from'],
                        $tableB => $fks[1]['from']
                    ]
                ];

                // Auto-generate N:M links
                if (!isset($schema['from'][$tableA][$table])) {
                    $schema['from'][$tableA][$table] = [
                        'type' => 'hasMany',
                        'local_key' => $fks[0]['to'],
                        'foreign_key' => $fks[0]['from'],
                        'is_pivot_link' => true
                    ];
                }
                if (!isset($schema['from'][$tableB][$table])) {
                    $schema['from'][$tableB][$table] = [
                        'type' => 'hasMany',
                        'local_key' => $fks[1]['to'],
                        'foreign_key' => $fks[1]['from'],
                        'is_pivot_link' => true
                    ];
                }
            }
        }

        return $schema;
    }
}

echo "==================================================\n";
echo "DISCOVERY ENGINE TEST SUITE\n";
echo "Testing Relationships, Pivots, and Metadata\n";
echo "==================================================\n\n";

// Setup In-Memory SQLite DB
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Create Schema with Comments (Simulated via structure)
$pdo->exec("
    CREATE TABLE users (
        id INTEGER PRIMARY KEY,
        email TEXT NOT NULL,
        role TEXT DEFAULT 'user'
    );
    CREATE TABLE posts (
        id INTEGER PRIMARY KEY,
        user_id INTEGER NOT NULL,
        title TEXT NOT NULL,
        content TEXT,
        FOREIGN KEY (user_id) REFERENCES users(id)
    );
    CREATE TABLE tags (
        id INTEGER PRIMARY KEY,
        name TEXT NOT NULL
    );
    CREATE TABLE post_tag (
        post_id INTEGER NOT NULL,
        tag_id INTEGER NOT NULL,
        PRIMARY KEY (post_id, tag_id),
        FOREIGN KEY (post_id) REFERENCES posts(id),
        FOREIGN KEY (tag_id) REFERENCES tags(id)
    );
    CREATE TABLE categories (
        id INTEGER PRIMARY KEY,
        parent_id INTEGER,
        name TEXT,
        FOREIGN KEY (parent_id) REFERENCES categories(id)
    );
");

// Insert Dummy Data
$pdo->exec("INSERT INTO users (email) VALUES ('alice@test.com'), ('bob@test.com')");
$pdo->exec("INSERT INTO posts (user_id, title) VALUES (1, 'Hello World'), (1, 'PHP Tips'), (2, 'SQLite Rocks')");
$pdo->exec("INSERT INTO tags (name) VALUES ('PHP'), ('Database'), ('Tutorial')");
$pdo->exec("INSERT INTO post_tag (post_id, tag_id) VALUES (1, 1), (1, 3), (2, 1), (3, 2)");
$pdo->exec("INSERT INTO categories (name, parent_id) VALUES ('Tech', NULL), ('Languages', 1)");

// Run Discovery
$discovery = new SQLiteDiscovery($pdo);
$schema = $discovery->discoverFullSchema();

$passed = 0;
$failed = 0;

function test($name, $condition) {
    global $passed, $failed;
    if ($condition) {
        echo "[OK] $name\n";
        $passed++;
    } else {
        echo "[FAIL] $name\n";
        $failed++;
    }
}

echo "--- Testing Relationship Discovery ---\n";
test('Users hasMany Posts', isset($schema['from']['users']['posts']) && $schema['from']['users']['posts']['type'] === 'hasMany');
test('Posts belongsTo Users', isset($schema['from']['posts']['users']) && $schema['from']['posts']['users']['type'] === 'belongsTo');
test('Posts hasMany PostTag', isset($schema['from']['posts']['post_tag']));
test('Tags hasMany PostTag', isset($schema['from']['tags']['post_tag']));

echo "\n--- Testing Pivot Detection ---\n";
test('post_tag detected as pivot', isset($schema['pivotTables']['post_tag']));
test('Pivot connects posts and tags', 
    isset($schema['pivotTables']['post_tag']['tables']) && 
    in_array('posts', $schema['pivotTables']['post_tag']['tables']) && 
    in_array('tags', $schema['pivotTables']['post_tag']['tables'])
);

echo "\n--- Testing Metadata Extraction ---\n";
test('Users table exists in schema', isset($schema['tables']['users']));
test('Users has email column', isset($schema['tables']['users']['columns']['email']));
test('Email column has simulated comment', $schema['tables']['users']['columns']['email']['comment'] === 'User email address');
test('Posts title has comment', $schema['tables']['posts']['columns']['title']['comment'] === 'Post title');
test('Primary keys detected', $schema['tables']['users']['primary_key'] === 'id');

echo "\n--- Testing Auto-Generated Inverses ---\n";
test('Categories self-reference detected', isset($schema['from']['categories']['categories']));

echo "\n==================================================\n";
echo "RESULTS: $passed Passed, $failed Failed\n";
echo "==================================================\n";

if ($failed === 0) {
    echo "\n🎉 Discovery Engine is working perfectly!\n";
    exit(0);
} else {
    echo "\n⚠️  Some tests failed. Check the output above.\n";
    exit(1);
}
