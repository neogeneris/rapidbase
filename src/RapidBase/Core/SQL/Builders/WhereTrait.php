<?php

namespace RapidBase\Core\SQL\Builders;

use RapidBase\Core\SQL;

/**
 * Trait shared for builders that need WHERE clauses.
 * 
 * Provides common methods for building WHERE conditions
 * with support for operators, NULL, IN, and OR groups.
 */
trait WhereTrait
{
    protected array $where = [];
    protected array $params = [];
    
    /**
     * Sets the WHERE conditions
     * 
     * @param array $conditions WHERE conditions
     * @return self
     */
    public function where(array $conditions): self
    {
        $this->where = array_merge($this->where, $conditions);
        return $this;
    }
    
    /**
     * Adds an additional WHERE condition
     * 
     * @param string|array $condition Condition or array of conditions
     * @param mixed $value Optional value for the condition
     * @return self
     */
    public function andWhere(string|array $condition, mixed $value = null): self
    {
        if (is_array($condition)) {
            $this->where = array_merge($this->where, $condition);
        } else {
            $this->where[] = $condition;
            if ($value !== null) {
                $this->params[] = $value;
            }
        }
        return $this;
    }
    
    /**
     * Builds the WHERE clause
     * 
     * @return array ['sql' => string, 'params' => array]
     */
    public function buildWhereClause(): array
    {
        if (empty($this->where)) {
            return ['sql' => '1', 'params' => []];
        }
        
        return SQL::buildWhere($this->where);
    }
    
    /**
     * Gets the current WHERE conditions
     */
    public function getWhere(): array
    {
        return $this->where;
    }
    
    /**
     * Gets the current WHERE parameters
     */
    public function getWhereParams(): array
    {
        return $this->params;
    }
}
