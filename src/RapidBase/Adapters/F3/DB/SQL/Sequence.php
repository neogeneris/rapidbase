<?php

namespace RapidBase\Adapters\F3\DB\SQL;

/**
 * F3 Sequence Adapter - Compatible with Fat-Free Framework DB\SQL\Sequence
 * Provides ID generation compatibility
 */
class Sequence {
    
    protected $db;
    protected $table;
    protected $field;
    
    public function __construct($db, $table = null, $field = 'id') {
        $this->db = $db;
        $this->table = $table;
        $this->field = $field;
    }
    
    /**
     * Get next sequence value
     */
    public function next() {
        if ($this->db instanceof \RapidBase\Adapters\F3\DB\SQL) {
            // Use RapidBase connection for last insert id
            $pdo = $this->db->getRapidConn()->getPdo();
            return $pdo->lastInsertId();
        }
        
        // Fallback
        return null;
    }
    
    /**
     * Reset sequence (if supported)
     */
    public function reset($value = 0) {
        // Not typically needed in MySQL/SQLite with auto_increment
        return true;
    }
}
