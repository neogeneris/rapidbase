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

        // Leer columnas opcionales (JSON array)
        $columnsParam = $this->context->params['columns'] ?? null;
        $columns = null;
        if ($columnsParam) {
            $decodedCols = json_decode($columnsParam, true);
            if (is_array($decodedCols)) {
                $columns = $decodedCols;
            }
        }

        $conditions = [];

        $x = X::con($connId)->from($tables, $conditions);

        // Usar siempre 'separate' para tener un total fiable
        $x->totalStrategy('separate');

        $pagination = [$page, $limit];
        $sortArray  = \RapidBase\Core\SQL\Q::sort($sort);
        $gridData   = $x->grid($columns ?? '*', $pagination, $sortArray, 300);

        // Forzar el total real con COUNT(*) (en caso de que X::grid falle)
        $total = $gridData['total'] ?? 0;
        if ($total <= 0) {
            $total = X::con($connId)->from($tables, $conditions)->count();
        }

        $lastPage = $limit > 0 ? (int) ceil($total / $limit) : 1;

        // Asegurar que las columnas/títulos coincidan con la proyección
        if ($columns) {
            $shortCols = array_map(function ($col) {
                return strpos($col, '.') !== false ? substr($col, strrpos($col, '.') + 1) : $col;
            }, $columns);
            $gridData['columns'] = $shortCols;
            $gridData['titles']  = array_map(function ($c) {
                return ucwords(str_replace('_', ' ', $c));
            }, $shortCols);
        }

        $sql = $gridData['debug']['sql'] ?? '';

        return [
            'success'   => true,
            'data'      => $gridData['data'],
            'total'     => $total,
            'columns'   => $gridData['columns'] ?? [],
            'titles'    => $gridData['titles'] ?? [],
            'page'      => $page,
            'limit'     => $limit,
            'last_page' => $lastPage,
            'stats'     => $gridData['stats'] ?? [],
            'sql'       => $sql,
        ];
    }
}