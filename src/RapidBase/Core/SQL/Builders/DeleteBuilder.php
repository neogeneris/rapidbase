<?php

namespace RapidBase\Core\SQL\Builders;

use RapidBase\Core\SQL;

/**
 * Builder para consultas DELETE usando objetos en lugar de arrays.
 * 
 * Reemplaza el enfoque tradicional basado en arrays con una API orientada a objetos
 * para mayor claridad y type-safety.
 * 
 * @example
 * // Delete simple
 * $delete = new DeleteBuilder('users');
 * $delete->where(['id' => 1]);
 * [$sql, $params] = $delete->build();
 * 
 * // Delete con condición compleja
 * $delete->where([
 *     'status' => 'inactive',
 *     'created_at' => ['<' => '2023-01-01']
 * ]);
 */
class DeleteBuilder extends WhereTrait
{
    protected string $table;
    protected bool $force = false;
    
    /**
     * Constructor
     * 
     * @param string $table Nombre de la tabla
     */
    public function __construct(string $table)
    {
        $this->table = $table;
    }
    
    /**
     * Habilita delete masivo sin WHERE (peligroso)
     */
    public function force(bool $force = true): self
    {
        $this->force = $force;
        return $this;
    }
    
    /**
     * Construye la consulta SQL
     * 
     * @return array [sql, params]
     */
    public function build(): array
    {
        if (empty($this->where) && !$this->force) {
            throw new \RuntimeException("PELIGRO: DELETE masivo sin WHERE.");
        }
        
        $whereData = $this->buildWhereClause();
        $sql = "DELETE FROM " . SQL::quote($this->table) . " WHERE " . $whereData['sql'];
        
        return [$sql, $whereData['params']];
    }
    
    /**
     * Obtiene el nombre de la tabla
     */
    public function getTable(): string
    {
        return $this->table;
    }
    
    /**
     * Reset del builder
     */
    public function reset(): self
    {
        $this->where = [];
        $this->params = [];
        $this->force = false;
        return $this;
    }
}
