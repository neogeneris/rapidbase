<?php

namespace RapidBase\Endpoints;

use RapidBase\Api\BaseEndpoint;
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
        $connectionId = $this->context->params['connection_id'] ?? 'main';
        
        if (!in_array($connectionId, Conn::listConnectionIds())) {
            return [
                'success' => false,
                'error' => "Connection '$connectionId' not found."
            ];
        }
        
        Conn::select($connectionId);
        
        try {
            // Use X::con()->description() to get schema metadata
            $description = X::con($connectionId)->description();
            
            // Organize results by type to avoid collisions
            $tables = [];
            $views = [];
            $relations = [];
            
            foreach ($description as $tableName => $info) {
                // Check if it's a view (SQLite stores views in sqlite_master with type='view')
                $isView = isset($info['type']) && $info['type'] === 'view';
                
                if ($isView) {
                    $views[] = $tableName;
                } else {
                    $tables[] = $tableName;
                    
                    // Extract relations if present
                    if (isset($info['relations']) && is_array($info['relations'])) {
                        foreach ($info['relations'] as $relation) {
                            $relations[] = $relation;
                        }
                    }
                }
            }
            
            return [
                'success' => true,
                'schema' => [
                    'tables' => $tables,
                    'views' => $views,
                    'relations' => $relations
                ],
                'details' => $description
            ];
            
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
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
