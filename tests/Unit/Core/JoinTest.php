<?php

/**
 * JoinTest - Prueba de JOIN automático con Q.
 *
 * Casos:
 * - 1:n (users → posts)
 * - n:1 (posts → users)
 * - 1:1 (users → profiles)
 * - n:m (posts → tags via pivote)
 * - Auto-referencia (categories → categories)
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use RapidBase\Core\DB;
use RapidBase\Core\Conn;
use RapidBase\Core\Gateway;
use RapidBase\Core\Cache\CacheService;
use RapidBase\Core\SchemaMap;

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

// Limpiar e insertar datos
DB::exec("DELETE FROM post_tag"); DB::exec("DELETE FROM tags");
DB::exec("DELETE FROM profiles"); DB::exec("DELETE FROM posts");
DB::exec("DELETE FROM users");

DB::insert('users', ['username' => 'Alice']);
DB::insert('users', ['username' => 'Bob']);
$aliceId = 1; $bobId = 2;

DB::insert('posts', ['user_id' => $aliceId, 'title' => 'Post de Alice 1', 'content' => 'Contenido A1']);
DB::insert('posts', ['user_id' => $aliceId, 'title' => 'Post de Alice 2', 'content' => 'Contenido A2']);
DB::insert('posts', ['user_id' => $bobId,   'title' => 'Post de Bob',     'content' => 'Contenido B']);

DB::insert('profiles', ['user_id' => $aliceId, 'bio' => 'Fanática de la F1', 'avatar' => 'alice.jpg']);
DB::insert('profiles', ['user_id' => $bobId,   'bio' => 'Piloto amateur',    'avatar' => 'bob.jpg']);

DB::insert('tags', ['name' => 'Racing']);
DB::insert('tags', ['name' => 'Simracing']);
$racingId = 1; $simracingId = 2;

DB::insert('post_tag', ['post_id' => 1, 'tag_id' => $racingId]);
DB::insert('post_tag', ['post_id' => 1, 'tag_id' => $simracingId]);
DB::insert('post_tag', ['post_id' => 2, 'tag_id' => $racingId]);
DB::insert('post_tag', ['post_id' => 3, 'tag_id' => $simracingId]);

// 2. Mapa de relaciones y columnas
$fullMap = [
    'relationships' => [
        'from' => [
            'users' => [
                'posts'    => ['type' => 'hasMany',  'local_key' => 'id', 'foreign_key' => 'user_id'],
                'profiles' => ['type' => 'hasOne',   'local_key' => 'id', 'foreign_key' => 'user_id']
            ],
            'posts' => [
                'post_tag' => ['type' => 'hasMany',  'local_key' => 'id', 'foreign_key' => 'post_id']
            ],
            'tags' => [
                'post_tag' => ['type' => 'hasMany',  'local_key' => 'id', 'foreign_key' => 'tag_id']
            ]
        ],
        'to' => [
            'posts' => [
                'users' => ['type' => 'belongsTo', 'local_key' => 'user_id', 'foreign_key' => 'id']
            ],
            'profiles' => [
                'users' => ['type' => 'belongsTo', 'local_key' => 'user_id', 'foreign_key' => 'id']
            ],
            'post_tag' => [
                'posts' => ['type' => 'belongsTo', 'local_key' => 'post_id', 'foreign_key' => 'id'],
                'tags'  => ['type' => 'belongsTo', 'local_key' => 'tag_id',  'foreign_key' => 'id']
            ]
        ]
    ],
    'tables' => [
        'users'    => ['id' => ['type' => 'int'], 'username' => ['type' => 'varchar']],
        'posts'    => ['id' => ['type' => 'int'], 'user_id' => ['type' => 'int'], 'title' => ['type' => 'varchar'], 'content' => ['type' => 'text']],
        'profiles' => ['user_id' => ['type' => 'int'], 'bio' => ['type' => 'text'], 'avatar' => ['type' => 'varchar']],
        'tags'     => ['id' => ['type' => 'int'], 'name' => ['type' => 'varchar']],
        'post_tag' => ['post_id' => ['type' => 'int'], 'tag_id' => ['type' => 'int']]
    ]
];

SchemaMap::setMap($fullMap, 'main');

// 3. Funciones de aserción
function assertJoin($msg, $cond) {
    echo $cond ? "  [OK] $msg\n" : "  [FAIL] $msg\n";
}

echo "==================================================\n";
echo "PRUEBAS DE RELACIONES CON JOIN AUTOMÁTICO (Q)\n";
echo "==================================================\n";

// 1:n (users → posts)
echo "\n--- 1:n (users → posts) ---\n";
$result = Gateway::select(
    ['users.username', 'posts.title'],
    ['users', 'posts'],
    ['users.id' => 1],
    [], [], [], 0, false
);
$data = $result['data'];
assertJoin("Número de registros", count($data) === 2);
assertJoin("Columnas esperadas", isset($data[0]['username'], $data[0]['title']));

// n:1 (posts → users)
echo "\n--- n:1 (posts → users) ---\n";
$result = Gateway::select(
    ['posts.title', 'users.username'],
    ['posts', 'users'],
    ['posts.id' => 1],
    [], [], [], 0, false
);
$data = $result['data'];
assertJoin("Registro único", count($data) === 1);
assertJoin("Usuario relacionado", $data[0]['username'] === 'Alice');

// 1:1 (users → profiles)
echo "\n--- 1:1 (users → profiles) ---\n";
$result = Gateway::select(
    ['users.username', 'profiles.bio'],
    ['users', 'profiles'],
    ['users.id' => 2],
    [], [], [], 0, false
);
$data = $result['data'];
assertJoin("Un solo registro", count($data) === 1);
assertJoin("Bio correcta", $data[0]['bio'] === 'Piloto amateur');

// n:m (posts → post_tag → tags)
echo "\n--- n:m (posts → tags vía pivote) ---\n";
$result = Gateway::select(
    ['posts.title', 'tags.name'],
    ['posts', 'post_tag', 'tags'],
    ['posts.id' => 1],
    [], [], [], 0, false
);
$data = $result['data'];
$tagNames = array_column($data, 'name');
assertJoin("Dos tags", count($data) === 2);
assertJoin("Contiene 'Racing'", in_array('Racing', $tagNames));
assertJoin("Contiene 'Simracing'", in_array('Simracing', $tagNames));

// n:m inversa (tags → post_tag → posts)
echo "\n--- n:m inverso (tags → posts vía pivote) ---\n";
$result = Gateway::select(
    ['tags.name', 'posts.title'],
    ['tags', 'post_tag', 'posts'],
    ['tags.name' => 'Racing'],
    [], [], [], 0, false
);
$data = $result['data'];
$postTitles = array_column($data, 'title');
assertJoin("Dos posts con tag 'Racing'", count($data) === 2);
assertJoin("Post de Alice 1 presente", in_array('Post de Alice 1', $postTitles));
assertJoin("Post de Alice 2 presente", in_array('Post de Alice 2', $postTitles));

// Triple relación (users → posts → post_tag → tags)
echo "\n--- Relación triple (users → posts → tags) ---\n";
$result = Gateway::select(
    ['users.username', 'posts.title', 'tags.name'],
    ['users', 'posts', 'post_tag', 'tags'],
    ['users.username' => 'Alice'],
    [], [], [], 0, false
);
$data = $result['data'];
assertJoin("Número de combinaciones esperado (3)", count($data) === 3);
$found = false;
foreach ($data as $row) {
    if ($row['title'] === 'Post de Alice 2' && $row['name'] === 'Racing') $found = true;
}
assertJoin("Combinación correcta (Alice 2 + Racing)", $found);

// ========== AUTO-REFERENCIA (categorías) ==========
echo "\n--- Auto-referencia (categorías) ---\n";

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

$fullMap['relationships']['from']['categories']['categories'] = [
    'type' => 'belongsTo',
    'local_key' => 'parent_id',
    'foreign_key' => 'id'
];
$fullMap['tables']['categories'] = ['id' => ['type' => 'int'], 'name' => ['type' => 'varchar'], 'parent_id' => ['type' => 'int']];
SchemaMap::setMap($fullMap, 'main');

// Caso 1: alias manuales
$result = Gateway::select(
    ['categories.id AS cat_id', 'categories.name AS cat_name', 'parent.name AS parent_name'],
    ['categories', 'categories as parent'],
    ['categories.name' => 'Laptops'],
    [], [], [], 0, false
);
$data = $result['data'];
assertJoin("Auto‑referencia con alias manual", isset($data[0]['parent_name']) && $data[0]['parent_name'] === 'Computadoras');

// Caso 2: usando nombres de tabla cualificados
$result = Gateway::select(
    ['categories.name', 'parent.name AS parent_name'],
    ['categories', 'categories as parent'],
    ['categories.name' => 'Laptops'],
    [], [], [], 0, false
);
$data = $result['data'];
assertJoin("Auto‑referencia con alias implícito", isset($data[0]['parent_name']) && $data[0]['parent_name'] === 'Computadoras');

CacheService::clear();

echo "\n==================================================\n";
echo "PRUEBAS FINALIZADAS\n";
echo "==================================================\n";
