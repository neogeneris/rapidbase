<?php

/**
 * JoinRelTest - Prueba de generación de SQL para relaciones con Q.
 *
 * Casos:
 * - 1:n (users → posts)
 * - n:1 (posts → users)
 * - 1:1 (users → profiles)
 * - n:m (posts → tags via post_tag)
 * - n:m inverso (tags → posts via post_tag)
 * - Auto-referencia (categories → categories)
 */

require_once __DIR__ . '/../../../../vendor/autoload.php';

use RapidBase\Core\SQL\Q;
use RapidBase\Core\SQL\ConditionMatrix;
use RapidBase\Core\SchemaMap;
use RapidBase\Core\Conn;

// Entorno
Conn::setup('sqlite::memory:', '', '', 'main');
ConditionMatrix::setDriver('sqlite');

// Crear tablas
$pdo = Conn::get();
$pdo->exec("DROP TABLE IF EXISTS users; CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)");
$pdo->exec("DROP TABLE IF EXISTS posts; CREATE TABLE posts (id INTEGER PRIMARY KEY, user_id INTEGER, title TEXT)");
$pdo->exec("CREATE TABLE profiles (user_id INTEGER PRIMARY KEY, bio TEXT)");
$pdo->exec("CREATE TABLE tags (id INTEGER PRIMARY KEY, name TEXT)");
$pdo->exec("CREATE TABLE post_tag (post_id INTEGER, tag_id INTEGER, PRIMARY KEY(post_id,tag_id))");
$pdo->exec("CREATE TABLE categories (id INTEGER PRIMARY KEY, name TEXT, parent_id INTEGER)");

// Insertar datos
$pdo->exec("INSERT INTO users VALUES (1,'Alice'), (2,'Bob')");
$pdo->exec("INSERT INTO posts VALUES (1,1,'Post 1'), (2,1,'Post 2'), (3,2,'Post 3')");
$pdo->exec("INSERT INTO profiles VALUES (1,'Bio A'), (2,'Bio B')");
$pdo->exec("INSERT INTO tags VALUES (1,'Racing'), (2,'Simracing')");
$pdo->exec("INSERT INTO post_tag VALUES (1,1), (1,2), (2,1), (3,2)");
$pdo->exec("INSERT INTO categories VALUES (1,'Electrónica',NULL), (2,'Computadoras',1), (3,'Laptops',2)");

// Mapa de relaciones completo
$schema = [
    'relationships' => [
        'from' => [
            'users' => [
                'posts'    => ['type' => 'hasMany', 'local_key' => 'id', 'foreign_key' => 'user_id'],
                'profiles' => ['type' => 'hasOne',  'local_key' => 'id', 'foreign_key' => 'user_id']
            ],
            'posts' => [
                'post_tag' => ['type' => 'hasMany', 'local_key' => 'id', 'foreign_key' => 'post_id']
            ],
            'tags' => [
                'post_tag' => ['type' => 'hasMany', 'local_key' => 'id', 'foreign_key' => 'tag_id']
            ],
            'categories' => [
                'categories' => ['type' => 'hasMany', 'local_key' => 'id', 'foreign_key' => 'parent_id']
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
            ],
            'categories' => [
                'categories' => ['type' => 'belongsTo', 'local_key' => 'parent_id', 'foreign_key' => 'id']
            ]
        ]
    ],
    'tables' => [
        'users'    => ['id' => ['type'=>'int'], 'name' => ['type'=>'varchar']],
        'posts'    => ['id' => ['type'=>'int'], 'user_id' => ['type'=>'int'], 'title' => ['type'=>'varchar']],
        'profiles' => ['user_id' => ['type'=>'int'], 'bio' => ['type'=>'text']],
        'tags'     => ['id' => ['type'=>'int'], 'name' => ['type'=>'varchar']],
        'post_tag' => ['post_id' => ['type'=>'int'], 'tag_id' => ['type'=>'int']],
        'categories'=> ['id' => ['type'=>'int'], 'name' => ['type'=>'varchar'], 'parent_id' => ['type'=>'int']]
    ]
];

SchemaMap::setMap($schema, 'main');

$passed = 0;
$failed = 0;

$test = function(string $description, callable $fn) use (&$passed, &$failed): void {
    try {
        $fn();
        echo "  [OK] $description\n";
        $passed++;
    } catch (Throwable $e) {
        echo "  [FAIL] $description\n";
        echo "         Error: " . $e->getMessage() . "\n";
        $failed++;
    }
};

