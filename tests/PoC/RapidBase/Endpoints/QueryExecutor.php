<?php

namespace RapidBase\Endpoints;

use RapidBase\Api\BaseEndpoint;
use RapidBase\Core\X;
use RapidBase\Core\Gateway;
use RapidBase\Core\Conn;

/**
 * QueryExecutor - Executes SQL queries using RapidBase core classes.
 * 
 * This endpoint demonstrates how to use RapidBase's X, Gateway, and Conn
 * classes instead of raw PDO. It supports SELECT, INSERT, UPDATE, DELETE
 * and returns standardized responses.
 */
class QueryExecutor extends BaseEndpoint
{
    /**
     * Execute a raw SQL query using RapidBase infrastructure.
     * Automatically detects query type (SELECT vs action) and uses
     * appropriate RapidBase methods (X::raw or Gateway).
     * 
     * @param string $sql The SQL query to execute
     * @return array Standardized response with data or affected rows
     */
    public function execute(string $sql): array
    {
        $connectionId = $this->context->params['connection_id'] ?? 'main';
        
        // Ensure connection exists using RapidBase Conn
        if (!in_array($connectionId, Conn::listConnectionIds())) {
            return [
                'success' => false,
                'error' => "Connection '$connectionId' not found. Use ConnectionManager to create it first."
            ];
        }
        
        Conn::select($connectionId);
        
        try {
            // Use RapidBase X::raw() which handles both SELECT and actions
            $response = X::con($connectionId)->raw($sql);
            
            // Check if it was a SELECT-like query (returns data)
            if (!empty($response->data) || str_starts_with(strtoupper(trim($sql)), 'SELECT')) {
                return [
                    'success' => true,
                    'type' => 'SELECT',
                    'data' => $response->data,
                    'columns' => $response->columns ?? [],
                    'titles' => $response->titles ?? [],
                    'total' => $response->total ?? count($response->data),
                    'duration_ms' => $response->durationMs ?? 0,
                    'sql' => $response->sql ?? $sql
                ];
            }
            
            // Action query (INSERT, UPDATE, DELETE)
            return [
                'success' => $response->success ?? false,
                'type' => 'ACTION',
                'affected_rows' => $response->affected ?? 0,
                'last_insert_id' => $response->lastId ?? null,
                'duration_ms' => $response->durationMs ?? 0,
                'sql' => $response->sql ?? $sql
            ];
            
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'sql' => $sql
            ];
        }
    }

    /**
     * Execute a parameterized query safely.
     * Note: RapidBase X::raw() doesn't support bound parameters directly,
     * so this method validates and sanitizes input before execution.
     * 
     * @param string $sql SQL with placeholders
     * @param array $params Parameters to bind
     * @return array Execution result
     */
    public function executeParams(string $sql, array $params = []): array
    {
        // For parameterized queries, we validate the params are scalar
        foreach ($params as $key => $value) {
            if (!is_scalar($value) && $value !== null) {
                return [
                    'success' => false,
                    'error' => "Parameter '$key' must be scalar or null"
                ];
            }
        }
        
        // Simple placeholder replacement for demonstration
        // In production, prefer using Q builder or X methods
        $processedSql = $sql;
        foreach ($params as $key => $value) {
            $placeholder = is_numeric($key) ? '?' : ":$key";
            if (is_string($value)) {
                $value = "'" . str_replace("'", "''", $value) . "'";
            } elseif ($value === null) {
                $value = 'NULL';
            }
            $processedSql = str_replace($placeholder, (string)$value, $processedSql);
        }
        
        return $this->execute($processedSql);
    }

    /**
     * Get last executed query status from Gateway.
     * 
     * @return array Last query status information
     */
    public function lastStatus(): array
    {
        $status = Gateway::status();
        return [
            'success' => true,
            'status' => $status
        ];
    }
}
