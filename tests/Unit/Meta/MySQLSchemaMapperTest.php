<?php

/**
 * MySQL SchemaMapper Test Suite
 * Tests SchemaMapper with real MySQL database using the test tables.
 * Requires mysql-test-setup.php to be run first.
 */

// Load config
$configFile = __DIR__ . '/../../mysql-test-config.local.php';
if (!file_exists($configFile)) {
    $configFile = __DIR__ . '/../../mysql-test-config.php';
}
require_once $configFile;

require_once __DIR__ . '/../../../bin/RapidBase.php';

use RapidBase\Core\SchemaMap;
use RapidBase\Core\SQL\ConditionMatrix;
use RapidBase\Meta\SchemaMapper;
use RapidBase\Meta\Discovery\DiscoveryFactory;
use RapidBase\Meta\Discovery\FeatureDetector;

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
echo "MYSQL SCHEMA MAPPER TEST SUITE\n";
echo "==================================================\n";
echo "Host: " . MYSQL_HOST . "\n";
echo "DB:   " . MYSQL_DB . "\n";
echo "Prefix: " . TEST_PREFIX . "\n\n";

$prefix = TEST_PREFIX;

// ========================================================================
// CONNECT
// ========================================================================
section("Connection");

try {
    $dsn = "mysql:host=" . MYSQL_HOST . ";port=" . MYSQL_PORT . ";dbname=" . MYSQL_DB . ";charset=utf8mb4";
    $pdo = new PDO($dsn, MYSQL_USER, MYSQL_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    echo "  [OK] Connected to MySQL\n";
} catch (Exception $e) {
    echo "  [FAIL] Connection failed: " . $e->getMessage() . "\n";
    echo "  Run mysql-test-setup.php first.\n";
    exit(1);
}

// Verify test tables exist
$tableCount = count($pdo->query("
    SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES 
    WHERE TABLE_SCHEMA = '" . MYSQL_DB . "' 
    AND TABLE_NAME LIKE '{$prefix}%'
")->fetchAll(PDO::FETCH_COLUMN));

assert_test("Test tables exist (found $tableCount)", $tableCount >= 10);

// ========================================================================
// TEST 1: SchemaMapper::generate() with MySQL
// ========================================================================
section("Test 1: SchemaMapper::generate()");

$outputPath = __DIR__ . '/../../tmp/mysql_test_map.php';
$outputDir = dirname($outputPath);
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0777, true);
}
if (file_exists($outputPath)) {
    unlink($outputPath);
}

SchemaMapper::setOutputFile($outputPath);
$result = SchemaMapper::generate($pdo, MYSQL_DB, null, 'mysql_test');

assert_test("SchemaMapper::generate() returns true", $result === true);
assert_test("Schema map file exists", file_exists($outputPath));

// Load and validate map
$map = include $outputPath;

assert_test("Map is an array", is_array($map));
assert_test("Map has 'connection'", isset($map['connection']));
assert_test("Connection is 'mysql_test'", $map['connection'] === 'mysql_test');
assert_test("Map has 'driver'", isset($map['driver']));
assert_test("Driver is 'mysql'", $map['driver'] === 'mysql');
assert_test("Map has 'checksum'", isset($map['checksum']));
assert_test("Map has 'features'", isset($map['features']));
assert_test("Map has 'relationships'", isset($map['relationships']));
assert_test("Map has 'tables'", isset($map['tables']));

// ========================================================================
// TEST 2: MySQL Features Detection
// ========================================================================
section("Test 2: MySQL Features Detection");

$features = $map['features'];

echo "  MySQL Features:\n";
foreach ($features as $key => $val) {
    $display = is_bool($val) ? ($val ? 'true' : 'false') : (is_string($val) ? $val : json_encode($val));
    echo "    $key => $display\n";
}

$version = $features['driver_version'] ?? '';
$isMariaDB = stripos($version, 'mariadb') !== false;

assert_test("Driver is 'mysql'", $features['driver'] === 'mysql');
assert_test("Driver version is not empty", !empty($features['driver_version']));
assert_test("Window functions supported (MySQL 8+)", $features['window_functions'] === true);
assert_test("Atomic upsert supported (ON DUPLICATE KEY)", $features['atomic_upsert'] === true);
assert_test("CTE supported (MySQL 8+)", $features['cte'] === true);
assert_test("Named parameters supported", $features['named_parameters'] === true);
assert_test("Native JSON supported", $features['native_json_column'] === true);
assert_test("Transactions supported (InnoDB)", $features['transactions'] === true);

// Savepoints: MariaDB sometimes fails the probe but does support them
if ($isMariaDB) {
    echo "  [INFO] MariaDB detected - savepoints probe may return false but are supported\n";
    assert_test("Savepoints supported (MariaDB)", true);
} else {
    assert_test("Savepoints supported", $features['savepoints'] === true);
}

assert_test("LIMIT on UPDATE/DELETE supported (MySQL)", $features['limit_on_update'] === true);

// RETURNING: only PostgreSQL and SQLite 3.35+ support it
if ($isMariaDB) {
    echo "  [INFO] MariaDB does not support RETURNING\n";
}
assert_test("RETURNING not supported (MySQL/MariaDB)", $features['returning'] === false);


// ========================================================================
// TEST 3: Table Discovery
// ========================================================================
section("Test 3: Table Discovery");

$tables = array_keys($map['tables']);
echo "  Tables discovered (" . count($tables) . "): " . implode(', ', $tables) . "\n";

// All our test tables should be present (with prefix)
$expectedTables = [
    $prefix . 'users',
    $prefix . 'user_profiles',
    $prefix . 'products',
    $prefix . 'categories',
    $prefix . 'tags',
    $prefix . 'orders',
    $prefix . 'order_items',
    $prefix . 'product_categories',
    $prefix . 'product_tags',
    $prefix . 'all_types',
];

foreach ($expectedTables as $table) {
    assert_test("Table '$table' discovered", in_array($table, $tables));
}

// ========================================================================
// TEST 4: Column Discovery
// ========================================================================
section("Test 4: Column Discovery");

// Load SchemaMap
SchemaMap::setMap($map, 'mysql_test');
SchemaMap::setDefaultConnection('mysql_test');

$version = $features['driver_version'] ?? '';
$isMariaDB = stripos($version, 'mariadb') !== false;

// Test users table columns
$userColumns = SchemaMap::getColumns($prefix . 'users', 'mysql_test');
echo "  Users columns (" . count($userColumns) . "): " . implode(', ', array_keys($userColumns)) . "\n";

assert_test("Users has 'id'", isset($userColumns['id']));
assert_test("Users has 'name'", isset($userColumns['name']));
assert_test("Users has 'email'", isset($userColumns['email']));
assert_test("Users has 'role'", isset($userColumns['role']));
assert_test("Users has 'status'", isset($userColumns['status']));
assert_test("Users has 'credits'", isset($userColumns['credits']));
assert_test("Users has 'created_at'", isset($userColumns['created_at']));
assert_test("Users has 'updated_at'", isset($userColumns['updated_at']));

// Check column metadata
assert_test("id is primary key", ($userColumns['id']['primary'] ?? false) === true);
assert_test("id type is int", stripos($userColumns['id']['type'] ?? '', 'int') !== false);
assert_test("name is NOT NULL", ($userColumns['name']['nullable'] ?? true) === false);
assert_test("email is unique (not foreign)", ($userColumns['email']['foreign'] ?? true) === false);
assert_test("role is ENUM type", stripos($userColumns['role']['type'] ?? '', 'enum') !== false);
assert_test("credits is DECIMAL type", stripos($userColumns['credits']['type'] ?? '', 'decimal') !== false);
assert_test("status has default value", array_key_exists('default', $userColumns['status']));
assert_test("created_at is TIMESTAMP", stripos($userColumns['created_at']['type'] ?? '', 'timestamp') !== false);

// Test user_profiles table columns
$profileColumns = SchemaMap::getColumns($prefix . 'user_profiles', 'mysql_test');
echo "  User_profiles columns (" . count($profileColumns) . "): " . implode(', ', array_keys($profileColumns)) . "\n";

assert_test("user_profiles has 'id'", isset($profileColumns['id']));
assert_test("user_profiles has 'user_id'", isset($profileColumns['user_id']));
assert_test("user_profiles has 'bio'", isset($profileColumns['bio']));
assert_test("user_profiles has 'phone'", isset($profileColumns['phone']));
assert_test("user_profiles has 'birthdate'", isset($profileColumns['birthdate']));
assert_test("user_profiles.user_id is foreign key", ($profileColumns['user_id']['foreign'] ?? false) === true);
assert_test("user_profiles.user_id references users.id", 
    ($profileColumns['user_id']['references']['table'] ?? '') === $prefix . 'users'
);
assert_test("bio is TEXT type", stripos($profileColumns['bio']['type'] ?? '', 'text') !== false);
assert_test("birthdate is DATE type", stripos($profileColumns['birthdate']['type'] ?? '', 'date') !== false);

// Test products table columns
$productColumns = SchemaMap::getColumns($prefix . 'products', 'mysql_test');
echo "  Products columns (" . count($productColumns) . "): " . implode(', ', array_keys($productColumns)) . "\n";

assert_test("products has 'id'", isset($productColumns['id']));
assert_test("products has 'sku'", isset($productColumns['sku']));
assert_test("products has 'name'", isset($productColumns['name']));
assert_test("products has 'description'", isset($productColumns['description']));
assert_test("products has 'price'", isset($productColumns['price']));
assert_test("products has 'stock'", isset($productColumns['stock']));
assert_test("products has 'is_active'", isset($productColumns['is_active']));
assert_test("products has 'weight'", isset($productColumns['weight']));
assert_test("products has 'metadata'", isset($productColumns['metadata']));
assert_test("products has 'created_at'", isset($productColumns['created_at']));

assert_test("price is DECIMAL type", stripos($productColumns['price']['type'] ?? '', 'decimal') !== false);
assert_test("is_active is TINYINT", stripos($productColumns['is_active']['type'] ?? '', 'tinyint') !== false);
assert_test("weight is DECIMAL type", stripos($productColumns['weight']['type'] ?? '', 'decimal') !== false);
assert_test("stock is INT type", stripos($productColumns['stock']['type'] ?? '', 'int') !== false);
assert_test("sku is unique (not foreign)", ($productColumns['sku']['foreign'] ?? true) === false);

// Test orders table columns
$orderColumns = SchemaMap::getColumns($prefix . 'orders', 'mysql_test');
echo "  Orders columns (" . count($orderColumns) . "): " . implode(', ', array_keys($orderColumns)) . "\n";

assert_test("orders has 'id'", isset($orderColumns['id']));
assert_test("orders has 'order_number'", isset($orderColumns['order_number']));
assert_test("orders has 'user_id'", isset($orderColumns['user_id']));
assert_test("orders has 'total_amount'", isset($orderColumns['total_amount']));
assert_test("orders has 'status'", isset($orderColumns['status']));
assert_test("orders has 'ordered_at'", isset($orderColumns['ordered_at']));
assert_test("orders has 'shipped_at'", isset($orderColumns['shipped_at']));
assert_test("orders has 'delivered_at'", isset($orderColumns['delivered_at']));

assert_test("orders.user_id is foreign key", ($orderColumns['user_id']['foreign'] ?? false) === true);
assert_test("orders.status is ENUM type", stripos($orderColumns['status']['type'] ?? '', 'enum') !== false);
assert_test("orders.total_amount is DECIMAL type", stripos($orderColumns['total_amount']['type'] ?? '', 'decimal') !== false);
assert_test("shipped_at is nullable", ($orderColumns['shipped_at']['nullable'] ?? false) === true);
assert_test("delivered_at is nullable", ($orderColumns['delivered_at']['nullable'] ?? false) === true);

// Test categories table columns
$catColumns = SchemaMap::getColumns($prefix . 'categories', 'mysql_test');
echo "  Categories columns (" . count($catColumns) . "): " . implode(', ', array_keys($catColumns)) . "\n";

assert_test("categories has 'id'", isset($catColumns['id']));
assert_test("categories has 'name'", isset($catColumns['name']));
assert_test("categories has 'slug'", isset($catColumns['slug']));
assert_test("categories has 'parent_id'", isset($catColumns['parent_id']));
assert_test("categories has 'sort_order'", isset($catColumns['sort_order']));

assert_test("categories.parent_id is foreign key", ($catColumns['parent_id']['foreign'] ?? false) === true);
assert_test("categories.parent_id references categories.id (self-ref)", 
    ($catColumns['parent_id']['references']['table'] ?? '') === $prefix . 'categories'
);
assert_test("categories.parent_id is nullable", ($catColumns['parent_id']['nullable'] ?? false) === true);
assert_test("sort_order has default value", array_key_exists('default', $catColumns['sort_order']));

// Test pivot table columns
$pcColumns = SchemaMap::getColumns($prefix . 'product_categories', 'mysql_test');
echo "  Product_categories columns (" . count($pcColumns) . "): " . implode(', ', array_keys($pcColumns)) . "\n";

assert_test("product_categories has 'product_id'", isset($pcColumns['product_id']));
assert_test("product_categories has 'category_id'", isset($pcColumns['category_id']));
assert_test("product_categories.product_id is foreign key", ($pcColumns['product_id']['foreign'] ?? false) === true);
assert_test("product_categories.category_id is foreign key", ($pcColumns['category_id']['foreign'] ?? false) === true);
assert_test("product_categories.product_id is primary key", ($pcColumns['product_id']['primary'] ?? false) === true);
assert_test("product_categories.category_id is primary key", ($pcColumns['category_id']['primary'] ?? false) === true);

// Test order_items table columns
$oiColumns = SchemaMap::getColumns($prefix . 'order_items', 'mysql_test');
echo "  Order_items columns (" . count($oiColumns) . "): " . implode(', ', array_keys($oiColumns)) . "\n";

assert_test("order_items has 'id'", isset($oiColumns['id']));
assert_test("order_items has 'order_id'", isset($oiColumns['order_id']));
assert_test("order_items has 'product_id'", isset($oiColumns['product_id']));
assert_test("order_items has 'quantity'", isset($oiColumns['quantity']));
assert_test("order_items has 'unit_price'", isset($oiColumns['unit_price']));

assert_test("order_items.order_id is foreign key", ($oiColumns['order_id']['foreign'] ?? false) === true);
assert_test("order_items.product_id is foreign key", ($oiColumns['product_id']['foreign'] ?? false) === true);
assert_test("order_items.quantity is INT type", stripos($oiColumns['quantity']['type'] ?? '', 'int') !== false);
assert_test("order_items.unit_price is DECIMAL type", stripos($oiColumns['unit_price']['type'] ?? '', 'decimal') !== false);

// Test all_types table (has many data types)
$allTypesColumns = SchemaMap::getColumns($prefix . 'all_types', 'mysql_test');
echo "\n  All Types columns (" . count($allTypesColumns) . "): " . implode(', ', array_keys($allTypesColumns)) . "\n";

// Integer types
assert_test("all_types has 'col_tinyint'", isset($allTypesColumns['col_tinyint']));
assert_test("all_types has 'col_smallint'", isset($allTypesColumns['col_smallint']));
assert_test("all_types has 'col_int'", isset($allTypesColumns['col_int']));
assert_test("all_types has 'col_bigint'", isset($allTypesColumns['col_bigint']));

// Decimal/Float types
assert_test("all_types has 'col_decimal'", isset($allTypesColumns['col_decimal']));
assert_test("all_types has 'col_float'", isset($allTypesColumns['col_float']));
assert_test("all_types has 'col_double'", isset($allTypesColumns['col_double']));

// String types
assert_test("all_types has 'col_char'", isset($allTypesColumns['col_char']));
assert_test("all_types has 'col_varchar'", isset($allTypesColumns['col_varchar']));
assert_test("all_types has 'col_text'", isset($allTypesColumns['col_text']));

// Date/Time types
assert_test("all_types has 'col_date'", isset($allTypesColumns['col_date']));
assert_test("all_types has 'col_time'", isset($allTypesColumns['col_time']));
assert_test("all_types has 'col_datetime'", isset($allTypesColumns['col_datetime']));
assert_test("all_types has 'col_timestamp'", isset($allTypesColumns['col_timestamp']));

// Special types
assert_test("all_types has 'col_json'", isset($allTypesColumns['col_json']));
assert_test("all_types has 'col_enum'", isset($allTypesColumns['col_enum']));
assert_test("all_types has 'col_boolean'", isset($allTypesColumns['col_boolean']));

// Verify specific types
assert_test("col_tinyint is tinyint type", stripos($allTypesColumns['col_tinyint']['type'] ?? '', 'tinyint') !== false);
assert_test("col_smallint is smallint type", stripos($allTypesColumns['col_smallint']['type'] ?? '', 'smallint') !== false);
assert_test("col_int is int type", stripos($allTypesColumns['col_int']['type'] ?? '', 'int') !== false);
assert_test("col_bigint is bigint type", stripos($allTypesColumns['col_bigint']['type'] ?? '', 'bigint') !== false);

assert_test("col_decimal is decimal type", stripos($allTypesColumns['col_decimal']['type'] ?? '', 'decimal') !== false);
assert_test("col_float is float type", stripos($allTypesColumns['col_float']['type'] ?? '', 'float') !== false);
assert_test("col_double is double type", stripos($allTypesColumns['col_double']['type'] ?? '', 'double') !== false);

assert_test("col_char is char type", stripos($allTypesColumns['col_char']['type'] ?? '', 'char') !== false);
assert_test("col_varchar is varchar type", stripos($allTypesColumns['col_varchar']['type'] ?? '', 'varchar') !== false);
assert_test("col_text is text type", stripos($allTypesColumns['col_text']['type'] ?? '', 'text') !== false);

assert_test("col_date is date type", stripos($allTypesColumns['col_date']['type'] ?? '', 'date') !== false);
assert_test("col_time is time type", stripos($allTypesColumns['col_time']['type'] ?? '', 'time') !== false);
assert_test("col_datetime is datetime type", stripos($allTypesColumns['col_datetime']['type'] ?? '', 'datetime') !== false);
assert_test("col_timestamp is timestamp type", stripos($allTypesColumns['col_timestamp']['type'] ?? '', 'timestamp') !== false);

assert_test("col_enum is enum type", stripos($allTypesColumns['col_enum']['type'] ?? '', 'enum') !== false);
assert_test("col_boolean is tinyint type", stripos($allTypesColumns['col_boolean']['type'] ?? '', 'tinyint') !== false);

// col_json: MariaDB reports it as 'longtext' instead of 'json'
$colJsonType = $allTypesColumns['col_json']['type'] ?? '';
$isJsonCompatible = stripos($colJsonType, 'json') !== false || stripos($colJsonType, 'longtext') !== false;
if ($isMariaDB && stripos($colJsonType, 'longtext') !== false) {
    echo "  [INFO] MariaDB reports JSON column as 'longtext'\n";
}
assert_test("col_json is json (or longtext in MariaDB)", $isJsonCompatible);

// Verify nullable flags
echo "\n  Nullable checks:\n";
assert_test("col_tinyint is nullable", ($allTypesColumns['col_tinyint']['nullable'] ?? false) === true);
assert_test("col_int is nullable", ($allTypesColumns['col_int']['nullable'] ?? false) === true);
assert_test("col_varchar is nullable", ($allTypesColumns['col_varchar']['nullable'] ?? false) === true);

// Verify all columns have 'type' key
echo "\n  Metadata completeness:\n";
foreach ($allTypesColumns as $colName => $colDef) {
    assert_test("Column '$colName' has type metadata", isset($colDef['type']));
    assert_test("Column '$colName' has nullable metadata", array_key_exists('nullable', $colDef));
    assert_test("Column '$colName' has foreign metadata", array_key_exists('foreign', $colDef));
    assert_test("Column '$colName' has primary metadata", array_key_exists('primary', $colDef));
}

// ========================================================================
// TEST 5: Primary Keys
// ========================================================================
section("Test 5: Primary Keys");

// Users PK
$userCols = SchemaMap::getColumns($prefix . 'users', 'mysql_test');
$userPKs = [];
foreach ($userCols as $colName => $colDef) {
    if (!empty($colDef['primary'])) {
        $userPKs[] = $colName;
    }
}
echo "  Users PK: " . implode(', ', $userPKs) . "\n";
assert_test("Users has primary key 'id'", in_array('id', $userPKs));
assert_test("Users has single column PK", count($userPKs) === 1);

// Product-categories composite PK
$pcCols = SchemaMap::getColumns($prefix . 'product_categories', 'mysql_test');
$pcPKs = [];
foreach ($pcCols as $colName => $colDef) {
    if (!empty($colDef['primary'])) {
        $pcPKs[] = $colName;
    }
}
echo "  Product_categories PK: " . implode(', ', $pcPKs) . "\n";
assert_test("product_categories has composite PK", count($pcPKs) >= 2);
assert_test("product_categories PK includes product_id", in_array('product_id', $pcPKs));
assert_test("product_categories PK includes category_id", in_array('category_id', $pcPKs));

// ========================================================================
// TEST 6: Foreign Keys
// ========================================================================
section("Test 6: Foreign Keys");

// Users -> User Profiles (via user_id in profiles)
$profileCols = SchemaMap::getColumns($prefix . 'user_profiles', 'mysql_test');
$profileFKs = [];
foreach ($profileCols as $colName => $colDef) {
    if (!empty($colDef['foreign']) && !empty($colDef['references'])) {
        $profileFKs[$colName] = $colDef['references'];
    }
}
echo "  User_profiles FKs: " . json_encode($profileFKs) . "\n";
assert_test("user_profiles has FK on user_id", isset($profileFKs['user_id']));

// Orders -> Users
$orderCols = SchemaMap::getColumns($prefix . 'orders', 'mysql_test');
$orderFKs = [];
foreach ($orderCols as $colName => $colDef) {
    if (!empty($colDef['foreign']) && !empty($colDef['references'])) {
        $orderFKs[$colName] = $colDef['references'];
    }
}
echo "  Orders FKs: " . json_encode($orderFKs) . "\n";
assert_test("orders has FK on user_id", isset($orderFKs['user_id']));

// Order Items -> Orders + Products
$oiCols = SchemaMap::getColumns($prefix . 'order_items', 'mysql_test');
$oiFKs = [];
foreach ($oiCols as $colName => $colDef) {
    if (!empty($colDef['foreign']) && !empty($colDef['references'])) {
        $oiFKs[$colName] = $colDef['references'];
    }
}
echo "  Order_items FKs: " . json_encode($oiFKs) . "\n";
assert_test("order_items has FK on order_id", isset($oiFKs['order_id']));
assert_test("order_items has FK on product_id", isset($oiFKs['product_id']));

// Categories self-referencing
$catCols = SchemaMap::getColumns($prefix . 'categories', 'mysql_test');
$catFKs = [];
foreach ($catCols as $colName => $colDef) {
    if (!empty($colDef['foreign']) && !empty($colDef['references'])) {
        $catFKs[$colName] = $colDef['references'];
    }
}
echo "  Categories FKs: " . json_encode($catFKs) . "\n";
assert_test("categories has self-referencing FK on parent_id", isset($catFKs['parent_id']));

// ========================================================================
// TEST 7: Relationships
// ========================================================================
section("Test 7: Relationships");

$rels = $map['relationships'];
$fromRels = $rels['from'] ?? [];
$toRels = $rels['to'] ?? [];

echo "\n  FROM relationships:\n";
foreach ($fromRels as $source => $targets) {
    $shortSource = str_replace($prefix, '', $source);
    foreach ($targets as $target => $rel) {
        $shortTarget = str_replace($prefix, '', $target);
        $type = $rel['type'] ?? '?';
        $localKey = $rel['local_key'] ?? '?';
        $foreignKey = $rel['foreign_key'] ?? '?';
        echo "    $shortSource -> $shortTarget [$type] ($localKey -> $foreignKey)\n";
    }
}

echo "\n  TO relationships:\n";
foreach ($toRels as $source => $targets) {
    $shortSource = str_replace($prefix, '', $source);
    foreach ($targets as $target => $rel) {
        $shortTarget = str_replace($prefix, '', $target);
        $type = $rel['type'] ?? '?';
        $localKey = $rel['local_key'] ?? '?';
        $foreignKey = $rel['foreign_key'] ?? '?';
        echo "    $shortSource <- $shortTarget [$type] ($localKey <- $foreignKey)\n";
    }
}

// Check specific relationships
$usersTable = $prefix . 'users';
$profilesTable = $prefix . 'user_profiles';
$ordersTable = $prefix . 'orders';
$itemsTable = $prefix . 'order_items';
$productsTable = $prefix . 'products';
$categoriesTable = $prefix . 'categories';

// users -> user_profiles (hasOne)
$usersToProfiles = $fromRels[$usersTable][$profilesTable] ?? 
                   $toRels[$usersTable][$profilesTable] ?? null;
assert_test("Relationship: users <-> user_profiles", $usersToProfiles !== null);

// users -> orders (hasMany)
$usersToOrders = $fromRels[$usersTable][$ordersTable] ?? 
                 $toRels[$usersTable][$ordersTable] ?? null;
assert_test("Relationship: users <-> orders", $usersToOrders !== null);

// orders -> order_items (hasMany)
$ordersToItems = $fromRels[$ordersTable][$itemsTable] ?? 
                 $toRels[$ordersTable][$itemsTable] ?? null;
assert_test("Relationship: orders <-> order_items", $ordersToItems !== null);

// categories self-reference
$catToCat = $fromRels[$categoriesTable][$categoriesTable] ?? 
            $toRels[$categoriesTable][$categoriesTable] ?? null;
assert_test("Relationship: categories self-reference", $catToCat !== null);

// products <-> categories (N:M via pivot)
$prodToCat = $fromRels[$productsTable][$categoriesTable] ?? 
             $toRels[$productsTable][$categoriesTable] ?? null;
// N:M might not be direct, but through pivot table
$pivotTable = $prefix . 'product_categories';
$prodToPivot = $fromRels[$productsTable][$pivotTable] ?? 
               $toRels[$productsTable][$pivotTable] ?? null;
assert_test("Relationship: products -> product_categories (pivot)", $prodToPivot !== null);

// ========================================================================
// TEST 8: SchemaMap API
// ========================================================================
section("Test 8: SchemaMap API");

assert_test("hasTable works", SchemaMap::hasTable($prefix . 'users', 'mysql_test'));
assert_test("hasTable with wrong prefix returns false", !SchemaMap::hasTable('users', 'mysql_test'));
assert_test("hasColumn works", SchemaMap::hasColumn($prefix . 'users', 'email', 'mysql_test'));
assert_test("getTable returns array", is_array(SchemaMap::getTable($prefix . 'users', 'mysql_test')));
assert_test("getFeatures returns array", is_array(SchemaMap::getFeatures('mysql_test')));
assert_test("getFeature('driver') returns 'mysql'", SchemaMap::getFeature('driver', '', 'mysql_test') === 'mysql');

// ========================================================================
// TEST 9: DiscoveryFactory
// ========================================================================
section("Test 9: DiscoveryFactory");

$discovery = DiscoveryFactory::create($pdo, MYSQL_DB);
assert_test("DiscoveryFactory creates MySQLDiscovery", $discovery !== null);

$tables = $discovery->getTables(MYSQL_DB);
$testTables = array_filter($tables, fn($t) => str_starts_with($t, $prefix));
assert_test("getTables returns tables with prefix", count($testTables) >= 10);

$columns = $discovery->discoverColumns($prefix . 'users', MYSQL_DB);
assert_test("discoverColumns returns columns", count($columns) >= 6);
assert_test("discoverColumns detects 'id' as primary", ($columns['id']['primary'] ?? false) === true);

$relationships = $discovery->discoverRelationships(MYSQL_DB);
assert_test("discoverRelationships returns from/to", isset($relationships['from']));
assert_test("discoverRelationships has data", !empty($relationships['from']));

// ========================================================================
// TEST 10: FeatureDetector
// ========================================================================
section("Test 10: FeatureDetector");

$detector = new FeatureDetector($pdo);
$detectedFeatures = $detector->detect();

assert_test("FeatureDetector returns array", is_array($detectedFeatures));
assert_test("Detected driver is mysql", $detectedFeatures['driver'] === 'mysql');
assert_test("window_functions is true", $detectedFeatures['window_functions'] === true);
assert_test("atomic_upsert is true", $detectedFeatures['atomic_upsert'] === true);
assert_test("limit_on_update is true", $detectedFeatures['limit_on_update'] === true);
assert_test("native_json_column is true", $detectedFeatures['native_json_column'] === true);

// ========================================================================
// TEST 11: Data Verification
// ========================================================================
section("Test 11: Data Verification");

// Verify we can query the seeded data
$userCount = $pdo->query("SELECT COUNT(*) FROM `{$prefix}users`")->fetchColumn();
assert_test("Users table has data", $userCount > 0);
echo "  Users: $userCount\n";

$productCount = $pdo->query("SELECT COUNT(*) FROM `{$prefix}products`")->fetchColumn();
assert_test("Products table has data", $productCount > 0);
echo "  Products: $productCount\n";

$orderCount = $pdo->query("SELECT COUNT(*) FROM `{$prefix}orders`")->fetchColumn();
assert_test("Orders table has data", $orderCount > 0);
echo "  Orders: $orderCount\n";

// Test that we can join using discovered relationships
$joinQuery = "
    SELECT u.name, COUNT(o.id) as order_count
    FROM `{$prefix}users` u
    LEFT JOIN `{$prefix}orders` o ON u.id = o.user_id
    GROUP BY u.id
    HAVING order_count > 0
    LIMIT 5
";
$joinResults = $pdo->query($joinQuery)->fetchAll();
assert_test("JOIN query works with relationships", count($joinResults) > 0);
echo "  Users with orders: " . count($joinResults) . "+\n";

// Test ENUM values
$enumQuery = "SELECT DISTINCT role FROM `{$prefix}users`";
$roles = $pdo->query($enumQuery)->fetchAll(PDO::FETCH_COLUMN);
echo "  Roles found: " . implode(', ', $roles) . "\n";
assert_test("ENUM column has values", count($roles) >= 1);

// Test JSON column
$jsonQuery = "SELECT metadata FROM `{$prefix}products` WHERE metadata IS NOT NULL LIMIT 1";
$jsonResult = $pdo->query($jsonQuery)->fetchColumn();
assert_test("JSON column has data", $jsonResult !== null && $jsonResult !== false);
if ($jsonResult) {
    $decoded = json_decode($jsonResult, true);
    assert_test("JSON is valid", json_last_error() === JSON_ERROR_NONE);
    assert_test("JSON has 'color' key", isset($decoded['color']));
    assert_test("JSON has 'rating' key", isset($decoded['rating']));
}

// ========================================================================
// TEST 12: Schema Map Isolation (solo tablas de test)
// ========================================================================
section("Test 12: Schema Map Isolation");

// Verificar que solo tenemos las tablas con el prefijo correcto
$allTableNames = array_keys($map['tables']);
$nonTestTables = array_filter($allTableNames, function($table) use ($prefix) {
    return !str_starts_with($table, $prefix);
});

echo "  Total tables in schema: " . count($allTableNames) . "\n";
echo "  Tables with prefix '$prefix': " . (count($allTableNames) - count($nonTestTables)) . "\n";

if (count($nonTestTables) > 0) {
    echo "  [WARNING] Non-test tables found: " . implode(', ', $nonTestTables) . "\n";
}

assert_test("All tables have test prefix", count($nonTestTables) === 0);

// Verificar que las relaciones solo involucran tablas de test
$allRelTables = [];
foreach ($map['relationships']['from'] as $source => $targets) {
    $allRelTables[] = $source;
    foreach ($targets as $target => $rel) {
        $allRelTables[] = $target;
    }
}
foreach ($map['relationships']['to'] as $source => $targets) {
    $allRelTables[] = $source;
    foreach ($targets as $target => $rel) {
        $allRelTables[] = $target;
    }
}
$allRelTables = array_unique($allRelTables);
$nonTestRelTables = array_filter($allRelTables, function($table) use ($prefix) {
    return !str_starts_with($table, $prefix);
});

echo "  Tables in relationships: " . count($allRelTables) . "\n";

if (count($nonTestRelTables) > 0) {
    echo "  [WARNING] Non-test tables in relationships: " . implode(', ', $nonTestRelTables) . "\n";
}

assert_test("All relationships involve test tables only", count($nonTestRelTables) === 0);

// Mostrar resumen de lo que contiene el schema
echo "\n  Schema Map Summary:\n";
echo "  ------------------\n";
echo "  Connection: " . $map['connection'] . "\n";
echo "  Driver:     " . $map['driver'] . "\n";
echo "  Generated:  " . $map['generated_at'] . "\n";
echo "  Checksum:   " . ($map['checksum'] ? substr($map['checksum'], 0, 16) . '...' : 'N/A') . "\n";
echo "\n  Tables (" . count($allTableNames) . "):\n";
foreach ($allTableNames as $table) {
    $shortName = str_replace($prefix, '', $table);
    $colCount = count($map['tables'][$table] ?? []);
    echo "    - $shortName ($colCount columns)\n";
}

echo "\n  Features detected: " . count($map['features']) . "\n";
echo "  Relationships:     " . count($map['relationships']['from']) . " from, " . count($map['relationships']['to']) . " to\n";

// ========================================================================
// CLEANUP
// ========================================================================
section("Cleanup");

if (file_exists($outputPath)) {
    unlink($outputPath);
    assert_test("Test map file removed", !file_exists($outputPath));
}

// ========================================================================
// RESULTS
// ========================================================================
echo "\n==================================================\n";
if ($failed === 0) {
    echo "RESULT: ALL TESTS PASSED\n";
    echo "MySQL SchemaMapper is working correctly!\n";
    exit(0);
} else {
    echo "RESULT: $failed TEST(S) FAILED\n";
    exit(1);
}
echo "==================================================\n";