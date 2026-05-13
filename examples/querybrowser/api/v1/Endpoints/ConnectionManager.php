<?php

namespace RapidBase\Endpoints;

use RapidBase\Api\BaseEndpoint;
use RapidBase\Core\Conn;

/**
 * ConnectionManager - Manages database connections.
 * 
 * This endpoint handles listing, testing and activating connections
 * using the internal Conn pool and configuration.
 */
class ConnectionManager extends BaseEndpoint
{
    private function getConnectionsFile(): string
    {
        return __DIR__ . '/../../data/connections.json';
    }

    private function loadConnections(): array
    {
        $file = $this->getConnectionsFile();
        if (!file_exists($file)) {
            return [];
        }
        $content = file_get_contents($file);
        return json_decode($content, true) ?: [];
    }

    private function saveConnections(array $connections): bool
    {
        $file = $this->getConnectionsFile();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return file_put_contents($file, json_encode($connections, JSON_PRETTY_PRINT)) !== false;
    }

    /**
     * List all registered connections.
     * 
     * @return array List of connections
     */
    public function list(): array
    {
        try {
            $connections = $this->loadConnections();
            
            return [
                'success' => true,
                'count' => count($connections),
                'connections' => array_values($connections)
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
            $connections = $this->loadConnections();
            
            // Generate new ID
            $newId = count($connections) > 0 ? max(array_keys($connections)) + 1 : 1;
            
            $connection = [
                'id' => $newId,
                'name' => $params['name'] ?? 'Unnamed',
                'driver' => $params['driver'] ?? 'sqlite',
                'host' => $params['host'] ?? 'localhost',
                'port' => $params['port'] ?? null,
                'database' => $params['database'] ?? $params['db'] ?? '',
                'username' => $params['username'] ?? $params['user'] ?? '',
                'password' => $params['password'] ?? $params['pass'] ?? '',
                'description' => $params['description'] ?? '',
                'environment' => $params['environment'] ?? 'dev',
                'status' => $params['status'] ?? 'active'
            ];
            
            $connections[$newId] = $connection;
            $saved = $this->saveConnections($connections);
            
            if (!$saved) {
                return [
                    'success' => false,
                    'error' => 'Failed to save connection'
                ];
            }
            
            return [
                'success' => true,
                'id' => $newId,
                'connection' => $connection
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
            $connections = $this->loadConnections();
            
            if (!isset($connections[$id])) {
                return [
                    'success' => false,
                    'error' => "Connection $id not found"
                ];
            }
            
            // Update fields if provided
            $conn = &$connections[$id];
            if (isset($params['name'])) $conn['name'] = $params['name'];
            if (isset($params['driver'])) $conn['driver'] = $params['driver'];
            if (isset($params['host'])) $conn['host'] = $params['host'];
            if (isset($params['port'])) $conn['port'] = $params['port'];
            if (isset($params['database'])) $conn['database'] = $params['database'];
            if (isset($params['username'])) $conn['username'] = $params['username'];
            if (isset($params['password'])) $conn['password'] = $params['password'];
            if (isset($params['description'])) $conn['description'] = $params['description'];
            if (isset($params['environment'])) $conn['environment'] = $params['environment'];
            if (isset($params['status'])) $conn['status'] = $params['status'];
            
            $saved = $this->saveConnections($connections);
            
            return [
                'success' => $saved,
                'connection' => $conn
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
            $connections = $this->loadConnections();
            
            if (!isset($connections[$id])) {
                return [
                    'success' => false,
                    'error' => "Connection $id not found"
                ];
            }
            
            unset($connections[$id]);
            $deleted = $this->saveConnections($connections);
            
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
            $connections = $this->loadConnections();
            
            if (!isset($connections[$id])) {
                return [
                    'success' => false,
                    'error' => "Connection $id not found"
                ];
            }
            
            $conn = $connections[$id];
            
            // Build DSN from connection details
            $driver = $conn['driver'] ?? 'sqlite';
            $host = $conn['host'] ?? 'localhost';
            $port = $conn['port'] ?? '';
            $database = $conn['database'] ?? '';
            $username = $conn['username'] ?? '';
            $password = $conn['password'] ?? '';
            
            $dsn = "$driver:";
            if ($driver === 'sqlite') {
                $dsn .= $database;
            } else {
                $dsn .= "host=$host;";
                if ($port) $dsn .= "port=$port;";
                $dsn .= "dbname=$database";
            }
            
            // Test connection using RapidBase Conn
            $start = microtime(true);
            Conn::add('test_temp', $dsn, $username, $password);
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
                'driver' => $driver,
                'database' => $database
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
            $connections = $this->loadConnections();
            
            if (!isset($connections[$id])) {
                return [
                    'success' => false,
                    'error' => "Connection $id not found"
                ];
            }
            
            $conn = $connections[$id];
            
            $driver = $conn['driver'] ?? 'sqlite';
            $host = $conn['host'] ?? 'localhost';
            $port = $conn['port'] ?? '';
            $database = $conn['database'] ?? '';
            $username = $conn['username'] ?? '';
            $password = $conn['password'] ?? '';
            
            $dsn = "$driver:";
            if ($driver === 'sqlite') {
                $dsn .= $database;
            } else {
                $dsn .= "host=$host;";
                if ($port) $dsn .= "port=$port;";
                $dsn .= "dbname=$database";
            }
            
            Conn::add($id, $dsn, $username, $password);
            Conn::select($id);
            
            return [
                'success' => true,
                'message' => "Connection $id activated",
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
