<?php

namespace RapidBase\Endpoints;

use RapidBase\Api\BaseEndpoint;

/**
 * Ejemplo de endpoint para gestión de usuarios
 */
class UserService extends BaseEndpoint {
    
    /**
     * Obtiene información de un usuario por su ID.
     */
    public function getUser(int $userId) {
        // Simular acceso a base de datos
        return [
            'id' => $userId,
            'name' => 'Usuario ' . $userId,
            'email' => "user{$userId}@example.com",
            'authenticated_user' => $this->context->session['user_id'] ?? null
        ];
    }
    
    /**
     * Crea un nuevo usuario con los datos proporcionados.
     */
    public function createUser(string $name, string $email, int $role = 1) {
        return [
            'success' => true,
            'message' => 'User created',
            'data' => [
                'name' => $name,
                'email' => $email,
                'role' => $role,
                'created_by' => $this->context->session['user_id'] ?? null
            ]
        ];
    }
    
    /**
     * Lista todos los usuarios con paginación opcional.
     */
    public function listUsers(int $page = 1, int $limit = 10) {
        return [
            'page' => $page,
            'limit' => $limit,
            'total' => 100,
            'users' => array_map(fn($i) => [
                'id' => $i,
                'name' => "Usuario {$i}"
            ], range(1, min($limit, 10)))
        ];
    }
}
