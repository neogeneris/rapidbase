<?php

namespace RapidBase\Adapters\F3\DB\SQL;

/**
 * F3 Mapper Adapter - Compatible with Fat-Free Framework DB\SQL\Mapper
 * Internally uses RapidBase Model for optimized performance
 * 
 * Note: Does NOT extend F3 classes to avoid dependency requirements.
 * Implements same interface for drop-in replacement.
 */
class Mapper {
    
    protected $db;
    protected $rapidGateway;
    protected $tableName;
    protected $data = [];
    protected $exists = false;
    protected $pkField = 'id';
    
    public function __construct($db, $table, $fields = null, $ttl = 60, $force = false) {
        $this->db = $db;
        $this->tableName = $table;
        
        // Get RapidBase components from adapted DB
        if ($db instanceof \RapidBase\Adapters\F3\DB\SQL) {
            $this->rapidGateway = $db->getRapidGateway();
        }
    }
    
    /**
     * Magic getter for properties
     */
    public function __get($key) {
        return isset($this->data[$key]) ? $this->data[$key] : null;
    }
    
    /**
     * Magic setter for properties
     */
    public function __set($key, $value) {
        $this->data[$key] = $value;
    }
    
    /**
     * Check if property is set
     */
    public function __isset($key) {
        return isset($this->data[$key]);
    }
    
    /**
     * Load record by criteria - uses RapidBase optimized select
     */
    public function load($criteria = null, $options = [], $ttl = 0) {
        if ($this->rapidGateway) {
            try {
                $where = $this->convertCriteria($criteria);
                
                // Use RapidBase buildSelect for optimization
                $sqlData = \RapidBase\Core\SQL::buildSelect('*', $this->tableName, $where, [], [], [], $options['order'] ?? null, 1);
                $result = $this->rapidGateway->select($sqlData['sql'], $sqlData['params']);
                
                if (!empty($result)) {
                    $this->data = $result[0];
                    $this->exists = true;
                    return true;
                }
                $this->data = [];
                $this->exists = false;
                return false;
            } catch (\Exception $e) {
                // Fallback to simple query
                return $this->loadFallback($criteria);
            }
        }
        
        return $this->loadFallback($criteria);
    }
    
    /**
     * Fallback load method
     */
    protected function loadFallback($criteria) {
        if (empty($criteria)) {
            return false;
        }
        
        $sql = "SELECT * FROM {$this->tableName} WHERE " . $criteria[0];
        $params = array_slice($criteria, 1);
        
        $stmt = $this->db->getPdo()->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if ($result) {
            $this->data = $result;
            $this->exists = true;
            return true;
        }
        
        $this->data = [];
        $this->exists = false;
        return false;
    }
    
    /**
     * Find records - uses RapidBase optimized select
     */
    public function find($criteria = null, $options = [], $ttl = 0) {
        if ($this->rapidGateway) {
            try {
                $where = $this->convertCriteria($criteria);
                $limit = $options['limit'] ?? null;
                $offset = $options['offset'] ?? null;
                $order = $options['order'] ?? null;
                
                $page = null;
                if ($limit) {
                    $page = [$offset ?? 0, $limit];
                }
                
                $sqlData = \RapidBase\Core\SQL::buildSelect('*', $this->tableName, $where, [], [], [], $order, $page);
                return $this->rapidGateway->select($sqlData['sql'], $sqlData['params']);
            } catch (\Exception $e) {
                // Fallback
            }
        }
        
        // Fallback implementation
        if (empty($criteria)) {
            $sql = "SELECT * FROM {$this->tableName}";
            $params = [];
        } else {
            $sql = "SELECT * FROM {$this->tableName} WHERE " . $criteria[0];
            $params = array_slice($criteria, 1);
        }
        
        $stmt = $this->db->getPdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    /**
     * Save current record - uses RapidBase optimized insert/update
     */
    public function save() {
        if ($this->rapidGateway) {
            try {
                if ($this->exists) {
                    // Update existing
                    $pk = $this->pkField;
                    if (isset($this->data[$pk])) {
                        $where = [$pk => $this->data[$pk]];
                        $this->rapidGateway->update($this->tableName, $this->data, $where);
                    }
                } else {
                    // Insert new
                    $insertId = $this->rapidGateway->insert($this->tableName, $this->data);
                    if ($insertId && isset($this->data[$this->pkField])) {
                        $this->data[$this->pkField] = $insertId;
                    }
                    $this->exists = true;
                }
                return $this;
            } catch (\Exception $e) {
                // Fallback
            }
        }
        
        // Fallback implementation
        if ($this->exists) {
            $pk = $this->pkField;
            if (isset($this->data[$pk])) {
                $fields = implode(', ', array_map(fn($k) => "$k = ?", array_keys($this->data)));
                $values = array_values($this->data);
                $sql = "UPDATE {$this->tableName} SET $fields WHERE $pk = ?";
                $values[] = $this->data[$pk];
                $stmt = $this->db->getPdo()->prepare($sql);
                $stmt->execute($values);
            }
        } else {
            $fields = implode(', ', array_keys($this->data));
            $placeholders = implode(', ', array_fill(0, count($this->data), '?'));
            $sql = "INSERT INTO {$this->tableName} ($fields) VALUES ($placeholders)";
            $stmt = $this->db->getPdo()->prepare($sql);
            $stmt->execute(array_values($this->data));
            $this->data[$this->pkField] = $this->db->lastinsertid();
            $this->exists = true;
        }
        
        return $this;
    }
    
    /**
     * Delete current record
     */
    public function erase() {
        if ($this->rapidGateway && $this->exists) {
            try {
                $pk = $this->pkField;
                $where = [$pk => $this->data[$pk]];
                $this->rapidGateway->delete($this->tableName, $where);
                $this->data = [];
                $this->exists = false;
                return true;
            } catch (\Exception $e) {
                // Fallback
            }
        }
        
        // Fallback
        if ($this->exists) {
            $pk = $this->pkField;
            $sql = "DELETE FROM {$this->tableName} WHERE $pk = ?";
            $stmt = $this->db->getPdo()->prepare($sql);
            $stmt->execute([$this->data[$pk]]);
            $this->data = [];
            $this->exists = false;
            return true;
        }
        
        return false;
    }
    
    /**
     * Check if mapper is dry (no data loaded)
     */
    public function dry() {
        return empty($this->data);
    }
    
    /**
     * Get primary key field name
     */
    public function pk() {
        return $this->pkField;
    }
    
    /**
     * Cast data to array
     */
    public function cast() {
        return $this->data;
    }
    
    /**
     * Get a field value
     */
    public function get($field) {
        return isset($this->data[$field]) ? $this->data[$field] : null;
    }
    
    /**
     * Set a field value
     */
    public function set($field, $value) {
        $this->data[$field] = $value;
    }
    
    /**
     * Convert F3 criteria array to RapidBase matrix format
     */
    protected function convertCriteria($criteria) {
        if (empty($criteria)) {
            return [];
        }
        
        if (is_string($criteria)) {
            return [];
        }
        
        if (is_array($criteria)) {
            if (isset($criteria[0]) && is_string($criteria[0])) {
                return [];
            } else {
                return $criteria;
            }
        }
        
        return $criteria;
    }
}
