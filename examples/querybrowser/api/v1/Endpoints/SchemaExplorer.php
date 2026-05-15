<?php

namespace RapidBase\Endpoints;

use RapidBase\Api\BaseEndpoint;
use RapidBase\Core\X;
use RapidBase\Core\Conn;
use RapidBase\Core\DB;
use RapidBase\Core\SchemaMap;

class SchemaExplorer extends BaseEndpoint
{
    public function getSchema(): array
    {
        $connId = $this->context->params['connectionId']
                  ?? $this->context->params['connection_id']
                  ?? 'main';

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
        $description = X::con($connId)->description();

        return [
            'success'   => true,
            'tables'    => $description['tables'],
            'views'     => $description['views'],
            'relations' => $description['relations'],
        ];
    }

    public function describeTable(): array
    {
        $connId = $this->context->params['connectionId']
                  ?? $this->context->params['connection_id']
                  ?? 'main';
        $table  = $this->context->params['table'] ?? '';

        if (empty($table)) {
            return ['success' => false, 'error' => 'Missing table parameter'];
        }

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
        $description = X::con($connId)->from($table)->description();

        return [
            'success'   => true,
            'table'     => $table,
            'structure' => $description['tables'][0] ?? null,
        ];
    }

    public function getRelatedTables(): array
    {
        $connId = $this->context->params['connectionId']
                  ?? $this->context->params['connection_id']
                  ?? 'main';
        $tables = $this->context->params['tables'] ?? '';

        if (empty($tables)) {
            return ['success' => false, 'error' => 'Missing tables parameter'];
        }

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

        $tableList = json_decode($tables, true);
        if (!is_array($tableList) || empty($tableList)) {
            return ['success' => false, 'error' => 'Invalid tables list'];
        }

        $map = SchemaMap::getMap();
        $relsFrom = $map['relationships']['from'] ?? [];
        $relsTo   = $map['relationships']['to']   ?? [];

        $toList = [];
        $fromList = [];
        foreach ($tableList as $t) {
            foreach ($relsFrom[$t] ?? [] as $target => $rel) {
                if (!in_array($target, $tableList)) $toList[$target] = true;
            }
            foreach ($relsTo[$t] ?? [] as $target => $rel) {
                if (!in_array($target, $tableList)) $fromList[$target] = true;
            }
        }

        return [
            'success' => true,
            'to'      => array_keys($toList),
            'from'    => array_keys($fromList),
        ];
    }
}