<?php

namespace RapidBase\Core\SQL;
/**
 * Estrategia básica de JOINs deterministas.
 */
class JoinStrategy {
    protected $table;
    protected $joins;

    public function __construct($table, array $joins) {
        $this->table = $table;
        $this->joins = $joins;
    }

    public function getFromClause(): string {
        if (is_string($this->table)) {
            return "`$this->table`";
        }
        // Soporte básico para alias ['users' => 'u']
        if (is_array($this->table)) {
            $t = key($this->table);
            $alias = current($this->table);
            return "`$t` AS `$alias`";
        }
        return "`$this->table`";
    }

    public function getJoinClause(): string {
        // Implementación básica, extensible para AutoJoin
        return '';
    }
}
