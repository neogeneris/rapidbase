<?php

namespace RapidBase\Endpoints;

use RapidBase\Api\BaseEndpoint;
use RapidBase\Core\X;
use RapidBase\Core\Conn;
use RapidBase\Core\DB;
use RapidBase\Core\SchemaMap;

class Grid extends BaseEndpoint
{
    public function data(): array
    {
        $connId = $this->context->params['connectionId'] ?? 'main';
        $table  = $this->context->params['table'] ?? '';
        $page   = max(1, (int)($this->context->params['page'] ?? 1));
        $limit  = max(1, min((int)($this->context->params['limit'] ?? 10), 1000));
        $sort   = $this->context->params['sort'] ?? null;
        $search = $this->context->params['search'] ?? '';

        if (empty($table)) {
            return ['success' => false, 'error' => 'Missing table'];
        }

        // Auto‑activar conexión (misma lógica que en otros endpoints)
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
                    }
                }
            }
        }

        Conn::select($connId);

        $decoded = json_decode($table, true);
        $tables = is_array($decoded) ? $decoded : [$table];

        $conditions = [];
        // Si se implementa búsqueda global, se podría usar Search::class aquí

        $x = X::con($connId)->from($tables, $conditions);

        $driver = SchemaMap::getMap()['driver'] ?? 'sqlite';
        $x->totalStrategy($driver === 'mysql' ? 'window' : 'separate');

        $pagination = [$page, $limit];
        $gridData = $x->grid('*', $pagination, $sort, 300);

        return [
            'success'   => true,
            'data'      => $gridData['data'],
            'total'     => $gridData['total'],
            'columns'   => $gridData['columns'],
            'titles'    => $gridData['titles'],
            'page'      => $gridData['page'],
            'limit'     => $gridData['limit'],
            'last_page' => $gridData['last_page'],
            'stats'     => $gridData['stats'],
            'debug'     => $gridData['debug'],
        ];
    }
}