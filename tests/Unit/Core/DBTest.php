<?php

declare(strict_types=1);

namespace RapidBase\Tests\Unit\Core;

use RapidBase\Tdd\TestCase;
use RapidBase\Core\DB;


// Bootstrap centralizado para pruebas unitarias
require_once __DIR__ . '/../bootstrap.php';
/**
 * Tests unitarios para la clase DB usando el framework TDD de RapidBase
 */
class DBTest extends TestCase
{
    public function setUp(): void
    {
        // Configurar conexión SQLite en memoria para tests
        static $initialized = false;
        if (!$initialized) {
            DB::setup('sqlite::memory:', '', '', 'test');
            $initialized = true;
        }
        
        // Crear tabla de prueba antes de cada test
        DB::exec("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT UNIQUE,
            age INTEGER DEFAULT 0
        )");
        
        // Limpiar datos
        DB::exec("DELETE FROM users");
    }

    public function testSetupConnection(): void
    {
        $this->env()->test('DB setup creates valid connection', function($test) {
            $pdo = \RapidBase\Core\Conn::get();
            $test->assertInstanceOf(\PDO::class, $pdo);
        });
    }

    public function testInsertAndFind(): void
    {
        $this->env()->test('DB can insert and find records', function($test) {
            // Insertar un usuario
            $userId = DB::insert('users', [
                'name' => 'John Doe',
                'email' => 'john@example.com',
                'age' => 30
            ]);

            $test->assertGreaterThan(0, $userId);

            // Buscar el usuario
            $user = DB::find('users', ['id' => $userId]);
            
            $test->assertNotFalse($user);
            $test->assertEquals('John Doe', $user['name']);
            $test->assertEquals('john@example.com', $user['email']);
            $test->assertEquals(30, $user['age']);
        });
    }

    public function testCount(): void
    {
        $this->env()->test('DB count works with and without conditions', function($test) {
            // Insertar varios usuarios
            DB::insert('users', ['name' => 'User 1', 'email' => 'user1@test.com', 'age' => 25]);
            DB::insert('users', ['name' => 'User 2', 'email' => 'user2@test.com', 'age' => 30]);
            DB::insert('users', ['name' => 'User 3', 'email' => 'user3@test.com', 'age' => 35]);

            $count = DB::count('users', []);
            $test->assertEquals(3, $count);

            $countWithCondition = DB::count('users', ['age' => 30]);
            $test->assertEquals(1, $countWithCondition);
        });
    }

    public function testExists(): void
    {
        $this->env()->test('DB exists returns correct boolean', function($test) {
            DB::insert('users', ['name' => 'Existing User', 'email' => 'exists@test.com', 'age' => 40]);

            $test->assertTrue(DB::exists('users', ['email' => 'exists@test.com']));
            $test->assertFalse(DB::exists('users', ['email' => 'nonexistent@test.com']));
        });
    }

    public function testUpdate(): void
    {
        $this->env()->test('DB update modifies records correctly', function($test) {
            $userId = DB::insert('users', [
                'name' => 'Original Name',
                'email' => 'update@test.com',
                'age' => 20
            ]);

            $updated = DB::update('users', 
                ['name' => 'Updated Name', 'age' => 25], 
                ['id' => $userId]
            );

            $test->assertTrue($updated);

            $user = DB::find('users', ['id' => $userId]);
            $test->assertEquals('Updated Name', $user['name']);
            $test->assertEquals(25, $user['age']);
        });
    }

    public function testDelete(): void
    {
        $this->env()->test('DB delete removes records', function($test) {
            $userId = DB::insert('users', [
                'name' => 'To Delete',
                'email' => 'delete@test.com',
                'age' => 50
            ]);

            $test->assertTrue(DB::exists('users', ['id' => $userId]));

            $deleted = DB::delete('users', ['id' => $userId]);
            $test->assertTrue($deleted);

            $test->assertFalse(DB::exists('users', ['id' => $userId]));
        });
    }

    public function testUpsertInsert(): void
    {
        $this->env()->test('DB upsert inserts new records', function($test) {
            $result = DB::upsert('users', 
                ['name' => 'New User', 'email' => 'upsert@test.com', 'age' => 28],
                ['email' => 'upsert@test.com']
            );

            $test->assertGreaterThan(0, $result);
            $test->assertTrue(DB::exists('users', ['email' => 'upsert@test.com']));
        });
    }

