<?php
/**
 * Prueba para diagnosticar el problema de LIMIT con array sort en DB::grid()
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/bootstrap.php';

use RapidBase\Core\DB;
use RapidBase\Core\Conn;
use RapidBase\Core\Cache\CacheService;

echo "=== DIAGNÓSTICO DE PROBLEMA LIMIT CON ARRAY SORT ===\n\n";

// Configurar entorno
Conn::setup('sqlite::memory:', '', '', 'main');

$cachePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rapidbase_debug_' . uniqid();
if (!is_dir($cachePath)) {
    mkdir($cachePath, 0777, true);
}
CacheService::init($cachePath);

// Crear tabla de prueba
DB::exec("CREATE TABLE test_grid (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    value INTEGER DEFAULT 0
)");

// Insertar 25 registros
for ($i = 1; $i <= 25; $i++) {
    DB::insert('test_grid', ['name' => "Item $i", 'value' => $i * 10]);
}

echo "Datos insertados: 25 registros\n\n";

// Prueba 1: grid con page=0 y array sort (debería retornar 25)
echo "--- Prueba 1: page=0 con array sort ['value', '-name'] ---\n";
try {
    $response = DB::grid('test_grid', [], 0, ['value', '-name']);
    echo "Registros obtenidos: " . count($response->data) . "\n";
    echo "Total reportado: {$response->total}\n";
    echo "Count: {$response->count}\n";
    echo "Page: {$response->state['page']}\n";
    echo "Per Page: {$response->state['per_page']}\n";
    echo "SQL ejecutado: " . ($response->metadata['sql'] ?? 'N/A') . "\n";
    
    if (count($response->data) !== 25) {
        echo "❌ ERROR: Se esperaban 25 registros pero se obtuvieron " . count($response->data) . "\n";
    } else {
        echo "✓ OK: Correcto\n";
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

// Prueba 2: grid con page=0 y string sort (para comparar)
echo "\n--- Prueba 2: page=0 con string sort 'value' ---\n";
try {
    $response = DB::grid('test_grid', [], 0, 'value');
    echo "Registros obtenidos: " . count($response->data) . "\n";
    echo "Total reportado: {$response->total}\n";
    echo "Count: {$response->count}\n";
    
    if (count($response->data) !== 25) {
        echo "❌ ERROR: Se esperaban 25 registros pero se obtuvieron " . count($response->data) . "\n";
    } else {
        echo "✓ OK: Correcto\n";
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

// Prueba 3: grid con page=1 y array sort (debería retornar 10)
echo "\n--- Prueba 3: page=1 con array sort ['value', '-name'] ---\n";
try {
    $response = DB::grid('test_grid', [], 1, ['value', '-name']);
    echo "Registros obtenidos: " . count($response->data) . "\n";
    echo "Total reportado: {$response->total}\n";
    echo "Count: {$response->count}\n";
    echo "Page: {$response->state['page']}\n";
    echo "Per Page: {$response->state['per_page']}\n";
    
    if (count($response->data) !== 10) {
        echo "❌ ERROR: Se esperaban 10 registros pero se obtuvieron " . count($response->data) . "\n";
    } else {
        echo "✓ OK: Correcto\n";
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

// Prueba 4: Analizar qué pasa en Gateway::select cuando sort es array
echo "\n--- Prueba 4: Debug interno de Gateway::select ---\n";
use RapidBase\Core\Gateway;
use RapidBase\Core\SQL\Q;

// Simular lo que hace DB::grid internamente
$page = 0;
$sort = ['value', '-name'];

echo "Valor de page: ";
var_dump($page);
echo "Valor de sort: ";
var_dump($sort);

// Verificar normalización de paginación en DB::grid (línea 254-259)
if (is_array($page) && count($page) === 2) {
    echo "→ page es array [page, perPage]\n";
} elseif (is_numeric($page) && (int)$page > 0) {
    echo "→ page es numérico positivo, usa limit 10 por defecto\n";
} else {
    echo "→ page=0 o no numérico: gatewayPage = [] (sin límites)\n";
    $gatewayPage = [];
}

// Ahora verificar qué pasa cuando Gateway::select recibe gatewayPage=[]
echo "\nVerificando lógica en Gateway::select (línea 34-48):\n";
if ($gatewayPage !== 0 && $gatewayPage !== null) {
    echo "→ gatewayPage NO es 0 ni null\n";
    if (is_array($gatewayPage)) {
        echo "→ Es array, crea pagination = [offset, limit]\n";
        $offset = max(0, (int)$gatewayPage[0]);
        $limit = max(1, (int)($gatewayPage[1] ?? 10));
        echo "   offset=$offset, limit=$limit\n";
    }
} else {
    echo "→ gatewayPage ES 0 o null, pagination permanece null\n";
}

// Limpieza
if (is_dir($cachePath)) {
    $files = glob($cachePath . '/*');
    foreach ($files as $file) {
        unlink($file);
    }
    rmdir($cachePath);
}

echo "\n=== ANÁLISIS COMPLETADO ===\n";
