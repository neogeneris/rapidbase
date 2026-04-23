<?php
/**
 * RapidBase Example Configuration
 * Solo configura la DB y carga el autoload. Sin funciones extra.
 */

// Evitar redefinición de constantes
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 3));
}

require_once BASE_PATH . '/vendor/autoload.php';

use RapidBase\Core\DB;

// Configuración SQLite
$driver = 'sqlite';
$dbPath = __DIR__ . '/database.sqlite';

try {
    // Firma correcta: setup(dsn, user, pass, name)
    // Para SQLite: dsn = "sqlite:path", user/pass vacíos
    DB::setup("sqlite:{$dbPath}", '', '', 'main');
    
    // Cargar schema_map si existe
    $schemaFile = __DIR__ . '/schema_map.php';
    if (file_exists($schemaFile)) {
        DB::loadRelationsMap($schemaFile);
    }
} catch (Exception $e) {
    die("DB Connection Error: " . $e->getMessage());
}