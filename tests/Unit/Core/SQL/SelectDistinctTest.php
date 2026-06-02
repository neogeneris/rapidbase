<?php

declare(strict_types=1);

namespace RapidBase\Core\SQL;

use RapidBase\Tdd\TestCase;
use RapidBase\Core\SchemaMap;
use RapidBase\Tests\Autojoins\AutojoinSetup;

require_once __DIR__ . '/AutojoinSetup.php';

/**
 * Test Suite for Q::selectDistinct()
 * Pruebas para el método selectDistinct de la clase Q
 */
class SelectDistinctTest extends TestCase
{
    private array $originalSchema = [];
    private static bool $setupDone = false;

    public static function setUpBeforeClass(): void
    {
        if (!self::$setupDone) {
            echo "\n🔍 Verificando schema_map.php...\n";
            if (!AutojoinSetup::init()) {
                throw new \RuntimeException('No se pudo inicializar el schema map para las pruebas');
            }
            self::$setupDone = true;
        }
    }

    public function setUp(): void
    {
        // Configurar schema de prueba
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
            ],
            'relationships' => [
                'from' => [
                    'posts' => [
                        'users' => ['local_key' => 'user_id', 'foreign_key' => 'id', 'type' => 'belongs_to'],
                    ],
                    'users' => [
                        'countries' => ['local_key' => 'country_id', 'foreign_key' => 'id', 'type' => 'belongs_to'],
                    ],
                ],
                'to' => [
                    'users' => [
                        'posts' => ['local_key' => 'id', 'foreign_key' => 'user_id', 'type' => 'has_many'],
                    ],
                    'countries' => [
                        'users' => ['local_key' => 'id', 'foreign_key' => 'country_id', 'type' => 'has_many'],
                    ],
                ],
            ],
        ]);
    }

    public function tearDown(): void
    {
        // Limpiar schema
        SchemaMap::setMap($this->originalSchema);
    }

    public function testSelectDistinctSimpleTable(): void
    {
        $this->env()->test('SELECT DISTINCT en tabla simple genera SQL con DISTINCT', function($test) {
            $query = Q::from('users');
            $compiled = $query->selectDistinct('name');
            
            $sql = $compiled->getSql();
            $test->assertStringContainsString('DISTINCT', $sql);
            $test->assertStringContainsString('name', $sql);
        });
    }

    public function testSelectDistinctWithMultipleFields(): void
    {
        $this->env()->test('SELECT DISTINCT con múltiples campos', function($test) {
            $query = Q::from('users');
            $compiled = $query->selectDistinct(['name', 'email']);
            
            $sql = $compiled->getSql();
            $test->assertStringContainsString('DISTINCT', $sql);
            $test->assertStringContainsString('name', $sql);
            $test->assertStringContainsString('email', $sql);
        });
    }

    public function testSelectDistinctWithWhere(): void
    {
        $this->env()->test('SELECT DISTINCT con condición WHERE', function($test) {
            $query = Q::from('users', ['country_id' => 1]);
            $compiled = $query->selectDistinct('name');
            
            $sql = $compiled->getSql();
            $params = $compiled->getParams();
            
            $test->assertStringContainsString('DISTINCT', $sql);
            $test->assertStringContainsString('WHERE', $sql);
            $test->assertCount(1, $params);
            $test->assertEquals(1, $params[0]);
        });
    }

    public function testSelectDistinctWithOrderBy(): void
    {
        $this->env()->test('SELECT DISTINCT con ORDER BY', function($test) {
            $query = Q::from('users');
            $compiled = $query->selectDistinct('name', null, 'name');
            
            $sql = $compiled->getSql();
            
            $test->assertStringContainsString('DISTINCT', $sql);
            $test->assertStringContainsString('ORDER BY', $sql);
        });
    }

    public function testSelectDistinctWithPagination(): void
    {
        $this->env()->test('SELECT DISTINCT con LIMIT y OFFSET', function($test) {
            $query = Q::from('users');
            $compiled = $query->selectDistinct('name', [0, 10]);
            
            $sql = $compiled->getSql();
            
            $test->assertStringContainsString('DISTINCT', $sql);
            $test->assertStringContainsString('LIMIT', $sql);
            $test->assertStringContainsString('OFFSET', $sql);
        });
    }

    public function testSelectDistinctWithJoin(): void
    {
        $this->env()->test('SELECT DISTINCT con JOIN entre tablas', function($test) {
            $query = Q::from(['users', 'countries']);
            $compiled = $query->selectDistinct('users.name');
            
            $sql = $compiled->getSql();
            
            $test->assertStringContainsString('DISTINCT', $sql);
            $test->assertStringContainsString('JOIN', $sql);
            $test->assertStringContainsString('users', $sql);
            $test->assertStringContainsString('countries', $sql);
        });
    }

    public function testSelectDistinctWithGroupBy(): void
    {
        $this->env()->test('SELECT DISTINCT con GROUP BY', function($test) {
            $query = Q::from('users');
            $compiled = $query->selectDistinct('country_id', null, [], 'country_id');
            
            $sql = $compiled->getSql();
            
            $test->assertStringContainsString('DISTINCT', $sql);
            $test->assertStringContainsString('GROUP BY', $sql);
        });
    }

    public function testSelectDistinctWithHaving(): void
    {
        $this->env()->test('SELECT DISTINCT con HAVING', function($test) {
            $query = Q::from('users');
            $compiled = $query->selectDistinct(
                'country_id', 
                null, 
                [], 
                'country_id', 
                [['COUNT', 'id', '>', 1]]
            );
            
            $sql = $compiled->getSql();
            
            $test->assertStringContainsString('DISTINCT', $sql);
            $test->assertStringContainsString('HAVING', $sql);
        });
    }

    public function testSelectDistinctVsSelect(): void
    {
        $this->env()->test('selectDistinct genera DISTINCT pero select normal no', function($test) {
            $queryDistinct = Q::from('users');
            $queryNormal = Q::from('users');
            
            $compiledDistinct = $queryDistinct->selectDistinct('name');
            $compiledNormal = $queryNormal->select('name');
            
            $sqlDistinct = $compiledDistinct->getSql();
            $sqlNormal = $compiledNormal->getSql();
            
            $test->assertStringContainsString('DISTINCT', $sqlDistinct);
            $test->assertTrue(
                !str_contains(strtoupper($sqlNormal), 'DISTINCT'),
                'SELECT normal no debe contener DISTINCT'
            );
        });
    }

    public function testSelectDistinctWithStar(): void
    {
        $this->env()->test('SELECT DISTINCT con * (todos los campos)', function($test) {
            $query = Q::from('users');
            $compiled = $query->selectDistinct('*');
            
            $sql = $compiled->getSql();
            
            $test->assertStringContainsString('DISTINCT', $sql);
            $test->assertStringContainsString('*', $sql);
        });
    }

    public function testSelectDistinctWithNullFields(): void
    {
        $this->env()->test('SELECT DISTINCT con campos null usa *', function($test) {
            $query = Q::from('users');
            $compiled = $query->selectDistinct();
            
            $sql = $compiled->getSql();
            
            $test->assertStringContainsString('DISTINCT', $sql);
            $test->assertStringContainsString('*', $sql);
        });
    }

    public function testSelectDistinctFieldQualification(): void
    {
        $this->env()->test('SELECT DISTINCT califica campos correctamente en JOIN', function($test) {
            $query = Q::from(['users', 'countries']);
            $compiled = $query->selectDistinct('name');
            
            $sql = $compiled->getSql();
            
            $test->assertStringContainsString('DISTINCT', $sql);
            // El campo debe estar calificado con el alias de la tabla principal
            $test->assertTrue(
                str_contains($sql, 'users.name') || str_contains($sql, '"name"'),
                'El campo debe estar calificado o entre comillas'
            );
        });
    }

    public function testSelectDistinctPreservesParams(): void
    {
        $this->env()->test('SELECT DISTINCT preserva los parámetros del WHERE', function($test) {
            $query = Q::from('users', ['country_id' => 5, 'name' => 'John']);
            $compiled = $query->selectDistinct('email');
            
            $params = $compiled->getParams();
            
            $test->assertCount(2, $params);
            $test->assertContains(5, $params);
            $test->assertContains('John', $params);
        });
    }
}
