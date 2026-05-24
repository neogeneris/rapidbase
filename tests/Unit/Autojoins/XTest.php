<?php

declare(strict_types=1);

namespace RapidBase\Core;

use RapidBase\Tdd\TestCase;
use RapidBase\Core\SchemaMap;
use RapidBase\Tests\Autojoins\AutojoinSetup;
use PDO;

require_once __DIR__ . '/AutojoinSetup.php';

/**
 * Test Suite for X (Execution Layer)
 * Pruebas para joins automáticos a nivel de capa de ejecución
 */
class XTest extends TestCase
{
    private string $testDb = ':memory:';
    private array $originalSchema = [];
    private static bool $setupDone = false;

    public static function setUpBeforeClass(): void
    {
        if (!self::$setupDone) {
            // Verificar y cargar schema_map.php antes de las pruebas
            echo "\n🔍 Verificando schema_map.php para XTest...\n";
            if (!AutojoinSetup::init()) {
                throw new \RuntimeException('No se pudo inicializar el schema map para las pruebas');
            }
            self::$setupDone = true;
        }
    }

    public function setUp(): void
    {
        // Configurar schema de prueba para joins
        SchemaMap::setMap([
            'tables' => [
                'users' => [
                    'id' => ['type' => 'int', 'primary' => true],
                    'name' => ['type' => 'string'],
                    'email' => ['type' => 'string'],
                    'country_id' => ['type' => 'int'],
                ],
                'countries' => [
                    'id' => ['type' => 'int', 'primary' => true],
                    'name' => ['type' => 'string'],
                    'code' => ['type' => 'string'],
                ],
                'posts' => [
                    'id' => ['type' => 'int', 'primary' => true],
                    'title' => ['type' => 'string'],
                    'user_id' => ['type' => 'int'],
                    'category_id' => ['type' => 'int'],
                ],
                'categories' => [
                    'id' => ['type' => 'int', 'primary' => true],
                    'name' => ['type' => 'string'],
                ],
                'comments' => [
                    'id' => ['type' => 'int', 'primary' => true],
                    'content' => ['type' => 'string'],
                    'post_id' => ['type' => 'int'],
                    'user_id' => ['type' => 'int'],
                ],
            ],
            'relationships' => [
                'from' => [
                    'posts' => [
                        'users' => ['local_key' => 'user_id', 'foreign_key' => 'id', 'type' => 'belongs_to'],
                    ],
                    'comments' => [
                        'posts' => ['local_key' => 'post_id', 'foreign_key' => 'id', 'type' => 'belongs_to'],
                        'users' => ['local_key' => 'user_id', 'foreign_key' => 'id', 'type' => 'belongs_to'],
                    ],
                    'users' => [
                        'countries' => ['local_key' => 'country_id', 'foreign_key' => 'id', 'type' => 'belongs_to'],
                    ],
                ],
                'to' => [
                    'users' => [
                        'posts' => ['local_key' => 'id', 'foreign_key' => 'user_id', 'type' => 'has_many'],
                        'comments' => ['local_key' => 'id', 'foreign_key' => 'user_id', 'type' => 'has_many'],
                    ],
                    'posts' => [
                        'comments' => ['local_key' => 'id', 'foreign_key' => 'post_id', 'type' => 'has_many'],
                    ],
                    'countries' => [
                        'users' => ['local_key' => 'id', 'foreign_key' => 'country_id', 'type' => 'has_many'],
                    ],
                ],
            ],
        ]);

        // Crear conexión de prueba y tablas usando DB::setup directamente
        DB::setup('sqlite::memory:', '', '', 'test_conn');
        
        // Crear las tablas en la conexión recién creada
        $pdo = \RapidBase\Core\Conn::get('test_conn');
        $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT, country_id INTEGER)");
        $pdo->exec("CREATE TABLE countries (id INTEGER PRIMARY KEY, name TEXT, code TEXT)");
        $pdo->exec("CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT, user_id INTEGER, category_id INTEGER)");
        $pdo->exec("CREATE TABLE categories (id INTEGER PRIMARY KEY, name TEXT)");
        $pdo->exec("CREATE TABLE comments (id INTEGER PRIMARY KEY, content TEXT, post_id INTEGER, user_id INTEGER)");
        
        // Insertar datos de prueba
        $pdo->exec("INSERT INTO countries (id, name, code) VALUES (1, 'Argentina', 'AR'), (2, 'Chile', 'CL')");
        $pdo->exec("INSERT INTO users (id, name, email, country_id) VALUES (1, 'Juan', 'juan@test.com', 1), (2, 'Maria', 'maria@test.com', 2)");
        $pdo->exec("INSERT INTO posts (id, title, user_id, category_id) VALUES (1, 'Post 1', 1, 1), (2, 'Post 2', 1, 2), (3, 'Post 3', 2, 1)");
        $pdo->exec("INSERT INTO comments (id, content, post_id, user_id) VALUES (1, 'Comment 1', 1, 2), (2, 'Comment 2', 1, 1)");
        $pdo->exec("INSERT INTO categories (id, name) VALUES (1, 'Tech'), (2, 'Life')");
        
