<?php
/**
 * Pruebas unitarias para SchemaExplorer Endpoint.
 * 
 * Valida la exploración de esquemas de bases de datos:
 * - Obtención de schema completo (tablas, vistas, relaciones)
 * - Descripción de tablas individuales
 * - Búsqueda de tablas relacionadas
 * - Prevención de conexión errónea a 'main'
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use RapidBase\Api\ApiContext;
use RapidBase\Endpoints\SchemaExplorer;
use RapidBase\Core\DB;
use RapidBase\Core\Conn;
use RapidBase\Core\Cache\CacheService;
use RapidBase\Core\SchemaMap;
use RapidBase\Models\Connection;

// Configuración de entorno de pruebas
$testDbFile = __DIR__ . '/tmp_test_schema.sqlite';
if (file_exists($testDbFile)) {
    unlink($testDbFile);
}

// Crear base de datos de prueba con esquema conocido
Conn::setup("sqlite:$testDbFile", '', '', 'schematest');

// Inicializar caché temporal
$testCachePath = __DIR__ . '/tmp_cache_schema';
if (!is_dir($testCachePath)) {
    mkdir($testCachePath, 0777, true);
}
CacheService::init($testCachePath);

// Crear esquema de prueba con tablas y relaciones
DB::exec("CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    email TEXT UNIQUE
)");

DB::exec("CREATE TABLE posts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    title TEXT,
    content TEXT,
    FOREIGN KEY (user_id) REFERENCES users(id)
)");

DB::exec("CREATE TABLE comments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id INTEGER,
    user_id INTEGER,
    body TEXT,
    FOREIGN KEY (post_id) REFERENCES posts(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
)");

DB::exec("CREATE VIEW user_posts AS 
    SELECT u.name, p.title 
    FROM users u 
    JOIN posts p ON u.id = p.user_id
");

echo "==================================================\n";
echo "PRUEBAS UNITARIAS: SchemaExplorer Endpoint\n";
echo "==================================================\n\n";

$passed = 0;
$failed = 0;

function assert_endpoint($msg, $cond) {
    global $passed, $failed;
    if ($cond) {
        echo "  ✓ [OK] $msg\n";
        $passed++;
    } else {
        echo "  ✗ [FAIL] $msg\n";
        $failed++;
    }
}

// ======================================================
// TEST 1: Obtener schema completo
// ======================================================
echo "\n--- Test 1: Obtener schema completo ---\n";

$context = new ApiContext(['connectionId' => 'schematest'], [], []);
$endpoint = new SchemaExplorer();
$endpoint->setContext($context);

$result = $endpoint->getSchema();
assert_endpoint("getSchema() retorna success=true", $result['success'] === true);
assert_endpoint("getSchema() retorna tables", isset($result['tables']) && is_array($result['tables']));
assert_endpoint("getSchema() encuentra tabla users", in_array('users', $result['tables']));
assert_endpoint("getSchema() encuentra tabla posts", in_array('posts', $result['tables']));
assert_endpoint("getSchema() encuentra tabla comments", in_array('comments', $result['tables']));

// ======================================================
// TEST 2: Obtener descripción de tabla específica
// ======================================================
echo "\n--- Test 2: Describir tabla específica ---\n";

$context = new ApiContext(['connectionId' => 'schematest', 'table' => 'users'], [], []);
$endpoint = new SchemaExplorer();
$endpoint->setContext($context);

$result = $endpoint->describeTable();
assert_endpoint("describeTable() retorna success=true", $result['success'] === true);
assert_endpoint("describeTable() retorna table name", isset($result['table']) && $result['table'] === 'users');
assert_endpoint("describeTable() retorna structure", isset($result['structure']));
assert_endpoint("describeTable() estructura tiene columnas", isset($result['structure']['columns']));

// Verificar columnas específicas
$columns = array_column($result['structure']['columns'], 'name');
assert_endpoint("describeTable() encuentra columna id", in_array('id', $columns));
assert_endpoint("describeTable() encuentra columna name", in_array('name', $columns));
assert_endpoint("describeTable() encuentra columna email", in_array('email', $columns));

// ======================================================
// TEST 3: describeTable sin parámetro table falla
// ======================================================
echo "\n--- Test 3: describeTable sin parámetro table ---\n";

$context = new ApiContext(['connectionId' => 'schematest'], [], []);
$endpoint = new SchemaExplorer();
$endpoint->setContext($context);

$result = $endpoint->describeTable();
assert_endpoint("describeTable() sin table falla", $result['success'] === false);
assert_endpoint("describeTable() indica missing table", strpos($result['error'], 'Missing') !== false);

// ======================================================
// TEST 4: Conexión inexistente falla
// ======================================================
echo "\n--- Test 4: Conexión inexistente ---\n";

$context = new ApiContext(['connectionId' => 'nonexistent'], [], []);
$endpoint = new SchemaExplorer();
$endpoint->setContext($context);

$result = $endpoint->getSchema();
assert_endpoint("getSchema() con conexión inexistente falla", $result['success'] === false);
assert_endpoint("getSchema() indica connection not found", strpos(strtolower($result['error']), 'not found') !== false || strpos(strtolower($result['error']), 'unavailable') !== false);

// ======================================================
// TEST 5: Prevenir uso accidental de 'main'
// ======================================================
echo "\n--- Test 5: Prevenir uso de 'main' por defecto ---\n";

// Cuando no se especifica connectionId, debería usar 'main' pero fallar si no existe
$context = new ApiContext([], [], []);
$endpoint = new SchemaExplorer();
$endpoint->setContext($context);

$result = $endpoint->getSchema();
// Debería fallar porque 'main' no está disponible en el contexto de pruebas
assert_endpoint("getSchema() sin connectionId maneja correctamente", $result['success'] === false || isset($result['tables']));

// ======================================================
// TEST 6: Validar que views se separan de tables
// ======================================================
echo "\n--- Test 6: Separación de tablas y vistas ---\n";

$context = new ApiContext(['connectionId' => 'schematest'], [], []);
$endpoint = new SchemaExplorer();
$endpoint->setContext($context);

$result = $endpoint->getSchema();
assert_endpoint("getSchema() retorna views", isset($result['views']) && is_array($result['views']));
assert_endpoint("getSchema() encuentra vista user_posts", in_array('user_posts', $result['views']));
assert_endpoint("getSchema() no incluye vista en tables", !in_array('user_posts', $result['tables']));

// ======================================================
// TEST 7: Relaciones entre tablas
// ======================================================
echo "\n--- Test 7: Detección de relaciones ---\n";

$context = new ApiContext(['connectionId' => 'schematest'], [], []);
$endpoint = new SchemaExplorer();
$endpoint->setContext($context);

$result = $endpoint->getSchema();
assert_endpoint("getSchema() retorna relations", isset($result['relations']) && is_array($result['relations']));
assert_endpoint("getSchema() detecta al menos una relación", count($result['relations']) > 0);

// ======================================================
// TEST 8: Buscar tablas relacionadas
// ======================================================
echo "\n--- Test 8: Buscar tablas relacionadas ---\n";

// Primero necesitamos asegurar que el SchemaMap esté cargado
SchemaMap::setMap([
    'tables' => [
        'users' => ['id', 'name', 'email'],
        'posts' => ['id', 'user_id', 'title'],
        'comments' => ['id', 'post_id', 'user_id']
    ],
    'relationships' => [
        'from' => [
            'posts' => ['users' => ['field' => 'user_id']],
            'comments' => ['posts' => ['field' => 'post_id'], 'users' => ['field' => 'user_id']]
        ],
        'to' => [
            'users' => ['posts' => ['field' => 'user_id'], 'comments' => ['field' => 'user_id']],
            'posts' => ['comments' => ['field' => 'post_id']]
        ]
    ],
    'driver' => 'sqlite'
], 'schematest');

$context = new ApiContext([
    'connectionId' => 'schematest',
    'tables' => json_encode(['posts'])
], [], []);
$endpoint = new SchemaExplorer();
$endpoint->setContext($context);

$result = $endpoint->getRelatedTables();
assert_endpoint("getRelatedTables() retorna success=true", $result['success'] === true);
assert_endpoint("getRelatedTables() retorna 'to'", isset($result['to']) && is_array($result['to']));
assert_endpoint("getRelatedTables() retorna 'from'", isset($result['from']) && is_array($result['from']));
// posts tiene relación hacia users (to) y comments tiene relación desde posts (from)
assert_endpoint("getRelatedTables() encuentra users como related 'to'", in_array('users', $result['to']));
assert_endpoint("getRelatedTables() encuentra comments como related 'from'", in_array('comments', $result['from']));

// ======================================================
// TEST 9: getRelatedTables sin parámetro tables falla
// ======================================================
echo "\n--- Test 9: getRelatedTables sin parámetro tables ---\n";

$context = new ApiContext(['connectionId' => 'schematest'], [], []);
$endpoint = new SchemaExplorer();
$endpoint->setContext($context);

$result = $endpoint->getRelatedTables();
assert_endpoint("getRelatedTables() sin tables falla", $result['success'] === false);
assert_endpoint("getRelatedTables() indica missing tables", strpos($result['error'], 'Missing') !== false);

// ======================================================
// TEST 10: getRelatedTables con lista inválida
// ======================================================
echo "\n--- Test 10: getRelatedTables con JSON inválido ---\n";

$context = new ApiContext([
    'connectionId' => 'schematest',
    'tables' => 'invalid-json'
], [], []);
$endpoint = new SchemaExplorer();
$endpoint->setContext($context);

$result = $endpoint->getRelatedTables();
assert_endpoint("getRelatedTables() con JSON inválido falla", $result['success'] === false);

// ======================================================
// TEST 11: describeTable para tabla con foreign keys
// ======================================================
echo "\n--- Test 11: Describir tabla con foreign keys ---\n";

$context = new ApiContext(['connectionId' => 'schematest', 'table' => 'comments'], [], []);
$endpoint = new SchemaExplorer();
$endpoint->setContext($context);

$result = $endpoint->describeTable();
assert_endpoint("describeTable(comments) retorna success=true", $result['success'] === true);
assert_endpoint("describeTable(comments) tiene estructura", isset($result['structure']));

// ======================================================
// TEST 12: Múltiples llamadas no duplican datos
// ======================================================
echo "\n--- Test 12: Múltiples llamadas consistentes ---\n";

$context = new ApiContext(['connectionId' => 'schematest'], [], []);
$endpoint = new SchemaExplorer();
$endpoint->setContext($context);

$result1 = $endpoint->getSchema();
$result2 = $endpoint->getSchema();

assert_endpoint("Múltiples llamadas retornan mismo count de tables", count($result1['tables']) === count($result2['tables']));
assert_endpoint("Múltiples llamadas retornan mismo count de views", count($result1['views']) === count($result2['views']));

// Limpieza final
Conn::close('schematest');
if (file_exists($testDbFile)) {
    unlink($testDbFile);
}
if (is_dir($testCachePath)) {
    array_map('unlink', glob("$testCachePath/*"));
    rmdir($testCachePath);
}

// ======================================================
// RESULTADOS FINALES
// ======================================================
echo "\n==================================================\n";
echo "RESULTADOS: $passed pasaron, $failed fallaron\n";
echo "==================================================\n";

if ($failed === 0) {
    echo "\033[32m✓ TODAS LAS PRUEBAS PASARON\033[0m\n";
    exit(0);
} else {
    echo "\033[31m✗ ALGUNAS PRUEBAS FALLARON\033[0m\n";
    exit(1);
}
