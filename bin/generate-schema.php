#!/usr/bin/env php
<?php

/**
 * Script de CLI para generar el archivo schema_map.php
 * 
 * Uso:
 *   php src/RapidBase/Cli/generate-schema.php <driver> <dsn> [usuario] [password]
 * 
 * Ejemplos:
 *   php generate-schema.php sqlite /path/to/database.sqlite
 *   php generate-schema.php pgsql "host=localhost;dbname=mydb" user pass
 */

// --- 1. Cargar dependencias ---
// La ruta es relativa al script actual (src/RapidBase/Cli) -> ../../../vendor/autoload.php
require_once __DIR__ . '/../../../vendor/autoload.php';

use RapidBase\Core\Conn;

// --- 2. Validar argumentos ---
if ($argc < 3) {
    echo "Error: Faltan argumentos.\n";
    echo "Uso: php generate-schema.php <driver> <dsn> [usuario] [password]\n";
    echo "Ejemplo: php generate-schema.php sqlite /path/to/db.sqlite\n";
    echo "Ejemplo: php generate-schema.php pgsql 'host=localhost;dbname=mydb' user pass\n";
    exit(1);
}

$driver = strtolower($argv[1]);
$dsnStr = $argv[2];
$user   = $argv[3] ?? '';
$pass   = $argv[4] ?? '';

// Construir el DSN completo según el driver
try {
    $fullDsn = match ($driver) {
        'sqlite' => "sqlite:" . $dsnStr,
        'mysql'  => "mysql:" . $dsnStr,
        'pgsql'  => "pgsql:" . $dsnStr,
        'sqlsrv' => "sqlsrv:" . $dsnStr,
        default  => throw new \InvalidArgumentException("Driver no soportado: '$driver'. Soportados: sqlite, mysql, pgsql, sqlsrv")
    };
} catch (\InvalidArgumentException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

// --- 3. Conectar a la base de datos ---
echo "Conectando a la base de datos ($driver)... ";
try {
    Conn::setup($fullDsn, $user, $pass, 'main');
    $pdo = Conn::get('main');
    $dbName = Conn::getDatabaseName('main') ?: 'default';
    echo "OK\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

// --- 4. Generar el schema map ---
echo "Generando schema map... ";
$mapper = new \RapidBase\Meta\SchemaMapper();
$outputFile = getcwd() . '/schema_map.php';
$mapper->setOutputFile($outputFile);

try {
    $success = $mapper->generate($pdo, $dbName);
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

if ($success) {
    echo "OK\n";
    echo "Archivo creado en: $outputFile\n";
    
    $map = include $outputFile;
    $tableCount = count($map['tables'] ?? []);
    $relCount = 0;
    if (isset($map['relationships'])) {
        foreach (['from', 'to'] as $dir) {
            if (isset($map['relationships'][$dir])) {
                foreach ($map['relationships'][$dir] as $table => $rels) {
                    $relCount += count($rels);
                }
            }
        }
    }
    echo "Incluye $tableCount tablas y $relCount relaciones.\n";
} else {
    echo "Error: No se pudo generar el schema map. Comprueba los permisos de escritura.\n";
    exit(1);
}