<?php
namespace Tests\Unit\Cache;

use RapidBase\Core\Cache\CacheService;
use RapidBase\Core\Cache\Adapters\DirectoryCacheAdapter;
use RapidBase\Core\Event;

// Autoload manual para el entorno de pruebas (usando rutas absolutas)
$basePath = dirname(__DIR__, 3) . '/src/RapidBase/Core';
include_once $basePath . '/Cache/CacheService.php';
include_once $basePath . '/Cache/Adapters/DirectoryCacheAdapter.php';
include_once $basePath . '/Event.php';

echo "--- Ejecutando: CacheTest.php (Motor de Persistencia L1/L2) ---\n";

/**
 * Utilidad de aserción para RapidBase
 */
function assert_cache($name, $expected, $actual) {
    if ($expected === $actual) {
        echo "\033[32m[OK]\033[0m $name\n";
    } else {
        echo "\033[31m[FAIL]\033[0m $name\n";
        echo "  Esperado: " . var_export($expected, true) . "\n";
        echo "  Obtenido: " . var_export($actual, true) . "\n";
        exit(1);
    }
}

/**
 * Limpieza profunda: Borra archivos y subcarpetas
 */
function deleteDirectory($dir) {
    if (!file_exists($dir)) return true;
    if (!is_dir($dir)) return unlink($dir);
    foreach (scandir($dir) as $item) {
        if ($item == '.' || $item == '..') continue;
        if (!deleteDirectory($dir . DIRECTORY_SEPARATOR . $item)) return false;
    }
    return rmdir($dir);
}

// Configuración del entorno temporal UNIFICADA
$testPath = __DIR__ . '/../../tmp/cache';
if (!is_dir($testPath)) {
    mkdir($testPath, 0777, true);
} else {
    // Limpiar por si había restos
    deleteDirectory($testPath);
    mkdir($testPath, 0777, true);
}

// Inicializamos el servicio
CacheService::init($testPath);

// --- CASO 1: Escritura y Lectura Estándar ---
$res1 = CacheService::remember('user_1', 60, fn() => "Neogeneris");
assert_cache("Escritura inicial en disco", "Neogeneris", $res1);

$res2 = CacheService::remember('user_1', 60, fn() => "ERROR");
assert_cache("Recuperación desde caché (Hit)", "Neogeneris", $res2);

// --- CASO 2: El problema del FALSE (Tipos Estrictos) ---
CacheService::remember('status_flag', 60, fn() => false);
$resFalse = CacheService::remember('status_flag', 60, fn() => "ERROR");
assert_cache("Manejo de valor booleano FALSE", false, $resFalse);

// --- CASO 3: Invalidación por Evento (SQL Update) ---
Event::listen('db.success', function($data) {
    if (strpos($data['sql'], 'UPDATE users') !== false) {
        CacheService::clearByPrefix('gw_users');
    }
});

CacheService::remember('gw_users_list', 60, fn() => ['id' => 1]);
// Disparamos evento que debería limpiar el prefijo 'gw_users'
Event::fire('db.success', ['sql' => 'UPDATE users SET active = 1']);

$checkInvalidation = false;
CacheService::remember('gw_users_list', 60, function() use (&$checkInvalidation) {
    $checkInvalidation = true;
    return [];
});
assert_cache("Invalidación automática por prefijo", true, $checkInvalidation);

// --- CASO 4: Expiración por TTL ---
echo "Probando expiración (esperando 2s)... ";
CacheService::remember('short_lived', 1, fn() => "Viejo");
sleep(2);
$resExp = CacheService::remember('short_lived', 60, fn() => "Nuevo");
assert_cache("Auto-expiración de archivos", "Nuevo", $resExp);

// --- CASO 5: Modo Fail-Safe (Motor Deshabilitado) ---
$ref = new \ReflectionProperty(CacheService::class, 'enabled');
$ref->setAccessible(true);
$ref->setValue(null, false);

$callCount = 0;
$callback = function() use (&$callCount) { $callCount++; return "DB_DATA"; };

CacheService::remember('fail_test', 60, $callback);
CacheService::remember('fail_test', 60, $callback);

assert_cache("Fail-Safe: Bypass del motor cuando está apagado", 2, $callCount);

// Restauramos
$ref->setValue(null, true);

// --- LIMPIEZA ---
deleteDirectory($testPath);

echo "\n\033[32m[SUCCESS]\033[0m La fundición de caché ha pasado todas las pruebas.\n";