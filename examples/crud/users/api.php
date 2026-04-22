<?php
/**
 * RESTful API for Users CRUD
 * Handles: list, create, update, delete, get
 */

require_once 'config.php';

use Core\DB;
use Example\User;

header('Content-Type: application/json');

// Get action from request
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'list':
            // Handle DataTables server-side processing or simple grid
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 0;
            $perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
            $sort = isset($_GET['sort']) ? $_GET['sort'] : [];
            $where = isset($_GET['where']) ? $_GET['where'] : [];
            
            // Use DB::grid() for efficient paginated listing with FETCH_NUM
            $result = DB::grid('users', $where, $page, $sort, $perPage);
            
            echo json_encode([
                'success' => true,
                'data' => $result->getData(), // Returns array of arrays (FETCH_NUM)
                'meta' => $result->getPagination()
            ]);
            break;
            
        case 'get':
            $id = $_GET['id'] ?? 0;
            if (!$id) {
                throw new Exception('ID required');
            }
            
            $user = new User($id);
            if (!$user->exists()) {
                throw new Exception('User not found');
            }
            
            echo json_encode([
                'success' => true,
                'data' => $user->toArray()
            ]);
            break;
            
        case 'create':
            $data = json_decode(file_get_contents('php://input'), true);
            if (!$data) {
                $data = $_POST;
            }
            
            $user = new User();
            $user->name = $data['name'] ?? '';
            $user->email = $data['email'] ?? '';
            $user->role = $data['role'] ?? 'user';
            $user->save();
            
            echo json_encode([
                'success' => true,
                'data' => $user->toArray(),
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
            
            $user = new User($id);
            if (!$user->exists()) {
                throw new Exception('User not found');
            }
            
            if (isset($data['name'])) $user->name = $data['name'];
            if (isset($data['email'])) $user->email = $data['email'];
            if (isset($data['role'])) $user->role = $data['role'];
            
            $user->save();
            
            echo json_encode([
                'success' => true,
                'data' => $user->toArray(),
                'message' => 'User updated successfully'
            ]);
            break;
            
        case 'delete':
            $id = $_POST['id'] ?? $_GET['id'] ?? 0;
            if (!$id) {
                throw new Exception('ID required');
            }
            
            $user = new User($id);
            if (!$user->exists()) {
                throw new Exception('User not found');
            }
            
            $user->delete();
            
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
