#!/usr/bin/env php
<?php

/**
 * Simple TDD Test for SystemInfo Endpoint
 * No external dependencies, uses RapidBase internal classes
 */

// Auto-load classes manually for this test
spl_autoload_register(function ($class) {
    $baseDir = __DIR__ . '/';
    $file = $baseDir . str_replace('\\', '/', $class) . '.php';
    
    // Try Api folder
    if ($class === 'RapidBase\Api\ApiContext') {
        $file = __DIR__ . '/Api/ApiContext.php';
    } elseif ($class === 'RapidBase\Api\BaseEndpoint') {
        $file = __DIR__ . '/Api/BaseEndpoint.php';
    } elseif ($class === 'RapidBase\Endpoints\SystemInfo') {
        $file = __DIR__ . '/Endpoints/SystemInfo.php';
    }
    
    if (file_exists($file)) {
        require_once $file;
        return true;
    }
    return false;
});

echo "_________________________________________________\n";
echo "RapidBase TDD - SystemInfo Endpoint Test\n";
echo "_________________________________________________\n\n";

// Test 1: Instantiate and describe
echo "Test 1: Instantiate SystemInfo and call describe()\n";
try {
    $context = new \RapidBase\Api\ApiContext(
        params: [],
        session: ['user_id' => 1],
        auth: ['role' => 'admin']
    );

    $api = new \RapidBase\Endpoints\SystemInfo();
    $api->setContext($context);

    $methods = $api->describe();
    
    if (isset($methods['catalog']) && isset($methods['method']) && isset($methods['version'])) {
        echo "[OK] describe() returned expected methods\n";
        echo "     Methods found: " . implode(', ', array_keys($methods)) . "\n";
    } else {
        echo "[FAILURE] describe() missing expected methods\n";
    }
} catch (\Throwable $e) {
    echo "[FAILURE] Error: " . $e->getMessage() . "\n";
}

echo "_________________________________________________\n\n";

// Test 2: Call catalog()
echo "Test 2: Call catalog() method\n";
try {
    $catalog = $api->catalog();
    
    if (is_array($catalog)) {
        echo "[OK] catalog() returned an array\n";
        echo "     Endpoints found: " . count($catalog) . "\n";
        foreach (array_keys($catalog) as $ep) {
            echo "       - $ep\n";
        }
    } else {
        echo "[FAILURE] catalog() did not return an array\n";
    }
} catch (\Throwable $e) {
    echo "[FAILURE] Error: " . $e->getMessage() . "\n";
}

echo "_________________________________________________\n\n";

// Test 3: Call version()
echo "Test 3: Call version() method\n";
try {
    $version = $api->version();
    
    if (isset($version['version']) && isset($version['name'])) {
        echo "[OK] version() returned valid data\n";
        echo "     Version: " . $version['version'] . "\n";
        echo "     Name: " . $version['name'] . "\n";
    } else {
        echo "[FAILURE] version() missing expected fields\n";
    }
} catch (\Throwable $e) {
    echo "[FAILURE] Error: " . $e->getMessage() . "\n";
}

echo "_________________________________________________\n\n";
echo "Tests completed.\n";
