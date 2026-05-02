<?php

namespace RapidBase\PoC\Flat;

/**
 * DeterministicJoin: Estrategia de joins explícitos y deterministas.
 * Máxima velocidad, sin inferencia mágica.
 * Se asume que las tablas vienen con la condición de join implícita en el nombre o se maneja externamente.
 * Para este experimento Flat, asumimos joins simples por convención de nombres si hay más de una tabla.
 */
class DeterministicJoin extends JoinStrategy
{
    public function build(array $tables): string
    {
        if (count($tables) < 2) {
            return '';
        }

        // Estrategia simple: Left Join secuencial basado en posición
        // En una implementación real, esto leería metadatos o configuraciones explícitas.
        $primary = $this->parseTable($tables[0]);
        $joins = [];

        for ($i = 1; $i < count($tables); $i++) {
            $current = $this->parseTable($tables[$i]);
            // Asumición determinista: la FK es id_tabla en la tabla actual o id en la actual apuntando a la anterior
            // Para simplificar este PoC: usamos un join genérico basado en 'id' si no hay metadata
            $joins[] = "LEFT JOIN {$current['name']} {$current['alias']} ON {$primary['alias']}.id = {$current['alias']}.{$primary['name']}_id";
        }

        return implode(' ', $joins);
    }

    private function parseTable(string $tableStr): array
    {
        $parts = preg_split('/\s+AS\s+/i', trim($tableStr));
        $name = $parts[0];
        $alias = $parts[1] ?? $name;
        return ['name' => $name, 'alias' => $alias];
    }
}
