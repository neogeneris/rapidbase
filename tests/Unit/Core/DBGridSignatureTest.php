<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../src/RapidBase/Core/Conn.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/DB.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/Cache/CacheService.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/Cache/Adapters/DirectoryCacheAdapter.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/Gateway.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/Executor.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/QueryResponse.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/SQL.php';

use RapidBase\Core\DB;
use RapidBase\Core\Conn;
use RapidBase\Core\Cache\CacheService;

/**
 * Pruebas para la firma del método DB::grid()
 */
class DBGridSignatureTest extends TestCase
{
    protected function setUp(): void
    {
        // Configurar DB en memoria para pruebas
        Conn::setup('sqlite::memory:', '', '', 'main');
        
        // Configurar caché en temporal
        $cachePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rapidbase_grid_test';
        if (!is_dir($cachePath)) {
            mkdir($cachePath, 0777, true);
        }
        CacheService::init($cachePath);
        
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

    public function testGridWithPageZeroReturnsAllRecords(): void
    {
        $response = DB::grid('test_grid', [], 0);
        
        $this->assertInstanceOf(\RapidBase\Core\QueryResponse::class, $response);
        $this->assertCount(25, $response->data);
        $this->assertEquals(25, $response->total);
    }

    public function testGridWithPageOneReturnsFirstPage(): void
    {
        $response = DB::grid('test_grid', [], 1, []);
        
        $this->assertInstanceOf(\RapidBase\Core\QueryResponse::class, $response);
        $this->assertCount(10, $response->data); // Default limit is 10
        $this->assertEquals(25, $response->total);
        $this->assertEquals(1, $response->state['page']);
    }

    public function testGridWithPageTwoReturnsSecondPage(): void
    {
        $response = DB::grid('test_grid', [], 2, []);
        
        $this->assertInstanceOf(\RapidBase\Core\QueryResponse::class, $response);
        $this->assertCount(10, $response->data);
        $this->assertEquals(25, $response->total);
        $this->assertEquals(2, $response->state['page']);
        
        // Verificar que el primer registro de la página 2 es el #11
        $firstRow = $response->data[0];
        $this->assertEquals(11, $firstRow[0]); // ID should be 11
    }

    public function testGridWithPageThreeReturnsRemainingRecords(): void
    {
        $response = DB::grid('test_grid', [], 3, []);
        
        $this->assertInstanceOf(\RapidBase\Core\QueryResponse::class, $response);
        $this->assertCount(5, $response->data); // Only 5 remaining
        $this->assertEquals(25, $response->total);
        $this->assertEquals(3, $response->state['page']);
    }

    public function testGridWithStringSortAscending(): void
    {
        $response = DB::grid('test_grid', [], 0, 'name');
        
        $this->assertInstanceOf(\RapidBase\Core\QueryResponse::class, $response);
        // First item should be "Item 1" (lexicographically first)
        $firstRow = $response->data[0];
        $this->assertStringContainsString('Item 1', $firstRow[1]);
    }

    public function testGridWithStringSortDescending(): void
    {
        $response = DB::grid('test_grid', [], 0, '-value');
        
        $this->assertInstanceOf(\RapidBase\Core\QueryResponse::class, $response);
        // First item should have the highest value (250)
        $firstRow = $response->data[0];
        $this->assertEquals(250, $firstRow[2]); // value column
    }

    public function testGridWithArraySort(): void
    {
        $response = DB::grid('test_grid', [], 0, ['value', '-name']);
        
        $this->assertInstanceOf(\RapidBase\Core\QueryResponse::class, $response);
        $this->assertCount(25, $response->data);
    }

    public function testGridWithWhereCondition(): void
    {
        $response = DB::grid('test_grid', [['value', '>', 100]], 0, []);
        
        $this->assertInstanceOf(\RapidBase\Core\QueryResponse::class, $response);
        $this->assertLessThan(25, $response->count);
        $this->assertEquals($response->count, $response->total);
    }
}