echo "================================================\n";
echo "PRUEBAS DE RELACIONES CON Q (generación SQL)\n";
echo "================================================\n";

// ---------------------------------------------------------------------------
// 1:n (users → posts)
// ---------------------------------------------------------------------------
echo "\n--- 1:n (users → posts) ---\n";
$compiled = Q::from(['users', 'posts'], ['users.id' => 1])->select(['users.name', 'posts.title']);
$sql = $compiled->getSql();
$params = $compiled->getParams();
echo "SQL: $sql\n";
echo "Params: " . json_encode($params) . "\n";

$test("LEFT JOIN con ON", function() use ($sql) {
    assert(str_contains($sql, 'LEFT JOIN'));
    assert(str_contains($sql, 'ON "users"."id" = "posts"."user_id"'));
});
$test("WHERE en tabla principal", function() use ($sql) {
    assert(str_contains($sql, '"users"."id" = ?'));
});
$test("Parámetro correcto", function() use ($params) {
    assert($params[0] === 1);
});

// ---------------------------------------------------------------------------
// n:1 (posts → users)
// ---------------------------------------------------------------------------
echo "\n--- n:1 (posts → users) ---\n";
$compiled = Q::from(['posts', 'users'], ['posts.id' => 1])->select(['posts.title', 'users.name']);
$sql = $compiled->getSql();
echo "SQL: $sql\n";

$test("LEFT JOIN con ON (n:1)", function() use ($sql) {
    assert(str_contains($sql, 'LEFT JOIN'));
    assert(str_contains($sql, 'ON "users"."id" = "posts"."user_id"'));
});

// ---------------------------------------------------------------------------
// 1:1 (users → profiles)
// ---------------------------------------------------------------------------
echo "\n--- 1:1 (users → profiles) ---\n";
$compiled = Q::from(['users', 'profiles'], ['users.id' => 2])->select(['users.name', 'profiles.bio']);
$sql = $compiled->getSql();
echo "SQL: $sql\n";

$test("LEFT JOIN con ON (1:1)", function() use ($sql) {
    assert(str_contains($sql, 'ON "users"."id" = "profiles"."user_id"'));
});

// ---------------------------------------------------------------------------
// n:m (posts → post_tag → tags)
// ---------------------------------------------------------------------------
echo "\n--- n:m (posts → tags via post_tag) ---\n";
$compiled = Q::from(['posts', 'post_tag', 'tags'], ['posts.id' => 1])->select(['posts.title', 'tags.name']);
$sql = $compiled->getSql();
echo "SQL: $sql\n";

$test("Tres tablas en FROM/JOIN", function() use ($sql) {
    assert(str_contains($sql, '"posts"'));
    assert(str_contains($sql, '"post_tag"'));
    assert(str_contains($sql, '"tags"'));
});
$test("JOIN posts->post_tag", function() use ($sql) {
    assert(str_contains($sql, 'ON "posts"."id" = "post_tag"."post_id"'));
});
$test("JOIN tags->post_tag", function() use ($sql) {
    assert(str_contains($sql, 'ON "post_tag"."tag_id" = "tags"."id"'));
});

// ---------------------------------------------------------------------------
// n:m inverso (tags → post_tag → posts)
// ---------------------------------------------------------------------------
echo "\n--- n:m inverso (tags → posts via post_tag) ---\n";
$compiled = Q::from(['tags', 'post_tag', 'posts'], ['tags.name' => 'Racing'])->select(['tags.name', 'posts.title']);
$sql = $compiled->getSql();
echo "SQL: $sql\n";

$test("Tres tablas en orden inverso", function() use ($sql) {
    assert(str_contains($sql, '"tags"'));
    assert(str_contains($sql, '"post_tag"'));
    assert(str_contains($sql, '"posts"'));
});

// ---------------------------------------------------------------------------
// Auto-referencia (categories → categories)
// ---------------------------------------------------------------------------
echo "\n--- Auto-referencia (categories) ---\n";
$compiled = Q::from(['categories', 'categories as parent'], ['categories.name' => 'Laptops'])
    ->select(['categories.name', 'parent.name as parent_name']);
$sql = $compiled->getSql();
echo "SQL: $sql\n";

$test("Usa alias para auto-referencia", function() use ($sql) {
    assert(str_contains($sql, '"parent"'));
});
$test("ON con parent_id = id", function() use ($sql) {
    assert(str_contains($sql, '"categories"."parent_id" = "parent"."id"'));
});

echo "\n================================================\n";
echo "RESULTADO: $passed pasaron, $failed fallaron\n";
echo "================================================\n";