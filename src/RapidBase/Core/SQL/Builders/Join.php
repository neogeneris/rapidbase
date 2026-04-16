<?php

namespace RapidBase\Core\SQL\Builders;

/**
 * Clase para representar un JOIN en consultas SQL.
 * 
 * Soporta todos los tipos de JOIN y permite especificar alias para tablas.
 * 
 * @example
 * // JOIN simple
 * new Join('INNER', 'posts', 'p', 'u.id = p.user_id')
 * // -> INNER JOIN `posts` AS `p` ON `u`.`id` = `p`.`user_id`
 * 
 * // Con parámetros
 * new Join('LEFT', 'categories', 'c', 'p.cat_id = c.id', ['status' => 'active'])
 */
class Join
{
    public string $type;
    public string $table;
    public ?string $alias;
    public string $on;
    public array $params;
    
    /**
     * Constructor
     * 
     * @param string $type Tipo de JOIN: INNER, LEFT, RIGHT, FULL, etc.
     * @param string $table Nombre real de la tabla
     * @param string|null $alias Alias opcional para la tabla
     * @param string $on Condición ON del JOIN
     * @param array $params Parámetros para la condición ON
     */
    public function __construct(
        string $type = 'LEFT',
        string $table,
        ?string $alias = null,
        string $on = '',
        array $params = []
    ) {
        $this->type = strtoupper($type);
        $this->table = $table;
        $this->alias = $alias;
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
        
        if ($this->alias !== null && $this->alias !== $this->table) {
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
            $config['type'] ?? 'LEFT',
            $config['table'],
            $config['alias'] ?? null,
            $config['on'] ?? '',
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
