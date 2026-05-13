<?php

declare(strict_types=1);

namespace RapidBase\Api\v1;

use RapidBase\Api\ApiContext;

/**
 * Simple Router for API v1
 * Routes requests to Endpoints based on $_REQUEST parameters.
 */
class Router {
    
    private string $endpointDir;
    
    public function __construct() {
        $this->endpointDir = __DIR__ . '/Endpoints/';
    }

    public function handle(): void {
        header('Content-Type: application/json');
        
        // Get parameters
        $epName = $_REQUEST['ep'] ?? '';
        $action = $_REQUEST['action'] ?? '';
        
        if (empty($epName)) {
            http_response_code(400);
            echo json_encode([
                'error' => 'Missing required parameter: ep',
                'usage' => 'index.php?ep=EndpointName&action=method&param1=value1...'
            ]);
            return;
        }
        
        // Sanitize endpoint name
        $epName = preg_replace('/[^a-zA-Z0-9_]/', '', $epName);
        $className = "RapidBase\\Endpoints\\{$epName}";
        $filePath = $this->endpointDir . "{$epName}.php";
        
        if (!file_exists($filePath)) {
            http_response_code(404);
            echo json_encode([
                'error' => "Endpoint '{$epName}' not found",
                'available_endpoints' => $this->getAvailableEndpoints()
            ]);
            return;
        }
        
        require_once $filePath;
        
        if (!class_exists($className)) {
            http_response_code(500);
            echo json_encode(['error' => "Class {$className} not found"]);
            return;
        }
        
        try {
            // Instantiate endpoint
            $endpoint = new $className();
            
            // Build context from $_REQUEST
            $context = new ApiContext(
                params: $_REQUEST,
                session: $_SESSION ?? [],
                auth: [] // Could extract from headers/session
            );
            
            // Inject context
            if (method_exists($endpoint, 'setContext')) {
                $endpoint->setContext($context);
            }
            
            // Execute action
            if (empty($action) || !method_exists($endpoint, $action)) {
                http_response_code(400);
                echo json_encode([
                    'error' => "Action '{$action}' not found in {$epName}",
                    'available_actions' => array_keys($endpoint->describe())
                ]);
                return;
            }
            
            // Extract parameters for the method
            $reflection = new \ReflectionMethod($className, $action);
            $params = [];
            foreach ($reflection->getParameters() as $param) {
                $name = $param->getName();
                if (isset($_REQUEST[$name])) {
                    $params[$name] = $_REQUEST[$name];
                } elseif ($param->isDefaultValueAvailable()) {
                    $params[$name] = $param->getDefaultValue();
                }
            }
            
            // Call method
            $result = $reflection->invokeArgs($endpoint, array_values($params));
            
            echo json_encode($result);
            
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'error' => 'Internal Server Error',
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
    }
    
    private function getAvailableEndpoints(): array {
        $endpoints = [];
        if (is_dir($this->endpointDir)) {
            foreach (scandir($this->endpointDir) as $file) {
                if (str_ends_with($file, '.php')) {
                    $endpoints[] = basename($file, '.php');
                }
            }
        }
        return $endpoints;
    }
}
