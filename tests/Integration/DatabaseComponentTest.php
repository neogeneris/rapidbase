<?php
namespace Tests\Integration;

// 1. Carga manual de dependencias (Fundamentos)
include_once __DIR__ . "/../../src/RapidBase/Core/Cache/CacheService.php";
include_once __DIR__ . "/../../src/RapidBase/Core/Cache/Adapters/DirectoryCacheAdapter.php";

use RapidBase\Core\Cache\CacheService;

/**
 * Simulacro de la clase DB para probar la integración con los cimientos de caché.
 */
class DB {
    public static function list(string $table) {
        // Usamos un TTL de 60 segundos para la lista
        return CacheService::remember("db_list_{$table}", 60, function() use ($table) {
            echo "[DB] Consultando lista de $table en la base de datos...\n";
            return [
                ['id' => 1, 'username' => 'admin', 'role' => 'root'],
                ['id' => 2, 'username' => 'anyul', 'role' => 'dev']
            ];
        });
    }

    public static function grid(string $table, array $params = []) {
        // La clave debe ser única según los parámetros (filtros, orden, etc.)
        $hash = md5(json_encode($params));
        $key = "db_grid_{$table}_{$hash}";

        return CacheService::remember($key, 300, function() use ($table, $params) {
            echo "[DB] Generando estructura de GRID para $table...\n";
            return [
                'metadata' => [
                    'table' => $table,
                    'params' => $params,
                    'generated_at' => date('H:i:s')
                ],
                'data' => [
                    ['ID' => 10, 'Name' => 'Producto Alpha', 'Stock' => 50],
                    ['ID' => 11, 'Name' => 'Producto Beta', 'Stock' => 12]
                ]
            ];
        });
    }
}

// --- EJECUCIÓN DEL TEST ---

echo "--- Test de Integración: DB::list & DB::grid ---\n";

// Inicializamos en una carpeta temporal para este test
$cachePath = __DIR__ . DIRECTORY_SEPARATOR . 'temp_db_integ';
CacheService::init($cachePath);

// PRUEBA 1: DB::list
echo "\n[PASO 1] Primera llamada a list('users'):\n";
$list1 = DB::list('users');

echo "[PASO 2] Segunda llamada (debería ser instantánea y sin mensaje [DB]):\n";
$list2 = DB::list('users');

// PRUEBA 2: DB::grid
echo "\n[PASO 3] Primera llamada a grid('products'):\n";
$grid1 = DB::grid('products', ['page' => 1]);

echo "[PASO 4] Segunda llamada a grid('products') con MISMOS parámetros:\n";
$grid2 = DB::grid('products', ['page' => 1]);

echo "[PASO 5] Llamada a grid('products') con DIFERENTES parámetros (Caché Miss):\n";
$grid3 = DB::grid('products', ['page' => 2]);

// --- VERIFICACIÓN FINAL ---

echo "\n--- Resultados ---\n";
$success = true;

if ($list1 !== $list2) {
    echo "\033[31m[FAIL]\033[0m Los datos de la lista no coinciden.\n";
    $success = false;
}

if ($grid1['metadata']['generated_at'] !== $grid2['metadata']['generated_at']) {
    echo "\033[31m[FAIL]\033[0m El grid no se recuperó de la caché.\n";
    $success = false;
}

if ($success) {
    echo "\033[32m[SUCCESS]\033[0m La integración de los componentes DB con la Caché funciona correctamente.\n";
}

// Limpieza de rastros
// Para ver los archivos generados en Windows, puedes comentar la siguiente línea:
// (new \Core\Cache\Adapters\DirectoryCacheAdapter($cachePath))->clear();