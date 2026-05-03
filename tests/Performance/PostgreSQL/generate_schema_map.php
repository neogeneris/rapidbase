<?php
/**
 * Script para generar schema_map.php desde PostgreSQL
 * Este archivo debe ejecutarse una sola vez para crear el mapa del esquema
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use RapidBase\Core\Conn;

// Configurar conexión usando Conn::setup
Conn::setup('pgsql:host=localhost;port=5432;dbname=rapidbase_test', 'rapidbase_user', 'rapidbase_pass', 'postgresql');
$pdo = Conn::get('postgresql');

echo "Conexión a PostgreSQL establecida exitosamente.\n";

echo "Detectando tablas en PostgreSQL...\n";

// Usar SchemaMapper directamente
$mapper = new \RapidBase\Meta\SchemaMapper();
$mapper->setOutputFile(__DIR__ . '/schema_map.php');

echo "Generando schema_map.php...\n";

// Generar el schema map
$result = $mapper->generate($pdo, 'rapidbase_test', 'public', 'postgresql');

if ($result) {
    echo "✓ schema_map.php generado exitosamente en: " . __DIR__ . '/schema_map.php' . "\n";
    
    // Mostrar contenido del archivo generado
    echo "\n--- Contenido del schema_map.php ---\n";
    echo file_get_contents(__DIR__ . '/schema_map.php');
    echo "\n--- Fin del schema_map.php ---\n";
} else {
    echo "✗ Error al generar schema_map.php\n";
    exit(1);
}

