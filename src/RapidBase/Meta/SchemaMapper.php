<?php

namespace RapidBase\Meta;

use RapidBase\Meta\Discovery\DiscoveryFactory;
use RapidBase\Meta\Discovery\DiscoveryInterface;
use RapidBase\Meta\Discovery\MySQLDiscovery;
use PDO;

class SchemaMapper
{
    private static ?DiscoveryInterface $discovery = null;
    private static string $outputFile = 'schema_map.php';

    public static function setDiscovery(DiscoveryInterface $discovery): void
    {
        self::$discovery = $discovery;
    }

    public static function setOutputFile(string $path): void
    {
        self::$outputFile = $path;
    }

    public static function generate(PDO $pdo, string $databaseName = null, string $schema = null): bool
    {
        $discovery = self::$discovery ?? DiscoveryFactory::create($pdo, $schema);
        $driverName = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        try {
            // 1. Generar la firma actual de la DB
            $signature = match($driverName) {
                'mysql' => MySQLDiscovery::getSchemaSignature($pdo, $databaseName),
                'pgsql' => \RapidBase\Meta\Discovery\PostgreSQLDiscovery::getSchemaSignature($pdo, $databaseName),
                default => ''
            };

            // 2. Descubrir relaciones y tablas
            $relationships = $discovery->discoverRelationships($databaseName);
            
            // Obtener tablas según el driver
            if ($driverName === 'pgsql') {
                $stmt = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public'");
                $allTables = $stmt->fetchAll(PDO::FETCH_COLUMN);
            } elseif ($driverName === 'sqlsrv') {
                $stmt = $pdo->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE' AND TABLE_SCHEMA = 'dbo'");
                $allTables = $stmt->fetchAll(PDO::FETCH_COLUMN);
            } elseif ($driverName === 'oci') {
                $schemaUpper = strtoupper($schema ?? user());
                $stmt = $pdo->query("SELECT TABLE_NAME FROM ALL_TABLES WHERE OWNER = '$schemaUpper'");
                $allTables = $stmt->fetchAll(PDO::FETCH_COLUMN);
            } else {
                $stmt = $pdo->query("SHOW TABLES FROM $databaseName");
                $allTables = $stmt->fetchAll(PDO::FETCH_COLUMN);
            }

            $tablesMetadata = [];
            foreach ($allTables as $table) {
                $tablesMetadata[$table] = $discovery->discoverColumns($table, $databaseName);
            }

            // 3. Construir contenido incluyendo el checksum
            $mapStructure = [
                'checksum'      => $signature,
                'generated_at'  => date('Y-m-d H:i:s'),
                'relationships' => $relationships,
                'tables'        => $tablesMetadata
            ];

            $mapContent = self::buildMapContent($mapStructure);

            // 4. Guardar
            return file_put_contents(self::$outputFile, $mapContent) !== false;

        } catch (\Exception $e) {
            error_log("Error en SchemaMapper: " . $e->getMessage());
            return false;
        }
    }

    private static function buildMapContent(array $mapStructure): string
    {
        $export = var_export($mapStructure, true);

        // Convertimos 'array (' a '[' y ')' a ']'
        $export = preg_replace('/array \(/', '[', $export);
        $export = preg_replace('/\),/', '],', $export);
        $export = preg_replace('/\)$/', ']', $export);

        $content = "<?php\n";
        $content .= "// Auto-generated schema map by Meta\SchemaMapper\n";
        $content .= "return " . $export . ";\n";
        return $content;
    }

    public static function loadMap(): ?array
    {
        $path = self::$outputFile;
        if (file_exists($path) && is_readable($path)) {
            return include $path;
        }
        return null;
    }
}
