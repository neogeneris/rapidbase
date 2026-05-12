<?php
// db-init.php - Crear la base de datos interna de conexiones
require_once 'config.php';

use RapidBase\Core\DB;

// Asegurar que la carpeta data existe
if (!is_dir(DATA_PATH)) mkdir(DATA_PATH, 0777, true);

$dbFile = DATA_PATH . '/connections.sqlite';

// Si el archivo ya existe, lo eliminamos para recrearlo con la estructura correcta
if (file_exists($dbFile)) {
    unlink($dbFile);
}

// Conectar a la base de datos interna (usando RapidBase)
DB::setup("sqlite:$dbFile", '', '', 'internal');

// Crear tabla con la estructura correcta que usa api.php
// Nota: host, port, database son columnas separadas; dsn se construye dinámicamente
$sql = "CREATE TABLE IF NOT EXISTS connections (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT UNIQUE NOT NULL,
    driver TEXT NOT NULL,           -- 'sqlite', 'mysql', 'pgsql'
    host TEXT,                      -- host del servidor (para mysql/pgsql)
    port INTEGER,                   -- puerto (para mysql/pgsql)
    database TEXT NOT NULL,         -- nombre de la base de datos o path (para sqlite)
    username TEXT,                  -- usuario de la conexión
    password TEXT,                  -- contraseña de la conexión
    description TEXT,               -- descripción opcional de la conexión
    status TEXT DEFAULT 'dev',      -- estado: 'dev', 'qa', 'prod'
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)";
DB::exec($sql, [], 'internal');

echo "Tabla 'connections' creada correctamente en: $dbFile\n";
echo "Estructura:\n";
echo "  - id: INTEGER PRIMARY KEY AUTOINCREMENT\n";
echo "  - name: TEXT UNIQUE NOT NULL\n";
echo "  - driver: TEXT NOT NULL (sqlite, mysql, pgsql)\n";
echo "  - host: TEXT\n";
echo "  - port: INTEGER\n";
echo "  - database: TEXT NOT NULL\n";
echo "  - username: TEXT\n";
echo "  - password: TEXT\n";
echo "  - description: TEXT\n";
echo "  - status: TEXT DEFAULT 'dev'\n";
echo "  - created_at: DATETIME\n";