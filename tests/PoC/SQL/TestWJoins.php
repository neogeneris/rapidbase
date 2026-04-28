<?php
/**
 * Pruebas de JOIN automático para la clase W
 * Compara con JoinTest.php de SQL.php
 */

require_once __DIR__ . '/../../../src/RapidBase/Core/DBInterface.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/Conn.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/QueryResponse.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/SQL.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/Executor.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/Cache/CacheService.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/Cache/Adapters/DirectoryCacheAdapter.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/Gateway.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/DB.php';
require_once __DIR__ . '/W.php';

use RapidBase\Core\DB;
use RapidBase\Core\Conn;
use RapidBase\Core\Gateway;
use RapidBase\Core\Cache\CacheService;
use RapidBase\Core\W;

// Configuración - Usar archivo temporal en lugar de memoria
$testDbPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'test_w.db';
if (!is_dir(dirname($testDbPath))) mkdir(dirname($testDbPath), 0777, true);
if (file_exists($testDbPath)) unlink($testDbPath);

Conn::setup("sqlite:$testDbPath", '', '', 'main');
$testCachePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'cache';
if (!is_dir($testCachePath)) mkdir($testCachePath, 0777, true);
CacheService::init($testCachePath);

// Crear tablas
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

// Limpiar e insertar datos
DB::exec("DELETE FROM profiles");
DB::exec("DELETE FROM posts");
DB::exec("DELETE FROM users");

DB::insert('users', ['username' => 'Alice']);
DB::insert('users', ['username' => 'Bob']);
$aliceId = 1; $bobId = 2;

DB::insert('posts', ['user_id' => $aliceId, 'title' => 'Post de Alice 1', 'content' => 'Contenido A1']);
DB::insert('posts', ['user_id' => $aliceId, 'title' => 'Post de Alice 2', 'content' => 'Contenido A2']);
DB::insert('posts', ['user_id' => $bobId,   'title' => 'Post de Bob',     'content' => 'Contenido B']);

DB::insert('profiles', ['user_id' => $aliceId, 'bio' => 'Fanática de la F1', 'avatar' => 'alice.jpg']);
DB::insert('profiles', ['user_id' => $bobId,   'bio' => 'Piloto amateur',    'avatar' => 'bob.jpg']);

// Funciones de aserción
function assertJoin($msg, $cond) {
    echo $cond ? "  [OK] $msg\n" : "  [FAIL] $msg\n";
}

echo "==================================================\n";
echo "PRUEBAS DE JOIN AUTOMÁTICO - CLASE W\n";
echo "==================================================\n";

// Test 1: Auto-join automático con array plano
echo "\n--- Test 1: Auto-join automático ['users', 'posts'] ---\n";
[$sql, $params] = W::from(['users', 'posts'], ['users.id' => 1])->select();
echo "SQL: $sql\n";
assertJoin("Contiene JOIN", stripos($sql, 'JOIN') !== false);
assertJoin("Contiene ON", stripos($sql, 'ON') !== false);

// Test 2: Auto-join determinista (primera tabla es pivote)
echo "\n--- Test 2: Auto-join determinista ['users', ['posts']] ---\n";
[$sql, $params] = W::from(['users', ['posts']], ['users.id' => 1])->select();
echo "SQL: $sql\n";
assertJoin("Contiene JOIN", stripos($sql, 'JOIN') !== false);

// Test 3: Relaciones inline
echo "\n--- Test 3: Relaciones inline ---\n";
$relations = [
    'drivers' => [
        'users' => [
            'type' => 'belongsTo',
            'local_key' => 'user_id',
            'foreign_key' => 'id',
        ],
    ]
];
[$sql, $params] = W::from($relations)->select();
echo "SQL: $sql\n";
assertJoin("Contiene JOIN", stripos($sql, 'JOIN') !== false);
assertJoin("Contiene ON", stripos($sql, 'ON') !== false);

// Test 4: Alias de tablas
echo "\n--- Test 4: Alias de tablas 'users as u' ---\n";
[$sql, $params] = W::from('users as u', ['u.id' => 1])->select();
echo "SQL: $sql\n";
assertJoin("Contiene AS", stripos($sql, 'AS') !== false);

// Test 5: Quoting automático
echo "\n--- Test 5: Quoting automático ---\n";
W::setDriver('sqlite');
[$sql, $params] = W::from('users')->select();
echo "SQL: $sql\n";
assertJoin("Contiene quotes (\")", strpos($sql, '"') !== false);

// Test 6: Método count()
echo "\n--- Test 6: Método count() ---\n";
[$sql, $params] = W::from('users')->count();
echo "SQL: $sql\n";
assertJoin("Contiene COUNT(*)", stripos($sql, 'COUNT(*)') !== false);

// Test 7: Método exists()
echo "\n--- Test 7: Método exists() ---\n";
[$sql, $params] = W::from('users', ['id' => 1])->exists();
echo "SQL: $sql\n";
assertJoin("Contiene EXISTS", stripos($sql, 'EXISTS') !== false);

// Test 8: Limit polimórfico
echo "\n--- Test 8: Limit polimórfico ---\n";
[$sql, $params] = W::from('users')->select('*', 10);
echo "SQL (limit=10): $sql\n";
assertJoin("Contiene LIMIT", stripos($sql, 'LIMIT') !== false);

[$sql, $params] = W::from('users')->select('*', [20, 10]);
echo "SQL (offset=20, limit=10): $sql\n";
assertJoin("Contiene LIMIT y OFFSET", stripos($sql, 'LIMIT') !== false && stripos($sql, 'OFFSET') !== false);

// Test 9: Group By y Having
echo "\n--- Test 9: GROUP BY y HAVING ---\n";
[$sql, $params] = W::from('posts')->select('*', null, null, ['user_id'], ['COUNT(*)' => '> 1']);
echo "SQL: $sql\n";
assertJoin("Contiene GROUP BY", stripos($sql, 'GROUP BY') !== false);
assertJoin("Contiene HAVING", stripos($sql, 'HAVING') !== false);

echo "\n==================================================\n";
echo "PRUEBAS FINALIZADAS\n";
echo "==================================================\n";

CacheService::clear();
