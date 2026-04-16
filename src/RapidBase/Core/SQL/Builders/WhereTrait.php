<?php

namespace RapidBase\Core\SQL\Builders;

use RapidBase\Core\SQL;

/**
 * Trait compartido para builders que necesitan cláusulas WHERE.
 * 
 * Proporciona métodos comunes para construir condiciones WHERE
 * con soporte para operadores, NULL, IN, y grupos OR.
 */
trait WhereTrait
{
    protected array $where = [];
    protected array $params = [];
    
    /**
     * Establece las condiciones WHERE
     * 
     * @param array $conditions Condiciones WHERE
     * @return self
     */
    public function where(array $conditions): self
    {
        $this->where = array_merge($this->where, $conditions);
        return $this;
    }
    
    /**
     * Agrega una condición WHERE adicional
     * 
     * @param string|array $condition Condición o array de condiciones
     * @param mixed $value Valor opcional para la condición
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
     * Construye la cláusula WHERE
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
     * Obtiene las condiciones WHERE actuales
     */
    public function getWhere(): array
    {
        return $this->where;
    }
    
    /**
     * Obtiene los parámetros WHERE actuales
     */
    public function getWhereParams(): array
    {
        return $this->params;
    }
}
