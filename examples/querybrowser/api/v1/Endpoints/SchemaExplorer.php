<?php

namespace RapidBase\Endpoints;

use RapidBase\Api\BaseEndpoint;
use RapidBase\Core\DB;
use RapidBase\Core\X;
use RapidBase\Core\Conn;
use RapidBase\Core\SchemaMap;

/**
 * SchemaExplorer - Explores database schema using RapidBase core classes.
 * 
 * Returns structured metadata about tables, views, and relations
 * without collisions between similarly named objects.
 */
class SchemaExplorer extends BaseEndpoint
{
    /**
     * Get database schema structure separated by object type.
     * Returns tables, views, and relations in separate keys to avoid naming collisions.
     * 
     * @return array Schema structure with tables, views, and relations
     */
public function getSchema(): array
{
    // Aceptar tanto connectionId como connection_id
    $connId = $this->context->params['connectionId'] 
              ?? $this->context->params['connection_id'] 
              ?? 'main';

    // Si la conexión no está en el pool, intentar activarla desde la BD interna
    if (!in_array($connId, Conn::listConnectionIds())) {
        // Extraer el ID numérico de la clave (ej. "saved_6" → 6)
        $id = (int)str_replace('saved_', '', $connId);
        if ($id > 0) {
            // Inicializar la BD interna (misma lógica que ConnectionManager)
            $dbFile = defined('CONNECTIONS_DB') ? CONNECTIONS_DB : __DIR__ . '/../../../data/connections.sqlite';
            if (!file_exists($dbFile)) {
                return ['success' => false, 'error' => 'Internal database not found'];
            }
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
                return ['success' => false, 'error' => "Connection not found in database"];
            }
        } else {
            return ['success' => false, 'error' => "Connection '$connId' not found."];
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
    /**
     * Get list of tables only.
     * 
     * @return array List of table names
     */
    public function getTables(): array
    {
        $result = $this->getSchema();
        
        if (!$result['success']) {
            return $result;
        }
        
        return [
            'success' => true,
            'tables' => $result['schema']['tables']
        ];
    }

    /**
     * Get list of views only.
     * 
     * @return array List of view names
     */
    public function getViews(): array
    {
        $result = $this->getSchema();
        
        if (!$result['success']) {
            return $result;
        }
        
        return [
            'success' => true,
            'views' => $result['schema']['views']
        ];
    }

    /**
     * Get relations/foreign keys.
     * 
     * @return array List of relations
     */
    public function getRelations(): array
    {
        $result = $this->getSchema();
        
        if (!$result['success']) {
            return $result;
        }
        
        return [
            'success' => true,
            'relations' => $result['schema']['relations']
        ];
    }

    /**
     * Get detailed description of a specific table.
     * 
     * @param string $table Table name to describe
     * @return array Table structure details
     */
    public function describeTable(string $table): array
    {
        $connectionId = $this->context->params['connection_id'] ?? 'main';
        
        if (!in_array($connectionId, Conn::listConnectionIds())) {
            return [
                'success' => false,
                'error' => "Connection '$connectionId' not found."
            ];
        }
        
        Conn::select($connectionId);
        
        try {
            $description = X::con($connectionId)->from($table)->description();
            
            return [
                'success' => true,
                'table' => $table,
                'structure' => $description[$table] ?? null
            ];
            
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
