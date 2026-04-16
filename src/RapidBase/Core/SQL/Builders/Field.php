<?php

namespace RapidBase\Core\SQL\Builders;

/**
 * Clase para representar un campo con alias en consultas SQL.
 * 
 * Permite especificar de forma precisa la sintaxis de campos con alias,
 * evitando ambigüedades y mejorando la legibilidad del código.
 * 
 * @example
 * // Sin alias
 * new Field('name')           // -> `name`
 * 
 * // Con alias
 * new Field('users.name', 'user_name')  // -> `users`.`name` AS `user_name`
 * 
 * // Con función agregada
 * new Field('COUNT(*)', 'total')        // -> COUNT(*) AS `total`
 */
class Field
{
    public string $name;
    public ?string $alias;
    
    /**
     * Constructor
     * 
     * @param string $name Nombre del campo (puede incluir tabla: 'table.column')
     * @param string|null $alias Alias opcional para el campo
     */
    public function __construct(string $name, ?string $alias = null)
    {
        $this->name = $name;
        $this->alias = $alias;
    }
    
    /**
     * Construye la representación SQL del campo
     * 
     * @param callable $quoteFunc Función para quoteear identificadores
     * @return string El campo formateado para SQL
     */
    public function toSql(callable $quoteFunc): string
    {
        if ($this->alias !== null) {
            return $quoteFunc($this->name) . ' AS ' . $quoteFunc($this->alias);
        }
        return $quoteFunc($this->name);
    }
    
    /**
     * Crea un Field desde un string con sintaxis AS
     * 
     * @param string $fieldString Campo en formato 'column AS alias'
     * @return self
     */
    public static function fromString(string $fieldString): self
    {
        if (preg_match('/^(.+?)\s+AS\s+(.+)$/i', trim($fieldString), $matches)) {
            return new self(trim($matches[1]), trim($matches[2]));
        }
        return new self($fieldString);
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
