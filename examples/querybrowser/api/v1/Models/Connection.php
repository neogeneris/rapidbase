<?php

namespace RapidBase\Models;

use RapidBase\ORM\ActiveRecord\Model;

/**
 * Connection - ActiveRecord model for database connections table.
 * 
 * Represents a stored database connection configuration that can be
 * activated in the Conn pool for use with RapidBase X, Gateway, etc.
 */
class Connection extends Model
{
    // Table name is inferred from class name (connections)
    
    /**
     * Build DSN string from connection attributes.
     * Supports sqlite, mysql, pgsql drivers.
     * 
     * @return string DSN connection string
     */
    public function buildDsn(): string
    {
        $driver = $this->driver ?? 'sqlite';
        
        switch ($driver) {
            case 'sqlite':
                // For SQLite, database field contains the file path
                return 'sqlite:' . ($this->database ?: ':memory:');
                
            case 'mysql':
                $dsn = "mysql:host={$this->host};dbname={$this->database}";
                if ($this->port) {
                    $dsn .= ";port={$this->port}";
                }
                return $dsn;
                
            case 'pgsql':
                $dsn = "pgsql:host={$this->host};dbname={$this->database}";
                if ($this->port) {
                    $dsn .= ";port={$this->port}";
                }
                return $dsn;
                
            default:
                throw new \RuntimeException("Unsupported driver: $driver");
        }
    }
    
    /**
     * Get connection as array without password.
     * 
     * @return array Safe connection data
     */
    public function toSafeArray(): array
    {
        $data = $this->toArray();
        unset($data['password']);
        return $data;
    }
}
