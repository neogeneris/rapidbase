<?php

require_once __DIR__ . '/../../../../bin/RapidBase.php'; // bundle

use RapidBase\Meta\Discovery\DiscoveryFactory;

$pass = true;

echo "--- Iniciando SqlServerDiscoveryTest ---\n";

// Ajusta estas credenciales a las de tu SQL Server
$host     = 'localhost';
$port     = 1433;
$user     = 'sa';
$password = 'manager';
$database = 'tempdb';          // la base de datos que elegiste

$serverStr = $host;
if ($port) {
    $serverStr .= ',' . $port;
}
$dsn = "sqlsrv:Server={$serverStr};Database={$database};Encrypt=0;TrustServerCertificate=1";

echo "Conectando a $dsn... ";
try {
    $pdo = new \PDO($dsn, $user, $password, [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
    ]);
    echo "OK\n";
} catch (\Throwable $e) {
    echo "[SKIP] No se pudo conectar: " . $e->getMessage() . "\n";
    echo "Resultado: SqlServerDiscoveryTest omitido.\n";
    exit(0);
}

try {
    $discovery = DiscoveryFactory::create($pdo, 'dbo');   // schema por defecto de SQL Server

    // 1. Tablas
    $tables = $discovery->getTables($database);
    echo "Tablas encontradas: " . count($tables) . "\n";
    if (count($tables) === 0) {
        echo "[ERROR] No se encontraron tablas.\n";
        $pass = false;
    } else {
        echo "Primeras 5: " . implode(', ', array_slice($tables, 0, 5)) . "\n";
    }

    // 2. Columnas de la primera tabla
    if (!empty($tables)) {
        $firstTable = $tables[0];
        $columns = $discovery->discoverColumns($firstTable, $database);
        echo "Columnas de '$firstTable': " . count($columns) . "\n";
        if (count($columns) === 0) {
            echo "[ERROR] No se encontraron columnas.\n";
            $pass = false;
        } else {
            foreach ($columns as $col => $def) {
                echo "  $col (" . ($def['primary'] ? 'PK, ' : '') . $def['type'] . ")\n";
            }
        }
    }

    // 3. Relaciones
    $relations = $discovery->discoverRelationships($database);
    echo "Relaciones encontradas (from): " . count($relations['from'] ?? []) . "\n";
    echo "Relaciones encontradas (to): "   . count($relations['to'] ?? []) . "\n";

} catch (\Throwable $e) {
    echo "[ERROR] Excepción: " . $e->getMessage() . "\n";
    $pass = false;
}

echo "---------------------------\n";

if ($pass) {
    echo "Resultado: All SqlServerDiscoveryTest passed.\n";
} else {
    echo "Resultado: Some SqlServerDiscoveryTest failed.\n";
    exit(1);
}