    public function testUpsertUpdate(): void
    {
        $this->env()->test('DB upsert updates existing records', function($test) {
            // Primero insertar
            DB::insert('users', [
                'name' => 'Initial Name',
                'email' => 'upsert2@test.com',
                'age' => 22
            ]);

            // Luego hacer upsert (debería actualizar)
            $result = DB::upsert('users', 
                ['name' => 'Updated via Upsert', 'age' => 33],
                ['email' => 'upsert2@test.com']
            );

            $test->assertTrue($result);
            
            $user = DB::find('users', ['email' => 'upsert2@test.com']);
            $test->assertEquals('Updated via Upsert', $user['name']);
            $test->assertEquals(33, $user['age']);
        });
    }

    public function testQueryRaw(): void
    {
        $this->env()->test('DB query executes raw SQL', function($test) {
            DB::insert('users', ['name' => 'Raw Query User', 'email' => 'raw@test.com', 'age' => 45]);

            $stmt = DB::query("SELECT * FROM users WHERE email = ?", ['raw@test.com']);
            $test->assertNotFalse($stmt);
            
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            $test->assertEquals('Raw Query User', $row['name']);
        });
    }

    public function testOne(): void
    {
        $this->env()->test('DB one returns single record', function($test) {
            DB::insert('users', ['name' => 'One User', 'email' => 'one@test.com', 'age' => 27]);

            $user = DB::one('users', ['email' => 'one@test.com']);
            
            $test->assertNotFalse($user);
            $test->assertEquals('One User', $user['name']);
        });
    }

    public function testMany(): void
    {
        $this->env()->test('DB many returns multiple records', function($test) {
            DB::insert('users', ['name' => 'Many 1', 'email' => 'many1@test.com', 'age' => 21]);
            DB::insert('users', ['name' => 'Many 2', 'email' => 'many2@test.com', 'age' => 22]);
            DB::insert('users', ['name' => 'Many 3', 'email' => 'many3@test.com', 'age' => 23]);

            $users = DB::many("SELECT * FROM users WHERE age >= ? ORDER BY age", [22]);
            
            $test->assertCount(2, $users);
            $test->assertEquals('Many 2', $users[0]['name']);
            $test->assertEquals('Many 3', $users[1]['name']);
        });
    }

    public function testValue(): void
    {
        $this->env()->test('DB value returns single scalar value', function($test) {
            DB::insert('users', ['name' => 'Value User', 'email' => 'value@test.com', 'age' => 99]);

            $age = DB::value("SELECT age FROM users WHERE email = ?", ['value@test.com']);
            $test->assertEquals(99, $age);

            $count = DB::value("SELECT COUNT(*) FROM users");
            $test->assertGreaterThan(0, $count);
        });
    }

    public function testStream(): void
    {
        $this->env()->test('DB stream yields records one by one', function($test) {
            DB::insert('users', ['name' => 'Stream 1', 'email' => 'stream1@test.com', 'age' => 31]);
            DB::insert('users', ['name' => 'Stream 2', 'email' => 'stream2@test.com', 'age' => 32]);
            DB::insert('users', ['name' => 'Stream 3', 'email' => 'stream3@test.com', 'age' => 33]);

            $count = 0;
            foreach (DB::stream("SELECT * FROM users WHERE age >= ?", [32]) as $row) {
                $count++;
                $test->assertArrayHasKey('name', $row);
                $test->assertArrayHasKey('email', $row);
            }

            $test->assertEquals(2, $count);
        });
    }

    public function testAll(): void
    {
        $this->env()->test('DB all returns all records', function($test) {
            DB::insert('users', ['name' => 'All 1', 'email' => 'all1@test.com', 'age' => 41]);
            DB::insert('users', ['name' => 'All 2', 'email' => 'all2@test.com', 'age' => 42]);

            $all = DB::all('users', [], ['id' => 'ASC']);
            
            $test->assertIsArray($all);
            $test->assertGreaterThan(1, count($all));
        });
    }

    public function testGrid(): void
    {
        $this->env()->test('DB grid returns paginated response', function($test) {
            for ($i = 0; $i < 15; $i++) {
                DB::insert('users', [
                    'name' => "Grid User $i", 
                    'email' => "grid$i@test.com", 
                    'age' => 20 + $i
                ]);
            }

            // La firma actual es: grid(table, conditions, page, sort)
            // donde page puede ser: int (pagina n), array [page, perPage], o 0 (sin limites)
            $response = DB::grid('users', [], [1, 10], ['id' => 'ASC']);
            
            $test->assertInstanceOf(\RapidBase\Core\QueryResponse::class, $response);
            $test->assertCount(10, $response->data);
            $test->assertEquals(15, $response->total);
            $test->assertEquals(1, $response->state['page']);
            $test->assertEquals(10, $response->state['per_page']);
        });
    }
}

// Auto-ejecución cuando se ejecuta directamente
if (php_sapi_name() === 'cli' && isset($argv[0]) && basename($argv[0]) === 'DBTest.php') {
    DBTest::runAllTests();
}
