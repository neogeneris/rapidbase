<?php

namespace RapidBase\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use RapidBase\Core\SchemaMap;
use RapidBase\Core\DB;
use RapidBase\Core\Conn;

/**
 * Test para verificar que SchemaMap carga correctamente los metadatos
 * y que DB::grid() puede obtener columnas y títulos desde el schema_map.
 */
class SchemaMapMetadataTest extends TestCase
{
    private static string $dbPath = __DIR__ . '/../../tmp/test_schema_metadata.sqlite';
    
    public static function setUpBeforeClass(): void
    {
        // Crear base de datos de prueba
        if (file_exists(self::$dbPath)) {
            unlink(self::$dbPath);
        }
        
        $pdo = new \PDO('sqlite:' . self::$dbPath);
        $pdo->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL,
                email TEXT NOT NULL,
                role TEXT DEFAULT 'user',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        // Insertar datos de prueba
        $pdo->exec("INSERT INTO users (name, email, role) VALUES 
            ('John Doe', 'john@example.com', 'admin'),
            ('Jane Smith', 'jane@example.com', 'user')
        ");
        
        // Configurar conexión
        DB::setup('sqlite:' . self::$dbPath, '', '', 'test');
    }
    
    public static function tearDownAfterClass(): void
    {
        if (file_exists(self::$dbPath)) {
            unlink(self::$dbPath);
        }
        SchemaMap::clear();
    }
    
    /**
     * Test que verifica que SchemaMap::getTable() retorna null cuando no hay mapa cargado
     */
    public function testGetTableReturnsNullWhenNoMapLoaded(): void
    {
        SchemaMap::clear();
        $table = SchemaMap::getTable('users');
        $this->assertNull($table);
    }
    
    /**
     * Test que verifica que SchemaMap carga correctamente desde archivo
     */
    public function testLoadFromFile(): void
    {
        SchemaMap::clear();
        $schemaMapFile = __DIR__ . '/../../../examples/crud/users/schema_map_local.php';
        
        $this->assertFileExists($schemaMapFile);
        
        SchemaMap::loadFromFile($schemaMapFile, 'test_connection');
        
        $map = SchemaMap::getMap('test_connection');
        $this->assertIsArray($map);
        $this->assertArrayHasKey('tables', $map);
        $this->assertArrayHasKey('users', $map['tables']);
    }
    
    /**
     * Test que verifica que getColumns retorna las columnas correctas
     */
    public function testGetColumns(): void
    {
        SchemaMap::clear();
        $schemaMapFile = __DIR__ . '/../../../examples/crud/users/schema_map_local.php';
        SchemaMap::loadFromFile($schemaMapFile, 'test');  // Usar misma conexión que setUp
        
        $columns = SchemaMap::getColumns('users', 'test');
        
        $this->assertIsArray($columns);
        $this->assertArrayHasKey('id', $columns);
        $this->assertArrayHasKey('name', $columns);
        $this->assertArrayHasKey('email', $columns);
        $this->assertArrayHasKey('role', $columns);
        $this->assertArrayHasKey('created_at', $columns);
        
        // Verificar propiedades de la columna 'id'
        $this->assertEquals('INTEGER', $columns['id']['type']);
        $this->assertTrue($columns['id']['primary']);
    }
    
    /**
     * Test que verifica que DB::grid() usa FETCH_NUM cuando se solicita
     */
    public function testGridUsesFetchNum(): void
    {
        SchemaMap::clear();
        $schemaMapFile = __DIR__ . '/../../../examples/crud/users/schema_map_local.php';
        SchemaMap::loadFromFile($schemaMapFile, 'test');
        
        // Ejecutar grid con useFetchNum = true
        $response = DB::grid('users', [], [], 1, 10);
        
        // Verificar que data es un array numérico (FETCH_NUM)
        $this->assertIsArray($response->data);
        $this->assertNotEmpty($response->data);
        
        // La primera fila debe ser un array indexado numéricamente
        $firstRow = $response->data[0];
        $this->assertIsArray($firstRow);
        
        // Verificar que las claves son numéricas (FETCH_NUM)
        $keys = array_keys($firstRow);
        $this->assertEquals(0, $keys[0], 'La primera clave debe ser 0 (FETCH_NUM)');
        $this->assertIsInt($keys[0], 'Las claves deben ser enteros (FETCH_NUM)');
    }
    
    /**
     * Test que verifica que QueryResponse contiene metadata de columnas y títulos
     */
    public function testQueryResponseContainsColumnMetadata(): void
    {
        SchemaMap::clear();
        $schemaMapFile = __DIR__ . '/../../../examples/crud/users/schema_map_local.php';
        SchemaMap::loadFromFile($schemaMapFile, 'main');  // Usar 'main' como en DB::grid()
        
        $response = DB::grid('users', [], [], 1, 10);
        
        // Verificar metadata
        $this->assertArrayHasKey('columns', $response->metadata);
        $this->assertArrayHasKey('titles', $response->metadata);
        
        // Verificar que las columnas coinciden con el schema
        $columns = $response->metadata['columns'];
        $this->assertContains('id', $columns);
        $this->assertContains('name', $columns);
        $this->assertContains('email', $columns);
        
        // Verificar que los títulos no están vacíos
        $titles = $response->metadata['titles'];
        $this->assertNotEmpty($titles);
        $this->assertCount(count($columns), $titles);
    }
    
    /**
     * Test que verifica toGridFormat() retorna la estructura esperada
     */
    public function testToGridFormat(): void
    {
        SchemaMap::clear();
        $schemaMapFile = __DIR__ . '/../../../examples/crud/users/schema_map_local.php';
        SchemaMap::loadFromFile($schemaMapFile, 'test');
        
        $response = DB::grid('users', [], [], 1, 10);
        $gridData = $response->toGridFormat();
        
        // Verificar estructura head
        $this->assertArrayHasKey('head', $gridData);
        $this->assertArrayHasKey('columns', $gridData['head']);
        $this->assertArrayHasKey('titles', $gridData['head']);
        
        // Verificar estructura data
        $this->assertArrayHasKey('data', $gridData);
        $this->assertIsArray($gridData['data']);
        $this->assertNotEmpty($gridData['data']);
        
        // Verificar que data es FETCH_NUM (arrays indexados)
        $firstRow = $gridData['data'][0];
        $this->assertEquals(0, array_keys($firstRow)[0]);
        
        // Verificar estructura page
        $this->assertArrayHasKey('page', $gridData);
        $this->assertArrayHasKey('current', $gridData['page']);
        $this->assertArrayHasKey('total', $gridData['page']);
        $this->assertArrayHasKey('records', $gridData['page']);
        
        // Verificar estructura stats
        $this->assertArrayHasKey('stats', $gridData);
        $this->assertArrayHasKey('exec_ms', $gridData['stats']);
        $this->assertArrayHasKey('cache', $gridData['stats']);
    }
    
    /**
     * Test que verifica que los títulos se generan correctamente desde descripciones o nombres
     */
    public function testTitleGeneration(): void
    {
        SchemaMap::clear();
        $schemaMapFile = __DIR__ . '/../../../examples/crud/users/schema_map_local.php';
        SchemaMap::loadFromFile($schemaMapFile, 'test');
        
        $response = DB::grid('users', [], [], 1, 10);
        $titles = $response->metadata['titles'];
        
        // Los títulos deben estar formateados (ej: created_at -> Created At)
        $this->assertContains('Id', $titles);
        $this->assertContains('Name', $titles);
        $this->assertContains('Email', $titles);
        $this->assertContains('Created At', $titles);
    }
}
