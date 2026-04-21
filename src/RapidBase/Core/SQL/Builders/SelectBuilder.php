<?php

namespace RapidBase\Core\SQL\Builders;

use RapidBase\Core\SQL;

/**
 * Class that replaces the traditional $parts array in buildSelect().
 * 
 * This class acts as a structured skeleton with the same properties
 * that the $parts array had, but with typed access and potentially faster performance.
 * 
 * Goal: Replace $parts['select'] = '*' with $this->select = '*'
 * while maintaining the same assembly logic but with better performance.
 */
class SelectBuilder
{
    // Public properties that replace $parts array indices
    public mixed $select = '*';      // replaces $parts['select']
    public mixed $from = '';         // replaces $parts['from']
    public array $join = [];         // replaces $parts['join']
    public array $where = [];        // replaces $parts['where']
    public array $params = [];       // replaces $parts['params']
    public array $groupBy = [];      // replaces $parts['groupBy']
    public array $having = [];       // replaces $parts['having']
    public array $orderBy = [];      // replaces $parts['orderBy']
    public int $limit = 10;          // replaces $parts['limit']
    public int $offset = 0;          // replaces $parts['offset']
    public bool $count = false;      // replaces $parts['count']
    public array $map = [];          // replaces $parts['map']
    public bool $noQuote = false;    // replaces $parts['noQuote']
    
    // Internal cache for built clauses
    private ?string $cachedSelectClause = null;
    private ?string $cachedFromClause = null;
    private ?string $cachedWhereClause = null;
    
    /**
     * Empty constructor - the class acts as a property container
     * Values are assigned directly: $builder->select = '*', $builder->from = 'users'
     */
    public function __construct()
    {
        // Default initialization is already in properties
    }
    
    /**
     * Builds the SELECT clause
     */
    public function buildSelectClause(): string
    {
        if ($this->cachedSelectClause !== null) {
            return $this->cachedSelectClause;
        }
        
        if ($this->select === '*') {
            $this->cachedSelectClause = 'SELECT *';
        } elseif (is_array($this->select)) {
            // Array of fields or Field objects
            $fields = [];
            foreach ($this->select as $field) {
                if ($field instanceof Field) {
                    $fields[] = $field->toSql([SQL::class, 'quote']);
                } elseif (is_array($field)) {
                    // Old format: ['field', 'alias']
                    $fields[] = SQL::quoteField($field[0]) . ' AS ' . SQL::quoteField($field[1]);
                } else {
                    $fields[] = SQL::quoteField($field);
                }
            }
            $this->cachedSelectClause = 'SELECT ' . implode(', ', $fields);
        } else {
            // String or expression
            $this->cachedSelectClause = 'SELECT ' . $this->select;
        }
        
        return $this->cachedSelectClause;
    }
    
    /**
     * Builds the FROM clause with JOINs
     */
    public function buildFromClause(): string
    {
        if ($this->cachedFromClause !== null) {
            return $this->cachedFromClause;
        }
        
        // Delegate to SQL::buildFromWithMap to handle all cases (including pivot)
        $sql = SQL::buildFromWithMap($this->from);
        
        $this->cachedFromClause = $sql;
        return $sql;
    }
    
    /**
     * Builds the WHERE clause
     */
    public function buildWhereClause(): string
    {
        if ($this->cachedWhereClause !== null) {
            return $this->cachedWhereClause;
        }
        
        if (empty($this->where)) {
            $this->cachedWhereClause = '';
            return '';
        }
        
        // Delegate to SQL::buildWhere to maintain compatibility
        $whereData = SQL::buildWhere($this->where, $this->params, $this->noQuote);
        $whereClause = is_array($whereData) ? ($whereData['sql'] ?? '') : $whereData;
        $this->cachedWhereClause = $whereClause;
        return $whereClause;
    }
    
    /**
     * Magic method for dynamic access (optional, for compatibility)
     */
    public function __get(string $name): mixed
    {
        return $this->$name ?? null;
    }
    
    /**
     * Magic method for dynamic assignment (optional, for compatibility)
     */
    public function __set(string $name, mixed $value): void
    {
        $this->$name = $value;
        // Invalidate caches when properties change
        if (in_array($name, ['select', 'from', 'join'])) {
            $this->cachedSelectClause = null;
            $this->cachedFromClause = null;
        }
        if ($name === 'where') {
            $this->cachedWhereClause = null;
        }
    }
    
    /**
     * Builds the GROUP BY clause
     */
    public function buildGroupByClause(): string
    {
        if (empty($this->groupBy)) {
            return '';
        }
        
        return 'GROUP BY ' . implode(', ', $this->groupBy);
    }
    
