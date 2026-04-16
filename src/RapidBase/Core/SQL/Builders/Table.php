<?php

namespace RapidBase\Core\SQL\Builders;

/**
 * Clase para representar una tabla con alias en consultas SQL.
 * 
 * Permite especificar de forma precisa la sintaxis de tablas con alias,
 * esencial para JOINs y consultas complejas.
 * 
 * @example
 * // Sin alias
 * new Table('users')           // -> FROM `users`
 * 
 * // Con alias
 * new Table('users', 'u')      // -> FROM `users` AS `u`
 */
class Table
{
    public string $name;
    public ?string $alias;
    
    /**
     * Constructor
     * 
     * @param string $name Nombre real de la tabla
     * @param string|null $alias Alias opcional para la tabla
     */
    public function __construct(string $name, ?string $alias = null)
    {
        $this->name = $name;
        $this->alias = $alias;
    }
    
    /**
     * Construye la representación SQL de la tabla
     * 
     * @param callable $quoteFunc Función para quoteear identificadores
     * @return string La tabla formateada para SQL
     */
    public function toSql(callable $quoteFunc): string
    {
        if ($this->alias !== null && $this->alias !== $this->name) {
            return $quoteFunc($this->name) . ' AS ' . $quoteFunc($this->alias);
        }
        return $quoteFunc($this->name);
    }
    
    /**
     * Crea un Table desde un string con sintaxis AS
     * 
     * @param string $tableString Tabla en formato 'table AS alias'
     * @return self
     */
    public static function fromString(string $tableString): self
    {
        if (preg_match('/^(\S+)\s+AS\s+(\S+)$/i', trim($tableString), $matches)) {
            return new self(trim($matches[1]), trim($matches[2]));
        }
        return new self($tableString);
    }
    
    /**
     * Obtiene el alias o el nombre si no hay alias
     */
    public function getIdentifier(): string
    {
        return $this->alias ?? $this->name;
    }
    
    /**
     * Convierte a array
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'alias' => $this->alias
        ];
    }
}
