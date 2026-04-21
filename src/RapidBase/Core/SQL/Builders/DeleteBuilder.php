<?php

namespace RapidBase\Core\SQL\Builders;

use RapidBase\Core\SQL;

/**
 * Builder for DELETE queries using objects instead of arrays.
 * 
 * Replaces the traditional array-based approach with an object-oriented API
 * for greater clarity and type-safety.
 * 
 * @example
 * // Simple delete
 * $delete = new DeleteBuilder('users');
 * $delete->where(['id' => 1]);
 * [$sql, $params] = $delete->build();
 * 
 * // Delete with complex condition
 * $delete->where([
 *     'status' => 'inactive',
 *     'created_at' => ['<' => '2023-01-01']
 * ]);
 */
class DeleteBuilder
{
    use WhereTrait;
    
    protected string $table;
    protected bool $force = false;
    
    /**
     * Constructor
     * 
     * @param string $table Table name
     */
    public function __construct(string $table)
    {
        $this->table = $table;
    }
    
    /**
     * Enables mass delete without WHERE (dangerous)
     */
    public function force(bool $force = true): self
    {
        $this->force = $force;
        return $this;
    }
    
    /**
     * Builds the SQL query
     * 
     * @return array [sql, params]
     */
    public function build(): array
    {
        if (empty($this->where) && !$this->force) {
            throw new \RuntimeException("DANGER: Mass DELETE without WHERE.");
        }
        
        $whereData = $this->buildWhereClause();
        $sql = "DELETE FROM " . SQL::quote($this->table) . " WHERE " . $whereData['sql'];
        
        return [$sql, $whereData['params']];
    }
    
    /**
     * Gets the table name
     */
    public function getTable(): string
    {
        return $this->table;
    }
    
    /**
     * Reset the builder
     */
    public function reset(): self
    {
        $this->where = [];
        $this->params = [];
        $this->force = false;
        return $this;
    }
}
