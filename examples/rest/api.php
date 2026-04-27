<?php
/**
 * REST API Endpoint - Ejemplo de uso de RESTAdapter
 * 
 * Este endpoint demuestra cómo RESTAdapter transforma los datos
 * desde QueryResponse (formato FETCH_NUM compacto) hacia JSON estándar.
 * 
 * URL Parameters:
 * - page: Número de página (ej: &page=2) o Página:Limite (ej: &page=2:50)
 * - sort: Campo de ordenamiento con prefijo opcional (ej: &sort=-created_at,id)
 *         Prefijo '-' indica DESC, por defecto es ASC
 * - search: Texto de búsqueda global (ej: &search=john)
 * - filter: Filtros en JSON (ej: &filter={"age":">18","status":"active"})
 */

declare(strict_types=1);

// ========== INCLUDES MANUALES (sin autoloader) ==========
$srcBase = __DIR__ . '/../../src/RapidBase';
$infraBase = __DIR__ . '/../../src/Infrastructure';

require_once $srcBase . '/Core/Conn.php';
require_once $srcBase . '/Core/SQL.php';
require_once $srcBase . '/Core/Executor.php';
require_once $srcBase . '/Core/SchemaMap.php';
require_once $srcBase . '/Core/Cache/CacheService.php';
require_once $srcBase . '/Core/Event.php';
require_once $srcBase . '/Core/Gateway.php';
require_once $srcBase . '/Core/QueryResponse.php';
require_once $srcBase . '/Core/DBInterface.php';
require_once $srcBase . '/Core/DB.php';
require_once $infraBase . '/Ui/Adapter/RESTAdapter.php';

// ========== CONFIGURACIÓN DB ==========
use RapidBase\Core\DB;
use RapidBase\Infrastructure\Ui\Adapter\RESTAdapter;
use RapidBase\Core\Cache\CacheService;

// Configuración SQLite
$dbPath = __DIR__ . '/../crud/users/database.sqlite';

// Inicializar caché (usando directorio tests/tmp)
$cacheDir = __DIR__ . '/../../tests/tmp/cache';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}
try {
    CacheService::init($cacheDir);
    CacheService::enable(); // Habilitar explícitamente
} catch (Exception $e) {
    // Si falla el cache, continuar sin él
    error_log('Cache init failed: ' . $e->getMessage());
}

try {
    DB::setup("sqlite:{$dbPath}", '', '', 'main');
} catch (Exception $e) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['error' => 'DB Connection Error: ' . $e->getMessage()]);
    exit;
}

// Configurar headers CORS y JSON
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Manejar preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    // Cargar schema map para obtener definición de columnas
    $schemaMapFile = __DIR__ . '/schema_map.php';
    $schemaMap = file_exists($schemaMapFile) ? require $schemaMapFile : [];
    
    // Obtener columnas de la tabla users desde el schema map
    $tableColumns = [];
    if (!empty($schemaMap['tables']['users'])) {
        $tableColumns = array_keys($schemaMap['tables']['users']);
    } else {
        // Fallback por si no hay schema map
        $tableColumns = ['id', 'name', 'email', 'role', 'created_at'];
    }
    
    // Parsear parámetros desde URL
    $pageParam = $_GET['page'] ?? 0;
    $sort = $_GET['sort'] ?? [];
    $search = $_GET['search'] ?? null;
    $filter = $_GET['filter'] ?? null;
    
    // Construir condiciones de búsqueda
    $conditions = [];
    if ($search) {
        $conditions[] = "name LIKE '%$search%' OR email LIKE '%$search%'";
    }
    
    // Procesar filtros JSON
    if ($filter) {
        $filters = json_decode($filter, true);
        if (is_array($filters)) {
            foreach ($filters as $field => $value) {
                // Soporte para operadores: =, >, <, >=, <=, !=, LIKE
                if (is_string($value) && preg_match('/^(>|<|>=|<=|!=|=|LIKE)?(.*)$/', $value, $matches)) {
                    $operator = $matches[1] ?: '=';
                    $val = $matches[2];
                    
                    // Escapar valor para prevenir SQL injection
                    $val = DB::getInstance()->quote($val);
                    
                    if ($operator === 'LIKE') {
                        $conditions[] = "$field LIKE '%$val%'";
                    } elseif ($operator === '=') {
                        $conditions[] = "$field = $val";
                    } else {
                        $conditions[] = "$field $operator $val";
                    }
                }
            }
        }
    }
    
    // Parsear página: formato polimórfico [pageNum, perPage]
    // Según estándar RapidBase: page=[numero_pagina, registros_por_pagina]
    // Ejemplo: page=1,10 significa "Página 1, mostrando 10 registros"
    $parsedPage = [];
    if (is_string($pageParam) && strpos($pageParam, ',') !== false) {
        // Formato: "pageNum,perPage" ej: "2,10" → Página 2, 10 regs por página
        $parts = explode(',', $pageParam);
        $pageNum = max(1, (int)($parts[0] ?? 1));
        $perPage = (int)($parts[1] ?? 10);
        $parsedPage = [$pageNum, $perPage];
    } elseif (is_numeric($pageParam) && $pageParam > 0) {
        // Solo número de página: usar default 10 regs por página
        $parsedPage = [(int)$pageParam, 10];
    } else {
        // Default: página 1, 10 registros por página
        $parsedPage = [1, 10];
    }
    
    // Convertir sort string a array si es necesario
    $sortArray = [];
    if (is_string($sort) && !empty($sort)) {
        $sortArray = [$sort];
    } elseif (is_array($sort)) {
        $sortArray = $sort;
    }
    
    // Ejecutar consulta con DB::grid() - usa FETCH_NUM por defecto (máximo rendimiento)
    // Si se pasara $class='StdClass' usaría FETCH_OBJ, o una clase específica usaría FETCH_CLASS
    $response = DB::grid(
        table: 'users',
        conditions: $conditions,
        page: $parsedPage,  // Offset o [offset, limit]
        sort: $sortArray
        // $class = null por defecto → PDO::FETCH_NUM
    );
    
    // Usar RESTAdapter para transformar la respuesta
    // El adapter recibe QueryResponse con datos FETCH_NUM y retorna formato compacto
    $adapter = new RESTAdapter(
        response: $response,
        searchableColumns: ['name', 'email'], // Columnas para búsqueda global
        schemaMap: $schemaMap,  // Pasar schema map para obtener definición de columnas
        tableName: 'users'      // Nombre de la tabla consultada
    );
    
    // Procesar parámetros y generar respuesta REST en formato compacto
    $result = $adapter->handle($_GET);
    
    // Retornar JSON
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal Server Error',
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
