<?php
/**
 * RESTful API for Users CRUD
 * Handles: list, create, update, delete, get
 * 
 * Incluye las clases manualmente sin autoloader.
 */

// ========== INCLUDES MANUALES (sin autoloader) ==========
// Orden de dependencias: Conn -> SQL -> Executor -> SchemaMap -> CacheService -> Gateway -> QueryResponse -> DB -> Model -> GridjsAdapter -> User

$srcBase = __DIR__ . '/../../../src/RapidBase';

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
require_once $srcBase . '/ORM/ActiveRecord/Model.php';
require_once $srcBase . '/Infrastructure/UI/Adapters/GridjsAdapter.php';
require_once __DIR__ . '/User.php';

// ========== CONFIGURACIÓN DB ==========
use RapidBase\Core\DB;
use Example\User;

// Configuración SQLite
$dbPath = __DIR__ . '/database.sqlite';

try {
    DB::setup("sqlite:{$dbPath}", '', '', 'main');
    
    // Cargar schema_map si existe
    $schemaFile = __DIR__ . '/schema_map.php';
    if (file_exists($schemaFile)) {
        DB::loadRelationsMap($schemaFile);
    }
} catch (Exception $e) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'DB Connection Error: ' . $e->getMessage()]);
    exit;
}

// ========== API HANDLER ==========
header('Content-Type: application/json');

// Get action from request
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'list':
            // Usar GridjsAdapter::build() para traducir todos los parámetros de entrada
            $params = \RapidBase\Infrastructure\UI\Adapters\GridjsAdapter::build(
                $_GET, 
                ['name', 'email'] // Columnas donde se aplicará la búsqueda
            );
            
            // Ejecutar consulta con la nueva firma optimizada
            $response = DB::grid('users', $params['conditions'], $params['page'], $params['sort']);
            
            // Usar GridjsAdapter::format() para retornar estructura completa con head.columns
            // Esto permite que el frontend lea los nombres reales de columnas desde schema_map.php
            echo json_encode(\RapidBase\Infrastructure\UI\Adapters\GridjsAdapter::format($response));
            break;
            
        case 'get':
            $id = $_GET['id'] ?? 0;
            if (!$id) {
                throw new Exception('ID required');
            }
            
            $user = User::read($id);
            if (!$user) {
                throw new Exception('User not found');
            }
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'created_at' => $user->created_at
                ]
            ]);
            break;
            
        case 'create':
            $data = json_decode(file_get_contents('php://input'), true);
            if (!$data) {
                $data = $_POST;
            }
            
            $userId = User::create([
                'name' => $data['name'] ?? '',
                'email' => $data['email'] ?? '',
                'role' => $data['role'] ?? 'user',
                'created_at' => date('Y-m-d H:i:s')
            ]);
            
            if (!$userId) {
                throw new Exception('Failed to create user');
            }
            
            $user = User::read($userId);
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'created_at' => $user->created_at
                ],
                'message' => 'User created successfully'
            ]);
            break;
            
        case 'update':
            // Leer el body raw ANTES de acceder a $_POST
            $rawBody = file_get_contents('php://input');
            $data = json_decode($rawBody, true);
            if (!$data) {
                // Si no es JSON, parsear como URL-encoded
                parse_str($rawBody, $data);
            }
            // Fallback a $_POST
            if (empty($data)) {
                $data = $_POST;
            }
            
            $id = $data['id'] ?? $_GET['id'] ?? 0;
            if (!$id) {
                throw new Exception('ID required');
            }
            
            $user = User::read($id);
            if (!$user) {
                throw new Exception('User not found');
            }
            
            if (isset($data['name'])) $user->name = $data['name'];
            if (isset($data['email'])) $user->email = $data['email'];
            if (isset($data['role'])) $user->role = $data['role'];
            
            if (!$user->save()) {
                throw new Exception('Failed to update user');
            }
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'created_at' => $user->created_at
                ],
                'message' => 'User updated successfully'
            ]);
            break;
            
        case 'delete':
            // Leer el body raw
            $rawBody = file_get_contents('php://input');
            $data = json_decode($rawBody, true);
            if (!$data) {
                parse_str($rawBody, $data);
            }
            if (empty($data)) {
                $data = $_POST;
            }
            
            $id = $data['id'] ?? $_GET['id'] ?? 0;
            if (!$id) {
                throw new Exception('ID required');
            }
            
            $user = User::read($id);
            if (!$user) {
                throw new Exception('User not found');
            }
            
            if (!User::delete($id)) {
                throw new Exception('Failed to delete user');
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'User deleted successfully'
            ]);
            break;
            
        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
