<?php

namespace RapidBase\Endpoints;

use RapidBase\Api\BaseEndpoint;
use RapidBase\Core\X;
use RapidBase\Core\Conn;
use RapidBase\Core\DB;

class QueryExecutor extends BaseEndpoint
{
    public function execute(string $sql): array
    {
        $connId = $this->context->params['connectionId']
                  ?? $this->context->params['connection_id']
                  ?? 'main';

        if (!in_array($connId, Conn::listConnectionIds())) {
            // Intentar buscar por nombre de conexión primero
            $dbFile = defined('CONNECTIONS_DB') ? CONNECTIONS_DB : __DIR__ . '/../../../data/connections.sqlite';
            if (file_exists($dbFile)) {
                DB::setup("sqlite:$dbFile", '', '', 'internal');
                
                // Buscar primero por nombre (connId como nombre)
                $connRow = X::con('internal')->from('connections', ['name' => $connId])->first();
                
                // Si no encuentra por nombre, intentar por ID numérico
                if (!$connRow && is_numeric($connId)) {
                    $connRow = X::con('internal')->from('connections', ['id' => (int)$connId])->first();
                }
                
                if ($connRow) {
                    $driver = $connRow['driver'];
                    $dsn = match ($driver) {
                        'sqlite' => "sqlite:{$connRow['database']}",
                        'mysql'  => "mysql:host={$connRow['host']};port=" . ($connRow['port'] ?? 3306) . ";dbname={$connRow['database']};charset=utf8mb4",
                        'pgsql'  => "pgsql:host={$connRow['host']};port=" . ($connRow['port'] ?? 5432) . ";dbname={$connRow['database']}",
                        default  => throw new \Exception("Unsupported driver: $driver"),
                    };
                    // Usar el nombre normalizado como connectionKey
                    $connectionKey = $this->normalizeConnectionName($connRow['name']);
                    DB::setup($dsn, $connRow['username'] ?? '', $connRow['password'] ?? '', $connectionKey);
                    $connId = $connectionKey;
                } else {
                    return ['success' => false, 'error' => "Connection not found in database"];
                }
            } else {
                return ['success' => false, 'error' => "Connection '$connId' not available."];
            }
        }

        Conn::select($connId);

        try {
            $xr = X::con($connId)->raw($sql);
            $isSelect = !empty($xr->data) || stripos(trim($sql), 'SELECT') === 0;

            return [
                'success'      => true,
                'type'         => $isSelect ? 'SELECT' : 'ACTION',
                'data'         => $xr->data ?? [],
                'columns'      => $xr->columns ?? [],
                'titles'       => $xr->titles ?? [],
                'total'        => $xr->total ?? count($xr->data ?? []),
                'affected_rows'=> $xr->affected ?? 0,
                'last_insert_id'=> $xr->lastId ?? null,
                'durationMs'   => $xr->durationMs ?? 0,
                'sql'          => $xr->sql ?? $sql,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error'   => $e->getMessage(),
                'sql'     => $sql,
            ];
        }
    }

    /**
     * Normalize connection name to be used as connection key.
     */
    private function normalizeConnectionName(string $name): string
    {
        $normalized = strtolower(trim($name));
        $normalized = preg_replace('/[^a-z0-9_\-]/', '_', $normalized);
        $normalized = preg_replace('/_+/', '_', $normalized);
        return 'conn_' . $normalized;
    }
}