<?php

namespace RapidBase\Endpoints;

use RapidBase\Api\BaseEndpoint;
use RapidBase\Core\Conn;
use RapidBase\Core\DB;
use RapidBase\Models\Connection;

/**
 * ConnectionManager - Manages database connections using RapidBase ActiveRecord.
 * 
 * This endpoint demonstrates CRUD operations on the connections table
 * using the Connection model which extends RapidBase ORM.
 */
class ConnectionManager extends BaseEndpoint
{
    /**
     * List all registered connections.
     * 
     * @return array List of connections
     */
    public function list(): array
    {
        try {
            $connections = Connection::all();
            
            return [
                'success' => true,
                'count' => count($connections),
                'connections' => $connections
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Create a new connection record.
     * Expects: name, driver, host, port, database, username, password, description, environment, status
     * 
     * @return array Created connection with ID
     */
    public function create(): array
    {
        $params = $this->context->params;
        
        try {
            $connection = new Connection();
            $connection->name = $params['name'] ?? 'Unnamed';
            $connection->driver = $params['driver'] ?? 'sqlite';
            $connection->host = $params['host'] ?? 'localhost';
            $connection->port = $params['port'] ?? null;
            $connection->database = $params['database'] ?? $params['db'] ?? '';
            $connection->username = $params['username'] ?? $params['user'] ?? '';
            $connection->password = $params['password'] ?? $params['pass'] ?? '';
            $connection->description = $params['description'] ?? '';
            $connection->environment = $params['environment'] ?? 'dev';
            $connection->status = $params['status'] ?? 'active';
            
            $saved = $connection->save();
            
            if (!$saved) {
                return [
                    'success' => false,
                    'error' => 'Failed to save connection'
                ];
            }
            
            return [
                'success' => true,
                'id' => (int) $connection->id,
                'connection' => $connection->toArray()
            ];
            
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Update an existing connection.
     * 
     * @return array Updated connection
     */
    public function update(): array
    {
        $params = $this->context->params;
        $id = $params['id'] ?? $params['connection_id'] ?? null;
        
        if (!$id) {
            return [
                'success' => false,
                'error' => 'Missing connection ID'
            ];
        }
        
        try {
            $connection = Connection::find($id);
            
            if (!$connection) {
                return [
                    'success' => false,
                    'error' => "Connection $id not found"
                ];
            }
            
            // Update fields if provided
            if (isset($params['name'])) $connection->name = $params['name'];
            if (isset($params['driver'])) $connection->driver = $params['driver'];
            if (isset($params['host'])) $connection->host = $params['host'];
            if (isset($params['port'])) $connection->port = $params['port'];
            if (isset($params['database'])) $connection->database = $params['database'];
            if (isset($params['username'])) $connection->username = $params['username'];
            if (isset($params['password'])) $connection->password = $params['password'];
            if (isset($params['description'])) $connection->description = $params['description'];
            if (isset($params['environment'])) $connection->environment = $params['environment'];
            if (isset($params['status'])) $connection->status = $params['status'];
            
            $saved = $connection->save();
            
            return [
                'success' => $saved,
                'connection' => $connection->toArray()
            ];
            
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Delete a connection.
     * 
     * @return array Deletion result
     */
    public function delete(): array
    {
        $params = $this->context->params;
        $id = $params['id'] ?? $params['connection_id'] ?? null;
        
        if (!$id) {
            return [
                'success' => false,
                'error' => 'Missing connection ID'
            ];
        }
        
        try {
            $connection = Connection::find($id);
            
            if (!$connection) {
                return [
                    'success' => false,
                    'error' => "Connection $id not found"
                ];
            }
            
            $deleted = $connection->delete();
            
            return [
                'success' => $deleted,
                'deleted_id' => $id
            ];
            
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Test a connection by attempting to connect.
     * 
     * @return array Connection test result with latency
     */
    public function test(): array
    {
        $params = $this->context->params;
        $id = $params['id'] ?? $params['connection_id'] ?? null;
        
        if (!$id) {
            return [
                'success' => false,
                'error' => 'Missing connection ID'
            ];
        }
        
        try {
            $connection = Connection::find($id);
            
            if (!$connection) {
                return [
                    'success' => false,
                    'error' => "Connection $id not found"
                ];
            }
            
            // Build DSN from connection details
            $dsn = $connection->buildDsn();
            
            // Test connection using RapidBase Conn
            $start = microtime(true);
            Conn::add('test_temp', $dsn, $connection->username, $connection->password);
            $pdo = Conn::get('test_temp');
            
            // Simple ping query
            $pdo->query('SELECT 1');
            $latency = round((microtime(true) - $start) * 1000, 2);
            
            // Clean up temp connection
            Conn::remove('test_temp');
            
            return [
                'success' => true,
                'connected' => true,
                'latency_ms' => $latency,
                'driver' => $connection->driver,
                'database' => $connection->database
            ];
            
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'connected' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Activate a connection in the Conn pool.
     * 
     * @return array Activation result
     */
    public function activate(): array
    {
        $params = $this->context->params;
        $id = $params['id'] ?? $params['connection_id'] ?? null;
        
        if (!$id) {
            return [
                'success' => false,
                'error' => 'Missing connection ID'
            ];
        }
        
        try {
            $connection = Connection::find($id);
            
            if (!$connection) {
                return [
                    'success' => false,
                    'error' => "Connection $id not found"
                ];
            }
            
            $dsn = $connection->buildDsn();
            Conn::add($connection->id, $dsn, $connection->username, $connection->password);
            Conn::select($connection->id);
            
            return [
                'success' => true,
                'message' => "Connection {$connection->id} activated",
                'active' => Conn::getCurrentConnectionId()
            ];
            
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
