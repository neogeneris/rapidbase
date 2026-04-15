<?php
/**
 * Pruebas unitarias para ORM\ActiveRecord\Model.
 * Valida el ciclo de vida de los objetos, Dirty Checking e Hidratación.
 */

require_once __DIR__ . '/../../../src/RapidBase/Core/Conn.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/QueryResponse.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/SQL.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/Executor.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/Cache/CacheService.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/Cache/Adapters/DirectoryCacheAdapter.php'; 
require_once __DIR__ . '/../../../src/RapidBase/Core/Gateway.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/DB.php';
require_once __DIR__ . '/../../../src/RapidBase/ORM/ActiveRecord/Model.php';

use RapidBase\Core\DB;
use RapidBase\Core\Conn;
use RapidBase\Core\SQL;
use RapidBase\ORM\ActiveRecord\Model;

// -------------------------------------------------------------------
// CONFIGURACIÓN DE ENTORNO
// -------------------------------------------------------------------
Conn::setup('sqlite::memory:', '', '', 'main');

// Tabla de prueba para el modelo
DB::exec("CREATE TABLE test_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL,
    email TEXT,
    age INTEGER,
    active INTEGER DEFAULT 1
)");

class TestUser extends Model {
    protected static string $table = 'test_users';
    protected static string $primaryKey = 'id';
}

echo "==================================================\n";
echo "PRUEBAS UNITARIAS: ORM\\ActiveRecord\\Model\n";
echo "==================================================\n\n";

$passed = 0; $failed = 0;

function resetTest() {
    SQL::reset();
    DB::exec("DELETE FROM test_users");
    DB::exec("DELETE FROM sqlite_sequence WHERE name='test_users'");
}

function assert_model($msg, $cond) {
    global $passed, $failed;
    if ($cond) { echo "  \033[32m[OK]\033[0m $msg\n"; $passed++; }
    else { echo "  \033[31m[FAIL]\033[0m $msg\n"; $failed++; }
}

// -------------------------------------------------------------------
// INICIO DE PRUEBAS
// -------------------------------------------------------------------

resetTest();

// --- TEST 1: Creación Estática ---
echo "Test 1: Creación de registros (Static Create)\n";
$id = TestUser::create(['username' => 'nelson', 'email' => 'n@veon.com', 'age' => 30]);
assert_model("Create devuelve ID 1", $id == 1);
assert_model("Registro existe en DB", DB::count('test_users') == 1);

// --- TEST 2: Hidratación y Lectura ---
echo "\nTest 2: Hidratación (Read)\n";
$user = TestUser::read(1);
assert_model("Read devuelve instancia de TestUser", $user instanceof TestUser);
assert_model("Atributos cargados: $user->username", $user->username === 'nelson');
assert_model("Objeto nace 'limpio' tras lectura", !$user->isDirty());

// --- TEST 3: Dirty Checking (Detección de cambios) ---
echo "\nTest 3: Dirty Checking (Instancia)\n";
$user->age = 31; // Modificamos un valor
assert_model("isDirty('age') detecta el cambio", $user->isDirty('age'));
assert_model("isDirty() general detecta cambios", $user->isDirty());
assert_model("getDirty() contiene solo la edad", isset($user->getDirty()['age']) && count($user->getDirty()) == 1);

// --- TEST 4: Persistencia de Cambios (Update) ---
echo "\nTest 4: Persistencia con save()\n";
$res = $user->save();
assert_model("save() devuelve true", $res === true);
assert_model("Objeto queda limpio tras guardar", !$user->isDirty());

$ageInDb = DB::value("SELECT age FROM test_users WHERE id = 1");
assert_model("Valor actualizado físicamente en DB: $ageInDb", $ageInDb == 31);

// --- TEST 5: Colecciones (All) ---
echo "\nTest 5: Colecciones de Objetos (All)\n";
TestUser::create(['username' => 'piloto', 'email' => 'p@veon.com']);
$all = TestUser::all();
assert_model("all() devuelve array con 2 elementos", count($all) == 2);
assert_model("Los elementos son instancias de TestUser", $all[1] instanceof TestUser);

// --- TEST 6: Eliminación ---
echo "\nTest 6: Eliminación (Destroy)\n";
$userToDelete = TestUser::read(2);
$resDel = $userToDelete->destroy();
assert_model("destroy() devuelve true", $resDel === true);
assert_model("Registro eliminado de la DB", DB::count('test_users') == 1);

// -------------------------------------------------------------------
// RESUMEN FINAL
// -------------------------------------------------------------------
echo "\n==================================================\n";
echo "RESULTADO: " . ($failed === 0 ? "PASADO" : "FALLIDO") . "\n";
echo "PASADAS: $passed | FALLIDAS: $failed\n";
echo "==================================================\n";