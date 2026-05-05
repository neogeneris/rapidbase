<?php

declare(strict_types=1);

namespace RapidBase\Core;

class SchemaMap
{
    private static array $maps = [];
    private static ?string $defaultConnectionId = 'default';

    public static function loadFromFile(string $filePath, string $connectionId = 'default'): void
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("Schema map file not found: {$filePath}");
        }
        $mapData = require $filePath;
        if (!is_array($mapData)) {
            throw new \RuntimeException("Schema map file must return an array: {$filePath}");
        }
        self::$maps[$connectionId] = $mapData;
    }

    public static function setMap(array $data, string $connectionId = 'default'): void
    {
        self::$maps[$connectionId] = $data;
    }

    public static function getMap(?string $connectionId = null): array
    {
        $id = $connectionId ?? self::$defaultConnectionId;
        return self::$maps[$id] ?? [];
    }

    public static function getFeatures(?string $connectionId = null): array
    {
        $map = self::getMap($connectionId);
        return $map['features'] ?? [];
    }

    public static function getFeature(string $name, mixed $default = false, ?string $connectionId = null): mixed
    {
        $features = self::getFeatures($connectionId);
        return $features[$name] ?? $default;
    }

    public static function getTable(string $tableName, ?string $connectionId = null): ?array
    {
        $map = self::getMap($connectionId);
        $tableName = strtolower($tableName);
        if (isset($map[$tableName])) {
            return $map[$tableName];
        }
        if (isset($map['tables'][$tableName])) {
            return $map['tables'][$tableName];
        }
        return null;
    }

    public static function getColumns(string $tableName, ?string $connectionId = null): array
    {
        $table = self::getTable($tableName, $connectionId);
        if ($table === null) {
            return [];
        }
        if (isset($table['columns']) && is_array($table['columns'])) {
            return $table['columns'];
        }
        // Filter only entries that are columns (have 'type' key)
        // This avoids collisions with metadata keys like 'primary_key', 'foreign_keys', 'indexes', 'description'
        $columns = [];
        foreach ($table as $key => $value) {
            if (is_array($value) && isset($value['type'])) {
                $columns[$key] = $value;
            }
        }
        return $columns;
    }

    public static function getPrimaryKeys(string $tableName, ?string $connectionId = null): array
    {
        $table = self::getTable($tableName, $connectionId);
        return $table['primary_key'] ?? [];
    }

    public static function getForeignKeys(string $tableName, ?string $connectionId = null): array
    {
        $table = self::getTable($tableName, $connectionId);
        return $table['foreign_keys'] ?? [];
    }

    public static function hasTable(string $tableName, ?string $connectionId = null): bool
    {
        return self::getTable($tableName, $connectionId) !== null;
    }

    public static function hasColumn(string $tableName, string $columnName, ?string $connectionId = null): bool
    {
        $columns = self::getColumns($tableName, $connectionId);
        return isset($columns[$columnName]);
    }

    public static function setDefaultConnection(string $connectionId): void
    {
        self::$defaultConnectionId = $connectionId;
    }

    public static function clear(): void
    {
        self::$maps = [];
        self::$defaultConnectionId = 'default';
    }
}