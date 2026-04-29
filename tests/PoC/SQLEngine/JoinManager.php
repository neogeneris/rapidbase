<?php

namespace RapidBase\SQLEngine;

/**
 * JoinManager Base - Estrategia determinista pura.
 * 
 * Los joins se definen explícitamente sin auto-detección.
 * Máximo rendimiento, cero overhead de análisis.
 */
class JoinManager
{
    protected array $joins = [];
    protected array $aliases = [];
    
    /**
     * Agrega un JOIN explícito.
     */
    public function addJoin(string $type, string $table, string $onCondition): self
    {
        $this->joins[] = [
            'type' => strtoupper($type),
            'table' => $table,
            'on' => $onCondition
        ];
        return $this;
    }
    
    /**
     * Construye la cláusula JOIN final.
     */
    public function buildJoinSQL(): string
    {
        if (empty($this->joins)) {
            return '';
        }
        
        $parts = [];
        foreach ($this->joins as $join) {
            $parts[] = "{$join['type']} JOIN {$join['table']} ON {$join['on']}";
        }
        
        return implode(' ', $parts);
    }
    
    /**
     * Obtiene los aliases definidos.
     */
    public function getAliases(): array
    {
        return $this->aliases;
    }
    
    /**
     * Limpia todos los joins.
     */
    public function clear(): self
    {
        $this->joins = [];
        $this->aliases = [];
        return $this;
    }
}
