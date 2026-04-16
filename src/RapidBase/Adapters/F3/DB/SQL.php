<?php

namespace RapidBase\Adapters\F3\DB;

use RapidBase\Core\Conn;
use RapidBase\Core\Gateway;

/**
 * F3 SQL Adapter - Compatible with Fat-Free Framework DB\SQL
 * Internally uses RapidBase Core for optimized performance
 * 
 * Note: Does NOT extend F3 classes to avoid dependency requirements.
 * Implements same interface for drop-in replacement.
 */
class SQL {
    
    protected $rapidConn;
    protected $rapidGateway;
    protected $pdo;
    protected $connectionName;
    
    public function __construct($dsn, $user = null, $pw = null, $options = []) {
        // Generate unique connection name
        $this->connectionName = 'f3_' . md5($dsn);
        
        // Setup RapidBase connection if not exists
        if (!Conn::has($this->connectionName)) {
            Conn::setup($dsn, $user ?? '', $pw ?? '', $this->connectionName);
        }
        
        // Get PDO instance
        $this->pdo = Conn::get($this->connectionName);
        
        // Initialize Gateway
        $this->rapidGateway = new Gateway($this->connectionName);
    }
    
    /**
     * Execute SQL query - delegates to RapidBase Gateway
     */
    public function exec($sql, $args = [], $ttl = 0) {
        try {
            $result = $this->rapidGateway->select($sql, $args);
            return $result;
        } catch (\Exception $e) {
            // Fallback to direct PDO execution
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($args);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }
    }
    
    /**
     * Get last insert ID
     */
    public function lastinsertid($table = null, $field = 'id') {
        return $this->pdo->lastInsertId();
    }
    
    /**
     * Quote identifier
     */
    public function quotekey($key) {
        return $this->pdo->quote($key);
    }
    
    /**
     * Get PDO instance
     */
    public function getPdo() {
        return $this->pdo;
    }
    
    /**
     * Get RapidBase Gateway instance for advanced features
     */
    public function getRapidGateway() {
        return $this->rapidGateway;
    }
}
