<?php
// File: src/RapidBase/Meta/Discovery/SQLiteDiscovery.php

namespace RapidBase\Meta\Discovery;

use PDO;

/**
 * SQLiteDiscovery: Extrae metadata del esquema de SQLite.
 * 
 * Soporta:
 * - Detección de tablas (sqlite_master)
 * - Columnas con tipos, nullability, defaults (PRAGMA table_info)
 * - Claves primarias
 * - Claves foráneas (PRAGMA foreign_key_list)
 * - Descripciones desde comentarios (si existen en sqlite_master)
 */
class SQLiteDiscovery implements DiscoveryInterface
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function discoverRelationships(?string $databaseName = 'main'): array
    {
        $graph = ['from' => [], 'to' => []];

        // Obtener todas las tablas
        $tables = $this->getTables();

        foreach ($tables as $table) {
            // Obtener foreign keys para cada tabla
            $fks = $this->getForeignKeys($table);
            
            foreach ($fks as $fk) {
                $sourceTable = $table;
                $targetTable = $fk['table'];
                $sourceColumn = $fk['from'];
                $targetColumn = $fk['to'];

                // from: tabla origen -> [tabla_destino => [col_local => col_remota]]
                if (!isset($graph['from'][$sourceTable])) {
                    $graph['from'][$sourceTable] = [];
                }
                if (!isset($graph['from'][$sourceTable][$targetTable])) {
                    $graph['from'][$sourceTable][$targetTable] = [];
                }
                $graph['from'][$sourceTable][$targetTable][$sourceColumn] = $targetColumn;

                // to: tabla destino <- [tabla_origen => [col_remota => col_local]]
                if (!isset($graph['to'][$targetTable])) {
                    $graph['to'][$targetTable] = [];
                }
                if (!isset($graph['to'][$targetTable][$sourceTable])) {
                    $graph['to'][$targetTable][$sourceTable] = [];
                }
                $graph['to'][$targetTable][$sourceTable][$targetColumn] = $sourceColumn;
            }
        }

        return $graph;
    }

    public function getTables(): array
    {
        $sql = "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name";
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function getColumns(string $table): array
    {
        $columns = [];
        
        // PRAGMA table_info retorna: cid, name, type, notnull, dflt_value, pk
        $stmt = $this->pdo->query("PRAGMA table_info('$table')");
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($result as $col) {
            $colName = $col['name'];
            $columns[$colName] = [
                'type' => $col['type'] ?? 'TEXT',
                'primary' => (bool)$col['pk'],
                'foreign' => false, // Se marca después
                'nullable' => !(bool)$col['notnull'],
                'default' => $col['dflt_value'],
                'references' => null,
                'description' => null // SQLite no soporta comentarios nativamente
            ];
        }

        // Marcar columnas que son foreign keys
        $fks = $this->getForeignKeys($table);
        foreach ($fks as $fk) {
            if (isset($columns[$fk['from']])) {
                $columns[$fk['from']]['foreign'] = true;
                $columns[$fk['from']]['references'] = $fk['table'] . '.' . $fk['to'];
            }
        }

        return $columns;
    }

    public function getPrimaryKeys(string $table): array
    {
        $stmt = $this->pdo->query("PRAGMA table_info('$table')");
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $pks = [];
        foreach ($result as $col) {
            if ((bool)$col['pk']) {
                $pks[] = $col['name'];
            }
        }
        
        return $pks;
    }

    public function getForeignKeys(string $table): array
    {
        $fks = [];
        
        // PRAGMA foreign_key_list retorna: id, seq, table, from, to, on_update, on_delete, match
        $stmt = $this->pdo->query("PRAGMA foreign_key_list('$table')");
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($result as $row) {
            $fks[] = [
                'id' => $row['id'],
                'table' => $row['table'],
                'from' => $row['from'],
                'to' => $row['to'] ?? $row['from'], // Si no hay 'to', asume misma columna
                'on_update' => $row['on_update'] ?? 'NO ACTION',
                'on_delete' => $row['on_delete'] ?? 'NO ACTION'
            ];
        }

        return $fks;
    }

    public function getTableComment(string $table): ?string
    {
        // SQLite no soporta comentarios de tabla nativamente
        // Podría leerse de una tabla especial si el usuario la crea
        return null;
    }

    public function getColumnComment(string $table, string $column): ?string
    {
        // SQLite no soporta comentarios de columna nativamente
        // Podría leerse de una tabla especial si el usuario la crea
        return null;
    }

    public function discoverColumns(string $tableName, ?string $databaseName = 'main'): array
    {
        return $this->getColumns($tableName);
    }

    public function discoverPrimaryKey(string $tableName, ?string $databaseName = 'main'): ?string
    {
        $pks = $this->getPrimaryKeys($tableName);
        return !empty($pks) ? $pks[0] : null;
    }
}
