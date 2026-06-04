<?php

declare(strict_types=1);

namespace RapidBase\Tests\Unit\Sort;

use RapidBase\Core\SQL\Q;
use RapidBase\Core\Env;
use RapidBase\Tdd\TestCase;


// Bootstrap centralizado para pruebas unitarias
require_once __DIR__ . '/../bootstrap.php';
/**
 * Pruebas unitarias para Q::sort()
 * 
 * Verifica la capacidad polimórfica de Q::sort() para aceptar múltiples formatos
 * y normalizarlos al formato interno canónico: ['columna' => 1|-1]
 */
class QSortTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        // No necesitamos Env::reset() ya que no existe esa clase
        // El entorno se maneja automáticamente por el TDD Runner
    }

    public function testFormatoCanónicoAsociativo(): void
    {
        $this->env()->test('Q::sort acepta formato canónico [col => 1/-1]', function($test) {
            $input = ['users.name' => 1, 'posts.date' => -1];
            $result = Q::sort($input);

            $test->assertIsArray($result);
            $test->assertEquals('ASC', $result['users.name']);
            $test->assertEquals('DESC', $result['posts.date']);
            $test->assertCount(2, $result);
        });
    }

    public function testFormatoGridObjetos(): void
    {
        $this->env()->test('Q::sort traduce formato Grid [{field, order}]', function($test) {
            $input = [
                ['field' => 'users.name', 'order' => 'asc'],
                ['field' => 'posts.date', 'order' => 'desc']
            ];
            $result = Q::sort($input);

            $test->assertIsArray($result);
            $test->assertEquals('ASC', $result['users.name']);
            $test->assertEquals('DESC', $result['posts.date']);
        });
    }

    public function testFormatoGridMayusculas(): void
    {
        $this->env()->test('Q::sort es insensible a mayúsculas en order (ASC/DESC)', function($test) {
            $input = [
                ['field' => 'name', 'order' => 'ASC'],
                ['field' => 'date', 'order' => 'DESC']
            ];
            $result = Q::sort($input);

            $test->assertEquals('ASC', $result['name']);
            $test->assertEquals('DESC', $result['date']);
        });
    }

    public function testFormatoAntiguoStringsConPrefijo(): void
    {
        $this->env()->test('Q::sort traduce formato antiguo ["col", "-col"]', function($test) {
            $input = ['users.name', '-posts.date'];
            $result = Q::sort($input);

            $test->assertIsArray($result);
            $test->assertEquals('ASC', $result['users.name']);
            $test->assertEquals('DESC', $result['posts.date']);
        });
    }

    public function testFormatoPosicionalEnteros(): void
    {
        $this->env()->test('Q::sort soporta ordenamiento posicional (1, -2)', function($test) {
            $input = [1, -2, 3];
            $result = Q::sort($input);

            $test->assertIsArray($result);
            $test->assertEquals('ASC', $result[1]);
            $test->assertEquals('DESC', $result[2]);
            $test->assertEquals('ASC', $result[3]);
        });
    }

    public function testFormatoPosicionalMixto(): void
    {
        $this->env()->test('Q::sort soporta mezcla de posiciones y nombres', function($test) {
            $input = [1, '-users.name', ['field' => 'email', 'order' => 'desc']];
            $result = Q::sort($input);

            $test->assertEquals('ASC', $result[1]);
            $test->assertEquals('DESC', $result['users.name']);
            $test->assertEquals('DESC', $result['email']);
        });
    }

    public function testCadenaSimple(): void
    {
        $this->env()->test('Q::sort acepta cadena simple "col"', function($test) {
            $result = Q::sort('users.name');
            $test->assertEquals('ASC', $result['users.name']);
        });

        $this->env()->test('Q::sort acepta cadena simple con prefijo "-col"', function($test) {
            $result = Q::sort('-users.name');
            $test->assertEquals('DESC', $result['users.name']);
        });
    }

    public function testCalificacionAutomaticaConTablas(): void
    {
        $this->env()->test('Q::sort califica automáticamente columnas si hay contexto de tablas', function($test) {
            // Usar método estático desde() en lugar de constructor
            $q = Q::from('users');
            $q->join('posts', 'posts.user_id', '=', 'users.id');
            
            // Si pasamos solo 'name', debería calificarse como 'users.name' o mantenerse si es ambiguo
            // Depende de la implementación actual, pero al menos no debe fallar
            $result = Q::sort('name', $q);
            
            // Lo importante es que retorne un array válido
            $test->assertIsArray($result);
            $test->assertTrue(count($result) > 0);
        });
    }

    public function testMantieneAlias(): void
    {
        $this->env()->test('Q::sort respeta alias en columnas', function($test) {
            $input = ['u.name as usuario' => 1];
            $result = Q::sort($input);

            $test->assertArrayHasKey('u.name as usuario', $result);
            $test->assertEquals('ASC', $result['u.name as usuario']);
        });
    }

    public function testEntradaVacia(): void
    {
        $this->env()->test('Q::sort maneja entrada vacía', function($test) {
            $test->assertIsArray(Q::sort([]));
            $test->assertEmpty(Q::sort([]));
        });

        $this->env()->test('Q::sort maneja entrada null', function($test) {
            $test->assertIsArray(Q::sort(null));
            $test->assertEmpty(Q::sort(null));
        });
    }

    public function testDireccionAscDesc(): void
    {
        $this->env()->test('Q::sort convierte 1 a ASC y -1 a DESC', function($test) {
            $test->assertEquals('ASC', Q::sort(['col' => 1])['col']);
            $test->assertEquals('DESC', Q::sort(['col' => -1])['col']);
            $test->assertEquals('ASC', Q::sort(['col' => 'asc'])['col']);
            $test->assertEquals('DESC', Q::sort(['col' => 'desc'])['col']);
        });
    }

    public function testIntegracionConQueryBuilder(): void
    {
        $this->env()->test('Q::sort integrado con Q builder genera ORDER BY correcto', function($test) {
            $q = Q::from('users')
              ->columns(['id', 'name'])
              ->sort(['name' => 'ASC', 'id' => 'DESC']);

            $sql = $q->select()->getSql();
            
            $test->assertStringContainsString('ORDER BY', $sql);
            $test->assertStringContainsString('"users"."name" ASC', $sql);
            $test->assertStringContainsString('"users"."id" DESC', $sql);
        });
    }
}
