<?php

declare(strict_types=1);

namespace RapidBase\Core;

use RapidBase\Tdd\TestCase;

/**
 * Pruebas para la firma del método DB::grid()
 * Modernizada para usar RapidBase TDD Framework
 */
class DBGridSignatureTest extends TestCase
{
    private string $cachePath = '';
    private string $dbPath = ':memory:';

    public function setUp(): void
    {
        // Configurar DB en memoria para pruebas
        Conn::setup('sqlite::memory:', '', '', 'main');
        
        // Configurar caché en temporal
        $this->cachePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rapidbase_grid_test_' . uniqid();
        if (!is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0777, true);
        }
        \RapidBase\Core\Cache\CacheService::init($this->cachePath);
        
        // Crear tabla de prueba
        DB::exec("CREATE TABLE test_grid (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            value INTEGER DEFAULT 0
        )");
        
        // Insertar datos de prueba
        for ($i = 1; $i <= 25; $i++) {
            DB::insert('test_grid', ['name' => "Item $i", 'value' => $i * 10]);
        }
    }

    public function tearDown(): void
    {
        // Limpiar caché temporal
        if (!empty($this->cachePath) && is_dir($this->cachePath)) {
            $this->deleteDirectory($this->cachePath);
        }
    }

    private function deleteDirectory(string $dir): void
    {
        if (!file_exists($dir)) return;
        if (!is_dir($dir)) {
            unlink($dir);
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item == '.' || $item == '..') continue;
            $this->deleteDirectory($dir . DIRECTORY_SEPARATOR . $item);
        }
        rmdir($dir);
    }

    public function testGridWithPageZeroReturnsAllRecords(): void
    {
        $this->env()->test('page=0 retorna todos los registros', function($test) {
            $response = DB::grid('test_grid', [], 0);
            
            $test->assertInstanceOf(\RapidBase\Core\QueryResponse::class, $response);
            $test->assertCount(25, $response->data);
            $test->assertEquals(25, $response->total);
        });
    }

    public function testGridWithPageOneReturnsFirstPage(): void
    {
        $this->env()->test('page=1 retorna primera página con limit por defecto', function($test) {
            $response = DB::grid('test_grid', [], 1);
            
            $test->assertInstanceOf(\RapidBase\Core\QueryResponse::class, $response);
            $test->assertCount(10, $response->data); // Default limit is 10
            $test->assertEquals(25, $response->total);
            $test->assertEquals(1, $response->state['page']);
        });
    }

    public function testGridWithPageTwoReturnsSecondPage(): void
    {
        $this->env()->test('page=2 retorna segunda página', function($test) {
            $response = DB::grid('test_grid', [], 2);
            
            $test->assertInstanceOf(\RapidBase\Core\QueryResponse::class, $response);
            $test->assertCount(10, $response->data);
            $test->assertEquals(25, $response->total);
            $test->assertEquals(2, $response->state['page']);
            
            // Verificar que el primer registro de la página 2 es el #11
            $firstRow = $response->data[0];
            $test->assertEquals(11, $firstRow[0]); // ID should be 11
        });
    }

    public function testGridWithPageThreeReturnsRemainingRecords(): void
    {
        $this->env()->test('page=3 retorna registros restantes', function($test) {
            $response = DB::grid('test_grid', [], 3);
            
            $test->assertInstanceOf(\RapidBase\Core\QueryResponse::class, $response);
            $test->assertCount(5, $response->data); // Only 5 remaining
            $test->assertEquals(25, $response->total);
            $test->assertEquals(3, $response->state['page']);
        });
    }

    public function testGridWithStringSortAscending(): void
    {
        $this->env()->test('ordenamiento ascendente por string funciona', function($test) {
            $response = DB::grid('test_grid', [], 0, 'name');
            
            $test->assertInstanceOf(\RapidBase\Core\QueryResponse::class, $response);
            // First item should be "Item 1" (lexicographically first)
            $firstRow = $response->data[0];
            $test->assertStringContainsString('Item 1', $firstRow[1]);
        });
    }

    public function testGridWithStringSortDescending(): void
    {
        $this->env()->test('ordenamiento descendente por string funciona', function($test) {
            $response = DB::grid('test_grid', [], 0, '-value');
            
            $test->assertInstanceOf(\RapidBase\Core\QueryResponse::class, $response);
            // First item should have the highest value (250)
            $firstRow = $response->data[0];
            $test->assertEquals(250, $firstRow[2]); // value column
        });
    }

    public function testGridWithArraySort(): void
    {
        $this->env()->test('ordenamiento con array funciona', function($test) {
            $response = DB::grid('test_grid', [], 0, ['value', '-name']);
            
            $test->assertInstanceOf(\RapidBase\Core\QueryResponse::class, $response);
            $test->assertCount(25, $response->data);
        });
    }

    public function testGridWithWhereCondition(): void
    {
        $this->env()->test('filtro WHERE funciona correctamente', function($test) {
            $response = DB::grid('test_grid', [['value', '>', 100]], 0);
            
            $test->assertInstanceOf(\RapidBase\Core\QueryResponse::class, $response);
            $test->assertLessThan(25, $response->count);
            $test->assertEquals($response->count, $response->total);
        });
    }
}
