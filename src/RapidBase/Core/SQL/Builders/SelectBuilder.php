<?php

namespace RapidBase\Core\SQL\Builders;

use RapidBase\Core\SQL;

/**
 * Clase que reemplaza el array $parts tradicional en buildSelect().
 * 
 * Esta clase actúa como un esqueleto estructurado con las mismas propiedades
 * que tenía el array $parts, pero con acceso tipado y potencialmente más rápido.
 * 
 * Objetivo: Reemplazar $parts['select'] = '*' por $this->select = '*'
 * manteniendo la misma lógica de ensamblaje pero con mejor performance.
 */
class SelectBuilder
{
    // Propiedades públicas que reemplazan los índices del array $parts
    public mixed $select = '*';      // reemplaza $parts['select']
    public mixed $from = '';         // reemplaza $parts['from']
    public array $join = [];         // reemplaza $parts['join']
    public array $where = [];        // reemplaza $parts['where']
    public array $params = [];       // reemplaza $parts['params']
    public array $groupBy = [];      // reemplaza $parts['groupBy']
    public array $having = [];       // reemplaza $parts['having']
    public array $orderBy = [];      // reemplaza $parts['orderBy']
    public int $limit = 10;          // reemplaza $parts['limit']
    public int $offset = 0;          // reemplaza $parts['offset']
    public bool $count = false;      // reemplaza $parts['count']
    public array $map = [];          // reemplaza $parts['map']
    public bool $noQuote = false;    // reemplaza $parts['noQuote']
    
    // Cache interna opcional para cláusulas construidas
    private ?string $cachedSelectClause = null;
    private ?string $cachedFromClause = null;
    private ?string $cachedWhereClause = null;
    
    /**
     * Constructor vacío - la clase actúa como contenedor de propiedades
     * Los valores se asignan directamente: $builder->select = '*', $builder->from = 'users'
     */
    public function __construct()
    {
        // Inicialización por defecto ya está en las propiedades
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
        } elseif (is_array($this->select)) {
            // Array de campos o Field objects
            $fields = [];
            foreach ($this->select as $field) {
                if ($field instanceof Field) {
                    $fields[] = $field->toSql([SQL::class, 'quote']);
                } elseif (is_array($field)) {
                    // Formato antiguo: ['campo', 'alias']
                    $fields[] = SQL::quoteField($field[0]) . ' AS ' . SQL::quoteField($field[1]);
                } else {
                    $fields[] = SQL::quoteField($field);
                }
            }
            $this->cachedSelectClause = 'SELECT ' . implode(', ', $fields);
        } else {
            // String o expresión
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
        
        $sql = 'FROM ';
        
        if ($this->from instanceof Table) {
            $sql .= $this->from->toSql([SQL::class, 'quote']);
        } elseif (is_string($this->from)) {
            // Soporte para formato antiguo: tabla como string simple
            $sql .= SQL::quoteField($this->from);
        } elseif (is_array($this->from)) {
            // Formato antiguo: [['tabla', 'alias']] o ['tabla']
            if (isset($this->from[0]) && is_array($this->from[0])) {
                // Array de tablas con alias: [['users', 'u'], ['orders', 'o']]
                $tables = [];
                foreach ($this->from as $t) {
                    if (is_array($t) && isset($t[1])) {
                        $tables[] = SQL::quoteField($t[0]) . ' AS ' . SQL::quoteField($t[1]);
                    } else {
                        $tables[] = SQL::quoteField($t);
                    }
                }
                $sql .= implode(', ', $tables);
            } elseif (isset($this->from[1])) {
                // Una sola tabla con alias: ['users', 'u']
                $sql .= SQL::quoteField($this->from[0]) . ' AS ' . SQL::quoteField($this->from[1]);
            } else {
                $sql .= SQL::quoteField($this->from[0]);
            }
        }
        
        // Procesar joins
        foreach ($this->join as $j) {
            if ($j instanceof Join) {
                $sql .= ' ' . $j->toSql([SQL::class, 'quote']);
            } elseif (is_array($j)) {
                // Formato antiguo de join: ['type' => 'LEFT', 'table' => 'users', 'alias' => 'u', 'on' => '...']
                $type = strtoupper($j['type'] ?? 'LEFT');
                $table = is_array($j['table']) ? SQL::quoteField($j['table'][0]) . ' AS ' . SQL::quoteField($j['table'][1]) : SQL::quoteField($j['table']);
                $alias = isset($j['alias']) ? ' AS ' . SQL::quoteField($j['alias']) : '';
                $on = isset($j['on']) ? " ON {$j['on']}" : '';
                $sql .= " {$type} JOIN {$table}{$alias}{$on}";
            }
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
            $this->cachedWhereClause = '';
            return '';
        }
        
        // Delegar a SQL::buildWhere para mantener compatibilidad
        $whereData = SQL::buildWhere($this->where, $this->params, $this->noQuote);
        $whereClause = is_array($whereData) ? ($whereData['sql'] ?? '') : $whereData;
        $this->cachedWhereClause = $whereClause;
        return $whereClause;
    }
    
    /**
     * Método mágico para acceso dinámico (opcional, para compatibilidad)
     */
    public function __get(string $name): mixed
    {
        return $this->$name ?? null;
    }
    
    /**
     * Método mágico para asignación dinámica (opcional, para compatibilidad)
     */
    public function __set(string $name, mixed $value): void
    {
        $this->$name = $value;
        // Invalidar caches cuando cambian las propiedades
        if (in_array($name, ['select', 'from', 'join'])) {
            $this->cachedSelectClause = null;
            $this->cachedFromClause = null;
        }
        if ($name === 'where') {
            $this->cachedWhereClause = null;
        }
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
        
        // Manejar arrays anidados en HAVING (ej: ['total' => ['>' => 5]])
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
     * Alias de buildSQL() para compatibilidad con SQL::buildSelect
     * Retorna solo el SQL construido
     */
    public function toSql(): string
    {
        return $this->buildSQL();
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
