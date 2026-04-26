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

// Configuración SQLite
$dbPath = __DIR__ . '/../crud/users/database.sqlite';

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
    // Definir columnas de la tabla users
    $columns = ['id', 'name', 'email', 'role', 'created_at'];
    
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
    
    // Parsear página: soporta "offset" o "offset,limit"
    $parsedPage = 0;
    if (is_string($pageParam) && strpos($pageParam, ',') !== false) {
        // Formato: "offset,limit" ej: "2,10" → offset=2, limit=10
        $parts = explode(',', $pageParam);
        $offset = (int)($parts[0] ?? 0);
        $limit = (int)($parts[1] ?? 10);
        // DB::grid espera [offset, limit] cuando se usa paginación custom
        $parsedPage = [$offset, $limit];
    } elseif (is_numeric($pageParam)) {
        // Número de página (1-based desde UI) → convertir a offset
        $pageNum = (int)$pageParam;
        // Página 1 = offset 0, Página 2 = offset 10, etc.
        $offset = ($pageNum - 1) * 10; // Default 10 items por página
        $parsedPage = $offset > 0 ? $offset : 0;
    }
    
    // Ejecutar consulta con DB::grid() - usa FETCH_NUM por defecto (máximo rendimiento)
    // Si se pasara $class='StdClass' usaría FETCH_OBJ, o una clase específica usaría FETCH_CLASS
    $response = DB::grid(
        table: 'users',
        conditions: $conditions,
        page: $parsedPage,  // Offset o [offset, limit]
        sort: $sort
        // $class = null por defecto → PDO::FETCH_NUM
    );
    
    // Usar RESTAdapter para transformar la respuesta
    // El adapter recibe QueryResponse con datos FETCH_NUM y retorna formato compacto
    $adapter = new RESTAdapter(
        response: $response,
        searchableColumns: ['name', 'email'] // Columnas para búsqueda global
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
