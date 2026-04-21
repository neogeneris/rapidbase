<?php

namespace RapidBase\Core\SQL\Builders;

/**
 * Class to represent a table with alias in SQL queries.
 * 
 * Allows precise specification of table syntax with aliases,
 * essential for JOINs and complex queries.
 * 
 * @example
 * // Without alias
 * new Table('users')           // -> FROM `users`
 * 
 * // With alias
 * new Table('users', 'u')      // -> FROM `users` AS `u`
 */
class Table
{
    public string $name;
    public ?string $alias;
    
    /**
     * Constructor
     * 
     * @param string $name Real table name
     * @param string|null $alias Optional alias for the table
     */
    public function __construct(string $name, ?string $alias = null)
    {
        $this->name = $name;
        $this->alias = $alias;
    }
    
    /**
     * Builds the SQL representation of the table
     * 
     * @param callable $quoteFunc Function to quote identifiers
     * @return string The table formatted for SQL
     */
    public function toSql(callable $quoteFunc): string
    {
        if ($this->alias !== null && $this->alias !== $this->name) {
            return $quoteFunc($this->name) . ' AS ' . $quoteFunc($this->alias);
        }
        return $quoteFunc($this->name);
    }
    
    /**
     * Creates a Table from a string with AS syntax
     * 
     * @param string $tableString Table in format 'table AS alias'
     * @return self
     */
    public static function fromString(string $tableString): self
    {
        if (preg_match('/^(\S+)\s+AS\s+(\S+)$/i', trim($tableString), $matches)) {
            return new self(trim($matches[1]), trim($matches[2]));
        }
        return new self($tableString);
    }
    
    /**
     * Gets the alias or name if no alias exists
     */
    public function getIdentifier(): string
    {
        return $this->alias ?? $this->name;
    }
    
    /**
     * Converts to array
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'alias' => $this->alias
        ];
    }
}
