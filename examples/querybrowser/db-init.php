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
    echo "Archivo de base de datos anterior eliminado: $dbFile\n";
}

// Conectar a la base de datos interna (usando RapidBase)
DB::setup("sqlite:$dbFile", '', '', 'internal');

// Eliminar tabla si existe para asegurar schema limpio
DB::exec("DROP TABLE IF EXISTS connections", [], 'internal');

// Crear tabla con la estructura correcta que usa la clase Connection
$sql = "CREATE TABLE connections (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT UNIQUE NOT NULL,
    driver TEXT NOT NULL,
    host TEXT,
    port INTEGER,
    database TEXT NOT NULL,
    username TEXT,
    password TEXT,
    description TEXT,
    environment TEXT DEFAULT 'development',
    status TEXT DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
)";
DB::exec($sql, [], 'internal');

echo "Tabla 'connections' creada correctamente en: $dbFile\n";

// Insertar datos de prueba iniciales
$initialData = [
    [
        'name' => 'Local SQLite',
        'driver' => 'sqlite',
        'host' => null,
        'port' => null,
        'database' => DATA_PATH . '/app.sqlite',
        'username' => null,
        'password' => null,
        'description' => 'Base de datos local SQLite para desarrollo',
        'environment' => 'development',
        'status' => 'active'
    ],
    [
        'name' => 'MySQL Test',
        'driver' => 'mysql',
        'host' => 'localhost',
        'port' => 3306,
        'database' => 'test_db',
        'username' => 'root',
        'password' => 'password',
        'description' => 'Conexión MySQL de prueba',
        'environment' => 'development',
        'status' => 'inactive'
    ]
];

foreach ($initialData as $data) {
    $insertSql = "INSERT INTO connections (name, driver, host, port, database, username, password, description, environment, status) 
                  VALUES (:name, :driver, :host, :port, :database, :username, :password, :description, :environment, :status)";
    DB::exec($insertSql, $data, 'internal');
}

echo "Datos iniciales insertados correctamente.\n";
echo "\nEstructura de la tabla:\n";
echo "----------------------\n";
echo "  - id: INTEGER PRIMARY KEY AUTOINCREMENT\n";
echo "  - name: TEXT UNIQUE NOT NULL\n";
echo "  - driver: TEXT NOT NULL (sqlite, mysql, pgsql)\n";
echo "  - host: TEXT\n";
echo "  - port: INTEGER\n";
echo "  - database: TEXT NOT NULL\n";
echo "  - username: TEXT\n";
echo "  - password: TEXT\n";
echo "  - description: TEXT\n";
echo "  - environment: TEXT DEFAULT 'development'\n";
echo "  - status: TEXT DEFAULT 'active'\n";
echo "  - created_at: DATETIME\n";
echo "  - updated_at: DATETIME\n";