<?php
/**
 * Gateway Test Suite
 * 
 * Pruebas específicas para validar la integración del Mapa de Proyección
 * y el uso de FETCH_NUM en Gateway.php.
 */

require_once __DIR__ . '/../../../src/RapidBase/Core/SQL.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/Executor.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/Conn.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/DBInterface.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/DB.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/Gateway.php';

use RapidBase\RapidBase;
use RapidBase\Core\DB;
use RapidBase\Core\SQL;

// Configuración inicial
define('TEST_DSN', 'sqlite::memory:');
define('TEST_USER', '');
define('TEST_PASS', '');

class GatewayTest
{
    private static int $passed = 0;
    private static int $failed = 0;
    private static array $errors = [];

    public static function run(): void
    {
        echo "==================================================\n";
        echo "GATEWAY TEST SUITE (Projection Map & FETCH_NUM)\n";
        echo "==================================================\n\n";

        try {
            self::setupDatabase();
            
            // 1. Pruebas de Integridad del Mapa de Proyección
            self::testSimpleSelectProjection();
            self::testJoinProjectionNoCollisions();
            self::testStarExpansionOrder();
            
            // 2. Pruebas de Funcionalidad CRUD
            self::testInsertAndGetId();
            self::testUpdateAffectedRows();
            self::testDeleteAffectedRows();
            self::testCount();
            
            // 3. Pruebas de Auto-Referencia
            self::testSelfJoinProjection();
            
            // 4. Prueba de Eficiencia (FETCH_NUM)
            self::testFetchNumUsage();

        } catch (Exception $e) {
            self::fail("Setup Error", $e->getMessage());
        }

        self::printSummary();
    }

    private static function setupDatabase(): void
    {
        DB::setup(TEST_DSN, TEST_USER, TEST_PASS);
        
        // Limpiar estado previo
        SQL::clearQueryCache();
        SQL::setQueryCacheEnabled(false);

        $pdo = DB::getConnection();
        
        // Tabla Users
        $pdo->exec("CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            email TEXT UNIQUE
        )");

        // Tabla Posts
        $pdo->exec("CREATE TABLE posts (
            id INTEGER PRIMARY KEY,
            user_id INTEGER,
            title TEXT,
            content TEXT,
            FOREIGN KEY (user_id) REFERENCES users(id)
        )");

        // Tabla Categories (Auto-referenciada)
        $pdo->exec("CREATE TABLE categories (
            id INTEGER PRIMARY KEY,
            parent_id INTEGER,
            name TEXT,
            FOREIGN KEY (parent_id) REFERENCES categories(id)
        )");

        // Seed Data
        $pdo->exec("INSERT INTO users (name, email) VALUES ('Alice', 'alice@test.com')");
        $pdo->exec("INSERT INTO users (name, email) VALUES ('Bob', 'bob@test.com')");
        
        $pdo->exec("INSERT INTO posts (user_id, title, content) VALUES (1, 'First Post', 'Content A')");
        $pdo->exec("INSERT INTO posts (user_id, title, content) VALUES (1, 'Second Post', 'Content B')");
        $pdo->exec("INSERT INTO posts (user_id, title, content) VALUES (2, 'Bob Post', 'Content C')");

        $pdo->exec("INSERT INTO categories (id, parent_id, name) VALUES (1, NULL, 'Root')");
        $pdo->exec("INSERT INTO categories (id, parent_id, name) VALUES (2, 1, 'Child 1')");
        $pdo->exec("INSERT INTO categories (id, parent_id, name) VALUES (3, 1, 'Child 2')");

        // Definir Schema Manualmente para SQL.php
        // El formato debe ser compatible con el sistema de relaciones de RapidBase
        SQL::setRelationsMap([
            'from' => [
                'posts' => [
                    'users' => [
                        'type' => 'belongsTo',
                        'local_key' => 'user_id',
                        'foreign_key' => 'id'
                    ]
                ],
                'categories' => [
                    'categories' => [
                        'type' => 'belongsTo',
                        'local_key' => 'parent_id',
                        'foreign_key' => 'id'
                    ]
                ]
            ],
            'tables' => [
                'users' => ['id' => 'int', 'name' => 'string', 'email' => 'string'],
                'posts' => ['id' => 'int', 'user_id' => 'int', 'title' => 'string', 'content' => 'string'],
                'categories' => ['id' => 'int', 'parent_id' => 'int', 'name' => 'string']
            ]
        ]);
    }

