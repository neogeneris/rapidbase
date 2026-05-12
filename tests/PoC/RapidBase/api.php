<?php

/**
 * Main API Router
 * Usage: api.php?ep=ConnectionManager&action=list
 * 
 * This file demonstrates how to route HTTP requests
 * to endpoints defined in the Endpoints/ folder.
 */

// Load RapidBase bundle
require_once __DIR__ . '/lib/RapidBase.php';

// Initialize database connection using Conn::setup
\RapidBase\Core\Conn::setup('sqlite:' . __DIR__ . '/rapidbase_poc.sqlite', '', '', 'main');

// Load API classes
require_once __DIR__ . '/Api/ApiContext.php';
require_once __DIR__ . '/Api/BaseEndpoint.php';

// Auto-load Models
spl_autoload_register(function ($class) {
    if (strpos($class, 'RapidBase\\Models\\') === 0) {
        $file = __DIR__ . '/Models/' . substr(strrchr($class, '\\'), 1) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

// Directory where endpoints are located
$endpointsDir = __DIR__ . '/Endpoints/';

// Get request parameters (supports GET, POST, or CLI)
$endpointName = $_REQUEST['ep'] ?? ($_SERVER['argv'][1] ?? '');
$action = $_REQUEST['action'] ?? ($_SERVER['argv'][2] ?? '');

// Merge all request sources for flexibility
$params = array_merge($_GET, $_POST, $_REQUEST);
if (!empty($_SERVER['argv'])) {
    // Parse CLI arguments like ep=X action=Y param1=value1
    for ($i = 3; $i < count($_SERVER['argv']); $i++) {
        if (strpos($_SERVER['argv'][$i], '=') !== false) {
            list($key, $value) = explode('=', $_SERVER['argv'][$i], 2);
            $params[$key] = $value;
        }
    }
}

// Validate endpoint is specified
if (empty($endpointName)) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Missing required parameter: ep',
        'usage' => 'api.php?ep=EndpointName&action=method&param1=value1...'
    ]);
    exit;
}

// Build class name
$className = "RapidBase\\Endpoints\\{$endpointName}";
$classFile = $endpointsDir . "{$endpointName}.php";

// Check if endpoint file exists
if (!file_exists($classFile)) {
    http_response_code(404);
    echo json_encode([
        'error' => "Endpoint '{$endpointName}' not found",
        'available_endpoints' => array_map(fn($f) => pathinfo($f, PATHINFO_FILENAME), glob($endpointsDir . '*.php'))
    ]);
    exit;
}

// Load the endpoint
require_once $classFile;

// Verify class exists
if (!class_exists($className)) {
    http_response_code(500);
    echo json_encode(['error' => "Class {$className} not found"]);
    exit;
}

// Create context with request data
$context = new \RapidBase\Api\ApiContext(
    params:  $params,
    session: $_SESSION ?? [],
    auth:    [] // Add authentication logic here
);

// Instantiate endpoint and inject context
$api = new $className();
$api->setContext($context);

// If no action specified, return endpoint description
if (empty($action)) {
    header('Content-Type: application/json');
    echo json_encode([
        'endpoint' => $endpointName,
        'version' => $api->version(),
        'catalog' => $api->catalog(),
        'methods' => $api->describe()
    ], JSON_PRETTY_PRINT);
    exit;
}

// Check if method exists
if (!method_exists($api, $action)) {
    http_response_code(400);
    echo json_encode([
        'error' => "Method '{$action}' not found in endpoint '{$endpointName}'",
        'available_methods' => array_keys($api->describe())
    ]);
    exit;
}

// Get method parameters
$reflection = new ReflectionMethod($api, $action);
$parameters = $reflection->getParameters();

// Build arguments array for method call
$args = [];
foreach ($parameters as $param) {
    $paramName = $param->getName();
    
    // Look for parameter in request
    if (isset($context->params[$paramName])) {
        $value = $context->params[$paramName];
        
        // Convert to expected type if necessary
        if ($param->hasType()) {
            $type = $param->getType()->getName();
            if ($type === 'int') {
                $value = (int)$value;
            } elseif ($type === 'float') {
                $value = (float)$value;
            } elseif ($type === 'bool') {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            }
        }
        
        $args[] = $value;
    } elseif (!$param->isOptional()) {
        http_response_code(400);
        echo json_encode([
            'error' => "Missing required parameter: {$paramName}",
            'required_parameters' => array_map(fn($p) => $p->getName(), 
                array_filter($parameters, fn($p) => !$p->isOptional()))
        ]);
        exit;
    } else {
        $args[] = $param->getDefaultValue();
    }
}

// Execute method and return result
try {
    $result = call_user_func_array([$api, $action], $args);
    header('Content-Type: application/json');
    echo json_encode($result, JSON_PRETTY_PRINT);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal server error',
        'message' => $e->getMessage()
    ]);
}
