<?php

namespace RapidBase\Core\SQL;

/**
 * JoinStrategy: Interfaz base para estrategias de JOIN.
 */
abstract class JoinStrategy
{
    /**
     * Genera la cláusula JOIN basada en las tablas involucradas.
     * @param array $tables Lista de tablas o ['tabla alias', ...]
     * @return string Cláusula JOIN o vacío si no aplica.
     */
    abstract public function build(array $tables): string;
}
