<?php

/**
 * Suite de Pruebas para Core\DB
 * Versión extendida que cubre los métodos más importantes.
 */

require_once __DIR__ . '/../../../src/RapidBase/Core/Conn.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/QueryResponse.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/SQL.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/Executor.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/Cache/CacheService.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/Cache/Adapters/DirectoryCacheAdapter.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/Gateway.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/DB.php';

use RapidBase\Core\DB;
use RapidBase\Core\Conn;
use RapidBase\Core\Cache\CacheService;
use RapidBase\Core\Gateway;

// Configuración de la base de datos en memoria
Conn::setup('sqlite::memory:', '', '', 'main');

// Ruta única de caché para pruebas
$testCachePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'cache';
if (!is_dir($testCachePath)) {
    mkdir($testCachePath, 0777, true);
}
CacheService::init($testCachePath);

// Crear tabla de pruebas
DB::exec("CREATE TABLE IF NOT EXISTS players (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    points INTEGER DEFAULT 0,
    email TEXT UNIQUE
)");

$failed = 0;
function assert_db($msg, $cond) {
    global $failed;
    if ($cond) {
        echo "  [OK] $msg\n";
    } else {
        echo "  [FAIL] $msg\n";
        $failed++;
    }
}

function deleteDirectory($dir) {
    if (!file_exists($dir)) return true;
    if (!is_dir($dir)) return unlink($dir);
    foreach (scandir($dir) as $item) {
        if ($item == '.' || $item == '..') continue;
        if (!deleteDirectory($dir . DIRECTORY_SEPARATOR . $item)) return false;
    }
    return rmdir($dir);
}

function resetDB() {
    global $testCachePath;
    DB::exec("DELETE FROM players");
    DB::exec("DELETE FROM sqlite_sequence WHERE name='players'");
    CacheService::clear();
    if (is_dir($testCachePath)) {
        deleteDirectory($testCachePath);
    }
    mkdir($testCachePath, 0777, true);
    CacheService::init($testCachePath);
}

// Clase de prueba para readAs
class Player {
    public $id;
    public $name;
    public $points;
    public static function getTable() { return 'players'; }
}

echo "==================================================\n";
echo "CORE\\DB: PRUEBAS EXTENDIDAS\n";
echo "==================================================\n";

// BLOQUE 0: Estado y ejecución directa
echo "\n--- Bloque 0: Estado y ejecución directa ---\n";
resetDB();

$resExec = DB::exec("CREATE TABLE IF NOT EXISTS test_exec (id INT)");
assert_db("exec devuelve array (no false)", is_array($resExec));

$stmt = DB::query("SELECT 1 as num");
assert_db("query devuelve PDOStatement", $stmt instanceof \PDOStatement);
$row = $stmt->fetch(\PDO::FETCH_ASSOC);
assert_db("query ejecuta correctamente", $row['num'] == 1);

DB::insert('players', ['name' => 'OneTest', 'points' => 10]);
$one = DB::one("SELECT * FROM players WHERE name = ?", ['OneTest']);
assert_db("one retorna array asociativo", is_array($one) && $one['name'] == 'OneTest');

$many = DB::many("SELECT * FROM players");
assert_db("many retorna array", is_array($many) && count($many) >= 1);

$val = DB::value("SELECT points FROM players WHERE name = ?", ['OneTest']);
assert_db("value retorna valor escalar", $val == 10);

// BLOQUE 1: CRUD básico
echo "\n--- Bloque 1: CRUD básico ---\n";
resetDB();

$lastId = DB::insert('players', ['name' => 'Alice', 'points' => 50]);
assert_db("insert retorna lastId > 0", $lastId > 0);

$found = DB::find('players', ['name' => 'Alice']);
assert_db("find encuentra registro", is_array($found) && $found['points'] == 50);

$total = DB::count('players');
assert_db("count devuelve número correcto", $total == 1);

$exists = DB::exists('players', ['name' => 'Alice']);
assert_db("exists true para registro existente", $exists === true);
$notExists = DB::exists('players', ['name' => 'Bob']);
assert_db("exists false para inexistente", $notExists === false);

