<?php

/**
 * Prueba de JOIN automático para distintos tipos de relaciones:
 * - 1:n (users ? posts)
 * - n:1 (posts ? users)
 * - 1:1 (users ? profiles)
 * - n:m (posts ? tags) usando tabla pivote post_tag
 * - Auto-referencia (categories ? categories)
 */

// Cargar dependencias
require_once __DIR__ . '/../../../src/RapidBase/Core/DBInterface.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/Conn.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/QueryResponse.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/SQL/Builders/Field.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/SQL/Builders/Table.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/SQL/Builders/Join.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/SQL/Builders/WhereTrait.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/SQL/Builders/SelectBuilder.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/SQL/Builders/InsertBuilder.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/SQL/Builders/UpdateBuilder.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/SQL/Builders/DeleteBuilder.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/SQL.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/Executor.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/Cache/CacheService.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/Cache/Adapters/DirectoryCacheAdapter.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/Gateway.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/DB.php';

use RapidBase\Core\DB;
use RapidBase\Core\Conn;
use RapidBase\Core\Gateway;
use RapidBase\Core\Cache\CacheService;

// Configuración
Conn::setup('sqlite::memory:', '', '', 'main');
$testCachePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'cache';
if (!is_dir($testCachePath)) mkdir($testCachePath, 0777, true);
CacheService::init($testCachePath);

// 1. Crear tablas
DB::exec("CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL
)");
DB::exec("CREATE TABLE IF NOT EXISTS posts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    title TEXT NOT NULL,
    content TEXT,
    FOREIGN KEY(user_id) REFERENCES users(id)
)");
DB::exec("CREATE TABLE IF NOT EXISTS profiles (
    user_id INTEGER PRIMARY KEY,
    bio TEXT,
    avatar TEXT,
    FOREIGN KEY(user_id) REFERENCES users(id)
)");
DB::exec("CREATE TABLE IF NOT EXISTS tags (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL
)");
DB::exec("CREATE TABLE IF NOT EXISTS post_tag (
    post_id INTEGER,
    tag_id INTEGER,
    PRIMARY KEY (post_id, tag_id),
    FOREIGN KEY(post_id) REFERENCES posts(id),
    FOREIGN KEY(tag_id) REFERENCES tags(id)
)");

// 2. Limpiar e insertar datos de prueba
DB::exec("DELETE FROM post_tag");
DB::exec("DELETE FROM tags");
DB::exec("DELETE FROM profiles");
DB::exec("DELETE FROM posts");
DB::exec("DELETE FROM users");

// Usuarios
DB::insert('users', ['username' => 'Alice']);
DB::insert('users', ['username' => 'Bob']);
$aliceId = 1; $bobId = 2;

// Posts (Alice tiene 2, Bob 1)
DB::insert('posts', ['user_id' => $aliceId, 'title' => 'Post de Alice 1', 'content' => 'Contenido A1']);
DB::insert('posts', ['user_id' => $aliceId, 'title' => 'Post de Alice 2', 'content' => 'Contenido A2']);
DB::insert('posts', ['user_id' => $bobId,   'title' => 'Post de Bob',     'content' => 'Contenido B']);

// Profiles (1:1)
DB::insert('profiles', ['user_id' => $aliceId, 'bio' => 'Fanática de la F1', 'avatar' => 'alice.jpg']);
DB::insert('profiles', ['user_id' => $bobId,   'bio' => 'Piloto amateur',    'avatar' => 'bob.jpg']);

// Tags
DB::insert('tags', ['name' => 'Racing']);
DB::insert('tags', ['name' => 'Simracing']);
$racingId = 1; $simracingId = 2;

// Relaciones n:m (post_tag)
DB::insert('post_tag', ['post_id' => 1, 'tag_id' => $racingId]);
DB::insert('post_tag', ['post_id' => 1, 'tag_id' => $simracingId]);
DB::insert('post_tag', ['post_id' => 2, 'tag_id' => $racingId]);
DB::insert('post_tag', ['post_id' => 3, 'tag_id' => $simracingId]);

// 3. Definir el mapa de relaciones completo (incluyendo tabla pivote)
$relationsMap = [
    'from' => [
        'users' => [
            'posts' => [
                'type' => 'hasMany',
                'local_key' => 'id',
                'foreign_key' => 'user_id'
            ],
            'profiles' => [
                'type' => 'hasOne',
                'local_key' => 'id',
                'foreign_key' => 'user_id'
            ]
        ],
        'posts' => [
            'post_tag' => [
                'type' => 'hasMany',
                'local_key' => 'id',
                'foreign_key' => 'post_id'
            ]
        ],
        'post_tag' => [
            'tags' => [
                'type' => 'hasOne',
                'local_key' => 'tag_id',
                'foreign_key' => 'id'
            ]
        ],
        'tags' => [
            'post_tag' => [
                'type' => 'hasMany',
                'local_key' => 'id',
                'foreign_key' => 'tag_id'
            ]
        ]
    ],
    'to' => [
        'posts' => [
            'users' => [
                'type' => 'belongsTo',
                'local_key' => 'user_id',
                'foreign_key' => 'id'
            ]
        ],
        'profiles' => [
            'users' => [
                'type' => 'belongsTo',
                'local_key' => 'user_id',
                'foreign_key' => 'id'
            ]
        ],
        'post_tag' => [
            'posts' => [
                'type' => 'belongsTo',
                'local_key' => 'post_id',
                'foreign_key' => 'id'
            ],
            'tags' => [
                'type' => 'belongsTo',
                'local_key' => 'tag_id',
                'foreign_key' => 'id'
            ]
        ]
    ]
];

