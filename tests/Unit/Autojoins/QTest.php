<?php

declare(strict_types=1);

namespace RapidBase\Core\SQL;

use RapidBase\Tdd\TestCase;
use RapidBase\Core\SchemaMap;
use RapidBase\Tests\Autojoins\AutojoinSetup;

require_once __DIR__ . '/AutojoinSetup.php';

/**
 * Test Suite for Q (Query Builder)
 * Pruebas para joins automáticos a nivel de QueryBuilder
 */
class QTest extends TestCase
{
    private array $originalSchema = [];
    private static bool $setupDone = false;

    public static function setUpBeforeClass(): void
    {
        if (!self::$setupDone) {
            // Verificar y cargar schema_map.php antes de las pruebas
            echo "\n🔍 Verificando schema_map.php...\n";
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
    }

    public function tearDown(): void
    {
        // Limpiar schema
        SchemaMap::setMap($this->originalSchema);
    }

    public function testSimpleSelectWithoutJoins(): void
    {
        $this->env()->test('tabla simple genera FROM sin JOINs', function($test) {
            $query = Q::from('users');
            $compiled = $query->select();
            
            $sql = $compiled->getSql();
            $test->assertStringContainsString('FROM', $sql);
            $test->assertStringNotStartsWith('JOIN', strtoupper(trim($sql)));
            $test->assertStringContainsString('users', $sql);
        });
    }

    public function testTwoTableJoin(): void
    {
        $this->env()->test('dos tablas relacionadas generan JOIN automático', function($test) {
            $query = Q::from(['users', 'posts']);
            $compiled = $query->select();
            
            $sql = $compiled->getSql();
            $test->assertStringContainsString('FROM', $sql);
            $test->assertStringContainsString('JOIN', $sql);
            $test->assertStringContainsString('users', $sql);
            $test->assertStringContainsString('posts', $sql);
        });
    }

    public function testThreeTableJoin(): void
    {
        $this->env()->test('tres tablas relacionadas generan múltiples JOINs', function($test) {
            $query = Q::from(['users', 'posts', 'comments']);
            $compiled = $query->select();
            
            $sql = $compiled->getSql();
            $test->assertStringContainsString('FROM', $sql);
            $joinCount = substr_count(strtoupper($sql), 'JOIN');
            $test->assertTrue($joinCount >= 2, "Expected at least 2 JOINs, got $joinCount");
        });
    }

    public function testJoinWithFilter(): void
    {
        $this->env()->test('JOIN con filtro WHERE mantiene condiciones', function($test) {
            $query = Q::from(['users', 'posts'], ['users.id' => 1]);
            $compiled = $query->select();
            
            $sql = $compiled->getSql();
            $params = $compiled->getParams();
            
            $test->assertStringContainsString('WHERE', $sql);
            $test->assertCount(1, $params);
            $test->assertEquals(1, $params[0]);
        });
    }

    public function testJoinFieldQualification(): void
    {
        $this->env()->test('campos en JOIN están correctamente calificados', function($test) {
            $query = Q::from(['users', 'countries']);
            $compiled = $query->select(['users.name', 'countries.name']);
            
            $sql = $compiled->getSql();
            $test->assertStringContainsString('users.name', $sql);
            $test->assertStringContainsString('countries.name', $sql);
        });
    }

    public function testJoinWithSort(): void
    {
        $this->env()->test('ORDER BY funciona con JOINs', function($test) {
            $query = Q::from(['users', 'posts']);
            $compiled = $query->select('*', null, ['users.name' => 'ASC']);
            
            $sql = $compiled->getSql();
            $test->assertStringContainsString('ORDER BY', $sql);
            $test->assertStringContainsString('users.name', $sql);
        });
    }

    public function testJoinWithPagination(): void
    {
        $this->env()->test('LIMIT/OFFSET funciona con JOINs', function($test) {
            $query = Q::from(['users', 'posts']);
            $compiled = $query->select('*', [10, 20]);
            
            $sql = $compiled->getSql();
            $test->assertStringContainsString('LIMIT', $sql);
            $test->assertStringContainsString('OFFSET', $sql);
        });
    }

    public function testJoinTablesInfo(): void
    {
        $this->env()->test('buildBaseState retorna tablesInfo correcto', function($test) {
            // Usar reflexión para probar método privado
            $query = Q::from(['users', 'posts']);
            $reflection = new \ReflectionClass($query);
            $method = $reflection->getMethod('buildBaseState');
            $method->setAccessible(true);
            
            $result = $method->invoke($query);
            
            $test->assertArrayHasKey('fromClause', $result);
            $test->assertArrayHasKey('tablesInfo', $result);
            $test->assertCount(2, $result['tablesInfo']);
        });
    }

    public function testUnrelatedTablesFallback(): void
    {
        $this->env()->test('tablas sin relación usan modo lineal', function($test) {
            // users y categories no tienen relación directa en nuestro schema
            $query = Q::from(['users', 'categories']);
            $compiled = $query->select();
            
            $sql = $compiled->getSql();
            // Debería generar algo, aunque sea un producto cartesiano o fallback
            $test->assertStringContainsString('FROM', $sql);
            $test->assertStringContainsString('users', $sql);
            $test->assertStringContainsString('categories', $sql);
        });
    }

    public function testJoinWithGroupBy(): void
    {
        $this->env()->test('GROUP BY funciona con JOINs', function($test) {
            $query = Q::from(['users', 'posts']);
            $compiled = $query->select('*', null, [], 'users.id');
            
            $sql = $compiled->getSql();
            $test->assertStringContainsString('GROUP BY', $sql);
        });
    }

    public function testJoinWithHaving(): void
    {
        $this->env()->test('HAVING funciona con JOINs', function($test) {
            $query = Q::from(['users', 'posts']);
            // Usar having() en lugar de pasar HAVING en select() para evitar ConditionMatrix
            $compiled = $query->select('*', null, [], 'users.id');
            $compiled->having([['COUNT', 'posts.id', '>', 1]]);
            
            $sql = $compiled->getSql();
            $test->assertStringContainsString('HAVING', $sql);
        });
    }

    public function testInsertSingleTableOnly(): void
    {
        $this->env()->test('INSERT solo permite una tabla', function($test) {
            $test->expectException(\RuntimeException::class);
            
            $query = Q::from(['users', 'posts']);
            $query->insert([['name' => 'test']]);
        });
    }

    public function testCompiledQueryHasSourceTables(): void
    {
        $this->env()->test('CompiledQuery incluye sourceTables', function($test) {
            $query = Q::from(['users', 'posts']);
            $compiled = $query->select();
            
            $sourceTables = $compiled->getSourceTables();
            $test->assertIsArray($sourceTables);
            $test->assertCount(2, $sourceTables);
            $test->assertContains('users', $sourceTables);
            $test->assertContains('posts', $sourceTables);
        });
    }
}
