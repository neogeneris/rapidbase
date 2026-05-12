<?php
/**
 * Database Setup Script for RapidBase PoC
 * Creates the connections table with proper schema
 */

$dbPath = __DIR__ . '/rapidbase_poc.sqlite';

// Remove existing DB for clean start
if (file_exists($dbPath)) {
    unlink($dbPath);
}

$pdo = new PDO("sqlite:$dbPath");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Create connections table with all required fields
$pdo->exec("
CREATE TABLE connections (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL DEFAULT 'Unnamed',
    driver TEXT NOT NULL DEFAULT 'sqlite',
    host TEXT DEFAULT 'localhost',
    port INTEGER,
    database TEXT,
    username TEXT,
    password TEXT,
    description TEXT,
    environment TEXT CHECK(environment IN ('dev', 'qa', 'production')) DEFAULT 'dev',
    status TEXT CHECK(status IN ('active', 'inactive', 'maintenance')) DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    modified_at DATETIME DEFAULT CURRENT_TIMESTAMP
)
");

// Create trigger to update modified_at
$pdo->exec("
CREATE TRIGGER update_connections_modified 
AFTER UPDATE ON connections
BEGIN
    UPDATE connections SET modified_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END
");

// Insert a default SQLite connection for testing
$pdo->exec("
INSERT INTO connections (name, driver, host, database, username, password, description, environment, status)
VALUES (
    'PoC SQLite',
    'sqlite',
    'localhost',
    '{$dbPath}',
    '',
    '',
    'Default SQLite connection for Proof of Concept',
    'dev',
    'active'
)
");

echo "Database setup complete!\n";
echo "DB Path: $dbPath\n";
echo "Table: connections\n";
echo "Default connection created with ID=1\n";

// Verify table structure
$stmt = $pdo->query("PRAGMA table_info(connections)");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "\nTable Structure:\n";
foreach ($columns as $col) {
    echo "  - {$col['name']} ({$col['type']})\n";
}