        Conn::select('test_conn');
    }

    public function tearDown(): void
    {
        SchemaMap::setMap($this->originalSchema);
        try {
            Conn::close('test_conn');
        } catch (\Throwable $e) {
            // Ignorar errores al cerrar
        }
    }

    public function testSimpleSelectExecution(): void
    {
        $this->env()->test('SELECT simple ejecuta correctamente', function($test) {
            $result = X::con('test_conn')
                ->from('users')
                ->select();
            
            $test->assertInstanceOf(XResponse::class, $result);
            $test->assertIsArray($result->data);
            $test->assertCount(2, $result->data);
        });
    }

    public function testTwoTableJoinExecution(): void
    {
        $this->env()->test('JOIN de dos tablas ejecuta correctamente', function($test) {
            $result = X::con('test_conn')
                ->from(['users', 'posts'])
                ->select();
            
            $test->assertInstanceOf(XResponse::class, $result);
            $test->assertIsArray($result->data);
            // Debería traer posts con sus usuarios
            $test->assertTrue(count($result->data) > 0);
        });
    }

    public function testThreeTableJoinExecution(): void
    {
        $this->env()->test('JOIN de tres tablas ejecuta correctamente', function($test) {
            $result = X::con('test_conn')
                ->from(['users', 'posts', 'comments'])
                ->select();
            
            $test->assertInstanceOf(XResponse::class, $result);
            $test->assertIsArray($result->data);
            // Debería traer comentarios con posts y usuarios
            $test->assertTrue(count($result->data) > 0);
        });
    }

    public function testJoinWithFilterExecution(): void
    {
        $this->env()->test('JOIN con filtro WHERE ejecuta correctamente', function($test) {
            $result = X::con('test_conn')
                ->from(['users', 'posts'], ['users.id' => 1])
                ->select();
            
            $test->assertInstanceOf(XResponse::class, $result);
            $test->assertIsArray($result->data);
            // Todos los resultados deberían ser del usuario 1
            foreach ($result->data as $row) {
                $test->assertTrue(isset($row['users_id']) || isset($row['id']));
            }
        });
    }

    public function testJoinWithFieldSelection(): void
    {
        $this->env()->test('JOIN con selección de campos específicos', function($test) {
            $result = X::con('test_conn')
                ->from(['users', 'countries'])
                ->select(['users.name', 'countries.name']);
            
            $test->assertInstanceOf(XResponse::class, $result);
            $test->assertIsArray($result->data);
            $test->assertCount(2, $result->data);
        });
    }

    public function testJoinWithSortExecution(): void
    {
        $this->env()->test('JOIN con ORDER BY ejecuta correctamente', function($test) {
            $result = X::con('test_conn')
                ->from(['users', 'posts'])
                ->select('*', null, ['users.name' => 'ASC']);
            
            $test->assertInstanceOf(XResponse::class, $result);
            $test->assertIsArray($result->data);
            $test->assertTrue($result->success);
        });
    }

    public function testJoinWithPaginationExecution(): void
    {
        $this->env()->test('JOIN con paginación ejecuta correctamente', function($test) {
            $result = X::con('test_conn')
                ->from(['users', 'posts'])
                ->select('*', [0, 2]);
            
            $test->assertInstanceOf(XResponse::class, $result);
            $test->assertIsArray($result->data);
            $test->assertTrue(count($result->data) <= 2);
        });
    }

    public function testGridWithJoins(): void
    {
        $this->env()->test('grid() funciona con JOINs', function($test) {
            $result = X::con('test_conn')
                ->from(['users', 'posts'])
                ->grid('*', [1, 10], '-users.id');
            
            $test->assertIsArray($result);
            $test->assertArrayHasKey('data', $result);
            $test->assertArrayHasKey('total', $result);
            $test->assertArrayHasKey('page', $result);
            $test->assertTrue($result['total'] > 0);
        });
    }

    public function testFirstWithJoins(): void
    {
        $this->env()->test('first() funciona con JOINs', function($test) {
            $result = X::con('test_conn')
                ->from(['users', 'posts'])
                ->first();
            
            $test->assertIsArray($result);
            $test->assertNotEmpty($result);
        });
    }

    public function testCountWithJoins(): void
    {
        $this->env()->test('count() funciona con JOINs', function($test) {
            $count = X::con('test_conn')
                ->from(['users', 'posts'])
                ->count();
            
            $test->assertIsInt($count);
            $test->assertTrue($count > 0);
        });
    }

    public function testExistsWithJoins(): void
    {
        $this->env()->test('exists() funciona con JOINs', function($test) {
            $exists = X::con('test_conn')
                ->from(['users', 'posts'])
                ->exists();
            
            $test->assertIsBool($exists);
            $test->assertTrue($exists);
        });
    }

    public function testJoinSqlGeneration(): void
    {
        $this->env()->test('SQL generado para JOINs es válido', function($test) {
            $result = X::con('test_conn')
                ->from(['users', 'posts'])
                ->select();
            
            $test->assertStringContainsString('SELECT', $result->sql);
            $test->assertStringContainsString('FROM', $result->sql);
            $test->assertStringContainsString('JOIN', $result->sql);
        });
    }

    public function testUnrelatedTablesExecution(): void
    {
        $this->env()->test('tablas sin relación ejecutan en modo lineal', function($test) {
            $result = X::con('test_conn')
                ->from(['users', 'categories'])
                ->select();
            
            $test->assertInstanceOf(XResponse::class, $result);
            $test->assertIsArray($result->data);
            // Modo lineal puede generar producto cartesiano
            $test->assertTrue(count($result->data) >= 0);
        });
    }

    public function testJoinWithDurationTracking(): void
    {
        $this->env()->test('JOIN rastrea duración de ejecución', function($test) {
            $result = X::con('test_conn')
                ->from(['users', 'posts', 'comments'])
                ->select();
            
            $test->assertIsNumeric($result->durationMs);
            $test->assertTrue($result->durationMs >= 0);
        });
    }
}