    // --- TESTS DE PROYECCIÓN ---

    private static function testSimpleSelectProjection(): void
    {
        $result = \RapidBase\Core\Gateway::select('*', 'users');
        $data = $result['data'];

        self::assertIsArray($data, "Result should be array");
        self::assertCount($data, 2, "Should find 2 users");
        
        // Verificar estructura del primer registro
        $first = $data[0];
        self::assertKeyExists($first, 'id', "Should have 'id' key");
        self::assertKeyExists($first, 'name', "Should have 'name' key");
        self::assertEquals($first['name'], 'Alice', "First user should be Alice");

        self::pass("Simple Select Projection");
    }

    private static function testJoinProjectionNoCollisions(): void
    {
        // Este es el caso crítico: dos tablas con columna 'id'
        // Unir posts con users. Ambos tienen 'id'.
        $result = \RapidBase\Core\Gateway::select(
            fields: ['posts.id', 'posts.title', 'users.id as user_id', 'users.name'],
            table: ['posts', 'users'],
            where: []
        );
        $data = $result['data'];

        self::assertCount($data, 3, "Should find 3 posts");

        $firstPost = $data[0];
        
        // El mapa debe haber prevenido la colisión o usado alias implícitos
        if (isset($firstPost['user_id']) && isset($firstPost['id'])) {
            self::assertNotEmpty($firstPost['id'], "posts.id should exist");
            self::assertNotEmpty($firstPost['user_id'], "users.id should exist (aliased)");
            self::assertNotEmpty($firstPost['name'], "Should have user name from join");
            self::pass("Join Projection (With Aliases)");
        } else {
            self::fail("Join Projection", "Missing expected keys in joined result: " . json_encode(array_keys($firstPost)));
        }
    }

    private static function testStarExpansionOrder(): void
    {
        // Verifica que SELECT * expanda las columnas en el orden correcto según el schema
        $result = \RapidBase\Core\Gateway::select('*', 'users');
        $data = $result['data'];
        
        $first = $data[0];
        $keys = array_keys($first);
        
        // El orden debería ser id, name, email según el schema definido
        self::assertEquals($keys[0], 'id', "First key should be id");
        self::assertEquals($keys[1], 'name', "Second key should be name");
        self::assertEquals($keys[2], 'email', "Third key should be email");

        self::pass("Star Expansion Order");
    }

    // --- TESTS CRUD ---

    private static function testInsertAndGetId(): void
    {
        $id = \RapidBase\Core\Gateway::insert('users', ['name' => 'Charlie', 'email' => 'charlie@test.com']);
        
        self::assertTrue(is_numeric($id) && $id > 0, "Insert should return new ID");
        
        $result = \RapidBase\Core\Gateway::select('*', 'users', ['id' => $id]);
        $user = $result['data'][0] ?? null;
        self::assertEquals($user['name'], 'Charlie', "Inserted user should be retrievable");

        self::pass("Insert and Get ID");
    }

    private static function testUpdateAffectedRows(): void
    {
        // Actualizar el usuario ID 2 (Bob)
        $affected = \RapidBase\Core\Gateway::update('users', ['name' => 'Robert'], ['id' => 2]);
        
        self::assertEquals($affected, 1, "Update should affect 1 row");
        
        $result = \RapidBase\Core\Gateway::select('*', 'users', ['id' => 2]);
        $user = $result['data'][0] ?? null;
        self::assertEquals($user['name'], 'Robert', "Name should be updated");

        self::pass("Update Affected Rows");
    }

    private static function testDeleteAffectedRows(): void
    {
        // Primero insertamos uno para borrar
        $pdo = DB::getConnection();
        $pdo->exec("INSERT INTO users (name, email) VALUES ('ToDelete', 'delete@test.com')");
        
        // Buscamos el ID
        $result = \RapidBase\Core\Gateway::select('*', 'users', ['email' => 'delete@test.com']);
        $toDeleteId = $result['data'][0]['id'] ?? null;

        if ($toDeleteId) {
            $affected = \RapidBase\Core\Gateway::delete('users', ['id' => $toDeleteId]);
            self::assertEquals($affected, 1, "Delete should affect 1 row");
            
            $result = \RapidBase\Core\Gateway::select('*', 'users', ['id' => $toDeleteId]);
            $deletedUser = $result['data'][0] ?? null;
            self::assertNull($deletedUser, "Deleted user should not exist");
            self::pass("Delete Affected Rows");
        } else {
            self::fail("Delete Setup", "Could not find user to delete");
        }
    }

