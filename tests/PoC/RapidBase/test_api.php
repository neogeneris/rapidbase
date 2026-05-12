<?php

// Para esta prueba de concepto, cargamos las clases manualmente
// En producción usarías composer autoload
require_once __DIR__ . '/Api/ApiContext.php';
require_once __DIR__ . '/Api/BaseEndpoint.php';
require_once __DIR__ . '/Endpoints/HealthService.php';

use RapidBase\Api\ApiContext;
use RapidBase\Endpoints\HealthService;

echo "=== Prueba de Concepto: API Testable ===\n\n";

// Crear contexto simulado (ideal para tests unitarios)
$context = new ApiContext(
    params:  ['connectionId' => 5],
    session: ['user_id' => 101],
    auth:    ['role' => 'admin']
);

// Instanciar el endpoint e inyectar contexto
$api = new HealthService();
$api->setContext($context);

// 1. Probar autodescripción del endpoint
echo "1. Autodescripción del endpoint:\n";
print_r($api->describe());

echo "\n";

// 2. Probar ejecución funcional
echo "2. Ejecución del método ping():\n";
$result = $api->ping(5);
print_r($result);

echo "\n";

// 3. Verificar que el contexto se usó correctamente
echo "3. Verificación de contexto inyectado:\n";
if ($result['user_context'] === 101) {
    echo "✓ El contexto de sesión se inyectó correctamente\n";
} else {
    echo "✗ Error: el contexto no se inyectó\n";
}

echo "\n=== Prueba completada ===\n";
