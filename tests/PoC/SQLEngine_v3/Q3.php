<?php

namespace RapidBase\PoC\SQLEngine_v3;

/**
 * Query Builder - Máximo 3 eslabones: from() -> configure() -> exec()
 * Versión intermedia con método configure() para parámetros adicionales
 */
class Q3 {
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
     */
    public static function from(string|array $table, array $filter = []): self {
        $instance = new self();
        $instance->state[self::T] = $table;
        $instance->state[self::F] = $filter;
        $instance->state[self::O] = [];
        $instance->state[self::L] = [];
        $instance->state[self::G] = [];
        $instance->state[self::H] = [];
        $instance->state[self::J] = [];
        $instance->state[self::S] = '*';
        return $instance;
    }
    
    /**
     * Configura parámetros adicionales - SEGUNDO ESLABÓN (OPCIONAL)
     */
    public function configure(array $orderBy = [], array $limit = [], 
                              array $groupBy = [], array $having = []): self {
        if ($orderBy) $this->state[self::O] = $orderBy;
        if ($limit) $this->state[self::L] = $limit;
        if ($groupBy) $this->state[self::G] = $groupBy;
        if ($having) $this->state[self::H] = $having;
        return $this;
    }
    
    /**
     * Ejecuta y genera SQL - TERCER ESLABÓN (FINAL)
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
     * SELECT - Método helper
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
        
        $sql = sprintf('SELECT %s FROM "%s"', $this->state[self::S], $tableName);
        
        [$whereSql, $whereParams] = $this->buildWhere();
        if ($whereSql) {
            $sql .= ' WHERE ' . $whereSql;
            $params = array_merge($params, $whereParams);
        }
        
        if (!empty($this->state[self::G])) {
            $sql .= ' GROUP BY ' . implode(', ', $this->state[self::G]);
        }
        
        if (!empty($this->state[self::H])) {
            [$havingSql, $havingParams] = $this->buildWhere($this->state[self::H], 'HAVING');
            if ($havingSql) {
                $sql .= ' ' . $havingSql;
                $params = array_merge($params, $havingParams);
            }
        }
        
        if (!empty($this->state[self::O])) {
            $sql .= ' ORDER BY ' . $this->buildOrder();
        }
        
        if (!empty($this->state[self::L])) {
            $sql .= ' LIMIT ' . $this->buildLimit();
            $params = array_merge($params, $this->state[self::L]);
        }
        
        return [$sql, $params];
    }
    
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
    
    private function buildWhere(array $filter = null, string $prefix = 'WHERE'): array {
        $filter = $filter ?? $this->state[self::F];
        if (empty($filter)) {
            return ['', []];
        }
        
        $conditions = [];
        $params = [];
        
        foreach ($filter as $field => $value) {
            if (is_array($value)) {
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
    
    private function buildLimit(): string {
        $limit = $this->state[self::L];
        if (count($limit) === 2) {
            return '? OFFSET ?';
        }
        return '?';
    }
}
