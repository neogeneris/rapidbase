<?php
/**
 * Discovery y Seed para PostgreSQL
 * Crea la tabla users e inserta datos de prueba
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use RapidBase\Core\DB;

// Configuración para PostgreSQL
$dsn = 'pgsql:host=localhost;port=5432;dbname=rapidbase_test';
DB::setup($dsn, 'rapidbase', 'rapidbase123');

echo "=== Discovery PostgreSQL ===\n\n";

// Verificar conexión
try {
    $version = DB::value("SELECT version()");
    echo "✅ Conectado a PostgreSQL\n";
    echo "   Versión: " . substr($version, 0, 50) . "...\n\n";
} catch (Exception $e) {
    die("❌ Error de conexión: " . $e->getMessage() . "\n");
}

// Crear tabla users
echo "📋 Creando tabla 'users'...\n";

// Usar query() para DDL (no retorna lastInsertId)
$pdo = DB::getConnection();
$pdo->exec("DROP TABLE IF EXISTS users CASCADE");

$sql = "
CREATE TABLE users (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    username VARCHAR(50) UNIQUE NOT NULL,
    phone VARCHAR(20),
    website VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

COMMENT ON TABLE users IS 'Tabla de ejemplo para demostración de RapidBase';
COMMENT ON COLUMN users.name IS 'Nombre completo del usuario';
COMMENT ON COLUMN users.email IS 'Correo electrónico único';
COMMENT ON COLUMN users.username IS 'Nombre de usuario único';
COMMENT ON COLUMN users.phone IS 'Número de teléfono';
COMMENT ON COLUMN users.website IS 'Sitio web personal';
";

$pdo->exec($sql);
echo "✅ Tabla creada exitosamente\n\n";

// Insertar datos de prueba
echo "🌱 Insertando datos de prueba...\n";

$users = [
    ['James Doe', 'james.doe@example.com', 'jamesdoe', '+1-555-0101', 'https://james.dev'],
    ['Mary Smith', 'mary.smith@example.com', 'marysmith', '+1-555-0102', 'https://mary.io'],
    ['John Johnson', 'john.johnson@example.com', 'johnjohnson', '+1-555-0103', 'https://john.tech'],
    ['Patricia Brown', 'patricia.brown@example.com', 'patriciab', '+1-555-0104', 'https://patricia.net'],
    ['Michael Davis', 'michael.davis@example.com', 'michaeld', '+1-555-0105', 'https://michael.org'],
    ['Linda Miller', 'linda.miller@example.com', 'lindam', '+1-555-0106', 'https://linda.dev'],
    ['William Wilson', 'william.wilson@example.com', 'williamw', '+1-555-0107', 'https://william.io'],
    ['Elizabeth Moore', 'elizabeth.moore@example.com', 'elizabethm', '+1-555-0108', 'https://elizabeth.tech'],
    ['David Taylor', 'david.taylor@example.com', 'davidt', '+1-555-0109', 'https://david.net'],
    ['Jennifer Anderson', 'jennifer.anderson@example.com', 'jennifera', '+1-555-0110', 'https://jennifer.org'],
    ['Robert Thomas', 'robert.thomas@example.com', 'robertt', '+1-555-0111', 'https://robert.dev'],
    ['Susan Jackson', 'susan.jackson@example.com', 'susanj', '+1-555-0112', 'https://susan.io'],
    ['Charles White', 'charles.white@example.com', 'charlesw', '+1-555-0113', 'https://charles.tech'],
    ['Jessica Harris', 'jessica.harris@example.com', 'jessicah', '+1-555-0114', 'https://jessica.net'],
    ['Daniel Martin', 'daniel.martin@example.com', 'danielm', '+1-555-0115', 'https://daniel.org'],
    ['Sarah Thompson', 'sarah.thompson@example.com', 'saraht', '+1-555-0116', 'https://sarah.dev'],
    ['Matthew Garcia', 'matthew.garcia@example.com', 'matthewg', '+1-555-0117', 'https://matthew.io'],
    ['Karen Martinez', 'karen.martinez@example.com', 'karenm', '+1-555-0118', 'https://karen.tech'],
    ['Anthony Robinson', 'anthony.robinson@example.com', 'anthonyr', '+1-555-0119', 'https://anthony.net'],
    ['Nancy Clark', 'nancy.clark@example.com', 'nancyc', '+1-555-0120', 'https://nancy.org'],
];

$insertSql = "INSERT INTO users (name, email, username, phone, website) VALUES (?, ?, ?, ?, ?)";
$stmt = $pdo->prepare($insertSql);
foreach ($users as $user) {
    $stmt->execute($user);
}

echo "✅ " . count($users) . " usuarios insertados\n\n";

// Ejecutar Meta Discovery
echo "🔍 Ejecutando Meta Discovery...\n";
require_once __DIR__ . '/../../../src/RapidBase/Meta/SchemaMapper.php';

use RapidBase\Meta\SchemaMapper;

SchemaMapper::setOutputFile(__DIR__ . '/../../../src/RapidBase/Meta/schema_map.php');
SchemaMapper::generate($pdo, 'rapidbase_test');

$schemaMap = SchemaMapper::loadMap();
echo "✅ Discovery completado\n";
echo "   Tablas encontradas: " . count($schemaMap['tables']) . "\n";
echo "   Relaciones mapeadas: " . (count($schemaMap['relationships']['from']) + count($schemaMap['relationships']['to'])) / 2 . "\n";
echo "   Schema guardado en: src/RapidBase/Meta/schema_map.php\n\n";

// Mostrar información de la tabla
echo "📊 Información de la tabla 'users':\n";
$info = DB::many("
    SELECT 
        column_name,
        data_type,
        is_nullable,
        column_default
    FROM information_schema.columns
    WHERE table_name = 'users'
    ORDER BY ordinal_position
");

foreach ($info as $col) {
    $nullable = $col['is_nullable'] === 'YES' ? 'NULL' : 'NOT NULL';
    echo "   - {$col['column_name']}: {$col['data_type']} {$nullable}\n";
}

echo "\n=== ¡Listo para usar! ===\n";
echo "Ejecuta: php -S localhost:8080 -t examples/crud/users/\n";
echo "Abre: http://localhost:8080\n";
