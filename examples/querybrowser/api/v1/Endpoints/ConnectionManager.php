<?php

namespace RapidBase\Endpoints;

use RapidBase\Api\BaseEndpoint;
use RapidBase\Core\X;
use RapidBase\Core\Conn;
use RapidBase\Core\DB;

// Carga manual del modelo (está fuera del bundle compilado)
require_once __DIR__ . '/../Models/Connection.php';

use RapidBase\Models\Connection;

class ConnectionManager extends BaseEndpoint
{
    private static bool $internalDbReady = false;

    /**
     * Reset the internal state for testing purposes.
     * This allows each test to start with a clean slate.
     */
    public static function resetInstance(): void
    {
        self::$internalDbReady = false;
    }

    private function ensureInternalDb(): void
    {
        if (self::$internalDbReady) return;

        // Permitir override mediante variable de entorno para tests
        $dbFile = getenv('CONNECTIONS_DB_OVERRIDE') ?: (defined('CONNECTIONS_DB') ? CONNECTIONS_DB : __DIR__ . '/../../../data/connections.sqlite');
        $dir = dirname($dbFile);
        if (!is_dir($dir)) mkdir($dir, 0777, true);

        if (!file_exists($dbFile)) {
            $pdo = new \PDO("sqlite:$dbFile");
            $pdo->exec("CREATE TABLE connections (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                driver TEXT NOT NULL,
                host TEXT,
                port INTEGER,
                database TEXT,
                username TEXT,
                password TEXT,
                description TEXT,
                environment TEXT DEFAULT 'development',
                status TEXT DEFAULT 'active',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
        }

        DB::setup("sqlite:$dbFile", '', '', 'internal');
        self::$internalDbReady = true;
    }

    public function list(): array
    {
        $this->ensureInternalDb();
        try {
            $connections = Connection::all();
            $result = array_map(fn($c) => $c->toSafeArray(), $connections);
            return [
                'success'     => true,
                'count'       => count($result),
                'connections' => $result,
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function test(): array
    {
        $params = $this->context->params;

        try {
            $temp = new Connection();
            $temp->fill($params);
            $dsn = $temp->buildDsn();

            $start = microtime(true);
            DB::setup($dsn, $params['username'] ?? '', $params['password'] ?? '', 'test_temp');
            $latency = round((microtime(true) - $start) * 1000, 2);
            Conn::close('test_temp');

            return [
                'success' => true,
                'message' => 'Conexión exitosa',
                'latency' => "{$latency}ms"
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error'   => $e->getMessage()
            ];
        }
    }

    public function create(): array
    {
        $this->ensureInternalDb();
        $rawParams = $this->context->params;

        // Lista blanca de campos permitidos
        $allowed = ['name', 'driver', 'host', 'port', 'database', 'username', 'password', 'description', 'environment', 'status'];
        $params = array_intersect_key($rawParams, array_flip($allowed));

        try {
            $id = Connection::create($params);
            if (!$id || (is_int($id) && $id <= 0)) {
                return ['success' => false, 'error' => 'No se pudo crear la conexión'];
            }

            // Intentar leer el registro recién creado
            $conn = Connection::read($id);
            if (!$conn) {
                // Fallback: construir el objeto manualmente con el ID devuelto
                $conn = new Connection(array_merge($params, ['id' => $id]));
            }

            return [
                'success'    => true,
                'id'         => $id,
                'connection' => $conn->toSafeArray()
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function delete(): array
    {
        $this->ensureInternalDb();
        $id = $this->context->params['id'] ?? null;
        if (!$id) return ['success' => false, 'error' => 'Missing id'];
        try {
            Connection::delete($id);
            return ['success' => true, 'deleted_id' => $id];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function update(): array
    {
        $this->ensureInternalDb();
        $rawParams = $this->context->params;
        $id = $rawParams['id'] ?? null;
        if (!$id) return ['success' => false, 'error' => 'Missing id'];

        // Lista blanca de campos permitidos
        $allowed = ['name', 'driver', 'host', 'port', 'database', 'username', 'password', 'description', 'environment', 'status'];
        $params = array_intersect_key($rawParams, array_flip($allowed));

        try {
            $updated = Connection::update($id, $params);
            if (!$updated) {
                return ['success' => false, 'error' => 'No se pudo actualizar la conexión'];
            }

            $conn = Connection::read($id);
            return [
                'success'    => true,
                'connection' => $conn ? $conn->toSafeArray() : null
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }



    public function ping(): array
    {
        $params = $this->context->params;
        $rawId = $params['connectionId'] ?? $params['id'] ?? null;
        if (!$rawId) return ['success' => false, 'error' => 'Missing connectionId'];

        $this->ensureInternalDb();

        // Soportar tanto IDs numéricos como nombres de conexión
        $conn = is_numeric($rawId) ? Connection::read((int)$rawId) : Connection::findByName($rawId);

        // Si el modelo falla, obtener los datos directamente desde la BD interna
        if (!$conn) {
            $row = is_numeric($rawId)
                ? X::con('internal')->from('connections', ['id' => (int)$rawId])->first()
                : X::con('internal')->from('connections', ['name' => $rawId])->first();
            if ($row) {
                $conn = new Connection();
                $conn->fill($row);
            }
        }

        if (!$conn) return ['success' => false, 'error' => 'Connection not found'];

        // Usar el nombre normalizado como connectionKey
        $connectionKey = $this->normalizeConnectionName($conn->name);
        if (!in_array($connectionKey, Conn::listConnectionIds())) {
            DB::setup($conn->buildDsn(), $conn->username ?? '', $conn->password ?? '', $connectionKey);
        }

        $result = X::con($connectionKey)->ping();

        return [
            'success'        => $result['success'],
            'latency'        => $result['latency'] ?? 0,
            'error'          => $result['error'] ?? null,
            'database_name'  => $conn->database,
            'host'           => $conn->host,
            'port'           => $conn->port,
            'driver'         => $conn->driver,
        ];
    }




    public function activate(): array
    {
        $params = $this->context->params;
        $rawId = $params['connectionId'] ?? $params['id'] ?? null;
        if (!$rawId) return ['success' => false, 'error' => 'Missing connectionId'];

        $this->ensureInternalDb();
        
        // Soportar tanto IDs numéricos como nombres de conexión
        $conn = is_numeric($rawId) ? Connection::read((int)$rawId) : Connection::findByName($rawId);
        if (!$conn) return ['success' => false, 'error' => 'Connection not found'];

        // Usar el nombre normalizado como connectionKey
        $connectionKey = $this->normalizeConnectionName($conn->name);
        DB::setup($conn->buildDsn(), $conn->username ?? '', $conn->password ?? '', $connectionKey);

        return [
            'success'      => true,
            'connectionId' => $connectionKey,
            'message'      => 'Connection activated',
        ];
    }

    /**
     * Normalize connection name to be used as connection key.
     * Replaces spaces and special characters with underscores.
     * Ensures the key is safe for use in session arrays and connection IDs.
     */
    private function normalizeConnectionName(string $name): string
    {
        // Convertir a lowercase y reemplazar espacios y caracteres especiales con underscores
        $normalized = strtolower(trim($name));
        $normalized = preg_replace('/[^a-z0-9_\-\s]/', '_', $normalized);
        $normalized = preg_replace('/[\s_]+/', '_', $normalized); // Evitar múltiples underscores seguidos
        return $normalized;
    }
}