    private static function testCount(): void
    {
        $count = \RapidBase\Core\Gateway::count('posts');
        
        self::assertEquals($count, 3, "Should count 3 posts");
        
        $countFiltered = \RapidBase\Core\Gateway::count('posts', ['user_id' => 1]);
        self::assertEquals($countFiltered, 2, "Should count 2 posts for user 1");

        self::pass("Count Method");
    }

    // --- TESTS AVANZADOS ---

    private static function testSelfJoinProjection(): void
    {
        // Join consigo misma para obtener padres
        try {
            $result = \RapidBase\Core\Gateway::select(
                fields: ['categories.id', 'categories.name', 'parent.name as parent_name'],
                table: ['categories', 'categories as parent'],
                where: ['categories.parent_id' => 1]
            );
            $data = $result['data'];
            self::assertIsArray($data, "Self-join query should work");
            self::pass("Self-Join Basic Structure");
        } catch (Exception $e) {
            self::fail("Self-Join", $e->getMessage());
        }
    }

    private static function testFetchNumUsage(): void
    {
        // Esta prueba es conceptual ya que FETCH_NUM es interno.
        // Verificamos que Gateway no lance errores al procesar resultados numéricos
        // y convertirlos a asociativos mediante el mapa.
        
        $result = \RapidBase\Core\Gateway::select('*', 'posts');
        $results = $result['data'];
        
        // Si llegamos aquí sin errores de índice indefinido, el mapa funcionó
        foreach ($results as $row) {
            if (!is_array($row)) {
                self::fail("Fetch Num Usage", "Row is not an array");
                return;
            }
            // Verificar que las claves son strings (hidratación exitosa)
            $keys = array_keys($row);
            if (!is_string($keys[0])) {
                self::fail("Fetch Num Usage", "Keys are not strings (Hydration failed)");
                return;
            }
        }
        
        self::pass("FETCH_NUM Integration & Hydration");
    }

    // --- ASSERTIONS HELPERS ---

    private static function assertIsArray($var, string $msg): void {
        if (!is_array($var)) self::fail($msg, "Expected array, got " . gettype($var));
    }

    private static function assertCount(array $arr, int $expected, string $msg): void {
        if (count($arr) !== $expected) self::fail($msg, "Expected count $expected, got " . count($arr));
    }

    private static function assertKeyExists(array $arr, string $key, string $msg): void {
        if (!array_key_exists($key, $arr)) self::fail($msg, "Key '$key' does not exist");
    }

    private static function assertEquals($actual, $expected, string $msg): void {
        if ($actual !== $expected) self::fail($msg, "Expected '$expected', got '$actual'");
    }

    private static function assertTrue(bool $condition, string $msg): void {
        if (!$condition) self::fail($msg, "Condition is false");
    }

    private static function assertNull($var, string $msg): void {
        if ($var !== null) self::fail($msg, "Expected null, got " . var_export($var, true));
    }

    private static function assertNotEmpty($val, string $msg): void {
        if (empty($val) && $val !== '0') self::fail($msg, "Value is empty");
    }

    private static function pass(string $testName): void
    {
        self::$passed++;
        echo "✓ $testName\n";
    }

    private static function fail(string $testName, string $reason): void
    {
        self::$failed++;
        self::$errors[] = "$testName: $reason";
        echo "✗ $testName - $reason\n";
    }

    private static function printSummary(): void
    {
        echo "\n==================================================\n";
        echo "SUMMARY\n";
        echo "==================================================\n";
        echo "Passed: " . self::$passed . "\n";
        echo "Failed: " . self::$failed . "\n";
        
        if (!empty(self::$errors)) {
            echo "\nErrors:\n";
            foreach (self::$errors as $error) {
                echo "  - $error\n";
            }
        }
        
        echo "\n";
        if (self::$failed === 0) {
            echo "🎉 All tests passed!\n";
        } else {
            echo "⚠️  Some tests failed.\n";
            exit(1);
        }
    }
}

// Ejecutar
GatewayTest::run();
