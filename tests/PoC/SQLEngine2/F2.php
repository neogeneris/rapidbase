<?php

namespace RapidBase\SQLEngine2;

/**
 * F2 (Finalizer 2) - Finaliza la cadena en SQLEngine2.
 * 
 * Equivalente a EF pero trabajando con B2 que tiene JoinManager integrado.
 */
class F2
{
    protected array $state = [];
    
    private const T_FRO = 0;
    private const T_TYP = 1;
    private const T_WHR = 2;
    private const T_PRM = 3;
    private const T_SEL = 4;
    private const T_ORD = 5;
    private const T_LIM = 6;
    private const T_OFF = 7;
    private const T_GRP = 8;
    private const T_HAV = 9;
    private const T_JON = 10;
    private const T_ALI = 11;
    
    public function __construct(array $state = [])
    {
        $this->state = $state;
    }
    
    /**
     * Crea una instancia F2 desde una instancia B2.
     */
    public static function fromBuilder(B2 $builder): self
    {
        return new self($builder->getState());
    }
    
    /**
     * Ejecuta SELECT y retorna [sql, params].
     */
    public function select($fields = '*'): array
    {
        if ($fields !== '*') {
            $this->state[self::T_SEL] = is_array($fields) ? implode(', ', $fields) : $fields;
        }
        
        return $this->buildSelect();
    }
    
    /**
     * Ejecuta DELETE y retorna [sql, params].
     */
    public function delete(): array
    {
        $table = $this->state[self::T_FRO];
        if ($this->state[self::T_TYP] === 'list') {
            $parts = explode(',', $table);
            $table = trim($parts[0]);
        }
        
        $whereSql = $this->state[self::T_WHR];
        $params = $this->state[self::T_PRM];
        
        $sql = "DELETE FROM {$table}";
        if ($whereSql) {
            $sql .= " WHERE " . $whereSql;
        }
        
        return [$sql, $params];
    }
    
    /**
     * Ejecuta UPDATE y retorna [sql, params].
     */
    public function update(array $data): array
    {
        $setParts = [];
        $params = $this->state[self::T_PRM];
        
        foreach ($data as $col => $val) {
            $setParts[] = "{$col} = ?";
            $params[] = $val;
        }
        
        $table = $this->state[self::T_FRO];
        if ($this->state[self::T_TYP] === 'list') {
            $parts = explode(',', $table);
            $table = trim($parts[0]);
        }
        
        $whereSql = $this->state[self::T_WHR];
        
        $sql = "UPDATE {$table} SET " . implode(', ', $setParts);
        if ($whereSql) {
            $sql .= " WHERE " . $whereSql;
        }
        
        return [$sql, $params];
    }
    
    /**
     * Ejecuta COUNT y retorna [sql, params].
     */
    public function count($field = '*'): array
    {
        $this->state[self::T_SEL] = 'COUNT(*) as total';
        return $this->buildSelect();
    }
    
    /**
     * Ejecuta EXISTS y retorna [sql, params].
     */
    public function exists(): array
    {
        $table = $this->state[self::T_FRO];
        $joins = $this->state[self::T_JON];
        $whereSql = $this->state[self::T_WHR];
        $params = $this->state[self::T_PRM];
        
        $sql = "SELECT EXISTS(SELECT 1 FROM {$table}";
        if (!empty($joins)) {
            $sql .= " " . $joins;
        }
        if (!empty($whereSql)) {
            $sql .= " WHERE " . $whereSql;
        }
        $sql .= ") as exists_flag";
        
        return [$sql, $params];
    }
    
    /**
     * Construye la consulta SELECT final.
     */
    private function buildSelect(): array
    {
        $sql = "SELECT {$this->state[self::T_SEL]} FROM {$this->state[self::T_FRO]}";
        
        if (!empty($this->state[self::T_JON])) {
            $sql .= " " . $this->state[self::T_JON];
        }
        
        $params = $this->state[self::T_PRM];
        
        if (!empty($this->state[self::T_WHR])) {
            $sql .= " WHERE " . $this->state[self::T_WHR];
        }
        
        if ($this->state[self::T_ORD]) {
            $sql .= " ORDER BY " . $this->state[self::T_ORD];
        }
        
        if (!empty($this->state[self::T_GRP])) {
            $sql .= " GROUP BY " . $this->state[self::T_GRP];
        }
        
        if (!empty($this->state[self::T_HAV])) {
            $sql .= " HAVING " . $this->state[self::T_HAV];
        }
        
        if ($this->state[self::T_LIM] !== null) {
            $sql .= " LIMIT ?";
            $params[] = $this->state[self::T_LIM];
        }
        
        if ($this->state[self::T_OFF] !== null) {
            $sql .= " OFFSET ?";
            $params[] = $this->state[self::T_OFF];
        }
        
        return [$sql, $params];
    }
}
