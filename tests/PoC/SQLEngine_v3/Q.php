<?php

namespace RapidBase\PoC\SQLEngine_v3;

/**
 * Query Builder optimizado - Máximo 2 eslabones: from() -> exec()
 * Usa array numérico (pila) con constantes cortas para máximo rendimiento
 */
class Q {
    // Constantes cortas para índices del array estado
    const T = 0;  // Table
    const F = 1;  // Filter/Where
    const J = 2;  // Joins
    const O = 3;  // Order
    const L = 4;  // Limit [offset, limit]
    const G = 5;  // Group
    const H = 6;  // Having
    const S = 7;  // Select fields
    
    /** @var array Estado de la consulta como pila numérica */
    private array $state = [];
    
    /**
     * Inicia la consulta - PRIMER ESLABÓN
     * Configura tabla, filtro y opcionalmente todos los parámetros
     */
    public static function from(string|array $table, array $filter = [], 
                                array $orderBy = [], array $limit = [], 
                                array $groupBy = [], array $having = []): self 
    {
        $instance = new self();
        $instance->state[self::T] = $table;
        $instance->state[self::F] = $filter;
        $instance->state[self::O] = $orderBy;
        $instance->state[self::L] = $limit;
        $instance->state[self::G] = $groupBy;
        $instance->state[self::H] = $having;
        $instance->state[self::J] = [];
        $instance->state[self::S] = '*';
        return $instance;
    }
    
    /**
     * Ejecuta y genera SQL - SEGUNDO ESLABÓN (FINAL)
     * @param string $type Tipo de consulta: select, delete, update, count, exists
     * @param array $data Datos para update o insert
     * @return array [sql, params]
     */
    public function exec(string $type = 'select', array $data = []): array {
        return match($type) {
            'select' => $this->compileSelect(),
            'delete' => $this->compileDelete(),
            'update' => $this->compileUpdate($data),
            'count'  => $this->compileCount(),
            'exists' => $this->compileExists(),
            default => throw new \InvalidArgumentException("Tipo '$type' no soportado")
        };
    }
    
    /**
     * SELECT - Método helper para mantener compatibilidad
     */
    public function select(string $fields = '*'): array {
        $this->state[self::S] = $fields;
        return $this->exec('select');
    }
    
    /**
     * Compilar SELECT usando plantillas sprintf
     */
    private function compileSelect(): array {
        $params = [];
        $table = $this->state[self::T];
        $tableName = is_array($table) ? implode(', ', $table) : $table;
        
        // Plantilla base
        $sql = sprintf('SELECT %s FROM "%s"', $this->state[self::S], $tableName);
        
        // WHERE
        [$whereSql, $whereParams] = $this->buildWhere();
        if ($whereSql) {
            $sql .= ' WHERE ' . $whereSql;
            $params = array_merge($params, $whereParams);
        }
        
        // GROUP BY
        if (!empty($this->state[self::G])) {
            $sql .= ' GROUP BY ' . implode(', ', $this->state[self::G]);
        }
        
        // HAVING
        if (!empty($this->state[self::H])) {
            [$havingSql, $havingParams] = $this->buildWhere($this->state[self::H], 'HAVING');
            if ($havingSql) {
                $sql .= ' ' . $havingSql;
                $params = array_merge($params, $havingParams);
            }
        }
        
        // ORDER BY
        if (!empty($this->state[self::O])) {
            $sql .= ' ORDER BY ' . $this->buildOrder();
        }
        
        // LIMIT
        if (!empty($this->state[self::L])) {
            $sql .= ' LIMIT ' . $this->buildLimit();
            $params = array_merge($params, $this->state[self::L]);
        }
        
        return [$sql, $params];
    }
    
    /**
     * Compilar DELETE
     */
    private function compileDelete(): array {
        $params = [];
        $table = is_array($this->state[self::T]) ? $this->state[self::T][0] : $this->state[self::T];
        
        $sql = sprintf('DELETE FROM "%s"', $table);
        
        [$whereSql, $whereParams] = $this->buildWhere();
        if ($whereSql) {
            $sql .= ' WHERE ' . $whereSql;
            $params = array_merge($params, $whereParams);
        }
        
        return [$sql, $params];
    }
    
    /**
     * Compilar UPDATE
     */
    private function compileUpdate(array $data): array {
        $params = [];
        $table = is_array($this->state[self::T]) ? $this->state[self::T][0] : $this->state[self::T];
        
        $setCols = array_keys($data);
        $setPlaceholders = str_repeat('?, ', count($data) - 1) . '?';
        $sql = sprintf('UPDATE "%s" SET %s = (%s)', $table, implode(', ', $setCols), $setPlaceholders);
        $params = array_values($data);
        
        [$whereSql, $whereParams] = $this->buildWhere();
        if ($whereSql) {
            $sql .= ' WHERE ' . $whereSql;
            $params = array_merge($params, $whereParams);
        }
        
        return [$sql, $params];
    }
    
    /**
     * Compilar COUNT
     */
    private function compileCount(): array {
        $params = [];
        $table = $this->state[self::T];
        $tableName = is_array($table) ? implode(', ', $table) : $table;
        
        $sql = sprintf('SELECT COUNT(*) as total FROM "%s"', $tableName);
        
        [$whereSql, $whereParams] = $this->buildWhere();
        if ($whereSql) {
            $sql .= ' WHERE ' . $whereSql;
            $params = array_merge($params, $whereParams);
        }
        
        return [$sql, $params];
    }
    
    /**
     * Compilar EXISTS
     */
    private function compileExists(): array {
        $params = [];
        $table = $this->state[self::T];
        $tableName = is_array($table) ? implode(', ', $table) : $table;
        
        [$whereSql, $whereParams] = $this->buildWhere();
        $whereClause = $whereSql ? ' WHERE ' . $whereSql : '';
        $params = array_merge($params, $whereParams);
        
        $sql = sprintf('SELECT EXISTS(SELECT 1 FROM "%s"%s) as exists_flag', $tableName, $whereClause);
        
        return [$sql, $params];
    }
    
    /**
     * Construir cláusula WHERE
     */
    private function buildWhere(array $filter = null, string $prefix = 'WHERE'): array {
        $filter = $filter ?? $this->state[self::F];
        if (empty($filter)) {
            return ['', []];
        }
        
        $conditions = [];
        $params = [];
        
        foreach ($filter as $field => $value) {
            if (is_array($value)) {
                // Operadores: ['>' => 10], ['IN' => [1,2,3]]
                foreach ($value as $op => $val) {
                    if ($op === 'IN') {
                        $placeholders = implode(',', array_fill(0, count($val), '?'));
                        $conditions[] = "$field IN ($placeholders)";
                        $params = array_merge($params, $val);
                    } else {
                        $conditions[] = "$field $op ?";
                        $params[] = $val;
                    }
                }
            } else {
                $conditions[] = "$field = ?";
                $params[] = $value;
            }
        }
        
        return [$prefix . ' ' . implode(' AND ', $conditions), $params];
    }
    
    /**
     * Construir ORDER BY
     */
    private function buildOrder(): string {
        $parts = [];
        foreach ($this->state[self::O] as $field) {
            if ($field[0] === '-') {
                $parts[] = substr($field, 1) . ' DESC';
            } else {
                $parts[] = $field . ' ASC';
            }
        }
        return implode(', ', $parts);
    }
    
    /**
     * Construir LIMIT
     */
    private function buildLimit(): string {
        $limit = $this->state[self::L];
        if (count($limit) === 2) {
            return '? OFFSET ?';
        }
        return '?';
    }
}
