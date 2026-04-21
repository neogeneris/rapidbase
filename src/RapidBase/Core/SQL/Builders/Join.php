<?php

namespace RapidBase\Core\SQL\Builders;

use RapidBase\Core\SQL;

/**
 * Class to represent a JOIN in SQL queries.
 * 
 * Supports all JOIN types and allows specifying table aliases.
 * 
 * @example
 * // Simple JOIN
 * new Join('users', 'u.id = p.user_id')
 * // -> LEFT JOIN `users` AS `u` ON `u`.`id` = `p`.`user_id`
 * 
 * // With specific type
 * new Join('posts', 'p.cat_id = c.id', 'INNER')
 */
class Join
{
    public string $type;
    public mixed $table;  // string or Table object
    public ?string $alias;
    public string $on;
    public array $params;
    
    /**
     * Constructor
     * 
     * @param string|Table $table Table name or Table object
     * @param string $on ON condition for the JOIN
     * @param string $type JOIN type: INNER, LEFT, RIGHT, FULL, etc. (default: LEFT)
     * @param array $params Parameters for the ON condition
     */
    public function __construct(
        mixed $table,
        string $on = '',
        string $type = 'LEFT',
        array $params = []
    ) {
        $this->type = strtoupper($type);
        
        if ($table instanceof Table) {
            $this->table = $table->name;
            $this->alias = $table->alias;
        } else {
            $this->table = (string)$table;
            $this->alias = null;
        }
        
        $this->on = $on;
        $this->params = $params;
    }
    
    /**
     * Builds the SQL representation of the JOIN
     * 
     * @param callable $quoteFunc Function to quote identifiers
     * @return string The JOIN formatted for SQL
     */
    public function toSql(callable $quoteFunc): string
    {
        $sql = "{$this->type} JOIN ";
        
        if ($this->alias !== null) {
            $sql .= $quoteFunc($this->table) . ' AS ' . $quoteFunc($this->alias);
        } else {
            $sql .= $quoteFunc($this->table);
        }
        
        if (!empty($this->on)) {
            $sql .= ' ON ' . $this->on;
        }
        
        return $sql;
    }
    
    /**
     * Creates a Join from a configuration array
     * 
     * @param array $config Configuration with keys: type, table, alias, on, params
     * @return self
     */
    public static function fromArray(array $config): self
    {
        return new self(
            $config['table'],
            $config['on'] ?? '',
            $config['type'] ?? 'LEFT',
            $config['params'] ?? []
        );
    }
    
    /**
     * Converts to array
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'table' => $this->table,
            'alias' => $this->alias,
            'on' => $this->on,
            'params' => $this->params
        ];
    }
}
