<?php
/**
 * Script para reproducir el error 500 del QueryBrowser
 * Simula la petición: 
 * tables=["posts","post_tags","post_categories","comments","users","categories","tags"]
 * columns=["posts.title","comments.content","users.name","users.email"]
 */

require __DIR__ . '/vendor/autoload.php';

use RapidBase\Core\SQL\Q;
use RapidBase\Core\X;
use RapidBase\Core\SchemaMap;
use RapidBase\Meta\Discovery\DiscoveryFactory;

// 1. Crear estructura de prueba (mismo schema que el ejemplo)
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$queries = [
    "CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT)",
    "CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT, user_id INTEGER)",
    "CREATE TABLE comments (id INTEGER PRIMARY KEY, content TEXT, post_id INTEGER, user_id INTEGER)",
    "CREATE TABLE categories (id INTEGER PRIMARY KEY, name TEXT)",
    "CREATE TABLE tags (id INTEGER PRIMARY KEY, name TEXT)",
    "CREATE TABLE post_categories (id INTEGER PRIMARY KEY, post_id INTEGER, category_id INTEGER)",
    "CREATE TABLE post_tags (id INTEGER PRIMARY KEY, post_id INTEGER, tag_id INTEGER)",
    
    // Inserts dummy
    "INSERT INTO users (name, email) VALUES ('Carlos', 'carlos@test.com')",
    "INSERT INTO posts (title, user_id) VALUES ('Mi Post', 1)",
    "INSERT INTO comments (content, post_id, user_id) VALUES ('Comentario', 1, 1)",
    "INSERT INTO categories (name) VALUES ('General')",
    "INSERT INTO tags (name) VALUES ('demo')",
    "INSERT INTO post_categories (post_id, category_id) VALUES (1, 1)",
    "INSERT INTO post_tags (post_id, tag_id) VALUES (1, 1)",
];

foreach ($queries as $q) {
    $pdo->exec($q);
}

// 2. Generar SchemaMap dinámico desde esta conexión usando SchemaMapper::generate
$outputPath = __DIR__ . '/debug_schema_map.php';
RapidBase\Meta\SchemaMapper::setOutputFile($outputPath);
$result = RapidBase\Meta\SchemaMapper::generate($pdo, 'main', null, 'default');

if (!$result || !file_exists($outputPath)) {
    throw new RuntimeException("Failed to generate schema map");
}

$schemaMapData = include $outputPath;
SchemaMap::setMap($schemaMapData, 'default');

echo "Schema Map generado con " . count($schemaMapData['tables']) . " tablas\n";
echo "Tablas: " . implode(', ', array_keys($schemaMapData['tables'])) . "\n\n";

echo "=== Intentando reproducir el JOIN complejo ===\n";
echo "Tablas: posts, post_tags, post_categories, comments, users, categories, tags\n";
echo "Columnas: posts.title, comments.content, users.name, users.email\n\n";

try {
    // Simular la llamada exacta del frontend
    $tables = ["posts", "post_tags", "post_categories", "comments", "users", "categories", "tags"];
    $columns = ["posts.title", "comments.content", "users.name", "users.email"];
    
    $q = Q::table($tables)
          ->select($columns);
          
    echo "SQL Generado:\n" . $q->toSql() . "\n\n";
    
    echo "Ejecutando con X...\n";
    
    // Configurar PDO para X
    RapidBase\Core\ConnectionManager::setConnection('default', $pdo);
    
    $result = X::query($q);
    
    echo "✅ Éxito! Registros obtenidos: " . count($result->data) . "\n";
    if (count($result->data) > 0) {
        echo "Primer registro: " . json_encode($result->data[0], JSON_PRETTY_PRINT) . "\n";
    }
    
} catch (Throwable $e) {
    echo "❌ ERROR DETECTADO:\n";
    echo "Mensaje: " . $e->getMessage() . "\n";
    echo "Archivo: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}

// Limpieza
if (file_exists(__DIR__ . '/debug_schema_map.php')) {
    unlink(__DIR__ . '/debug_schema_map.php');
}
