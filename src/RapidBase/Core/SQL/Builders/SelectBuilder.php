<?php

namespace RapidBase\Core;

use RapidBase\Core\SQL\Builders\Field;
use RapidBase\Core\SQL\Builders\Table;
use RapidBase\Core\SQL\Builders\Join;

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
    public string|array|Field $select = '*';
    public string|Table $from = '';
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
    
    // Soporte para auto-join
    private bool $autoJoinEnabled = false;
    private array $tablesForAutoJoin = [];
    
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
     * 
     * Soporta:
     * - String: '*', 'name', 'COUNT(*)'
     * - Array de strings: ['name', 'email']
     * - Array de objetos Field: [new Field('name', 'n'), new Field('email', 'e')]
     * - Objeto Field: new Field('name', 'n')
     */
    public function setSelect(string|array|Field $fields): self
    {
        if ($fields instanceof Field) {
            // Single Field object
            $this->select = $fields;
        } elseif ($fields === '*') {
            $this->select = '*';
        } elseif (is_array($fields)) {
            // Check if it's an array of Field objects
            $hasFieldObjects = false;
            foreach ($fields as $f) {
                if ($f instanceof Field) {
                    $hasFieldObjects = true;
                    break;
                }
            }
            
            if ($hasFieldObjects) {
                $this->select = $fields;
            } else {
                // Array of strings
                $this->select = implode(', ', $fields);
            }
        } else {
            $this->select = $fields;
        }
        $this->cachedSelectClause = null;
        return $this;
    }
    
    /**
     * Agrega un campo al SELECT
     * 
     * @param string|Field $field Nombre del campo o objeto Field
     * @return self
     */
    public function addField(string|Field $field): self
    {
        if ($this->select === '*') {
            $this->select = [$field];
        } elseif (is_string($this->select)) {
            $this->select = [$this->select, $field];
        } elseif (is_array($this->select)) {
            $this->select[] = $field;
        }
        $this->cachedSelectClause = null;
        return $this;
    }
    
    /**
     * Establece la tabla principal FROM con soporte para auto-join
     * 
     * Soporta:
     * - String: 'users'
     * - Array de strings: ['users', 'posts'] (para auto-join)
     * - Objeto Table: new Table('users', 'u')
     * - Array asociativo: ['users' => 'u'] (alias)
     */
    public function setFrom(string|array|Table $table): self
    {
        $this->cachedFromClause = null;
        
        if ($table instanceof Table) {
            // Table object
            $this->from = $table;
            $this->autoJoinEnabled = false;
            $this->tablesForAutoJoin = [];
        } elseif (is_array($table)) {
            // Verificar si es un array de tablas para auto-join: ['products', 'categories']
            if (isset($table[0]) && is_string($table[0])) {
                // Array indexado de tablas para auto-join
                $this->tablesForAutoJoin = $table;
                $this->autoJoinEnabled = true;
                $this->from = $table[0]; // La primera tabla será la raíz
            } else {
                // Array asociativo para aliases: ['products' => 'u'] -> FROM products u
                $tableName = key($table);
                $alias = current($table);
                $this->from = new Table($tableName, $alias);
                $this->autoJoinEnabled = false;
                $this->tablesForAutoJoin = [];
            }
        } else {
            // String simple: 'users'
            $this->from = $table;
            $this->autoJoinEnabled = false;
            $this->tablesForAutoJoin = [];
        }
        
        return $this;
    }
    
    /**
     * Agrega una tabla al FROM (para auto-join)
     * 
     * @param string|Table $table Nombre de la tabla o objeto Table
     * @return self
     */
    public function addTable(string|Table $table): self
    {
        if (!is_array($this->from)) {
            $this->from = [$this->from];
        }
        
        if ($table instanceof Table) {
            $this->from[] = $table->name . ' AS ' . $table->alias;
        } else {
            $this->from[] = $table;
        }
        
        $this->cachedFromClause = null;
        return $this;
    }
    
    /**
     * Agrega un JOIN
     * 
     * Soporta:
     * - Parámetros individuales: addJoin('INNER', 'posts', 'p', 'u.id = p.user_id')
     * - Objeto Join: addJoin(new Join('INNER', 'posts', 'p', 'u.id = p.user_id'))
     */
    public function addJoin(string|Join $type, string $table = '', string $alias = '', string $on = '', array $params = []): self
    {
        if ($type instanceof Join) {
            // Join object
            $this->joins[] = $type;
        } else {
            // Traditional parameters
            $this->joins[] = [
                'type' => $type,
                'table' => $table,
                'alias' => $alias,
                'on' => $on,
                'params' => $params
            ];
        }
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
        } elseif ($this->select instanceof Field) {
            // Single Field object
            $this->cachedSelectClause = 'SELECT ' . $this->select->toSql([SQL::class, 'quote']);
        } elseif (is_array($this->select)) {
            // Array of fields
            $fields = [];
            foreach ($this->select as $field) {
                if ($field instanceof Field) {
                    $fields[] = $field->toSql([SQL::class, 'quote']);
                } else {
                    // String field
                    $fields[] = SQL::quoteField($field);
                }
            }
            $this->cachedSelectClause = 'SELECT ' . implode(', ', $fields);
        } else {
            // String select
            $this->cachedSelectClause = 'SELECT ' . $this->select;
        }
        
        return $this->cachedSelectClause;
    }
    
    /**
     * Construye la cláusula FROM con JOINs (soporta auto-join)
     */
    public function buildFromClause(): string
    {
        if ($this->cachedFromClause !== null && !$this->autoJoinEnabled) {
            return $this->cachedFromClause;
        }
        
        // Si hay auto-join habilitado, construir JOINs automáticamente
        if ($this->autoJoinEnabled && !empty($this->tablesForAutoJoin)) {
            $sql = $this->buildAutoJoinClause();
            $this->cachedFromClause = $sql;
            return $sql;
        }
        
        // Modo manual tradicional o con objetos
        $sql = 'FROM ';
        
        if ($this->from instanceof Table) {
            // Table object
            $sql .= $this->from->toSql([SQL::class, 'quote']);
        } elseif (is_string($this->from)) {
            // String
            $sql .= $this->from;
        } else {
            // Array (fallback)
            $sql .= is_array($this->from) ? implode(', ', $this->from) : '';
        }
        
        foreach ($this->joins as $join) {
            if ($join instanceof Join) {
                // Join object
                $sql .= ' ' . $join->toSql([SQL::class, 'quote']);
            } else {
                // Traditional array format
                $sql .= " {$join['type']} JOIN {$join['table']} AS {$join['alias']} ON {$join['on']}";
            }
        }
        
        $this->cachedFromClause = $sql;
        return $sql;
    }
    
    /**
     * Construye cláusula FROM con auto-join usando el algoritmo de SQL.php
     */
    private function buildAutoJoinClause(): string
    {
        // Delegar a SQL::buildFromWithMap para usar el algoritmo optimizado
        // Esto preserva la funcionalidad de orderTablesByWeakness y buildJoinTree
        $tables = $this->tablesForAutoJoin;
        
        // Convertir formato: ['products', 'categories'] a formato esperado por buildFromWithMap
        $formattedTables = [];
        foreach ($tables as $t) {
            if (is_array($t)) {
                // ['products' => 'p'] -> 'products AS p'
                $real = key($t);
                $alias = current($t);
                $formattedTables[] = "$real AS $alias";
            } else {
                // 'products'
                $formattedTables[] = $t;
            }
        }
        
        try {
            // Usar el método público de SQL que maneja auto-join
            $fromClause = SQL::buildFromWithMap($formattedTables);
            return $fromClause;
        } catch (\Exception $e) {
            // Fallback a modo manual si falla el auto-join
            $sql = 'FROM ' . $this->from;
            foreach ($this->joins as $join) {
                $sql .= " {$join['type']} JOIN {$join['table']} AS {$join['alias']} ON {$join['on']}";
            }
            return $sql;
        }
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
                        $this->params[] = $val;
                    }
                }
            } elseif (is_numeric($key)) {
                // Condición directa: ['active = 1']
                $conditions[] = $value;
            } else {
                // Igualdad simple: ['active' => 1]
                $conditions[] = "`$key` = ?";
                $this->params[] = $value;
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
