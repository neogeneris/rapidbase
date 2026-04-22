<?php

namespace RapidBase\Meta\Discovery;

use PDO;
use RapidBase\Meta\DTO\TableSchema;
use RapidBase\Meta\DTO\ColumnSchema;
use RapidBase\Meta\DTO\RelationSchema;

/**
 * Discovery implementation for Oracle Database
 * Uses ALL_ views for metadata discovery (works with proper privileges)
 */
class OracleDiscovery implements DiscoveryInterface
{
    private PDO $pdo;
    private string $schema;

    public function __construct(PDO $pdo, string $schema)
    {
        $this->pdo = $pdo;
        $this->schema = strtoupper($schema); // Oracle stores identifiers in uppercase
    }

    public function getTables(): array
    {
        // Use ALL_TABLES to see tables accessible to current user
        $sql = "SELECT TABLE_NAME 
                FROM ALL_TABLES 
                WHERE OWNER = :schema
                ORDER BY TABLE_NAME";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':schema' => $this->schema]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function getColumns(string $table): array
    {
        $sql = "SELECT 
                    COLUMN_NAME,
                    DATA_TYPE,
                    DATA_LENGTH,
                    DATA_PRECISION,
                    DATA_SCALE,
                    NULLABLE,
                    DATA_DEFAULT,
                    IDENTITY_COLUMN
                FROM ALL_TAB_COLUMNS
                WHERE TABLE_NAME = :table AND OWNER = :schema
                ORDER BY COLUMN_ID";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':table' => strtoupper($table),
            ':schema' => $this->schema
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $columns = [];
        foreach ($rows as $row) {
            $isNullable = $row['NULLABLE'] === 'Y';
            // Oracle 12c+ identity columns show 'YES' in IDENTITY_COLUMN
            $isAutoInc = $row['IDENTITY_COLUMN'] === 'YES';
            
            $columns[] = new ColumnSchema(
                name: $row['COLUMN_NAME'],
                type: $row['DATA_TYPE'],
                length: $row['DATA_LENGTH'] ?? $row['DATA_PRECISION'],
                scale: $row['DATA_SCALE'],
                nullable: $isNullable,
                default: $row['DATA_DEFAULT'],
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
        $sql = "SELECT ACC.COLUMN_NAME
                FROM ALL_CONSTRAINTS AC
                INNER JOIN ALL_CONS_COLUMNS ACC 
                    ON AC.CONSTRAINT_NAME = ACC.CONSTRAINT_NAME
                    AND AC.OWNER = ACC.OWNER
                WHERE AC.CONSTRAINT_TYPE = 'P'
                AND AC.TABLE_NAME = :table
                AND AC.OWNER = :schema
                ORDER BY ACC.POSITION";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':table' => strtoupper($table),
            ':schema' => $this->schema
        ]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function getForeignKeys(string $table): array
    {
        // Oracle specific query using ALL_CONSTRAINTS and ALL_CONS_COLUMNS
        $sql = "SELECT 
                    AC.CONSTRAINT_NAME AS FK_NAME,
                    ACC.TABLE_NAME AS TABLE_NAME,
                    ACC.COLUMN_NAME AS COLUMN_NAME,
                    ACC_PR.TABLE_NAME AS REFERENCED_TABLE_NAME,
                    ACC_PR.COLUMN_NAME AS REFERENCED_COLUMN_NAME
                FROM ALL_CONSTRAINTS AC
                INNER JOIN ALL_CONS_COLUMNS ACC 
                    ON AC.CONSTRAINT_NAME = ACC.CONSTRAINT_NAME
                    AND AC.OWNER = ACC.OWNER
                INNER JOIN ALL_CONSTRAINTS AC_PR 
                    ON AC.R_CONSTRAINT_NAME = AC_PR.CONSTRAINT_NAME
                    AND AC.OWNER = AC_PR.OWNER
                INNER JOIN ALL_CONS_COLUMNS ACC_PR 
                    ON AC_PR.CONSTRAINT_NAME = ACC_PR.CONSTRAINT_NAME
                    AND AC_PR.OWNER = ACC_PR.OWNER
                    AND ACC.POSITION = ACC_PR.POSITION
                WHERE AC.CONSTRAINT_TYPE = 'R'
                AND ACC.TABLE_NAME = :table
                AND AC.OWNER = :schema
                ORDER BY AC.CONSTRAINT_NAME, ACC.POSITION";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':table' => strtoupper($table),
            ':schema' => $this->schema
        ]);
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
