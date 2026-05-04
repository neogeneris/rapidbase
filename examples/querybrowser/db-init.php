<?php
// init_connections_db.php - Crear la base de datos interna de conexiones
require_once 'config.php';

use RapidBase\Core\DB;

// Asegurar que la carpeta data existe
if (!is_dir(DATA_PATH)) mkdir(DATA_PATH, 0777, true);

$dbFile = DATA_PATH . '/connections.sqlite';
// Conectar a la base de datos interna (usando RapidBase)
DB::setup("sqlite:$dbFile", '', '', 'internal');

// Crear tabla si no existe
$sql = "CREATE TABLE IF NOT EXISTS connections (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT UNIQUE NOT NULL,
    driver TEXT NOT NULL,   -- 'sqlite', 'mysql', 'pgsql'
    dsn TEXT NOT NULL,      -- cadena de conexión (ej: 'sqlite:/path', 'mysql:host=...;dbname=...')
    username TEXT,
    password TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)";
DB::exec($sql, [], 'internal');

echo "Tabla 'connections' creada correctamente.\n";