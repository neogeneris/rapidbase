<?php
/**
 * MySQL Test Setup
 * Creates test tables with all MySQL data types and seeds 300+ records.
 * 
 * Usage: php tests/mysql-test-setup.php [--seed-only] [--clean]
 */

// Load config
$configFile = __DIR__ . '/mysql-test-config.local.php';
if (!file_exists($configFile)) {
    $configFile = __DIR__ . '/mysql-test-config.php';
}
require_once $configFile;

// Load RapidBase (the bundled file from the examples directory)
require_once __DIR__ . '/../examples/querybrowser/RapidBase.php';

use RapidBase\Core\Conn;
use RapidBase\Core\Gateway;
use RapidBase\Core\SchemaMap;
use RapidBase\Meta\SchemaMapper;

// CLI options
$cleanOnly = in_array('--clean', $argv);
$seedOnly  = in_array('--seed-only', $argv);
$help      = in_array('--help', $argv) || in_array('-h', $argv);

if ($help) {
    echo "MySQL Test Setup\n";
    echo "Usage: php tests/mysql-test-setup.php [options]\n\n";
    echo "Options:\n";
    echo "  --clean      Drop test tables only\n";
    echo "  --seed-only  Seed data only (tables must exist)\n";
    echo "  --help       Show this help\n";
    exit(0);
}

$prefix = TEST_PREFIX;

echo "==================================================\n";
echo "MYSQL TEST SETUP\n";
echo "==================================================\n";
echo "Host: " . MYSQL_HOST . ":" . MYSQL_PORT . "\n";
echo "DB:   " . MYSQL_DB . "\n";
echo "User: " . MYSQL_USER . "\n\n";

