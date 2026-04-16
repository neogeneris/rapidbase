<?php

namespace RapidBase\Core\SQL\Builders;

use RapidBase\Core\SQL;

/**
 * Builder para consultas UPDATE usando objetos en lugar de arrays.
 * 
 * Reemplaza el enfoque tradicional basado en arrays con una API orientada a objetos
 * para mayor claridad y type-safety.
 * 
 * @example
 * // Update simple
 * $update = new UpdateBuilder('users');
 * $update->set(['name' => 'John', 'email' => 'john@example.com']);
 * $update->where(['id' => 1]);
 * [$sql, $params] = $update->build();
 * 
 * // Update con condición compleja
 * $update->where([
 *     'status' => 'active',
 *     'created_at' => ['>' => '2023-01-01']
 * ]);
 */
class UpdateBuilder extends WhereTrait
{
    protected string $table;
    protected array $data = [];
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
     * Establece los valores a actualizar
     * 
     * @param array $data Array asociativo de columnas y valores
     * @return self
     */
    public function set(array $data): self
    {
        $this->data = array_merge($this->data, $data);
        return $this;
    }
    
    /**
     * Establece un valor específico
     * 
     * @param string $column Nombre de la columna
     * @param mixed $value Valor a actualizar
     * @return self
     */
    public function setValue(string $column, mixed $value): self
    {
        $this->data[$column] = $value;
        return $this;
    }
    
    /**
     * Habilita update masivo sin WHERE (peligroso)
     */
    public function force(bool $force = true): self
    {
        $this->force = $force;
        return $this;
    }
    
    /**
     * Normaliza un valor (convierte strings vacíos a null)
     */
    protected function normalizeValue(mixed $value): mixed
    {
        if ($value === '' || $value === "\0") {
            return null;
        }
        return $value;
    }
    
    /**
     * Construye la consulta SQL
     * 
     * @return array [sql, params]
     */
    public function build(): array
    {
        if (empty($this->data)) {
            throw new \InvalidArgumentException("No se pueden actualizar registros sin datos.");
        }
        
        if (empty($this->where) && !$this->force) {
            throw new \RuntimeException("PELIGRO: UPDATE masivo sin WHERE en [{$this->table}].");
        }
        
        $parts = ["UPDATE " . SQL::quote($this->table), "SET"];
        $setParts = [];
        $params = [];
        
        foreach ($this->data as $col => $val) {
            $token = SQL::nextTokenPublic();
            $setParts[] = SQL::quote($col) . " = :$token";
            $params[$token] = $this->normalizeValue($val);
        }
        
        $parts[] = implode(', ', $setParts);
        
        $whereData = $this->buildWhereClause();
        $parts[] = "WHERE " . $whereData['sql'];
        
        $sql = implode(' ', $parts);
        
        return [$sql, array_merge($params, $whereData['params'])];
    }
    
    /**
     * Obtiene el nombre de la tabla
     */
    public function getTable(): string
    {
        return $this->table;
    }
    
    /**
     * Obtiene los datos a actualizar
     */
    public function getData(): array
    {
        return $this->data;
    }
    
    /**
     * Reset del builder
     */
    public function reset(): self
    {
        $this->data = [];
        $this->where = [];
        $this->params = [];
        $this->force = false;
        return $this;
    }
}
