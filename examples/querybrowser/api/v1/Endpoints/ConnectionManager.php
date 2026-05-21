<?php

namespace RapidBase\Endpoints;

use RapidBase\Api\BaseEndpoint;
use RapidBase\Core\X;
use RapidBase\Core\Conn;
use RapidBase\Core\DB;
use RapidBase\Core\Gateway;

class ConnectionManager extends BaseEndpoint
{
    private static bool $internalDbReady = false;

    private function ensureInternalDb(): void
    {
        if (self::$internalDbReady) return;

        // Ruta al archivo real de conexiones
        if (defined('CONNECTIONS_DB')) {
            $dbFile = CONNECTIONS_DB;
        } else {
            $dbFile = __DIR__ . '/../../../data/connections.sqlite';
        }

        $dir = dirname($dbFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

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
            $result = X::con('internal')->from('connections')->select();
            $connections = array_map(function ($row) {
                return [
                    'id'          => $row[0],
                    'name'        => $row[1],
                    'driver'      => $row[2],
                    'host'        => $row[3],
                    'port'        => $row[4],
                    'database'    => $row[5],
                    'username'    => $row[6],
                    'password'    => $row[7],
                    'description' => $row[8],
                    'environment' => $row[9],
                    'status'      => $row[10],
                ];
            }, $result->data);
            return [
                'success'     => true,
                'count'       => count($connections),
                'connections' => $connections,
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Endpoint para probar credenciales en caliente ANTES de guardarlas 
     * Invocado por ConnectionDialog.js -> api.php?action=test_connection
     */
    public function test(): array
    {
        $params = $this->context->params;
        $driver = $params['driver'] ?? 'mysql';
        $host   = $params['host'] ?? 'localhost';
        $port   = $params['port'] ?? null;
        $dbName = $params['database'] ?? '';
        $user   = $params['username'] ?? '';
        $pass   = $params['password'] ?? '';

        try {
            $options = [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => 4
            ];

            $dsn = match ($driver) {
                'sqlite' => "sqlite:{$dbName}",
                'mysql', 'mariadb' => "mysql:host={$host}" . ($port ? ";port={$port}" : "") . ";dbname={$dbName};charset=utf8mb4",
                'pgsql'  => "pgsql:host={$host}" . ($port ? ";port={$port}" : "") . ";dbname={$dbName}",
                'sqlsrv' => "sqlsrv:Server={$host}" . ($port ? ",{$port}" : "") . ";Database={$dbName};Encrypt=0;TrustServerCertificate=1",
                default  => throw new \Exception("Controlador '{$driver}' no soportado."),
            };

            if ($driver === 'sqlsrv') {
                $options[\PDO::SQLSRV_ATTR_ENCODING] = \PDO::SQLSRV_ENCODING_UTF8;
            }

            $start = microtime(true);
            $testPdo = new \PDO($dsn, $user, $pass, $options);
            $latency = round((microtime(true) - $start) * 1000, 2);

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
        $params = $this->context->params;
        try {
            $id = X::con('internal')->from('connections')->insert([
                'name'        => $params['name'] ?? 'Unnamed',
                'driver'      => $params['driver'] ?? 'sqlite',
                'host'        => $params['host'] ?? null,
                'port'        => $params['port'] ?? null,
                'database'    => $params['database'] ?? '',
                'username'    => $params['username'] ?? null,
                'password'    => $params['password'] ?? null,
                'description' => $params['description'] ?? null,
                'environment' => $params['environment'] ?? 'development',
                'status'      => $params['status'] ?? 'active',
            ]);
            return [
                'success'    => true,
                'id'         => $id,
                'connection' => [
                    'id'          => $id,
                    'name'        => $params['name'] ?? 'Unnamed',
                    'driver'      => $params['driver'] ?? 'sqlite',
                    'host'        => $params['host'] ?? null,
                    'port'        => $params['port'] ?? null,
                    'database'    => $params['database'] ?? '',
                    'username'    => $params['username'] ?? null,
                    'password'    => $params['password'] ?? null,
                    'description' => $params['description'] ?? null,
                    'environment' => $params['environment'] ?? 'development',
                    'status'      => $params['status'] ?? 'active',
                ]
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
            X::con('internal')->from('connections', ['id' => $id])->delete();
            return ['success' => true, 'deleted_id' => $id];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function ping(): array
    {
        $params = $this->context->params;
        $rawId = $params['connectionId'] ?? $params['id'] ?? null;
        if (!$rawId) return ['success' => false, 'error' => 'Missing connectionId'];

        // Quitar el prefijo "saved_" si está presente
        $id = (int) str_replace('saved_', '', $rawId);

        $this->ensureInternalDb();
        $connRow = X::con('internal')->from('connections', ['id' => $id])->first();
        if (!$connRow) return ['success' => false, 'error' => 'Connection not found'];

        $driver = $connRow['driver'];
        $dsn = match ($driver) {
            'sqlite' => "sqlite:{$connRow['database']}",
            'mysql', 'mariadb' => "mysql:host={$connRow['host']};port=" . ($connRow['port'] ?? 3306) . ";dbname={$connRow['database']};charset=utf8mb4",
            'pgsql'  => "pgsql:host={$connRow['host']};port=" . ($connRow['port'] ?? 5432) . ";dbname={$connRow['database']}",
            'sqlsrv' => "sqlsrv:Server={$connRow['host']}" .
                        ($connRow['port'] ? ",{$connRow['port']}" : "") .
                        ";Database={$connRow['database']};Encrypt=0;TrustServerCertificate=1",
            default  => throw new \Exception("Unsupported driver: " . ($driver ?? 'unknown')), // CORREGIDO: Bug de variable indefinida resuelto
        };

        // Activar si no está en el pool
        $connectionKey = "saved_{$id}";
        if (!in_array($connectionKey, Conn::listConnectionIds())) {
            DB::setup($dsn, $connRow['username'] ?? '', $connRow['password'] ?? '', $connectionKey);
        }

        $result = X::con($connectionKey)->ping();

        return [
            'success'        => $result['success'],
            'latency'        => $result['latency'] ?? 0,
            'error'          => $result['error'] ?? null,
            'database_name'  => $connRow['database'] ?? '',
            'host'           => $connRow['host'] ?? null,
            'port'           => $connRow['port'] ?? null,
            'driver'         => $driver,
        ];
    }

    public function activate(): array
    {
        $params = $this->context->params;
        $id = $params['connectionId'] ?? $params['id'] ?? null;
        if (!$id) return ['success' => false, 'error' => 'Missing connectionId'];

        $this->ensureInternalDb();
        $connRow = X::con('internal')->from('connections', ['id' => $id])->first();
        if (!$connRow) return ['success' => false, 'error' => 'Connection not found'];

        $driver = $connRow['driver'];
        $connectionKey = "saved_{$id}";
        
        $dsn = match ($driver) {
            'sqlite' => "sqlite:{$connRow['database']}",
            'mysql', 'mariadb' => "mysql:host={$connRow['host']};port=" . ($connRow['port'] ?? 3306) . ";dbname={$connRow['database']};charset=utf8mb4",
            'pgsql'  => "pgsql:host={$connRow['host']};port=" . ($connRow['port'] ?? 5432) . ";dbname={$connRow['database']}",
            'sqlsrv' => "sqlsrv:Server={$connRow['host']}" .
                        ($connRow['port'] ? ",{$connRow['port']}" : "") .
                        ";Database={$connRow['database']};Encrypt=0;TrustServerCertificate=1",
            default  => throw new \Exception("Unsupported driver: " . ($driver ?? 'unknown')),
        };

        DB::setup($dsn, $connRow['username'] ?? '', $connRow['password'] ?? '', $connectionKey);

        return [
            'success'      => true,
            'connectionId' => $connectionKey,
            'message'      => 'Connection activated',
        ];
    }
}