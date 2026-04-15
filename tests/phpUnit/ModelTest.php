<?php

namespace RapidBase\Tests\Unit\ORM;

use PHPUnit\Framework\TestCase;
use RapidBase\Core\DB;
use RapidBase\ORM\ActiveRecord\Model;

/**
 * Modelo de prueba para tests del ORM
 */
class TestUser extends Model
{
    protected static string $table = 'test_users';
    protected static string $primaryKey = 'id';
}

/**
 * Tests unitarios para el ORM ActiveRecord Model
 */
class ModelTest extends TestCase
{
    private static bool $initialized = false;

    public static function setUpBeforeClass(): void
    {
        if (!self::$initialized) {
            DB::setup('sqlite::memory:', '', '', 'orm_test');
            self::$initialized = true;
        }
    }

    protected function setUp(): void
    {
        // Crear tabla de prueba
        DB::exec("CREATE TABLE IF NOT EXISTS test_users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT UNIQUE,
            age INTEGER DEFAULT 0,
            active INTEGER DEFAULT 1
        )");
        
        // Limpiar datos
        DB::exec("DELETE FROM test_users");
    }

    public function testCreateAndSave(): void
    {
        $user = new TestUser([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'age' => 30
        ]);

        $result = $user->save();
        $this->assertTrue($result);
        $this->assertGreaterThan(0, $user->id);
    }

    public function testStaticCreate(): void
    {
        $userId = TestUser::create([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'age' => 25
        ]);

        $this->assertGreaterThan(0, $userId);
    }

    public function testRead(): void
    {
        $userId = DB::insert('test_users', [
            'name' => 'Read Test',
            'email' => 'read@test.com',
            'age' => 35
        ]);

        $user = TestUser::read($userId);
        
        $this->assertInstanceOf(TestUser::class, $user);
        $this->assertEquals('Read Test', $user->name);
        $this->assertEquals('read@test.com', $user->email);
    }

    public function testReadNotFound(): void
    {
        $user = TestUser::read(99999);
        $this->assertNull($user);
    }

    public function testAll(): void
    {
        DB::insert('test_users', ['name' => 'User 1', 'email' => 'u1@test.com', 'age' => 20]);
        DB::insert('test_users', ['name' => 'User 2', 'email' => 'u2@test.com', 'age' => 21]);
        DB::insert('test_users', ['name' => 'User 3', 'email' => 'u3@test.com', 'age' => 22]);

        $users = TestUser::all();
        
        $this->assertCount(3, $users);
        $this->assertContainsOnlyInstancesOf(TestUser::class, $users);
    }

    public function testUpdate(): void
    {
        $user = new TestUser([
            'name' => 'Original',
            'email' => 'original@test.com',
            'age' => 40
        ]);
        $user->save();

        $user->name = 'Updated';
        $user->age = 41;
        $result = $user->save();

        $this->assertTrue($result);
        
        $fresh = TestUser::read($user->id);
        $this->assertEquals('Updated', $fresh->name);
        $this->assertEquals(41, $fresh->age);
    }

    public function testDelete(): void
    {
        $user = new TestUser([
            'name' => 'To Delete',
            'email' => 'delete@test.com',
            'age' => 50
        ]);
        $user->save();

        $result = TestUser::delete($user->id);
        $this->assertTrue($result);

        $fresh = TestUser::read($user->id);
        $this->assertNull($fresh);
    }

    public function testDestroy(): void
    {
        $user = new TestUser([
            'name' => 'Destroy Me',
            'email' => 'destroy@test.com',
            'age' => 60
        ]);
        $user->save();

        $result = $user->destroy();
        $this->assertTrue($result);

        $fresh = TestUser::read($user->id);
        $this->assertNull($fresh);
    }

    public function testDirtyTracking(): void
    {
        $user = new TestUser([
            'name' => 'Dirty Test',
            'email' => 'dirty@test.com',
            'age' => 28
        ]);
        $user->save();
        $user->syncOriginal();

        $this->assertFalse($user->isDirty());

        $user->name = 'Changed Name';
        $this->assertTrue($user->isDirty());
        $this->assertTrue($user->isDirty('name'));
        $this->assertFalse($user->isDirty('age'));

        $dirty = $user->getDirty();
        $this->assertArrayHasKey('name', $dirty);
        $this->assertEquals('Changed Name', $dirty['name']);
    }

    public function testMagicGettersSetters(): void
    {
        $user = new TestUser();
        $user->name = 'Magic User';
        $user->email = 'magic@test.com';

        $this->assertEquals('Magic User', $user->name);
        $this->assertEquals('magic@test.com', $user->email);
        $this->assertTrue(isset($user->name));
        $this->assertFalse(isset($user->nonexistent));
    }

    public function testFill(): void
    {
        $user = new TestUser();
        $user->fill([
            'name' => 'Filled User',
            'email' => 'filled@test.com',
            'age' => 33
        ]);

        $this->assertEquals('Filled User', $user->name);
        $this->assertEquals(33, $user->age);
    }

    public function testGetTableAndPrimaryKey(): void
    {
        $this->assertEquals('test_users', TestUser::getTable());
        $this->assertEquals('id', TestUser::getPrimaryKey());
    }

    public function testSyncFromHydration(): void
    {
        $user = new TestUser(['name' => 'Hydrated', 'email' => 'hydrate@test.com']);
        // Acceder directamente a la propiedad protegida para verificar el estado inicial
        $reflection = new \ReflectionClass($user);
        $attributesProp = $reflection->getProperty('attributes');
        $attributesProp->setAccessible(true);
        
        $initialAttributes = $attributesProp->getValue($user);
        $this->assertEquals('Hydrated', $initialAttributes['name']);
        
        // Asignar una propiedad dinámica
        $user->extraField = 'Extra Value';
        
        // Ejecutar syncFromHydration
        $user->syncFromHydration();
        
        // Verificar que extraField se movió a attributes
        $finalAttributes = $attributesProp->getValue($user);
        $this->assertArrayHasKey('extraField', $finalAttributes);
        $this->assertEquals('Extra Value', $finalAttributes['extraField']);
    }
}
