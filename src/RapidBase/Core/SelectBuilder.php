<?php

namespace RapidBase\Core;

/**
 * Clase dinámica para construir consultas SELECT.
 * 
 * Esta clase reemplaza el enfoque basado en arrays ($sql['SELECT'] = '*')
 * con un objeto cuyas propiedades representan cada parte de la sintaxis SQL.
 * 
 * Objetivo: Mejorar la legibilidad, mantenibilidad y potencialmente el rendimiento
 * al evitar operaciones de array repetitivas.
 */
class SelectBuilder
{
    // Propiedades públicas para acceso directo
    public string $select = '*';
    public string $from = '';
    public array $joins = [];
    public array $where = [];
    public array $params = [];
    public array $groupBy = [];
    public array $having = [];
    public array $orderBy = [];
    public int $limit = 10;
    public int $offset = 0;
    
    // Cache interna para partes construidas
    private ?string $cachedSelectClause = null;
    private ?string $cachedFromClause = null;
    private ?string $cachedWhereClause = null;
    
    /**
     * Constructor con inicialización fluida
     */
    public function __construct(
        string|array $select = '*',
        string $from = '',
        array $where = [],
        array $orderBy = [],
        int $page = 1,
        int $perPage = 10
    ) {
        $this->setSelect($select);
        $this->setFrom($from);
        $this->setWhere($where);
        $this->setOrderBy($orderBy);
        $this->setPagination($page, $perPage);
    }
    
    /**
     * Establece las columnas del SELECT
     */
    public function setSelect(string|array $fields): self
    {
        if ($fields === '*') {
            $this->select = '*';
        } elseif (is_array($fields)) {
            $this->select = implode(', ', $fields);
        } else {
            $this->select = $fields;
        }
        $this->cachedSelectClause = null;
        return $this;
    }
    
    /**
     * Establece la tabla principal FROM
     */
    public function setFrom(string $table): self
    {
        $this->from = $table;
        $this->cachedFromClause = null;
        return $this;
    }
    
    /**
     * Agrega un JOIN
     */
    public function addJoin(
        string $type,
        string $table,
        string $alias,
        string $on,
        array $params = []
    ): self {
        $this->joins[] = [
            'type' => $type,
            'table' => $table,
            'alias' => $alias,
            'on' => $on,
            'params' => $params
        ];
        $this->cachedFromClause = null;
        return $this;
    }
    
    /**
     * Establece las condiciones WHERE
     */
    public function setWhere(array $conditions, array $params = []): self
    {
        $this->where = $conditions;
        $this->params = $params;
        $this->cachedWhereClause = null;
        return $this;
    }
    
    /**
     * Agrega una condición WHERE adicional
     */
    public function addWhere(string $condition, mixed $value = null): self
    {
        $this->where[] = $condition;
        if ($value !== null) {
            $this->params[] = $value;
        }
        $this->cachedWhereClause = null;
        return $this;
    }
    
    /**
     * Establece GROUP BY
     */
    public function setGroupBy(array $columns): self
    {
        $this->groupBy = $columns;
        return $this;
    }
    
    /**
     * Establece HAVING
     */
    public function setHaving(array $conditions, array $params = []): self
    {
        $this->having = $conditions;
        $this->params = array_merge($this->params, $params);
        return $this;
    }
    
    /**
     * Establece ORDER BY
     */
    public function setOrderBy(array $sort): self
    {
        $this->orderBy = $sort;
        return $this;
    }
    
    /**
     * Agrega una columna al ORDER BY
     */
    public function addOrderBy(string $column, string $direction = 'ASC'): self
    {
        $this->orderBy[$column] = $direction;
        return $this;
    }
    
    /**
     * Establece paginación
     */
    public function setPagination(int $page, int $perPage): self
    {
        $this->limit = $perPage;
        $this->offset = ($page - 1) * $perPage;
        return $this;
    }
    
    /**
     * Establece LIMIT directamente
     */
    public function setLimit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }
    
    /**
     * Establece OFFSET directamente
     */
    public function setOffset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }
    
    /**
     * Construye la cláusula SELECT
     */
    public function buildSelectClause(): string
    {
        if ($this->cachedSelectClause !== null) {
            return $this->cachedSelectClause;
        }
        
        if ($this->select === '*') {
            $this->cachedSelectClause = 'SELECT *';
        } else {
            $this->cachedSelectClause = 'SELECT ' . $this->select;
        }
        
        return $this->cachedSelectClause;
    }
    
    /**
     * Construye la cláusula FROM con JOINs
     */
    public function buildFromClause(): string
    {
        if ($this->cachedFromClause !== null) {
            return $this->cachedFromClause;
        }
        
        $sql = 'FROM ' . $this->from;
        
        foreach ($this->joins as $join) {
            $sql .= " {$join['type']} JOIN {$join['table']} AS {$join['alias']} ON {$join['on']}";
        }
        
        $this->cachedFromClause = $sql;
        return $sql;
    }
    
    /**
     * Construye la cláusula WHERE
     */
    public function buildWhereClause(): string
    {
        if ($this->cachedWhereClause !== null) {
            return $this->cachedWhereClause;
        }
        
        if (empty($this->where)) {
            return '';
        }
        
        // Manejar arrays anidados en WHERE (ej: ['column' => ['>' => 0]])
        $conditions = [];
        foreach ($this->where as $key => $value) {
            if (is_array($value)) {
                // Manejar operadores: ['column' => ['>' => 0, '<' => 100]]
                foreach ($value as $operator => $val) {
                    if (is_numeric($operator)) {
                        // Array indexado: ['column > ?', 0]
                        $conditions[] = $val;
                    } else {
                        // Array asociativo con operador: ['column' => ['>' => 0]]
                        $conditions[] = "`$key` $operator ?";
                    }
                }
            } elseif (is_numeric($key)) {
                // Condición directa: ['active = 1']
                $conditions[] = $value;
            } else {
                // Igualdad simple: ['active' => 1]
                $conditions[] = "`$key` = ?";
            }
        }
        
        $this->cachedWhereClause = 'WHERE ' . implode(' AND ', $conditions);
        return $this->cachedWhereClause;
    }
    
    /**
     * Construye la cláusula GROUP BY
     */
    public function buildGroupByClause(): string
    {
        if (empty($this->groupBy)) {
            return '';
        }
        
        return 'GROUP BY ' . implode(', ', $this->groupBy);
    }
    
    /**
     * Construye la cláusula HAVING
     */
    public function buildHavingClause(): string
    {
        if (empty($this->having)) {
            return '';
        }
        
        return 'HAVING ' . implode(' AND ', $this->having);
    }
    
    /**
     * Construye la cláusula ORDER BY
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
     * Construye la cláusula LIMIT/OFFSET
     */
    public function buildLimitClause(): string
    {
        return "LIMIT {$this->limit} OFFSET {$this->offset}";
    }
    
    /**
     * Construye la consulta SQL completa
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
     * Obtiene todos los parámetros
     */
    public function getParams(): array
    {
        return $this->params;
    }
    
    /**
     * Construye y retorna [sql, params]
     */
    public function build(): array
    {
        return [$this->buildSQL(), $this->getParams()];
    }
    
    /**
     * Convierte el objeto a array (para compatibilidad)
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
     * Crea desde un array (para compatibilidad inversa)
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
     * Reset completo
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
