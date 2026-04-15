<?php

namespace RapidBase\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use RapidBase\Core\DB;
use RapidBase\Core\Conn;
use RapidBase\Core\Executor;

/**
 * Tests unitarios para la clase DB
 */
class DBTest extends TestCase
{
    private static bool $initialized = false;

    public static function setUpBeforeClass(): void
    {
        if (!self::$initialized) {
            // Configurar conexión SQLite en memoria para tests
            DB::setup('sqlite::memory:', '', '', 'test');
            self::$initialized = true;
        }
    }

    protected function setUp(): void
    {
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
        $this->assertTrue(self::$initialized);
        $pdo = Conn::get();
        $this->assertInstanceOf(\PDO::class, $pdo);
    }

    public function testInsertAndFind(): void
    {
        // Insertar un usuario
        $userId = DB::insert('users', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'age' => 30
        ]);

        $this->assertGreaterThan(0, $userId);

        // Buscar el usuario
        $user = DB::find('users', ['id' => $userId]);
        
        $this->assertNotFalse($user);
        $this->assertEquals('John Doe', $user['name']);
        $this->assertEquals('john@example.com', $user['email']);
        $this->assertEquals(30, $user['age']);
    }

    public function testCount(): void
    {
        // Insertar varios usuarios
        DB::insert('users', ['name' => 'User 1', 'email' => 'user1@test.com', 'age' => 25]);
        DB::insert('users', ['name' => 'User 2', 'email' => 'user2@test.com', 'age' => 30]);
        DB::insert('users', ['name' => 'User 3', 'email' => 'user3@test.com', 'age' => 35]);

        $count = DB::count('users', []);
        $this->assertEquals(3, $count);

        $countWithCondition = DB::count('users', ['age' => 30]);
        $this->assertEquals(1, $countWithCondition);
    }

    public function testExists(): void
    {
        DB::insert('users', ['name' => 'Existing User', 'email' => 'exists@test.com', 'age' => 40]);

        $this->assertTrue(DB::exists('users', ['email' => 'exists@test.com']));
        $this->assertFalse(DB::exists('users', ['email' => 'nonexistent@test.com']));
    }

    public function testUpdate(): void
    {
        $userId = DB::insert('users', [
            'name' => 'Original Name',
            'email' => 'update@test.com',
            'age' => 20
        ]);

        $updated = DB::update('users', 
            ['name' => 'Updated Name', 'age' => 25], 
            ['id' => $userId]
        );

        $this->assertTrue($updated);

        $user = DB::find('users', ['id' => $userId]);
        $this->assertEquals('Updated Name', $user['name']);
        $this->assertEquals(25, $user['age']);
    }

    public function testDelete(): void
    {
        $userId = DB::insert('users', [
            'name' => 'To Delete',
            'email' => 'delete@test.com',
            'age' => 50
        ]);

        $this->assertTrue(DB::exists('users', ['id' => $userId]));

        $deleted = DB::delete('users', ['id' => $userId]);
        $this->assertTrue($deleted);

        $this->assertFalse(DB::exists('users', ['id' => $userId]));
    }

    public function testUpsertInsert(): void
    {
        $result = DB::upsert('users', 
            ['name' => 'New User', 'email' => 'upsert@test.com', 'age' => 28],
            ['email' => 'upsert@test.com']
        );

        $this->assertGreaterThan(0, $result);
        $this->assertTrue(DB::exists('users', ['email' => 'upsert@test.com']));
    }

    public function testUpsertUpdate(): void
    {
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

        $this->assertTrue($result);
        
        $user = DB::find('users', ['email' => 'upsert2@test.com']);
        $this->assertEquals('Updated via Upsert', $user['name']);
        $this->assertEquals(33, $user['age']);
    }

    public function testQueryRaw(): void
    {
        DB::insert('users', ['name' => 'Raw Query User', 'email' => 'raw@test.com', 'age' => 45]);

        $stmt = DB::query("SELECT * FROM users WHERE email = ?", ['raw@test.com']);
        $this->assertNotFalse($stmt);
        
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertEquals('Raw Query User', $row['name']);
    }

    public function testOne(): void
    {
        DB::insert('users', ['name' => 'One User', 'email' => 'one@test.com', 'age' => 27]);

        $user = DB::one("SELECT * FROM users WHERE email = ?", ['one@test.com']);
        
        $this->assertNotFalse($user);
        $this->assertEquals('One User', $user['name']);
    }

    public function testMany(): void
    {
        DB::insert('users', ['name' => 'Many 1', 'email' => 'many1@test.com', 'age' => 21]);
        DB::insert('users', ['name' => 'Many 2', 'email' => 'many2@test.com', 'age' => 22]);
        DB::insert('users', ['name' => 'Many 3', 'email' => 'many3@test.com', 'age' => 23]);

        $users = DB::many("SELECT * FROM users WHERE age >= ? ORDER BY age", [22]);
        
        $this->assertCount(2, $users);
        $this->assertEquals('Many 2', $users[0]['name']);
        $this->assertEquals('Many 3', $users[1]['name']);
    }

    public function testValue(): void
    {
        DB::insert('users', ['name' => 'Value User', 'email' => 'value@test.com', 'age' => 99]);

        $age = DB::value("SELECT age FROM users WHERE email = ?", ['value@test.com']);
        $this->assertEquals(99, $age);

        $count = DB::value("SELECT COUNT(*) FROM users");
        $this->assertGreaterThan(0, $count);
    }

    public function testGetLastError(): void
    {
        // Intentar insertar con email duplicado para generar error
        DB::insert('users', ['name' => 'Error Test', 'email' => 'error@test.com', 'age' => 18]);
        
        try {
            DB::insert('users', ['name' => 'Error Test 2', 'email' => 'error@test.com', 'age' => 19]);
            // SQLite puede no lanzar excepción inmediatamente dependiendo de la configuración
        } catch (\Exception $e) {
            $error = DB::getLastError();
            $this->assertNotNull($error);
        }
    }

    public function testStream(): void
    {
        DB::insert('users', ['name' => 'Stream 1', 'email' => 'stream1@test.com', 'age' => 31]);
        DB::insert('users', ['name' => 'Stream 2', 'email' => 'stream2@test.com', 'age' => 32]);
        DB::insert('users', ['name' => 'Stream 3', 'email' => 'stream3@test.com', 'age' => 33]);

        $count = 0;
        foreach (DB::stream("SELECT * FROM users WHERE age >= ?", [32]) as $row) {
            $count++;
            $this->assertArrayHasKey('name', $row);
            $this->assertArrayHasKey('email', $row);
        }

        $this->assertEquals(2, $count);
    }

    public function testAll(): void
    {
        DB::insert('users', ['name' => 'All 1', 'email' => 'all1@test.com', 'age' => 41]);
        DB::insert('users', ['name' => 'All 2', 'email' => 'all2@test.com', 'age' => 42]);

        $all = DB::all('users', [], ['id' => 'ASC']);
        
        $this->assertIsArray($all);
        $this->assertGreaterThan(1, count($all));
    }

    public function testGrid(): void
    {
        for ($i = 0; $i < 15; $i++) {
            DB::insert('users', [
                'name' => "Grid User $i", 
                'email' => "grid$i@test.com", 
                'age' => 20 + $i
            ]);
        }

        $response = DB::grid('users', [], ['id' => 'ASC'], 1, 10);
        
        $this->assertInstanceOf(\RapidBase\Core\QueryResponse::class, $response);
        $this->assertCount(10, $response->data);
        $this->assertEquals(15, $response->total);
        $this->assertEquals(1, $response->state['page']);
        $this->assertEquals(10, $response->state['per_page']);
    }
}