$updated = DB::update('players', ['points' => 75], ['name' => 'Alice']);
assert_db("update retorna true", $updated === true);
$updatedRecord = DB::find('players', ['name' => 'Alice']);
assert_db("update modificó puntos", $updatedRecord['points'] == 75);

$deleted = DB::delete('players', ['name' => 'Alice']);
assert_db("delete retorna true", $deleted === true);
$afterDelete = DB::count('players');
assert_db("registro eliminado", $afterDelete == 0);

// BLOQUE 2: create, upsert, read, readAs
echo "\n--- Bloque 2: create, upsert, read, readAs ---\n";
resetDB();

$createId = DB::create('players', ['name' => 'Bob', 'points' => 30]);
assert_db("create devuelve lastId", $createId !== false && $createId > 0);

$upsertUpdate = DB::upsert('players', ['points' => 35], ['name' => 'Bob']);
assert_db("upsert actualiza existente", $upsertUpdate === true);
$bob = DB::find('players', ['name' => 'Bob']);
assert_db("upsert modificó puntos", $bob['points'] == 35);

$upsertInsert = DB::upsert('players', ['name' => 'Carol', 'points' => 40], ['name' => 'Carol']);
assert_db("upsert inserta nuevo", $upsertInsert > 0);
$carol = DB::find('players', ['name' => 'Carol']);
assert_db("nuevo registro insertado", $carol['points'] == 40);

$readRecord = DB::read('players', ['name' => 'Carol']);
assert_db("read funciona igual que find", $readRecord['name'] == 'Carol');

$player = DB::readAs(Player::class, ['name' => 'Carol']);
assert_db("readAs mapea a objeto Player", $player instanceof Player && $player->name == 'Carol');

// BLOQUE 3: all, list, grid
echo "\n--- Bloque 3: all, list, grid ---\n";
resetDB();
DB::insert('players', ['name' => 'David', 'points' => 10]);
DB::insert('players', ['name' => 'Eva',   'points' => 20]);
DB::insert('players', ['name' => 'Frank', 'points' => 30]);

$all = DB::all('players', [], ['points']);
assert_db("all devuelve array de 3 registros", count($all) == 3);
assert_db("all respeta orden", $all[0]['points'] == 10 && $all[2]['points'] == 30);

$list = DB::list('players', [], ['-points'], 1, 2);
assert_db("list respeta límite", count($list) == 2);
assert_db("list orden correcto", $list[0]['points'] == 30 && $list[1]['points'] == 20);

$grid = DB::grid('players', [], ['-points'], 1, 2);
assert_db("grid es QueryResponse", $grid instanceof \Core\QueryResponse);
assert_db("grid contiene total", $grid->total == 3);
assert_db("grid datos paginados", count($grid->data) == 2);

// BLOQUE 4: streaming
echo "\n--- Bloque 4: streaming ---\n";
$stream = DB::stream("SELECT * FROM players ORDER BY id");
assert_db("stream devuelve Generator", $stream instanceof \Generator);
$countStream = 0;
foreach ($stream as $row) {
    $countStream++;
}
assert_db("generator itera sobre todos", $countStream == 3);

// BLOQUE 5: Estado y metadatos
echo "\n--- Bloque 5: Estado y metadatos ---\n";
resetDB();
DB::insert('players', ['name' => 'Grace', 'points' => 99]);
$status = DB::status();
assert_db("status devuelve array", is_array($status));
assert_db("status contiene 'success'", isset($status['success']));
$lastId = DB::lastInsertId();
assert_db("lastInsertId > 0", $lastId > 0);
$rows = DB::getAffectedRows();
assert_db("getAffectedRows es entero", is_int($rows) && $rows >= 0);
$error = DB::getLastError();
assert_db("getLastError puede ser null", $error === null || is_string($error));

// Probar error forzado usando un método que pase por Gateway (no lanza excepción, solo registra error)
DB::count('tabla_inexistente');
$errorMsg = DB::getLastError();
assert_db("getLastError captura error", !empty($errorMsg));

echo "\n==================================================\n";
if ($failed === 0) {
    echo "RESULTADO: TODAS LAS PRUEBAS PASARON\n";
} else {
    echo "RESULTADO: FALLARON $failed PRUEBAS\n";
}
echo "==================================================\n";
