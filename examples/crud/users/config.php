<?php
/**
 * Configuration for Users CRUD Example
 */

// Enable error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Define base path
define('BASE_PATH', dirname(__DIR__, 3));

// Autoloader
require_once BASE_PATH . '/Core/Autoloader.php';
\Core\Autoloader::register();

// Database configuration
define('DB_DRIVER', 'sqlite');
define('DB_PATH', __DIR__ . '/database.sqlite');

// Initialize database connection
use Core\DB;
DB::connect([
    'driver' => DB_DRIVER,
    'database' => DB_PATH
]);

// Set SQL driver for proper quoting
\Core\SQL::setDriver(DB_DRIVER);

// Enable L1/L2 cache for SQL structure (optional)
\Core\SQL::enableSqlCache(true);
