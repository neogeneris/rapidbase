<?php
/**
 * Script para generar schema_map.php desde la base de datos de prueba
 * Este archivo debe ejecutarse una sola vez para crear el mapa del esquema
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../../vendor/autoload.php';

use RapidBase\Meta\SchemaMapper;
use RapidBase\Meta\Discovery\SQLiteDiscovery;

// Crear conexión PDO a SQLite en memoria
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "Creando tablas de prueba...\n";

// Crear las mismas tablas que usa el benchmark
$pdo->exec("CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    email TEXT NOT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE posts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    title TEXT NOT NULL,
    content TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
)");

$pdo->exec("CREATE TABLE categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    description TEXT
)");

$pdo->exec("CREATE TABLE post_categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id INTEGER NOT NULL,
    category_id INTEGER NOT NULL,
    FOREIGN KEY (post_id) REFERENCES posts(id),
    FOREIGN KEY (category_id) REFERENCES categories(id)
)");

$pdo->exec("CREATE TABLE comments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    content TEXT NOT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (post_id) REFERENCES posts(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
)");

$pdo->exec("CREATE TABLE tags (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL
)");

$pdo->exec("CREATE TABLE post_tags (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id INTEGER NOT NULL,
    tag_id INTEGER NOT NULL,
    FOREIGN KEY (post_id) REFERENCES posts(id),
    FOREIGN KEY (tag_id) REFERENCES tags(id)
)");

echo "Tablas creadas exitosamente.\n";

// Configurar discovery para SQLite
$discovery = new SQLiteDiscovery($pdo);
SchemaMapper::setDiscovery($discovery);

// Establecer ruta de salida
$outputFile = __DIR__ . '/schema_map.php';
SchemaMapper::setOutputFile($outputFile);

echo "Generando schema_map.php...\n";

// Generar el schema map
$result = SchemaMapper::generate($pdo);

if ($result) {
    echo "✓ schema_map.php generado exitosamente en: $outputFile\n";
    
    // Mostrar contenido del archivo generado
    echo "\n--- Contenido del schema_map.php ---\n";
    echo file_get_contents($outputFile);
    echo "\n--- Fin del schema_map.php ---\n";
} else {
    echo "✗ Error al generar schema_map.php\n";
    exit(1);
}