DB::setRelationsMap($relationsMap);

// 4. Funciones de aserción
function assertJoin($msg, $cond) {
    echo $cond ? "  [OK] $msg\n" : "  [FAIL] $msg\n";
}

echo "==================================================\n";
echo "PRUEBAS DE RELACIONES CON JOIN AUTOMÁTICO\n";
echo "==================================================\n";

// 1:n
echo "\n--- 1:n (users ? posts) ---\n";
$result = DB::all(['users', 'posts'], ['users.id' => 1]);
assertJoin("Número de registros", count($result) === 2);
assertJoin("Columnas de ambas tablas", isset($result[0]['username']) && isset($result[0]['title']));

// n:1
echo "\n--- n:1 (posts ? users) ---\n";
$result = DB::all(['posts', 'users'], ['posts.id' => 1]);
assertJoin("Registro único", count($result) === 1);
assertJoin("Usuario relacionado", $result[0]['username'] === 'Alice');

// 1:1
echo "\n--- 1:1 (users ? profiles) ---\n";
$result = DB::all(['users', 'profiles'], ['users.id' => 2]);
assertJoin("Un solo registro", count($result) === 1);
assertJoin("Bio correcta", $result[0]['bio'] === 'Piloto amateur');

// n:m usando pivote (posts ? post_tag ? tags)
echo "\n--- n:m (posts ? tags vía pivote) ---\n";
$result = DB::all(['posts', 'post_tag', 'tags'], ['posts.id' => 1]);
$tagNames = array_column($result, 'name');
assertJoin("Dos tags", count($result) === 2);
assertJoin("Contiene 'Racing'", in_array('Racing', $tagNames));
assertJoin("Contiene 'Simracing'", in_array('Simracing', $tagNames));

// n:m inverso (tags ? post_tag ? posts)
echo "\n--- n:m inverso (tags ? posts vía pivote) ---\n";
$result = DB::all(['tags', 'post_tag', 'posts'], ['tags.name' => 'Racing']);
$postTitles = array_column($result, 'title');
assertJoin("Dos posts con tag 'Racing'", count($result) === 2);
assertJoin("Post de Alice 1 presente", in_array('Post de Alice 1', $postTitles));
assertJoin("Post de Alice 2 presente", in_array('Post de Alice 2', $postTitles));

// Relación triple (users ? posts ? post_tag ? tags)
echo "\n--- Relación triple (users ? posts ? tags) ---\n";
$result = DB::all(['users', 'posts', 'post_tag', 'tags'], ['users.username' => 'Alice']);
assertJoin("Número de combinaciones esperado (3)", count($result) === 3);
$found = false;
foreach ($result as $row) {
    if ($row['title'] === 'Post de Alice 2' && $row['name'] === 'Racing') $found = true;
}
assertJoin("Combinación correcta (Alice 2 + Racing)", $found);

// ========== AUTO-REFERENCIA (categorías) ==========
echo "\n--- Auto-referencia (categorías) ---\n";

// Crear tabla categories
DB::exec("CREATE TABLE IF NOT EXISTS categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    parent_id INTEGER NULL,
    FOREIGN KEY(parent_id) REFERENCES categories(id)
)");
DB::exec("DELETE FROM categories");
DB::insert('categories', ['name' => 'Electrónica', 'parent_id' => null]);
DB::insert('categories', ['name' => 'Computadoras', 'parent_id' => 1]);
DB::insert('categories', ['name' => 'Laptops', 'parent_id' => 2]);

// Añadir relación auto-referencia al mapa (usando el nombre real de la tabla)
$relationsMap['from']['categories']['categories'] = [
    'type' => 'belongsTo',
    'local_key' => 'parent_id',
    'foreign_key' => 'id'
];
DB::setRelationsMap($relationsMap);

// Caso 1: Usar alias manuales en fields (funciona sin esquema de tablas)
$result = Gateway::select(
    fields: ['categories.id AS cat_id', 'categories.name AS cat_name', 'parent.name AS parent_name'],
    table: ['categories', 'categories as parent'],
    where: ['categories.name' => 'Laptops']
);
$data = $result['data'];
assertJoin("Auto-referencia con alias manual", isset($data[0]['parent_name']) && $data[0]['parent_name'] === 'Computadoras');

// Caso 2: Intentar usar '*' sin esquema de tablas -> debe lanzar excepción
$exceptionCaught = false;
try {
    Gateway::select('*', ['categories', 'categories as parent'], ['categories.name' => 'Laptops']);
} catch (\RuntimeException $e) {
    $exceptionCaught = (strpos($e->getMessage(), 'requires the full schema map') !== false);
}
assertJoin("Excepción esperada por uso de '*' sin esquema", $exceptionCaught);

// Caso 3: Cargar esquema completo y probar '*' con alias automáticos
$fullMap = [
    'relationships' => $relationsMap,
    'tables' => [
        'categories' => [
            'id' => ['type' => 'int'],
            'name' => ['type' => 'varchar'],
            'parent_id' => ['type' => 'int']
        ]
    ]
];
DB::setRelationsMap($fullMap);   // <-- CORREGIDO

// Ahora '*' debería generar alias automáticos
$result = Gateway::select('*', ['categories', 'categories as parent'], ['categories.name' => 'Laptops']);
$data = $result['data'];
assertJoin("Auto-referencia con '*' y esquema completo", isset($data[0]['parent_name']) && $data[0]['parent_name'] === 'Computadoras');

// Limpiar caché
CacheService::clear();

echo "\n==================================================\n";
echo "PRUEBAS FINALIZADAS\n";
echo "==================================================\n";