try {
    // Connect to MySQL
    $dsn = "mysql:host=" . MYSQL_HOST . ";port=" . MYSQL_PORT . ";dbname=" . MYSQL_DB . ";charset=utf8mb4";
    $pdo = new PDO($dsn, MYSQL_USER, MYSQL_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    echo "[OK] Connected to MySQL\n\n";
    
} catch (Exception $e) {
    echo "[FAIL] Connection failed: " . $e->getMessage() . "\n";
    echo "\nTip: Create mysql-test-config.local.php with your credentials:\n";
    echo "<?php\n";
    echo "define('MYSQL_HOST', '127.0.0.1');\n";
    echo "define('MYSQL_PORT', 3306);\n";
    echo "define('MYSQL_USER', 'root');\n";
    echo "define('MYSQL_PASS', 'your_password');\n";
    echo "define('MYSQL_DB', 'test');\n";
    exit(1);
}

// ========================================================================
// DROP TABLES
// ========================================================================
if ($cleanOnly || !$seedOnly) {
    echo "--- Dropping existing test tables ---\n";
    
    $tables = [
        "{$prefix}order_items",
        "{$prefix}orders",
        "{$prefix}product_categories",
        "{$prefix}categories",
        "{$prefix}product_tags",
        "{$prefix}tags",
        "{$prefix}products",
        "{$prefix}user_profiles",
        "{$prefix}users",
        "{$prefix}all_types",
    ];
    
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    foreach ($tables as $table) {
        try {
            $pdo->exec("DROP TABLE IF EXISTS `$table`");
            echo "  Dropped: $table\n";
        } catch (Exception $e) {
            echo "  Skip: $table\n";
        }
    }
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    
    if ($cleanOnly) {
        echo "\n[DONE] Test tables cleaned.\n";
        exit(0);
    }
}

// ========================================================================
// CREATE TABLES
// ========================================================================
if (!$seedOnly) {
    echo "\n--- Creating test tables ---\n";
    
    // 1. Users
    $pdo->exec("
        CREATE TABLE `{$prefix}users` (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            email VARCHAR(150) UNIQUE NOT NULL,
            role ENUM('admin','editor','user') NOT NULL DEFAULT 'user',
            status TINYINT NOT NULL DEFAULT 1,
            credits DECIMAL(10,2) DEFAULT 0.00,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  Created: {$prefix}users\n";
    
    // 2. User Profiles (1:1)
    $pdo->exec("
        CREATE TABLE `{$prefix}user_profiles` (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED UNIQUE NOT NULL,
            bio TEXT NULL,
            phone VARCHAR(20) NULL,
            birthdate DATE NULL,
            FOREIGN KEY (user_id) REFERENCES `{$prefix}users`(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  Created: {$prefix}user_profiles\n";
    
    // 3. Categories (self-referencing)
    $pdo->exec("
        CREATE TABLE `{$prefix}categories` (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            slug VARCHAR(100) UNIQUE NOT NULL,
            parent_id INT UNSIGNED NULL,
            sort_order INT DEFAULT 0,
            FOREIGN KEY (parent_id) REFERENCES `{$prefix}categories`(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  Created: {$prefix}categories\n";
    
    // 4. Products
    $pdo->exec("
        CREATE TABLE `{$prefix}products` (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sku VARCHAR(50) UNIQUE NOT NULL,
            name VARCHAR(200) NOT NULL,
            description TEXT NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            stock INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            weight DECIMAL(8,3) NULL,
            metadata JSON NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_active_price (is_active, price)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  Created: {$prefix}products\n";
    
    // 5. Product-Category (N:M)
    $pdo->exec("
        CREATE TABLE `{$prefix}product_categories` (
            product_id INT UNSIGNED NOT NULL,
            category_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (product_id, category_id),
            FOREIGN KEY (product_id) REFERENCES `{$prefix}products`(id) ON DELETE CASCADE,
            FOREIGN KEY (category_id) REFERENCES `{$prefix}categories`(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  Created: {$prefix}product_categories\n";
    
    // 6. Tags
    $pdo->exec("
        CREATE TABLE `{$prefix}tags` (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(50) UNIQUE NOT NULL,
            color CHAR(7) DEFAULT '#000000'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  Created: {$prefix}tags\n";
    
    // 7. Product-Tag (N:M)
    $pdo->exec("
        CREATE TABLE `{$prefix}product_tags` (
            product_id INT UNSIGNED NOT NULL,
            tag_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (product_id, tag_id),
            FOREIGN KEY (product_id) REFERENCES `{$prefix}products`(id) ON DELETE CASCADE,
            FOREIGN KEY (tag_id) REFERENCES `{$prefix}tags`(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  Created: {$prefix}product_tags\n";
    
    // 8. Orders
    $pdo->exec("
        CREATE TABLE `{$prefix}orders` (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_number VARCHAR(20) UNIQUE NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            status ENUM('pending','processing','shipped','delivered','cancelled') DEFAULT 'pending',
            ordered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            shipped_at DATETIME NULL,
            delivered_at DATETIME NULL,
            FOREIGN KEY (user_id) REFERENCES `{$prefix}users`(id),
            INDEX idx_user_status (user_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  Created: {$prefix}orders\n";
    
    // 9. Order Items
    $pdo->exec("
        CREATE TABLE `{$prefix}order_items` (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id INT UNSIGNED NOT NULL,
            product_id INT UNSIGNED NOT NULL,
            quantity INT NOT NULL DEFAULT 1,
            unit_price DECIMAL(10,2) NOT NULL,
            FOREIGN KEY (order_id) REFERENCES `{$prefix}orders`(id) ON DELETE CASCADE,
            FOREIGN KEY (product_id) REFERENCES `{$prefix}products`(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  Created: {$prefix}order_items\n";
    
    // 10. All Types table
    $pdo->exec("
        CREATE TABLE `{$prefix}all_types` (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            col_tinyint TINYINT NULL,
            col_smallint SMALLINT NULL,
            col_int INT NULL,
            col_bigint BIGINT NULL,
            col_decimal DECIMAL(10,2) NULL,
            col_float FLOAT NULL,
            col_double DOUBLE NULL,
            col_char CHAR(10) NULL,
            col_varchar VARCHAR(100) NULL,
            col_text TEXT NULL,
            col_date DATE NULL,
            col_time TIME NULL,
            col_datetime DATETIME NULL,
            col_timestamp TIMESTAMP NULL,
            col_json JSON NULL,
            col_enum ENUM('a','b','c') NULL,
            col_boolean TINYINT(1) DEFAULT 0,
            INDEX idx_int (col_int),
            INDEX idx_datetime (col_datetime)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  Created: {$prefix}all_types\n";
    
    echo "\n[OK] All tables created.\n";
}

// ========================================================================
// SEED DATA (300+ records)
// ========================================================================
echo "\n--- Seeding data ---\n";

// Seed Categories (15)
$categories = [
    ['Electronics', 'electronics', null],
    ['Computers', 'computers', 1],
    ['Smartphones', 'smartphones', 1],
    ['Accessories', 'accessories', 1],
    ['Books', 'books', null],
    ['Fiction', 'fiction', 5],
    ['Non-Fiction', 'non-fiction', 5],
    ['Technical', 'technical', 7],
    ['Clothing', 'clothing', null],
    ['Men', 'men', 9],
    ['Women', 'women', 9],
    ['Sports', 'sports', null],
    ['Home', 'home', null],
    ['Toys', 'toys', null],
    ['Food', 'food', null],
];

$stmt = $pdo->prepare("INSERT INTO `{$prefix}categories` (name, slug, parent_id) VALUES (?, ?, ?)");
foreach ($categories as $cat) {
    $stmt->execute($cat);
}
echo "  Seeded " . count($categories) . " categories\n";

// Seed Tags (20)
$tagNames = ['new', 'sale', 'popular', 'premium', 'eco', 'wireless', 'bluetooth', 'usb-c', '4k', 'hd',
             'portable', 'waterproof', 'organic', 'vegan', 'handmade', 'vintage', 'limited', 'bestseller',
             'clearance', 'exclusive'];

$stmt = $pdo->prepare("INSERT INTO `{$prefix}tags` (name) VALUES (?)");
foreach ($tagNames as $tag) {
    $stmt->execute([$tag]);
}
echo "  Seeded 20 tags\n";

// Seed Users (50)
$firstNames = ['John', 'Jane', 'Bob', 'Alice', 'Charlie', 'Diana', 'Eve', 'Frank', 'Grace', 'Henry',
               'Ivy', 'Jack', 'Kate', 'Leo', 'Mia', 'Noah', 'Olivia', 'Paul', 'Quinn', 'Rose',
               'Sam', 'Tina', 'Uma', 'Victor', 'Wendy', 'Xavier', 'Yara', 'Zack', 'Amy', 'Ben'];
$lastNames = ['Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller', 'Davis', 'Rodriguez',
              'Martinez', 'Hernandez', 'Lopez', 'Gonzalez', 'Wilson', 'Anderson', 'Thomas', 'Taylor',
              'Moore', 'Jackson', 'Martin'];
$roles = ['admin', 'editor', 'user'];

$userStmt = $pdo->prepare("INSERT INTO `{$prefix}users` (name, email, role, status, credits) VALUES (?, ?, ?, ?, ?)");
$profileStmt = $pdo->prepare("INSERT INTO `{$prefix}user_profiles` (user_id, bio, phone, birthdate) VALUES (?, ?, ?, ?)");

for ($i = 0; $i < 50; $i++) {
    $first = $firstNames[$i % count($firstNames)];
    $last  = $lastNames[$i % count($lastNames)];
    $name  = "$first $last";
    $email = strtolower($first . '.' . $last . ($i + 1)) . '@example.com';
    $role  = $roles[$i % 3];
    $status = rand(0, 10) > 1 ? 1 : 0;
    $credits = round(rand(0, 10000) / 100, 2);
    
    $userStmt->execute([$name, $email, $role, $status, $credits]);
    $userId = $pdo->lastInsertId();
    
    if (rand(0, 10) > 3) {
        $bio = "Bio for $name.";
        $phone = '+1' . rand(200,999) . '-' . rand(100,999) . '-' . rand(1000,9999);
        $birthdate = date('Y-m-d', strtotime("-" . rand(18,70) . " years"));
        $profileStmt->execute([$userId, $bio, $phone, $birthdate]);
    }
}
echo "  Seeded 50 users with profiles\n";

// Seed Products (100)
$productNames = [
    'Wireless Mouse', 'Mechanical Keyboard', 'USB-C Hub', '4K Monitor', 'Bluetooth Speaker',
    'Smartphone Case', 'Laptop Stand', 'Webcam HD', 'External SSD', 'Power Bank',
    'Running Shoes', 'Coffee Maker', 'Desk Lamp', 'Backpack', 'Water Bottle',
    'Notebook', 'Pen Set', 'Headphones', 'Tablet', 'Smartwatch'
];

$stmt = $pdo->prepare("INSERT INTO `{$prefix}products` (sku, name, description, price, stock, is_active, weight, metadata) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

for ($i = 0; $i < 100; $i++) {
    $sku = 'SKU-' . str_pad($i + 1, 5, '0', STR_PAD_LEFT);
    $name = $productNames[$i % 20] . ' ' . ($i + 1);
    $desc = "High quality product. Perfect for everyday use.";
    $price = round(rand(99, 99999) / 100, 2);
    $stock = rand(0, 500);
    $isActive = rand(0, 10) > 1 ? 1 : 0;
    $weight = round(rand(10, 5000) / 100, 3);
    $metadata = json_encode([
        'color' => ['red','blue','black','white'][rand(0,3)],
        'rating' => round(rand(30, 50) / 10, 1),
    ]);
    
    $stmt->execute([$sku, $name, $desc, $price, $stock, $isActive, $weight, $metadata]);
}
echo "  Seeded 100 products\n";

// Product-Category assignments
$stmt = $pdo->prepare("INSERT IGNORE INTO `{$prefix}product_categories` (product_id, category_id) VALUES (?, ?)");
for ($i = 1; $i <= 100; $i++) {
    $numCats = rand(1, 3);
    $catIds = [];
    for ($j = 0; $j < $numCats; $j++) {
        $catIds[] = rand(1, 15);
    }
    $catIds = array_unique($catIds);
    foreach ($catIds as $catId) {
        $stmt->execute([$i, $catId]);
    }
}
echo "  Assigned product-category relationships\n";

// Product-Tag assignments
$stmt = $pdo->prepare("INSERT IGNORE INTO `{$prefix}product_tags` (product_id, tag_id) VALUES (?, ?)");
for ($i = 1; $i <= 100; $i++) {
    $numTags = rand(1, 4);
    $tagIds = [];
    for ($j = 0; $j < $numTags; $j++) {
        $tagIds[] = rand(1, 20);
    }
    $tagIds = array_unique($tagIds);
    foreach ($tagIds as $tagId) {
        $stmt->execute([$i, $tagId]);
    }
}
echo "  Assigned product-tag relationships\n";

// Seed Orders (100)
$statuses = ['pending', 'processing', 'shipped', 'delivered', 'cancelled'];
$orderStmt = $pdo->prepare("INSERT INTO `{$prefix}orders` (order_number, user_id, total_amount, status, ordered_at, shipped_at, delivered_at) VALUES (?, ?, 0, ?, ?, ?, ?)");
$itemStmt = $pdo->prepare("INSERT INTO `{$prefix}order_items` (order_id, product_id, quantity, unit_price) VALUES (?, ?, ?, ?)");

for ($i = 0; $i < 100; $i++) {
    $orderNum = 'ORD-' . date('Ymd') . '-' . str_pad($i + 1, 4, '0', STR_PAD_LEFT);
    $userId = rand(1, 50);
    $status = $statuses[rand(0, 4)];
    $orderedAt = date('Y-m-d H:i:s', strtotime("-" . rand(1, 90) . " days"));
    $shippedAt = in_array($status, ['shipped', 'delivered']) ? 
        date('Y-m-d H:i:s', strtotime($orderedAt . ' +' . rand(1, 3) . ' days')) : null;
    $deliveredAt = ($status === 'delivered') ? 
        date('Y-m-d H:i:s', strtotime($orderedAt . ' +' . rand(3, 7) . ' days')) : null;
    $totalAmount = 0;
    
    $orderStmt->execute([$orderNum, $userId, $status, $orderedAt, $shippedAt, $deliveredAt]);
    $orderId = $pdo->lastInsertId();
    
    $numItems = rand(1, 5);
    for ($j = 0; $j < $numItems; $j++) {
        $productId = rand(1, 100);
        $quantity = rand(1, 3);
        $unitPrice = round(rand(99, 19999) / 100, 2);
        $totalAmount += $quantity * $unitPrice;
        $itemStmt->execute([$orderId, $productId, $quantity, $unitPrice]);
    }
    
    $pdo->exec("UPDATE `{$prefix}orders` SET total_amount = $totalAmount WHERE id = $orderId");
}
echo "  Seeded 100 orders with items\n";

// Seed All Types (50)
$allStmt = $pdo->prepare("
    INSERT INTO `{$prefix}all_types` (
        col_tinyint, col_smallint, col_int, col_bigint,
        col_decimal, col_float, col_double,
        col_char, col_varchar, col_text,
        col_date, col_time, col_datetime, col_timestamp,
        col_json, col_enum, col_boolean
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

for ($i = 0; $i < 50; $i++) {
    $allStmt->execute([
        rand(-128, 127),
        rand(-32768, 32767),
        rand(-999999, 999999),
        rand(0, 9999999999),
        round(rand(0, 999999) / 100, 2),
        round(rand(0, 1000) / 1000, 4),
        round(rand(0, 10000) / 100, 6),
        str_pad(chr(65 + $i % 26), 10),
        'Sample text ' . ($i + 1),
        'Longer text content for row ' . ($i + 1),
        date('Y-m-d', strtotime("-" . rand(0, 365) . " days")),
        date('H:i:s'),
        date('Y-m-d H:i:s', strtotime("-" . rand(0, 86400*30) . " seconds")),
        date('Y-m-d H:i:s'),
        json_encode(['key' => "val$i", 'num' => $i]),
        ['a','b','c'][rand(0,2)],
        rand(0, 1)
    ]);
}
echo "  Seeded 50 rows in all_types\n";

// ========================================================================
// SCHEMA MAP
// ========================================================================
echo "\n--- Generating Schema Map ---\n";

$outputPath = __DIR__ . '/../tmp/mysql_schema_map.php';
$outputDir = dirname($outputPath);
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0777, true);
}

SchemaMapper::setOutputFile($outputPath);
$result = SchemaMapper::generate($pdo, MYSQL_DB, null, 'mysql_test');

if ($result) {
    $map = include $outputPath;
    $tableCount = count($map['tables'] ?? []);
    $relCount = count($map['relationships']['from'] ?? []);
    echo "[OK] Schema map generated\n";
    echo "  Tables: $tableCount\n";
    echo "  Relationships: $relCount\n";
    echo "  Features: " . count($map['features'] ?? []) . "\n";
} else {
    echo "[FAIL] Schema map generation failed\n";
}

// ========================================================================
// VERIFY
// ========================================================================
echo "\n--- Data Verification ---\n";

$counts = [];
$tableNames = ['users', 'user_profiles', 'products', 'categories', 'tags', 'orders', 'order_items', 'product_categories', 'product_tags', 'all_types'];
$total = 0;

foreach ($tableNames as $table) {
    $count = $pdo->query("SELECT COUNT(*) FROM `{$prefix}{$table}`")->fetchColumn();
    $counts[$table] = $count;
    $total += $count;
    echo "  $table: $count rows\n";
}

echo "\n  Total records: $total\n";

echo "\n==================================================\n";
echo "SETUP COMPLETE\n";
echo "==================================================\n";
echo "Tables: " . count($tableNames) . "\n";
echo "Total records: $total\n";
echo "Schema map: " . ($result ? 'OK' : 'FAILED') . "\n";