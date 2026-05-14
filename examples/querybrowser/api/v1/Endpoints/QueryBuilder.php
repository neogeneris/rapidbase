<?php

namespace RapidBase\Endpoints;

use RapidBase\Api\BaseEndpoint;
use RapidBase\Core\X;
use RapidBase\Core\SQL\Q;
use RapidBase\Core\Conn;
use RapidBase\Core\DB;

class QueryBuilder extends BaseEndpoint
{
    /**
     * Generates a SELECT SQL statement for the given tables, optionally
     * including only the specified columns.
     */
    public function autoQuery(string $tables): array
    {
        $connId = $this->context->params['connectionId']
                  ?? $this->context->params['connection_id']
                  ?? 'main';

        // Auto‑activar conexión si no está en el pool
        if (!in_array($connId, Conn::listConnectionIds())) {
            $id = (int) str_replace('saved_', '', $connId);
            if ($id > 0) {
                $dbFile = defined('CONNECTIONS_DB') ? CONNECTIONS_DB : __DIR__ . '/../../../data/connections.sqlite';
                if (file_exists($dbFile)) {
                    DB::setup("sqlite:$dbFile", '', '', 'internal');
                    $connRow = X::con('internal')->from('connections', ['id' => $id])->first();
                    if ($connRow) {
                        $driver = $connRow['driver'];
                        $dsn = match ($driver) {
                            'sqlite' => "sqlite:{$connRow['database']}",
                            'mysql'  => "mysql:host={$connRow['host']};port=" . ($connRow['port'] ?? 3306) . ";dbname={$connRow['database']};charset=utf8mb4",
                            'pgsql'  => "pgsql:host={$connRow['host']};port=" . ($connRow['port'] ?? 5432) . ";dbname={$connRow['database']}",
                            default  => throw new \Exception("Unsupported driver: $driver"),
                        };
                        DB::setup($dsn, $connRow['username'] ?? '', $connRow['password'] ?? '', $connId);
                    } else {
                        return ['success' => false, 'error' => 'Connection not found in database'];
                    }
                } else {
                    return ['success' => false, 'error' => "Connection '$connId' not available."];
                }
            } else {
                return ['success' => false, 'error' => "Connection '$connId' not available."];
            }
        }

        Conn::select($connId);

        try {
            $tableList = json_decode($tables, true);
            if (!is_array($tableList) || empty($tableList)) {
                return ['success' => false, 'error' => 'Invalid tables list'];
            }

            // Columnas opcionales
            $columns = null;
            if (isset($this->context->params['columns'])) {
                $columns = json_decode($this->context->params['columns'], true);
                if (!is_array($columns)) $columns = null;
            }

            $compiled = Q::from($tableList)->select($columns ?? '*');
            $sql = X::con($connId)->toSQL($compiled);

            return [
                'success' => true,
                'sql'     => $sql,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }
    }
}