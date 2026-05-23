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
     * including only the specified columns, sorting and pagination.
     *
     * Expected parameters (in context):
     *   connectionId   string   Connection key (normalized name, e.g. conn_mydb)
     *   tables         string   JSON array of table names
     *   columns        string   (optional) JSON array of qualified columns
     *   sort           string   (optional) JSON array of sort objects or compact string
     *   page           int      (optional) Page number (default 1)
     *   limit          int      (optional) Page size (default 50)
     *
     * @param string $tables  JSON‑encoded array of table names
     * @return array          ['success' => true, 'sql' => $sql] or error
     */
    public function autoQuery(string $tables): array
    {
        // ── Resolve connection ID ────────────────────────────────────────
        $connId = $this->context->params['connectionId']
                  ?? $this->context->params['connection_id']
                  ?? 'main';

        // ── Auto‑activate connection if not already in the pool ───────────
        if (!in_array($connId, Conn::listConnectionIds())) {
            $dbFile = defined('CONNECTIONS_DB')
                ? CONNECTIONS_DB
                : __DIR__ . '/../../../data/connections.sqlite';

            if (file_exists($dbFile)) {
                DB::setup("sqlite:$dbFile", '', '', 'internal');
                
                // Buscar primero por nombre
                $connRow = X::con('internal')
                    ->from('connections', ['name' => $connId])
                    ->first();
                
                // Si no encuentra por nombre, intentar por ID numérico
                if (!$connRow && is_numeric($connId)) {
                    $connRow = X::con('internal')
                        ->from('connections', ['id' => (int)$connId])
                        ->first();
                }

                if ($connRow) {
                    $driver = $connRow['driver'];
                    $dsn = match ($driver) {
                        'sqlite' => "sqlite:{$connRow['database']}",
                        'mysql'  => "mysql:host={$connRow['host']};port="
                                    . ($connRow['port'] ?? 3306)
                                    . ";dbname={$connRow['database']};charset=utf8mb4",
                        'pgsql'  => "pgsql:host={$connRow['host']};port="
                                    . ($connRow['port'] ?? 5432)
                                    . ";dbname={$connRow['database']}",
                        default  => throw new \Exception("Unsupported driver: $driver"),
                    };
                    // Usar el nombre normalizado como connectionKey
                    $connectionKey = $this->normalizeConnectionName($connRow['name']);
                    DB::setup(
                        $dsn,
                        $connRow['username'] ?? '',
                        $connRow['password'] ?? '',
                        $connectionKey
                    );
                    $connId = $connectionKey;
                } else {
                    return [
                        'success' => false,
                        'error'   => 'Connection not found in database'
                    ];
                }
            } else {
                return [
                    'success' => false,
                    'error'   => "Connection '$connId' not available."
                ];
            }
        }

        Conn::select($connId);

        // ── Parse and compile the query ───────────────────────────────────
        try {
            $tableList = json_decode($tables, true);
            if (!is_array($tableList) || empty($tableList)) {
                return ['success' => false, 'error' => 'Invalid tables list'];
            }

            // Optional columns (JSON array)
            $columns = null;
            if (isset($this->context->params['columns'])) {
                $columns = json_decode($this->context->params['columns'], true);
                if (!is_array($columns)) {
                    $columns = null;
                }
            }

            // Pagination
            $page  = (int)($this->context->params['page'] ?? 1);
            $limit = (int)($this->context->params['limit'] ?? 50);
            $pagination = Q::page([$page, $limit]);

            // Sort – accept both JSON array and compact string
            $sortParam = $this->context->params['sort'] ?? null;
            if (is_string($sortParam) && str_starts_with(trim($sortParam), '[')) {
                $sortParam = json_decode($sortParam, true);
            }
            $sortArray = Q::sort($sortParam);

            // Build the SELECT
            $compiled = Q::from($tableList)
                ->select($columns ?? '*', $pagination, $sortArray);
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