<?php

namespace RapidBase\Core\SQL\Builders;

use RapidBase\Core\SQL;

/**
 * Builder para consultas INSERT usando objetos en lugar de arrays.
 * 
 * Reemplaza el enfoque tradicional basado en arrays con una API orientada a objetos
 * para mayor claridad y type-safety.
 * 
 * @example
 * // Insert simple
 * $insert = new InsertBuilder('users');
 * $insert->values(['name' => 'John', 'email' => 'john@example.com']);
 * [$sql, $params] = $insert->build();
 * 
 * // Insert múltiple
 * $insert->values([
 *     ['name' => 'John', 'email' => 'john@example.com'],
 *     ['name' => 'Jane', 'email' => 'jane@example.com']
 * ]);
 */
class InsertBuilder
{
    protected string $table;
    protected array $rows = [];
    protected array $columns = [];
    
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
     * Establece los valores a insertar
     * 
     * @param array $rows Array de filas (asociativo o lista de arrays asociativos)
     * @return self
     */
    public function values(array $rows): self
    {
        // Detectar si es un solo registro o múltiples
        if (!isset($rows[0]) || !is_array($rows[0])) {
            // Single row: ['name' => 'John']
            $this->rows = [$rows];
            $this->columns = array_keys($rows);
        } else {
            // Multiple rows: [['name' => 'John'], ['name' => 'Jane']]
            $this->rows = $rows;
            // Usar las columnas de la primera fila como referencia
            $this->columns = array_keys($rows[0] ?? []);
        }
        
        return $this;
    }
    
    /**
     * Agrega una columna específica con su valor
     * 
     * @param string $column Nombre de la columna
     * @param mixed $value Valor a insertar
     * @return self
     */
    public function value(string $column, mixed $value): self
    {
        if (empty($this->rows)) {
            $this->rows = [[]];
        }
        
        $this->rows[0][$column] = $value;
        $this->columns = array_unique(array_merge($this->columns, [$column]));
        
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
        if (empty($this->rows)) {
            throw new \InvalidArgumentException("No se pueden insertar registros vacíos.");
        }
        
        if (empty($this->rows[0])) {
            throw new \InvalidArgumentException("El registro de datos está vacío.");
        }
        
        $columns = $this->columns;
        $quotedCols = implode(', ', array_map([SQL::class, 'quote'], $columns));
        $placeholders = [];
        $params = [];
        
        // Solo construimos para la primera fila (INSERT single)
        foreach ($columns as $col) {
            $token = SQL::nextTokenPublic();
            $value = $this->normalizeValue($this->rows[0][$col]);
            $placeholders[] = ":$token";
            $params[$token] = $value;
        }
        
        $sql = "INSERT INTO " . SQL::quote($this->table) . " ($quotedCols) VALUES (" . implode(', ', $placeholders) . ")";
        
        return [$sql, $params];
    }
    
    /**
     * Obtiene el nombre de la tabla
     */
    public function getTable(): string
    {
        return $this->table;
    }
    
    /**
     * Obtiene las filas a insertar
     */
    public function getRows(): array
    {
        return $this->rows;
    }
    
    /**
     * Obtiene las columnas
     */
    public function getColumns(): array
    {
        return $this->columns;
    }
    
    /**
     * Reset del builder
     */
    public function reset(): self
    {
        $this->rows = [];
        $this->columns = [];
        return $this;
    }
}
