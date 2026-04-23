<?php
/**
 * RESTful API for Users CRUD
 * Handles: list, create, update, delete, get
 */

require_once 'config.php';

use RapidBase\Core\DB;
use Example\User;

header('Content-Type: application/json');

// Get action from request
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'list':
            // Handle DataTables server-side processing or simple grid
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
            $sort = isset($_GET['sort']) ? $_GET['sort'] : [];
            $where = isset($_GET['where']) ? $_GET['where'] : [];
            
            // Use DB::grid() for efficient paginated listing with FETCH_NUM
            // Firma: grid($table, $conditions, $page, $sort, $perPage)
            $result = DB::grid('users', $where, $page, $sort, $perPage);
            
            // Convertir a formato Grid moderno para máxima compatibilidad
            echo json_encode($result->toGridFormat());
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
            $id = $_POST['id'] ?? $_GET['id'] ?? 0;
            if (!$id) {
                throw new Exception('ID required');
            }
            
            $data = json_decode(file_get_contents('php://input'), true);
            if (!$data) {
                $data = $_POST;
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
            $id = $_POST['id'] ?? $_GET['id'] ?? 0;
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
