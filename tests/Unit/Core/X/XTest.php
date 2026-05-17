<?php

namespace RapidBase\Tests\Unit\Core\X;

use RapidBase\Tdd\TestCase;
use RapidBase\Core\X;

/**
 * Clase de prueba para X
 * Cada método público en X tiene su correspondiente testXXX() aquí.
 * Relación 1:1 con los métodos públicos de X.php
 */
class XTest extends TestCase
{
    protected X $x;

    public function setUp(): void
    {
        // Usamos SQLite en memoria para cada test
        $this->x = X::con('sqlite::memory:');
        
        // Tabla de prueba estándar
        $this->x->raw("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, name TEXT, email TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
        $this->x->raw("DELETE FROM users");
    }

    public function tearDown(): void
    {
        $this->x = null;
    }

    // ================================================================
    // TESTS POR MÉTODO (1:1 con X.php)
    // ================================================================

    public function testCon(): void
    {
        $this->assertNotNull($this->x);
        $this->assertInstanceOf(X::class, $this->x);
    }

    public function testFrom(): void
    {
        $result = $this->x->from('users')->toSQL();
        $this->assertStringContainsString('FROM users', $result);
    }

    public function testInto(): void
    {
        // into es alias de from en algunos contextos o para insert
        $result = $this->x->into('users')->toSQL();
        // Dependiendo de la implementación, into puede preparar un INSERT
        $this->assertIsString($result);
    }

    public function testCached(): void
    {
        $cachedX = $this->x->cached(60);
        $this->assertInstanceOf(X::class, $cachedX);
        // Verificar que se marca como cached internamente si existe tal flag
    }

    public function testWithCountTtl(): void
    {
        $result = $this->x->withCountTtl(120);
        $this->assertInstanceOf(X::class, $result);
    }

    public function testTotalStrategy(): void
    {
        $result = $this->x->totalStrategy('exact');
        $this->assertInstanceOf(X::class, $result);
    }

    public function testSelect(): void
    {
        // Insertar dato de prueba
        $this->x->raw("INSERT INTO users (name, email) VALUES ('Test', 'test@test.com')");
        
        $result = $this->x->from('users')->select();
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertEquals('Test', $result[0]['name']);
    }

    public function testFirst(): void
    {
        $this->x->raw("INSERT INTO users (name, email) VALUES ('First', 'first@test.com')");
        
        $result = $this->x->from('users')->first();
        $this->assertIsArray($result);
        $this->assertEquals('First', $result['name']);
    }

    public function testExists(): void
    {
        $this->x->raw("INSERT INTO users (name, email) VALUES ('Exist', 'exist@test.com')");
        
        $exists = $this->x->from('users')->where('name', 'Exist')->exists();
        $this->assertTrue($exists);
        
        $notExists = $this->x->from('users')->where('name', 'NoExist')->exists();
        $this->assertFalse($notExists);
    }

    public function testCount(): void
    {
        $this->x->raw("INSERT INTO users (name, email) VALUES ('C1', 'c1@test.com'), ('C2', 'c2@test.com')");
        
        $count = $this->x->from('users')->count();
        $this->assertEquals(2, $count);
    }

    public function testGrid(): void
    {
        $this->x->raw("INSERT INTO users (name, email) VALUES ('G1', 'g1@test.com'), ('G2', 'g2@test.com'), ('G3', 'g3@test.com')");
        
        $grid = $this->x->from('users')->grid(1, 2); // Página 1, 2 items
        $this->assertIsArray($grid);
        $this->assertArrayHasKey('data', $grid);
        $this->assertArrayHasKey('total', $grid);
        $this->assertArrayHasKey('page', $grid);
        $this->assertCount(2, $grid['data']);
    }

    public function testInsert(): void
    {
        $data = ['name' => 'InsertTest', 'email' => 'insert@test.com'];
        $result = $this->x->from('users')->insert($data);
        
        $this->assertTrue($result > 0);
        
        $verify = $this->x->from('users')->where('id', $result)->first();
        $this->assertEquals('InsertTest', $verify['name']);
    }

    public function testUpsert(): void
    {
        // Primero insertamos
        $id = $this->x->from('users')->insert(['name' => 'UpsertTest', 'email' => 'upsert@test.com']);
        
        // Luego hacemos upsert con mismo email (depende de la implementación de unique)
        // En SQLite usamos INSERT OR REPLACE o similar
        $data = ['id' => $id, 'name' => 'UpsertUpdated', 'email' => 'upsert@test.com'];
        $result = $this->x->from('users')->upsert($data, ['email']);
        
        $this->assertTrue($result);
        
        $verify = $this->x->from('users')->where('id', $id)->first();
        $this->assertEquals('UpsertUpdated', $verify['name']);
    }

    public function testUpdate(): void
    {
        $id = $this->x->from('users')->insert(['name' => 'UpdateTest', 'email' => 'update@test.com']);
        
        $affected = $this->x->from('users')
            ->where('id', $id)
            ->update(['name' => 'UpdatedName']);
        
        $this->assertEquals(1, $affected);
        
        $verify = $this->x->from('users')->where('id', $id)->first();
        $this->assertEquals('UpdatedName', $verify['name']);
    }

    public function testDelete(): void
    {
        $id = $this->x->from('users')->insert(['name' => 'DeleteTest', 'email' => 'delete@test.com']);
        
        $affected = $this->x->from('users')
            ->where('id', $id)
            ->delete();
        
        $this->assertEquals(1, $affected);
        
        $exists = $this->x->from('users')->where('id', $id)->exists();
        $this->assertFalse($exists);
    }

    public function testRaw(): void
    {
        $result = $this->x->raw("SELECT 1 as num");
        $this->assertIsArray($result);
        $this->assertEquals(1, $result[0]['num']);
    }

    public function testToSQL(): void
    {
        $sql = $this->x->from('users')->where('id', 1)->toSQL();
        $this->assertIsString($sql);
        $this->assertStringContainsString('SELECT', strtoupper($sql));
        $this->assertStringContainsString('FROM users', $sql);
    }

    public function testPing(): void
    {
        $result = $this->x->ping();
        $this->assertTrue($result);
    }

    public function testDescription(): void
    {
        $desc = $this->x->from('users')->description();
        $this->assertIsArray($desc);
        // Debe contener información de columnas
        $this->assertGreaterThan(0, count($desc));
    }
}
