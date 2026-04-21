<?php

namespace RapidBase\Core\SQL\Builders;

use RapidBase\Core\SQL;

/**
 * Builder for INSERT queries using objects instead of arrays.
 * 
 * Replaces the traditional array-based approach with an object-oriented API
 * for greater clarity and type-safety.
 * 
 * @example
 * // Simple insert
 * $insert = new InsertBuilder('users');
 * $insert->values(['name' => 'John', 'email' => 'john@example.com']);
 * [$sql, $params] = $insert->build();
 * 
 * // Multiple insert
 * $insert->values([
 *     ['name' => 'John', 'email' => 'john@example.com'],
 *     ['name' => 'Jane', 'email' => 'jane@example.com']
 * ]);
 */
class InsertBuilder
{
    protected string $table;
    protected array $rows = [];
    protected array $columns = [];
    
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
     * Sets the values to insert
     * 
     * @param array $rows Array of rows (associative or list of associative arrays)
     * @return self
     */
    public function values(array $rows): self
    {
        // Detect if it's a single record or multiple
        if (!isset($rows[0]) || !is_array($rows[0])) {
            // Single row: ['name' => 'John']
            $this->rows = [$rows];
            $this->columns = array_keys($rows);
        } else {
            // Multiple rows: [['name' => 'John'], ['name' => 'Jane']]
            $this->rows = $rows;
            // Use columns from first row as reference
            $this->columns = array_keys($rows[0] ?? []);
        }
        
        return $this;
    }
    
    /**
     * Adds a specific column with its value
     * 
     * @param string $column Column name
     * @param mixed $value Value to insert
     * @return self
     */
    public function value(string $column, mixed $value): self
    {
        if (empty($this->rows)) {
            $this->rows = [[]];
        }
        
        $this->rows[0][$column] = $value;
        $this->columns = array_unique(array_merge($this->columns, [$column]));
        
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
        if (empty($this->rows)) {
            throw new \InvalidArgumentException("Cannot insert empty records.");
        }
        
        if (empty($this->rows[0])) {
            throw new \InvalidArgumentException("The data record is empty.");
        }
        
        $columns = $this->columns;
        $quotedCols = implode(', ', array_map([SQL::class, 'quote'], $columns));
        $placeholders = [];
        $params = [];
        
        // Build only for first row (INSERT single)
        foreach ($columns as $col) {
            $token = SQL::nextTokenPublic();
            $value = $this->normalizeValue($this->rows[0][$col]);
            $placeholders[] = ":$token";
            $params[$token] = $value;
        }
        
        $sql = "INSERT INTO " . SQL::quote($this->table) . " ($quotedCols) VALUES (" . implode(', ', $placeholders) . ")";
        
        return [$sql, $params];
    }
    
    /**
     * Gets the table name
     */
    public function getTable(): string
    {
        return $this->table;
    }
    
    /**
     * Gets the rows to insert
     */
    public function getRows(): array
    {
        return $this->rows;
    }
    
    /**
     * Gets the columns
     */
    public function getColumns(): array
    {
        return $this->columns;
    }
    
    /**
     * Reset the builder
     */
    public function reset(): self
    {
        $this->rows = [];
        $this->columns = [];
        return $this;
    }
}
