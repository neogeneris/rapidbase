<?php
/**
 * MySQL Test Configuration
 * Copy this file to mysql-test-config.local.php and adjust settings
 */

// MySQL connection settings
define('MYSQL_HOST', '127.0.0.1');
define('MYSQL_PORT', 3306);
define('MYSQL_USER', 'root');
define('MYSQL_PASS', '');
define('MYSQL_DB', 'test');  // Using MySQL's default 'test' database

// Test table prefix to avoid conflicts
define('TEST_PREFIX', 'rb_test_');