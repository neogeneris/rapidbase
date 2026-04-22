<?php
// File: src/Meta/Discovery/DiscoveryFactory.php

namespace RapidBase\Meta\Discovery;

use PDO;
use InvalidArgumentException;

class DiscoveryFactory
{
    /**
     * Crea una instancia de Discovery basada en el driver de PDO.
     *
     * @param PDO $pdo Instancia de PDO conectada a la base de datos.
     * @return DiscoveryInterface
     */
    public static function create(PDO $pdo, ?string $schema = null): DiscoveryInterface
    {
        $driverName = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        switch ($driverName) {
            case 'mysql':
                return new MySQLDiscovery($pdo);
            case 'pgsql':
                return new PostgreSQLDiscovery($pdo, $schema ?? 'public');
            case 'sqlsrv':
                return new SqlServerDiscovery($pdo, $schema ?? 'dbo');
            case 'oci':
                if (!$schema) {
                    throw new InvalidArgumentException("Oracle requiere especificar el schema");
                }
                return new OracleDiscovery($pdo, $schema);
            case 'sqlite':
                return new SQLiteDiscovery($pdo);
            default:
                throw new InvalidArgumentException("Discovery no disponible para el driver: $driverName");
        }
    }
}