    /**
     * Builds the HAVING clause
     */
    public function buildHavingClause(): string
    {
        if (empty($this->having)) {
            return '';
        }
        
        // Handle nested arrays in HAVING (e.g.: ['total' => ['>' => 5]])
        $conditions = [];
        foreach ($this->having as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $operator => $val) {
                    if (is_numeric($operator)) {
                        $conditions[] = $val;
                    } else {
                        $conditions[] = "`$key` $operator ?";
                        $this->params[] = $val;
                    }
                }
            } else {
                $conditions[] = "`$key` = ?";
                $this->params[] = $value;
            }
        }
        
        return 'HAVING ' . implode(' AND ', $conditions);
    }
    
    /**
     * Builds the ORDER BY clause
     */
    public function buildOrderByClause(): string
    {
        if (empty($this->orderBy)) {
            return '';
        }
        
        $parts = [];
        foreach ($this->orderBy as $column => $direction) {
            $parts[] = "$column $direction";
        }
        
        return 'ORDER BY ' . implode(', ', $parts);
    }
    
    /**
     * Builds the LIMIT/OFFSET clause
     */
    public function buildLimitClause(): string
    {
        return "LIMIT {$this->limit} OFFSET {$this->offset}";
    }
    
    /**
     * Builds the complete SQL query
     */
    public function buildSQL(): string
    {
        $parts = [
            $this->buildSelectClause(),
            $this->buildFromClause()
        ];
        
        $whereClause = $this->buildWhereClause();
        if ($whereClause !== '') {
            $parts[] = $whereClause;
        }
        
        $groupByClause = $this->buildGroupByClause();
        if ($groupByClause !== '') {
            $parts[] = $groupByClause;
        }
        
        $havingClause = $this->buildHavingClause();
        if ($havingClause !== '') {
            $parts[] = $havingClause;
        }
        
        $orderByClause = $this->buildOrderByClause();
        if ($orderByClause !== '') {
            $parts[] = $orderByClause;
        }
        
        $parts[] = $this->buildLimitClause();
        
        return implode(' ', $parts);
    }
    
    /**
     * Gets all parameters
     */
    public function getParams(): array
    {
        return $this->params;
    }
    
    /**
     * Builds and returns [sql, params]
     */
    public function build(): array
    {
        return [$this->buildSQL(), $this->getParams()];
    }
    
    /**
     * Alias of buildSQL() for compatibility with SQL::buildSelect
     * Returns only the built SQL
     */
    public function toSql(): string
    {
        return $this->buildSQL();
    }
    
    /**
     * Converts object to array (for compatibility)
     */
    public function toArray(): array
    {
        return [
            'SELECT' => $this->select,
            'FROM' => $this->from,
            'JOINS' => $this->joins,
            'WHERE' => $this->where,
            'PARAMS' => $this->params,
            'GROUP_BY' => $this->groupBy,
            'HAVING' => $this->having,
            'ORDER_BY' => $this->orderBy,
            'LIMIT' => $this->limit,
            'OFFSET' => $this->offset
        ];
    }
    
    /**
     * Creates from array (for backward compatibility)
     */
    public static function fromArray(array $array): self
    {
        $builder = new self();
        
        if (isset($array['SELECT'])) {
            $builder->select = $array['SELECT'];
        }
        if (isset($array['FROM'])) {
            $builder->from = $array['FROM'];
        }
        if (isset($array['JOINS'])) {
            $builder->joins = $array['JOINS'];
        }
        if (isset($array['WHERE'])) {
            $builder->where = $array['WHERE'];
        }
        if (isset($array['PARAMS'])) {
            $builder->params = $array['PARAMS'];
        }
        if (isset($array['GROUP_BY'])) {
            $builder->groupBy = $array['GROUP_BY'];
        }
        if (isset($array['HAVING'])) {
            $builder->having = $array['HAVING'];
        }
        if (isset($array['ORDER_BY'])) {
            $builder->orderBy = $array['ORDER_BY'];
        }
        if (isset($array['LIMIT'])) {
            $builder->limit = $array['LIMIT'];
        }
        if (isset($array['OFFSET'])) {
            $builder->offset = $array['OFFSET'];
        }
        
        return $builder;
    }
    
    /**
     * Full reset
     */
    public function reset(): self
    {
        $this->select = '*';
        $this->from = '';
        $this->joins = [];
        $this->where = [];
        $this->params = [];
        $this->groupBy = [];
        $this->having = [];
        $this->orderBy = [];
        $this->limit = 10;
        $this->offset = 0;
        $this->cachedSelectClause = null;
        $this->cachedFromClause = null;
        $this->cachedWhereClause = null;
        
        return $this;
    }
}
