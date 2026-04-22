<?php

namespace RapidBase\Core;

/**
 * SchemaMap: Gestiona la lectura de metadatos desde un archivo estático schema_map.php.
 * 
 * Esta clase es agnóstica a la base de datos y actúa como la única fuente de verdad
 * para la estructura del esquema en tiempo de ejecución, evitando consultas costosas
 * al information_schema.
 * 
 * Soporta múltiples conexiones cargando diferentes archivos de mapa.
 */
class SchemaMap
{
    /**
     * @var array Almacena los mapas cargados por conexión/ID.
     */
    private static array $maps = [];

    /**
     * @var string|null Identificador de la conexión actual por defecto.
     */
    private static ?string $defaultConnectionId = 'default';

    /**
     * Carga un archivo schema_map.php desde el disco.
     *
     * @param string $filePath Ruta absoluta al archivo PHP que retorna un array.
     * @param string $connectionId Identificador único para esta conexión (ej: 'mysql_main', 'sqlite_logs').
     * @return void
     * @throws \RuntimeException Si el archivo no existe o no es legible.
     */
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

    /**
     * Establece el mapa manualmente (útil para testing o generación dinámica).
     *
     * @param array $data Array con la estructura del esquema.
     * @param string $connectionId Identificador de la conexión.
     * @return void
     */
    public static function setMap(array $data, string $connectionId = 'default'): void
    {
        self::$maps[$connectionId] = $data;
    }

    /**
     * Obtiene el mapa completo de una conexión.
     *
     * @param string|null $connectionId Si es null, usa la conexión por defecto.
     * @return array
     */
    public static function getMap(?string $connectionId = null): array
    {
        $id = $connectionId ?? self::$defaultConnectionId;
        return self::$maps[$id] ?? [];
    }

    /**
     * Obtiene la definición de una tabla específica.
     *
     * @param string $tableName Nombre de la tabla.
     * @param string|null $connectionId
     * @return array|null Retorna null si la tabla no existe en el mapa.
     */
    public static function getTable(string $tableName, ?string $connectionId = null): ?array
    {
        $map = self::getMap($connectionId);
        // Normalizar nombre de tabla (minúsculas usualmente)
        $tableName = strtolower($tableName);
        
        // El mapa puede tener dos formatos:
        // 1. Formato directo: ['users' => [...], 'posts' => [...]]
        // 2. Formato completo: ['checksum'=>..., 'tables'=>['users'=>[...]]]
        if (isset($map[$tableName])) {
            return $map[$tableName];
        }
        
        // Intentar con formato completo
        if (isset($map['tables'][$tableName])) {
            return $map['tables'][$tableName];
        }
        
        return null;
    }

    /**
     * Obtiene las columnas de una tabla.
     *
     * @param string $tableName
     * @param string|null $connectionId
     * @return array Array de columnas [nombre => propiedades] o array vacío.
     */
    public static function getColumns(string $tableName, ?string $connectionId = null): array
    {
        $table = self::getTable($tableName, $connectionId);
        return $table['columns'] ?? [];
    }

    /**
     * Obtiene la clave primaria de una tabla.
     *
     * @param string $tableName
     * @param string|null $connectionId
     * @return array Array con los nombres de las columnas PK.
     */
    public static function getPrimaryKeys(string $tableName, ?string $connectionId = null): array
    {
        $table = self::getTable($tableName, $connectionId);
        return $table['primary_key'] ?? [];
    }

    /**
     * Obtiene las claves foráneas de una tabla.
     *
     * @param string $tableName
     * @param string|null $connectionId
     * @return array Array de definiciones de FK.
     */
    public static function getForeignKeys(string $tableName, ?string $connectionId = null): array
    {
        $table = self::getTable($tableName, $connectionId);
        return $table['foreign_keys'] ?? [];
    }

    /**
     * Verifica si una tabla existe en el mapa.
     *
     * @param string $tableName
     * @param string|null $connectionId
     * @return bool
     */
    public static function hasTable(string $tableName, ?string $connectionId = null): bool
    {
        return self::getTable($tableName, $connectionId) !== null;
    }

    /**
     * Verifica si una columna existe en una tabla.
     *
     * @param string $tableName
     * @param string $columnName
     * @param string|null $connectionId
     * @return bool
     */
    public static function hasColumn(string $tableName, string $columnName, ?string $connectionId = null): bool
    {
        $columns = self::getColumns($tableName, $connectionId);
        return isset($columns[$columnName]);
    }

    /**
     * Establece la conexión por defecto para llamadas estáticas sin ID explícito.
     *
     * @param string $connectionId
     * @return void
     */
    public static function setDefaultConnection(string $connectionId): void
    {
        self::$defaultConnectionId = $connectionId;
    }

    /**
     * Limpia todos los mapas cargados (útil para tests).
     *
     * @return void
     */
    public static function clear(): void
    {
        self::$maps = [];
        self::$defaultConnectionId = 'default';
    }
}
