<?php
/**
 * migrate_connections.php - Agrega campos description y environment a la tabla connections
 * 
 * Uso: php migrate_connections.php
 * 
 * Este script agrega las columnas faltantes a la tabla connections si no existen.
 */

require_once 'config.php';

use RapidBase\Core\DB;

$dbFile = DATA_PATH . '/connections.sqlite';

if (!file_exists($dbFile)) {
    echo "[ERROR] La base de datos de conexiones no existe. Ejecuta primero db-init.php\n";
    exit(1);
}

// Conectar a la base de datos interna
DB::setup("sqlite:$dbFile", '', '', 'internal');

echo "[INFO] Verificando estructura de la tabla connections...\n";

// Verificar si la columna description ya existe
$result = DB::query("PRAGMA table_info(connections)", [], 'internal');
$columns = array_column($result->fetchAll(PDO::FETCH_ASSOC), 'name');

if (!in_array('description', $columns)) {
    echo "[INFO] Agregando columna 'description'...\n";
    DB::exec("ALTER TABLE connections ADD COLUMN description TEXT", [], 'internal');
    echo "[OK] Columna 'description' agregada.\n";
} else {
    echo "[INFO] Columna 'description' ya existe.\n";
}

if (!in_array('environment', $columns)) {
    echo "[INFO] Agregando columna 'environment'...\n";
    DB::exec("ALTER TABLE connections ADD COLUMN environment TEXT", [], 'internal');
    echo "[OK] Columna 'environment' agregada.\n";
} else {
    echo "[INFO] Columna 'environment' ya existe.\n";
}

echo "\n[OK] Migración completada exitosamente!\n";
