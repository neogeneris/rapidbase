<?php

namespace RapidBase\Meta\Discovery;

use PDO;
use RapidBase\Meta\DTO\TableSchema;
use RapidBase\Meta\DTO\ColumnSchema;
use RapidBase\Meta\DTO\RelationSchema;

/**
 * Discovery implementation for Microsoft SQL Server
 * Uses information_schema and sys views for FK detection
 */
class SqlServerDiscovery implements DiscoveryInterface
{
    private PDO $pdo;
    private string $schema = 'dbo'; // Default schema in SQL Server

    public function __construct(PDO $pdo, string $schema = 'dbo')
    {
        $this->pdo = $pdo;
        $this->schema = $schema;
    }

    public function getTables(): array
    {
        $sql = "SELECT TABLE_NAME 
                FROM INFORMATION_SCHEMA.TABLES 
                WHERE TABLE_TYPE = 'BASE TABLE' 
                AND TABLE_SCHEMA = ?
                ORDER BY TABLE_NAME";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$this->schema]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function getColumns(string $table): array
    {
        $sql = "SELECT 
                    COLUMN_NAME,
                    DATA_TYPE,
                    CHARACTER_MAXIMUM_LENGTH,
                    NUMERIC_PRECISION,
                    NUMERIC_SCALE,
                    IS_NULLABLE,
                    COLUMN_DEFAULT,
                    CASE WHEN COLUMNPROPERTY(OBJECT_ID(TABLE_SCHEMA + '.' + TABLE_NAME), COLUMN_NAME, 'IsIdentity') = 1 THEN 'YES' ELSE 'NO' END AS IS_AUTO_INCREMENT
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_NAME = ? AND TABLE_SCHEMA = ?
                ORDER BY ORDINAL_POSITION";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$table, $this->schema]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $columns = [];
        foreach ($rows as $row) {
            $isNullable = strtoupper($row['IS_NULLABLE']) === 'YES';
            $isAutoInc = strtoupper($row['IS_AUTO_INCREMENT']) === 'YES';
            
            // Determine primary key status separately in getPrimaryKey
            
            $columns[] = new ColumnSchema(
                name: $row['COLUMN_NAME'],
                type: $row['DATA_TYPE'],
                length: $row['CHARACTER_MAXIMUM_LENGTH'] ?? $row['NUMERIC_PRECISION'],
                scale: $row['NUMERIC_SCALE'],
                nullable: $isNullable,
                default: $row['COLUMN_DEFAULT'],
                autoIncrement: $isAutoInc,
                isPrimaryKey: false // Se marca después
            );
        }

        // Marcar Primary Keys
        $pkColumns = $this->getPrimaryKeys($table);
        foreach ($columns as $col) {
            if (in_array($col->name, $pkColumns)) {
                $col->isPrimaryKey = true;
            }
        }

        return $columns;
    }

    public function getPrimaryKeys(string $table): array
    {
        $sql = "SELECT KCU.COLUMN_NAME
                FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS AS TC
                INNER JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE AS KCU
                    ON TC.CONSTRAINT_NAME = KCU.CONSTRAINT_NAME
                    AND TC.CONSTRAINT_SCHEMA = KCU.CONSTRAINT_SCHEMA
                WHERE TC.CONSTRAINT_TYPE = 'PRIMARY KEY'
                AND TC.TABLE_NAME = ?
                AND TC.TABLE_SCHEMA = ?
                ORDER BY KCU.ORDINAL_POSITION";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$table, $this->schema]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function getForeignKeys(string $table): array
    {
        // SQL Server specific query using sys.views for reliable FK resolution
        $sql = "SELECT 
                    fk.name AS FK_NAME,
                    tp.name AS TABLE_NAME,
                    cp.name AS COLUMN_NAME,
                    tr.name AS REFERENCED_TABLE_NAME,
                    cr.name AS REFERENCED_COLUMN_NAME
                FROM sys.foreign_keys AS fk
                INNER JOIN sys.foreign_key_columns AS fkc ON fk.object_id = fkc.constraint_object_id
                INNER JOIN sys.tables AS tp ON fkc.parent_object_id = tp.object_id
                INNER JOIN sys.columns AS cp ON fkc.parent_object_id = cp.object_id AND fkc.parent_column_id = cp.column_id
                INNER JOIN sys.tables AS tr ON fkc.referenced_object_id = tr.object_id
                INNER JOIN sys.columns AS cr ON fkc.referenced_object_id = cr.object_id AND fkc.referenced_column_id = cr.column_id
                WHERE tp.name = ? 
                AND SCHEMA_NAME(tp.schema_id) = ?
                ORDER BY fk.name, fkc.constraint_column_id";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$table, $this->schema]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $relations = [];
        foreach ($rows as $row) {
            $relations[] = new RelationSchema(
                localTable: $row['TABLE_NAME'],
                localColumn: $row['COLUMN_NAME'],
                foreignTable: $row['REFERENCED_TABLE_NAME'],
                foreignColumn: $row['REFERENCED_COLUMN_NAME'],
                constraintName: $row['FK_NAME']
            );
        }

        return $relations;
    }

    public function discoverTable(string $table): TableSchema
    {
        $columns = $this->getColumns($table);
        $foreignKeys = $this->getForeignKeys($table);
        $primaryKeys = $this->getPrimaryKeys($table);

        return new TableSchema(
            name: $table,
            schema: $this->schema,
            columns: $columns,
            primaryKeys: $primaryKeys,
            relations: $foreignKeys
        );
    }
}
