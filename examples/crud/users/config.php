<?php
/**
 * Configuration for Users CRUD Example
 */

// Enable error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Define base path
define('BASE_PATH', dirname(__DIR__, 3));

// Load Composer autoloader
require_once BASE_PATH . '/vendor/autoload.php';

use RapidBase\Core\DB;
use RapidBase\Core\Conn;

// Database configuration
define('DB_DRIVER', 'sqlite');
define('DB_PATH', __DIR__ . '/database.sqlite');

// Initialize database connection
DB::setup('sqlite:' . DB_PATH, '', '', 'main');

// Load schema map for metadata (columns, titles, relationships)
$schemaMapFile = __DIR__ . '/schema_map_local.php';
if (file_exists($schemaMapFile)) {
    \RapidBase\Core\SchemaMap::loadFromFile($schemaMapFile, 'main');
}
