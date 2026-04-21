<?php

namespace RapidBase\Core\SQL\Builders;

use RapidBase\Core\SQL;

/**
 * Builder for UPDATE queries using objects instead of arrays.
 * 
 * Replaces the traditional array-based approach with an object-oriented API
 * for greater clarity and type-safety.
 * 
 * @example
 * // Simple update
 * $update = new UpdateBuilder('users');
 * $update->set(['name' => 'John', 'email' => 'john@example.com']);
 * $update->where(['id' => 1]);
 * [$sql, $params] = $update->build();
 * 
 * // Update with complex condition
 * $update->where([
 *     'status' => 'active',
 *     'created_at' => ['>' => '2023-01-01']
 * ]);
 */
class UpdateBuilder
{
    use WhereTrait;
    
    protected string $table;
    protected array $data = [];
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
     * Sets the values to update
     * 
     * @param array $data Associative array of columns and values
     * @return self
     */
    public function set(array $data): self
    {
        $this->data = array_merge($this->data, $data);
        return $this;
    }
    
    /**
     * Sets a specific value
     * 
     * @param string $column Column name
     * @param mixed $value Value to update
     * @return self
     */
    public function setValue(string $column, mixed $value): self
    {
        $this->data[$column] = $value;
        return $this;
    }
    
    /**
     * Enables mass update without WHERE (dangerous)
     */
    public function force(bool $force = true): self
    {
        $this->force = $force;
        return $this;
    }
    
    /**
     * Normalizes a value (converts empty strings to null)
     */
    protected function normalizeValue(mixed $value): mixed
    {
        if ($value === '' || $value === "\0") {
            return null;
        }
        return $value;
    }
    
    /**
     * Builds the SQL query
     * 
     * @return array [sql, params]
     */
    public function build(): array
    {
        if (empty($this->data)) {
            throw new \InvalidArgumentException("Cannot update records without data.");
        }
        
        if (empty($this->where) && !$this->force) {
            throw new \RuntimeException("DANGER: Mass UPDATE without WHERE on [{$this->table}].");
        }
        
        $parts = ["UPDATE " . SQL::quote($this->table), "SET"];
        $setParts = [];
        $params = [];
        
        foreach ($this->data as $col => $val) {
            $token = SQL::nextTokenPublic();
            $setParts[] = SQL::quote($col) . " = :$token";
            $params[$token] = $this->normalizeValue($val);
        }
        
        $parts[] = implode(', ', $setParts);
        
        $whereData = $this->buildWhereClause();
        $parts[] = "WHERE " . $whereData['sql'];
        
        $sql = implode(' ', $parts);
        
        return [$sql, array_merge($params, $whereData['params'])];
    }
    
    /**
     * Gets the table name
     */
    public function getTable(): string
    {
        return $this->table;
    }
    
    /**
     * Gets the data to update
     */
    public function getData(): array
    {
        return $this->data;
    }
    
    /**
     * Reset the builder
     */
    public function reset(): self
    {
        $this->data = [];
        $this->where = [];
        $this->params = [];
        $this->force = false;
        return $this;
    }
}
