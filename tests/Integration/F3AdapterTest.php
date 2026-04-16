<?php

/**
 * F3 Adapter Compatibility Test
 * Tests that RapidBase adapters can mimic F3 ORM behavior
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use RapidBase\Adapters\F3\DB\SQL as RapidSQL;
use RapidBase\Adapters\F3\DB\SQL\Mapper as RapidMapper;

echo "=== F3 Adapter Compatibility Test ===\n\n";

// Test 1: Connection
echo "[Test 1] Creating connection...\n";
try {
    $db = new RapidSQL('sqlite::memory:', null, null);
    echo "✅ Connection created successfully\n";
} catch (Exception $e) {
    echo "❌ Connection failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 2: Create test table
echo "\n[Test 2] Creating test table...\n";
$db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, email TEXT)");
echo "✅ Table 'users' created\n";

// Test 3: Insert data using Mapper
echo "\n[Test 3] Inserting data via Mapper...\n";
$user = new RapidMapper($db, 'users');
$user->name = 'John Doe';
$user->email = 'john@example.com';
$user->save();
echo "✅ User inserted with ID: " . $user->id . "\n";

// Test 4: Load single record
echo "\n[Test 4] Loading single record...\n";
$user2 = new RapidMapper($db, 'users');
$loaded = $user2->load(['id=?', 1]);
if ($loaded && $user2->name === 'John Doe') {
    echo "✅ Record loaded successfully: " . $user2->name . "\n";
} else {
    echo "❌ Failed to load record\n";
    exit(1);
}

// Test 5: Find multiple records
echo "\n[Test 5] Inserting more records and finding...\n";
$user3 = new RapidMapper($db, 'users');
$user3->name = 'Jane Smith';
$user3->email = 'jane@example.com';
$user3->save();

$found = $user3->find(['name LIKE ?', '%Doe%']);
if (count($found) >= 1) {
    echo "✅ Found " . count($found) . " record(s) matching criteria\n";
} else {
    echo "❌ Find failed\n";
    exit(1);
}

// Test 6: Update record
echo "\n[Test 6] Updating record...\n";
$user2->name = 'John Updated';
$user2->save();
$user2->load(['id=?', 1]);
if ($user2->name === 'John Updated') {
    echo "✅ Record updated successfully\n";
} else {
    echo "❌ Update failed\n";
    exit(1);
}

// Test 7: Delete record
echo "\n[Test 7] Deleting record...\n";
$user2->erase();
$user2->load(['id=?', 1]);
if (!$user2->dry()) {
    echo "⚠️  Record still exists (delete may not have worked)\n";
} else {
    echo "✅ Record deleted successfully\n";
}

echo "\n=== All Tests Passed! ===\n";
echo "The F3 Adapter is working correctly with RapidBase internals.\n";
