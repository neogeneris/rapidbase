<?php
// File: src/Meta/Discovery/SqlServerDiscovery.php

namespace RapidBase\Meta\Discovery;

use PDO;

class SqlServerDiscovery implements DiscoveryInterface
{
    private PDO $pdo;
    private string $schema;

    public function __construct(PDO $pdo, string $schema = 'dbo')
    {
        $this->pdo = $pdo;
        $this->schema = $schema;
    }

    public function discoverRelationships(string $databaseName): array
    {
        $graph = ['from' => [], 'to' => []];

        $sql = "
            SELECT 
                fk.name AS constraint_name,
                tp.name AS source_table,
                cp.name AS source_column,
                tr.name AS target_table,
                cr.name AS target_column
            FROM sys.foreign_keys AS fk
            INNER JOIN sys.foreign_key_columns AS fkc ON fk.object_id = fkc.constraint_object_id
            INNER JOIN sys.tables AS tp ON fkc.parent_object_id = tp.object_id
            INNER JOIN sys.columns AS cp ON fkc.parent_object_id = cp.object_id AND fkc.parent_column_id = cp.column_id
            INNER JOIN sys.tables AS tr ON fkc.referenced_object_id = tr.object_id
            INNER JOIN sys.columns AS cr ON fkc.referenced_object_id = cr.object_id AND fkc.referenced_column_id = cr.column_id
            INNER JOIN sys.schemas AS s ON tp.schema_id = s.schema_id
            WHERE s.name = :schemaName
            ORDER BY tp.name, cp.name;
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':schemaName' => $this->schema]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $sourceTable  = $row['source_table'];
            $sourceColumn = $row['source_column'];
            $targetTable  = $row['target_table'];
            $targetColumn = $row['target_column'];

            if (!isset($graph['from'][$sourceTable])) {
                $graph['from'][$sourceTable] = [];
            }
            $graph['from'][$sourceTable][$targetTable] = [
                'type'        => 'belongsTo',
                'local_key'   => $sourceColumn,
                'foreign_key' => $targetColumn
            ];

            if (!isset($graph['to'][$targetTable])) {
                $graph['to'][$targetTable] = [];
            }
            $graph['to'][$targetTable][$sourceTable] = [
                'type'        => 'hasMany',
                'local_key'   => $sourceColumn,
                'foreign_key' => $targetColumn
            ];
        }

        return $graph;
    }

    public function discoverColumns(string $tableName, string $databaseName): array
    {
        $columns = [];

        $sql = "
            SELECT 
                c.COLUMN_NAME,
                c.DATA_TYPE,
                c.IS_NULLABLE,
                c.COLUMN_DEFAULT,
                ccu.TABLE_NAME AS REFERENCED_TABLE_NAME,
                ccu.COLUMN_NAME AS REFERENCED_COLUMN_NAME
            FROM INFORMATION_SCHEMA.COLUMNS c
            LEFT JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu 
                ON c.TABLE_SCHEMA = kcu.TABLE_SCHEMA 
                AND c.TABLE_NAME = kcu.TABLE_NAME 
                AND c.COLUMN_NAME = kcu.COLUMN_NAME
            LEFT JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc 
                ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
            LEFT JOIN INFORMATION_SCHEMA.CONSTRAINT_COLUMN_USAGE ccu 
                ON rc.UNIQUE_CONSTRAINT_NAME = ccu.CONSTRAINT_NAME
            WHERE c.TABLE_SCHEMA = :schemaName 
              AND c.TABLE_NAME = :tableName
            ORDER BY c.ORDINAL_POSITION;
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':schemaName' => $this->schema,
            ':tableName'  => $tableName
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $primaryKey = $this->discoverPrimaryKey($tableName, $databaseName);

        foreach ($rows as $row) {
            $colName = $row['COLUMN_NAME'];
            
            $columns[$colName] = [
                'type'       => strtolower($row['DATA_TYPE']),
                'primary'    => ($primaryKey !== null && $colName === $primaryKey),
                'foreign'    => !empty($row['REFERENCED_TABLE_NAME']),
                'nullable'   => ($row['IS_NULLABLE'] === 'YES'),
                'default'    => $row['COLUMN_DEFAULT'],
                'references' => !empty($row['REFERENCED_TABLE_NAME']) ? [
                    'table'  => $row['REFERENCED_TABLE_NAME'],
                    'column' => $row['REFERENCED_COLUMN_NAME']
                ] : null,
            ];
        }

        return $columns;
    }

    public function discoverPrimaryKey(string $tableName, string $databaseName): ?string
    {
        $sql = "
            SELECT kcu.COLUMN_NAME
            FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc
            INNER JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu 
                ON tc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME 
                AND tc.TABLE_SCHEMA = kcu.TABLE_SCHEMA
            WHERE tc.CONSTRAINT_TYPE = 'PRIMARY KEY'
              AND tc.TABLE_SCHEMA = :schemaName
              AND tc.TABLE_NAME = :tableName
            ORDER BY kcu.ORDINAL_POSITION;
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':schemaName' => $this->schema,
            ':tableName'  => $tableName
        ]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['COLUMN_NAME'] : null;
    }

    public function getTables(string $databaseName): array
    {
        $sql = "
            SELECT TABLE_NAME 
            FROM INFORMATION_SCHEMA.TABLES 
            WHERE TABLE_TYPE = 'BASE TABLE' 
              AND TABLE_SCHEMA = :schemaName
            ORDER BY TABLE_NAME;
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':schemaName' => $this->schema]);
        
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}