<?php

/**
 * Suite de pruebas para demostrar la facilidad de testing de la API
 */

require_once __DIR__ . '/Api/ApiContext.php';
require_once __DIR__ . '/Api/BaseEndpoint.php';
require_once __DIR__ . '/Endpoints/HealthService.php';
require_once __DIR__ . '/Endpoints/UserService.php';

use RapidBase\Api\ApiContext;
use RapidBase\Endpoints\HealthService;
use RapidBase\Endpoints\UserService;

class ApiTester {
    private int $passed = 0;
    private int $failed = 0;
    
    public function assert($condition, string $message): void {
        if ($condition) {
            echo "✓ {$message}\n";
            $this->passed++;
        } else {
            echo "✗ {$message}\n";
            $this->failed++;
        }
    }
    
    public function summary(): array {
        return [
            'passed' => $this->passed,
            'failed' => $this->failed,
            'total' => $this->passed + $this->failed
        ];
    }
    
    public function getFailedCount(): int {
        return $this->failed;
    }
}

$test = new ApiTester();

echo "=== PRUEBAS UNITARIAS - HealthService ===\n\n";

// Test 1: Inyección de contexto
$context = new ApiContext(
    params: ['connectionId' => 5],
    session: ['user_id' => 101]
);
$health = new HealthService();
$health->setContext($context);
$test->assert(true, "Se puede inyectar contexto en el endpoint");

// Test 2: Método ping devuelve estructura correcta
$result = $health->ping(5);
$test->assert($result['status'] === 'online', "ping() devuelve status 'online'");
$test->assert($result['user_context'] === 101, "ping() usa el contexto de sesión correctamente");
$test->assert(isset($result['latency']), "ping() devuelve latencia");

// Test 3: Autodescripción funciona
$description = $health->describe();
$test->assert(isset($description['ping']), "describe() incluye el método ping");
$test->assert($description['ping']['description'] !== '', "describe() extrae documentación del método");
$test->assert(count($description['ping']['parameters']) > 0, "describe() incluye parámetros");

echo "\n=== PRUEBAS UNITARIAS - UserService ===\n\n";

// Test 4: getUser con contexto
$context = new ApiContext(
    params: ['userId' => 42],
    session: ['user_id' => 999]
);
$userService = new UserService();
$userService->setContext($context);

$user = $userService->getUser(42);
$test->assert($user['id'] === 42, "getUser() devuelve el ID correcto");
$test->assert($user['authenticated_user'] === 999, "getUser() accede al contexto de sesión");

// Test 5: createUser con parámetros opcionales
$result = $userService->createUser('Test', 'test@example.com');
$test->assert($result['success'] === true, "createUser() retorna éxito");
$test->assert($result['data']['role'] === 1, "createUser() usa valor por defecto para role");

// Test 6: createUser con todos los parámetros
$result = $userService->createUser('Admin', 'admin@example.com', 5);
$test->assert($result['data']['role'] === 5, "createUser() respeta parámetro role explícito");

// Test 7: listUsers con paginación
$result = $userService->listUsers(2, 5);
$test->assert($result['page'] === 2, "listUsers() respeta parámetro page");
$test->assert($result['limit'] === 5, "listUsers() respeta parámetro limit");

// Test 8: listUsers con valores por defecto
$result = $userService->listUsers();
$test->assert($result['page'] === 1, "listUsers() usa valor por defecto page=1");
$test->assert($result['limit'] === 10, "listUsers() usa valor por defecto limit=10");

// Test 9: Prueba de aislamiento - diferentes contextos no interfieren
$context1 = new ApiContext(session: ['user_id' => 111]);
$context2 = new ApiContext(session: ['user_id' => 222]);

$api1 = new HealthService();
$api1->setContext($context1);

$api2 = new HealthService();
$api2->setContext($context2);

$result1 = $api1->ping(1);
$result2 = $api2->ping(1);

$test->assert($result1['user_context'] === 111, "Contexto 1 está aislado (user_id=111)");
$test->assert($result2['user_context'] === 222, "Contexto 2 está aislado (user_id=222)");

// Test 10: Verificar que describe() ignora métodos mágicos y de la base
$description = $userService->describe();
$test->assert(!array_key_exists('setContext', $description), 
    "describe() ignora métodos heredados de BaseEndpoint");
$test->assert(!array_key_exists('__construct', $description), "describe() ignora métodos mágicos");

echo "\n";
$summary = $test->summary();
echo "=== Resumen ===\n";
echo "Pasadas: {$summary['passed']}\n";
echo "Fallidas: {$summary['failed']}\n";
echo "Total: {$summary['total']}\n";

if ($test->getFailedCount() === 0) {
    echo "\n🎉 ¡Todas las pruebas pasaron!\n";
    exit(0);
} else {
    echo "\n⚠️  Algunas pruebas fallaron\n";
    exit(1);
}
