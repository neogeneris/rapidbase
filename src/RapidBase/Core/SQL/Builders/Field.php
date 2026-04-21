<?php

namespace RapidBase\Core\SQL\Builders;

/**
 * Class to represent a field with alias in SQL queries.
 * 
 * Allows precise specification of field syntax with aliases,
 * avoiding ambiguities and improving code readability.
 * 
 * @example
 * // Without alias
 * new Field('name')           // -> `name`
 * 
 * // With alias
 * new Field('users.name', 'user_name')  // -> `users`.`name` AS `user_name`
 * 
 * // With aggregate function
 * new Field('COUNT(*)', 'total')        // -> COUNT(*) AS `total`
 */
class Field
{
    public string $name;
    public ?string $alias;
    
    /**
     * Constructor
     * 
     * @param string $name Field name (can include table: 'table.column')
     * @param string|null $alias Optional alias for the field
     */
    public function __construct(string $name, ?string $alias = null)
    {
        $this->name = $name;
        $this->alias = $alias;
    }
    
    /**
     * Builds the SQL representation of the field
     * 
     * @param callable $quoteFunc Function to quote identifiers
     * @return string The field formatted for SQL
     */
    public function toSql(callable $quoteFunc): string
    {
        if ($this->alias !== null) {
            return $quoteFunc($this->name) . ' AS ' . $quoteFunc($this->alias);
        }
        return $quoteFunc($this->name);
    }
    
    /**
     * Creates a Field from a string with AS syntax
     * 
     * @param string $fieldString Field in format 'column AS alias'
     * @return self
     */
    public static function fromString(string $fieldString): self
    {
        if (preg_match('/^(.+?)\s+AS\s+(.+)$/i', trim($fieldString), $matches)) {
            return new self(trim($matches[1]), trim($matches[2]));
        }
        return new self($fieldString);
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
