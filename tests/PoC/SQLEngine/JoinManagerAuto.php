<?php

namespace RapidBase\SQLEngine;

/**
 * JoinManagerAuto - Extiende JoinManager con auto-join por convenciones.
 * 
 * Detecta automáticamente las relaciones basándose en nombres de tablas.
 * Ej: users + posts → LEFT JOIN posts ON users.id = posts.user_id
 */
class JoinManagerAuto extends JoinManager
{
    /**
     * Agrega una tabla para auto-join automático.
     * Detecta la relación basándose en convenciones de nombres.
     */
    public function addAutoJoin(string $table, array $existingAliases = []): self
    {
        // Extraer nombre base y alias
        $alias = $this->extractAlias($table);
        $realName = preg_replace('/\s+as\s+.*/i', '', $table);
        
        // Guardar alias
        $this->aliases[$alias] = $realName;
        
        // Auto-detectar relación por convención de nombres
        $foreignKey = $this->detectForeignKey($alias, $existingAliases);
        
        if ($foreignKey) {
            $quotedTable = $this->quote($realName);
            $quotedAlias = $this->quote($alias);
            
            // Determinar la condición ON
            $onCondition = $this->buildOnCondition($alias, $foreignKey, $existingAliases);
            
            if ($onCondition) {
                $this->joins[] = [
                    'type' => 'LEFT',
                    'table' => "{$quotedTable} AS {$quotedAlias}",
                    'on' => $onCondition
                ];
            }
        }
        
        return $this;
    }
    
    /**
     * Detecta la foreign key basada en convenciones.
     */
    protected function detectForeignKey(string $tableAlias, array $existingAliases): ?string
    {
        // Convertir plural a singular: users → user, posts → post
        $singular = rtrim($tableAlias, 's');
        
        // Buscar coincidencias en aliases existentes
        foreach ($existingAliases as $existingAlias => $existingReal) {
            if ($existingAlias === $tableAlias) continue;
            
            // Coincidencia directa: user == user
            if ($existingAlias === $singular || $existingReal === $singular) {
                return $singular . '_id';
            }
        }
        
        return null;
    }
    
    /**
     * Construye la condición ON para el join.
     */
    protected function buildOnCondition(string $newAlias, string $foreignKey, array $existingAliases): string
    {
        $singular = rtrim($newAlias, 's');
        
        foreach ($existingAliases as $existingAlias => $existingReal) {
            if ($existingAlias === $singular || $existingReal === $singular) {
                return $this->quote($existingAlias) . ".{$foreignKey} = " . $this->quote($newAlias) . ".id";
            }
        }
        
        return '';
    }
    
    /**
     * Extrae el alias de una definición de tabla.
     */
    protected function extractAlias(string $table): string
    {
        $table = trim($table);
        if (preg_match('/^(\w+)(?:\s+(?:as\s+)?(\w+))?$/i', $table, $matches)) {
            return $matches[2] ?? $matches[1];
        }
        return $table;
    }
    
    /**
     * Coteja un identificador según el driver.
     */
    protected function quote(string $identifier): string
    {
        return '"' . trim($identifier, '"') . '"';
    }
}
