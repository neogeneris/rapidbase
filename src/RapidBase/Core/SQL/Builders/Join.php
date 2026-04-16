<?php

namespace RapidBase\Core\SQL\Builders;

use RapidBase\Core\SQL;

/**
 * Clase para representar un JOIN en consultas SQL.
 * 
 * Soporta todos los tipos de JOIN y permite especificar alias para tablas.
 * 
 * @example
 * // JOIN simple
 * new Join('users', 'u.id = p.user_id')
 * // -> LEFT JOIN `users` AS `u` ON `u`.`id` = `p`.`user_id`
 * 
 * // Con tipo específico
 * new Join('posts', 'p.cat_id = c.id', 'INNER')
 */
class Join
{
    public string $type;
    public mixed $table;  // string o Table object
    public ?string $alias;
    public string $on;
    public array $params;
    
    /**
     * Constructor
     * 
     * @param string|Table $table Nombre de la tabla o objeto Table
     * @param string $on Condición ON del JOIN
     * @param string $type Tipo de JOIN: INNER, LEFT, RIGHT, FULL, etc. (default: LEFT)
     * @param array $params Parámetros para la condición ON
     */
    public function __construct(
        mixed $table,
        string $on = '',
        string $type = 'LEFT',
        array $params = []
    ) {
        $this->type = strtoupper($type);
        
        if ($table instanceof Table) {
            $this->table = $table->name;
            $this->alias = $table->alias;
        } else {
            $this->table = (string)$table;
            $this->alias = null;
        }
        
        $this->on = $on;
        $this->params = $params;
    }
    
    /**
     * Construye la representación SQL del JOIN
     * 
     * @param callable $quoteFunc Función para quoteear identificadores
     * @return string El JOIN formateado para SQL
     */
    public function toSql(callable $quoteFunc): string
    {
        $sql = "{$this->type} JOIN ";
        
        if ($this->alias !== null) {
            $sql .= $quoteFunc($this->table) . ' AS ' . $quoteFunc($this->alias);
        } else {
            $sql .= $quoteFunc($this->table);
        }
        
        if (!empty($this->on)) {
            $sql .= ' ON ' . $this->on;
        }
        
        return $sql;
    }
    
    /**
     * Crea un Join desde un array de configuración
     * 
     * @param array $config Configuración con keys: type, table, alias, on, params
     * @return self
     */
    public static function fromArray(array $config): self
    {
        return new self(
            $config['table'],
            $config['on'] ?? '',
            $config['type'] ?? 'LEFT',
            $config['params'] ?? []
        );
    }
    
    /**
     * Convierte a array
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'table' => $this->table,
            'alias' => $this->alias,
            'on' => $this->on,
            'params' => $this->params
        ];
    }
}
