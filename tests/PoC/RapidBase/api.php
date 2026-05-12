<?php

/**
 * Router principal de la API
 * Uso: api.php?ep=HealthService&action=ping&connectionId=5
 * 
 * Este archivo demuestra cómo se podría enrutar las solicitudes HTTP
 * hacia los endpoints definidos en la carpeta EndPoints/
 */

// Cargar clases (en producción usar composer autoload)
require_once __DIR__ . '/Api/ApiContext.php';
require_once __DIR__ . '/Api/BaseEndpoint.php';

// Directorio donde se encuentran los endpoints
$endpointsDir = __DIR__ . '/Endpoints/';

// Obtener parámetros de la solicitud
$endpointName = $_GET['ep'] ?? '';
$action = $_GET['action'] ?? '';

// Validar que se haya especificado un endpoint
if (empty($endpointName)) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Missing required parameter: ep',
        'usage' => 'api.php?ep=EndpointName&action=method&param1=value1...'
    ]);
    exit;
}

// Construir el nombre de la clase
$className = "RapidBase\\Endpoints\\{$endpointName}";
$classFile = $endpointsDir . "{$endpointName}.php";

// Verificar si el archivo del endpoint existe
if (!file_exists($classFile)) {
    http_response_code(404);
    echo json_encode([
        'error' => "Endpoint '{$endpointName}' not found",
        'available_endpoints' => array_map(fn($f) => pathinfo($f, PATHINFO_FILENAME), glob($endpointsDir . '*.php'))
    ]);
    exit;
}

// Cargar el endpoint
require_once $classFile;

// Verificar que la clase exista
if (!class_exists($className)) {
    http_response_code(500);
    echo json_encode(['error' => "Class {$className} not found"]);
    exit;
}

// Crear el contexto con los datos de la solicitud
$context = new \RapidBase\Api\ApiContext(
    params:  array_merge($_GET, $_POST),
    session: $_SESSION ?? [],
    auth:    [] // Aquí podrías agregar lógica de autenticación
);

// Instanciar el endpoint e inyectar contexto
$api = new $className();
$api->setContext($context);

// Si no se especifica acción, devolver la descripción del endpoint
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

// Verificar que el método exista
if (!method_exists($api, $action)) {
    http_response_code(400);
    echo json_encode([
        'error' => "Method '{$action}' not found in endpoint '{$endpointName}'",
        'available_methods' => array_keys($api->describe())
    ]);
    exit;
}

// Obtener los parámetros requeridos por el método
$reflection = new ReflectionMethod($api, $action);
$parameters = $reflection->getParameters();

// Construir el array de argumentos para llamar al método
$args = [];
foreach ($parameters as $param) {
    $paramName = $param->getName();
    
    // Buscar el parámetro en GET o POST
    if (isset($context->params[$paramName])) {
        $value = $context->params[$paramName];
        
        // Convertir al tipo esperado si es necesario
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

// Ejecutar el método y devolver resultado
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
