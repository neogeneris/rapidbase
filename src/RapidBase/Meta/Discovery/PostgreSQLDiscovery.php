<?php
// File: src/Meta/Discovery/PostgreSQLDiscovery.php

namespace RapidBase\Meta\Discovery;

use PDO;

class PostgreSQLDiscovery implements DiscoveryInterface
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function discoverRelationships(string $databaseName = null): array
    {
        $graph = ['from' => [], 'to' => []];

        // Consulta para obtener relaciones (claves foráneas) en PostgreSQL
        $sql = "
            SELECT
                tc.table_name AS source_table,
                kcu.column_name AS source_column,
                ccu.table_name AS target_table,
                ccu.column_name AS target_column,
                tc.constraint_name
            FROM 
                information_schema.table_constraints AS tc
                JOIN information_schema.key_column_usage AS kcu
                    ON tc.constraint_name = kcu.constraint_name
                    AND tc.table_schema = kcu.table_schema
                JOIN information_schema.constraint_column_usage AS ccu
                    ON ccu.constraint_name = tc.constraint_name
                    AND ccu.table_schema = tc.table_schema
            WHERE tc.constraint_type = 'FOREIGN KEY'
            ORDER BY tc.table_name, kcu.column_name;";

        $stmt = $this->pdo->query($sql);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($results as $row) {
            $sourceTable = $row['source_table'];
            $targetTable = $row['target_table'];
            $sourceColumn = $row['source_column'];
            $targetColumn = $row['target_column'];

            // Relación 'from': sourceTable -> targetTable
            $graph['from'][$sourceTable][$targetTable] = [
                'type' => 'belongsTo',
                'local_key' => $sourceColumn,
                'foreign_key' => $targetColumn,
            ];

            // Relación 'to': targetTable <- sourceTable
            $graph['to'][$targetTable][$sourceTable] = [
                'type' => 'hasMany',
                'local_key' => $sourceColumn,
                'foreign_key' => $targetColumn,
            ];
        }

        return $graph;
    }
    
    public static function getSchemaSignature(PDO $pdo, string $databaseName = null): string 
    {
        // En PostgreSQL, usamos el checksum de las tablas y sus tiempos de modificación
        $sql = "SELECT md5(string_agg(tablename || '_' || pg_relation_size(schemaname||'.'||tablename), ''))
                FROM pg_tables 
                WHERE schemaname = 'public'";
        $stmt = $pdo->query($sql);
        return $stmt->fetchColumn() ?: '';
    }

    public function discoverColumns(string $tableName, string $databaseName = null): array
    {
        $columns = [];

        // Consulta para obtener columnas y sus relaciones FK en PostgreSQL
        $sql = "
            SELECT 
                c.column_name, 
                c.data_type, 
                c.is_nullable,
                c.column_default,
                CASE 
                    WHEN pk.column_name IS NOT NULL THEN 'PRI'
                    ELSE ''
                END AS column_key,
                fk_tbl.table_name AS referenced_table_name,
                fk_col.column_name AS referenced_column_name
            FROM information_schema.columns c
            LEFT JOIN information_schema.constraint_column_usage ccu 
                ON c.table_schema = ccu.table_schema 
                AND c.table_name = ccu.table_name 
                AND c.column_name = ccu.column_name
            LEFT JOIN information_schema.table_constraints tc 
                ON tc.constraint_name = ccu.constraint_name 
                AND tc.constraint_type = 'FOREIGN KEY'
            LEFT JOIN information_schema.key_column_usage fk_col
                ON tc.constraint_name = fk_col.constraint_name
                AND fk_col.position_in_unique_constraint = 1
            LEFT JOIN information_schema.table_constraints fk_tbl
                ON tc.constraint_name = fk_tbl.constraint_name
                AND fk_tbl.constraint_type = 'FOREIGN KEY'
            LEFT JOIN information_schema.key_column_usage pk
                ON c.table_schema = pk.table_schema
                AND c.table_name = pk.table_name
                AND c.column_name = pk.column_name
                AND pk.constraint_name = (
                    SELECT constraint_name 
                    FROM information_schema.table_constraints 
                    WHERE table_schema = c.table_schema 
                    AND table_name = c.table_name 
                    AND constraint_type = 'PRIMARY KEY'
                    LIMIT 1
                )
            WHERE c.table_schema = 'public' 
              AND c.table_name = :tableName
            ORDER BY c.ordinal_position";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['tableName' => $tableName]);

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $columns[$row['column_name']] = [
                'type'       => $row['data_type'],
                'primary'    => ($row['column_key'] === 'PRI'),
                'foreign'    => !is_null($row['referenced_table_name']),
                'nullable'   => ($row['is_nullable'] === 'YES'),
                'default'    => $row['column_default'],
                'references' => $row['referenced_table_name'] ? [
                    'table'  => $row['referenced_table_name'],
                    'column' => $row['referenced_column_name']
                ] : null,
            ];
        }

        return $columns;
    }
    
    public function discoverPrimaryKey(string $tableName, string $databaseName = null): ?string
    {
        $sql = "
            SELECT a.attname AS column_name
            FROM pg_index i
            JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
            JOIN pg_class c ON c.oid = i.indrelid
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE i.indisprimary 
              AND c.relname = :tableName
              AND n.nspname = 'public'
            ORDER BY a.attnum;";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindParam(':tableName', $tableName, PDO::PARAM_STR);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result) {
            $pkCols = [$result['column_name']];
            $more = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($more as $row) {
                $pkCols[] = $row['column_name'];
            }
            return count($pkCols) === 1 ? $pkCols[0] : $pkCols;
        }

        return null;
    }
}
