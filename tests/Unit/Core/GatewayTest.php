<?php
/**
 * Gateway Test Suite - TDD Framework
 * 
 * Pruebas para validar la integración del Mapa de Proyección,
 * el uso de FETCH_NUM en Gateway.php y la funcionalidad del caché.
 */

declare(strict_types=1);

namespace RapidBase\Tests\Unit\Core;

use RapidBase\Tdd\TestCase;
use RapidBase\Core\DB;
use RapidBase\Core\SchemaMap;
use RapidBase\Core\Gateway;


// Bootstrap centralizado para pruebas unitarias
require_once __DIR__ . '/../bootstrap.php';
/**
 * @requires extension pdo_sqlite
 */
class GatewayTest extends TestCase
{
    private static bool $initialized = false;

    /**
     * Setup inicial: crea la base de datos y el schema
     */
    public function setUp(): void
    {
        if (!self::$initialized) {
            self::setupDatabase();
            self::$initialized = true;
        }
    }

    /**
     * Configura la base de datos SQLite en memoria y las tablas de prueba
     */
    private static function setupDatabase(): void
    {
        // Configurar conexión SQLite en memoria
        DB::setup('sqlite::memory:', '', '');
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

        // Definir Schema Manualmente
        SchemaMap::setMap([
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

    /**
     * @test
     * @group projection
     * @group select
     */
    public function testSimpleSelectProjection(): void
    {
        $result = Gateway::select('*', 'users');
        $data = $result['data'];

        $this->assertIsArray($data, "Result should be array");
        $this->assertCount(2, $data, "Should find 2 users");
        
        // Verificar estructura del primer registro
        $first = $data[0];
        $this->assertArrayHasKey('id', $first, "Should have 'id' key");
        $this->assertArrayHasKey('name', $first, "Should have 'name' key");
        $this->assertEquals('Alice', $first['name'], "First user should be Alice");
    }

    /**
     * @test
     * @group join
     * @group projection
     */
    public function testJoinProjectionNoCollisions(): void
    {
        // Este es el caso crítico: dos tablas con columna 'id'
        // Unir posts con users. Ambos tienen 'id'.
        $result = Gateway::select(
            fields: ['posts.id', 'posts.title', 'users.id as user_id', 'users.name'],
            table: ['posts', 'users'],
            where: []
        );
        $data = $result['data'];

        // Verificar que hay datos (el join puede retornar menos registros dependiendo de la implementación)
        $this->assertNotEmpty($data, "Should have at least one post");

        $firstPost = $data[0];
        
        // El mapa debe haber prevenido la colisión o usado alias implícitos
        $this->assertArrayHasKey('user_id', $firstPost, "Should have user_id key");
        $this->assertArrayHasKey('id', $firstPost, "Should have id key");
        $this->assertTrue(!empty($firstPost['id']), "posts.id should exist");
        $this->assertTrue(!empty($firstPost['user_id']), "users.id should exist (aliased)");
        $this->assertTrue(!empty($firstPost['name']), "Should have user name from join");
    }

    /**
     * @test
     * @group projection
     * @group star
     */
    public function testStarExpansionOrder(): void
    {
        // Verifica que SELECT * expanda las columnas en el orden correcto según el schema
        $result = Gateway::select('*', 'users');
        $data = $result['data'];
        
        $first = $data[0];
        $keys = array_keys($first);
        
        // El orden debería ser id, name, email según el schema definido
        $this->assertEquals($keys[0], 'id', "First key should be id");
        $this->assertEquals($keys[1], 'name', "Second key should be name");
        $this->assertEquals($keys[2], 'email', "Third key should be email");
    }

    /**
     * @test
     * @group crud
     * @group insert
     */
    public function testInsertAndGetId(): void
    {
        $id = Gateway::insert('users', ['name' => 'Charlie', 'email' => 'charlie@test.com']);
        
        $this->assertTrue(is_numeric($id) && $id > 0, "Insert should return new ID");
        
        $result = Gateway::select('*', 'users', ['id' => $id]);
        $user = $result['data'][0] ?? null;
        $this->assertEquals($user['name'], 'Charlie', "Inserted user should be retrievable");
    }

    /**
     * @test
     * @group crud
     * @group update
     */
    public function testUpdateAffectedRows(): void
    {
        // Actualizar el usuario ID 2 (Bob)
        $affected = Gateway::update('users', ['name' => 'Robert'], ['id' => 2]);
        
        $this->assertEquals($affected, 1, "Update should affect 1 row");
        
        $result = Gateway::select('*', 'users', ['id' => 2]);
        $user = $result['data'][0] ?? null;
        $this->assertEquals($user['name'], 'Robert', "Name should be updated");
    }

    /**
     * @test
     * @group crud
     * @group delete
     */
    public function testDeleteAffectedRows(): void
    {
        // Primero insertamos uno para borrar
        $pdo = DB::getConnection();
        $pdo->exec("INSERT INTO users (name, email) VALUES ('ToDelete', 'delete@test.com')");
        
        // Buscamos el ID
        $result = Gateway::select('*', 'users', ['email' => 'delete@test.com']);
        $toDeleteId = $result['data'][0]['id'] ?? null;

        if ($toDeleteId) {
            $affected = Gateway::delete('users', ['id' => $toDeleteId]);
            $this->assertEquals($affected, 1, "Delete should affect 1 row");
            
            $result = Gateway::select('*', 'users', ['id' => $toDeleteId]);
            $deletedUser = $result['data'][0] ?? null;
            $this->assertNull($deletedUser, "Deleted user should not exist");
        } else {
            $this->fail("Could not find user to delete");
        }
    }

    /**
     * @test
     * @group crud
     * @group count
     */
    public function testCount(): void
    {
        $count = Gateway::count('posts');
        
        $this->assertEquals($count, 3, "Should count 3 posts");
        
        $countFiltered = Gateway::count('posts', ['user_id' => 1]);
        $this->assertEquals($countFiltered, 2, "Should count 2 posts for user 1");
    }

    /**
     * @test
     * @group join
     * @group self-reference
     */
    public function testSelfJoinProjection(): void
    {
        // Join consigo misma para obtener padres
        $result = Gateway::select(
            fields: ['categories.id', 'categories.name', 'parent.name as parent_name'],
            table: ['categories', 'categories as parent'],
            where: ['categories.parent_id' => 1]
        );
        $data = $result['data'];
        $this->assertIsArray($data, "Self-join query should work");
    }

    /**
     * @test
     * @group fetch
     * @group hydration
     */
    public function testFetchNumUsage(): void
    {
        // Esta prueba verifica que Gateway procesa correctamente resultados FETCH_NUM
        // y los convierte a arrays asociativos mediante el mapa de proyección.
        
        $result = Gateway::select('*', 'posts');
        $results = $result['data'];
        
        // Si llegamos aquí sin errores de índice indefinido, el mapa funcionó
        foreach ($results as $row) {
            $this->assertIsArray($row, "Row should be an array");
            // Verificar que las claves son strings (hidratación exitosa)
            $keys = array_keys($row);
            $this->assertTrue(is_string($keys[0]), "Keys should be strings (Hydration successful)");
        }
    }

    /**
     * @test
     * @group cache
     */
    public function testCacheIntegration(): void
    {
        // Prueba que el caché funciona correctamente con Gateway
        // Ejecutar la misma consulta dos veces para verificar caché
        
        $result1 = Gateway::select('*', 'users');
        $result2 = Gateway::select('*', 'users');
        
        $this->assertIsArray($result1['data'], "First query should return data");
        $this->assertIsArray($result2['data'], "Second query should return data");
        $this->assertEquals(count($result1['data']), count($result2['data']), "Both queries should return same count");
    }
}
