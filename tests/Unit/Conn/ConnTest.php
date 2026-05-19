<?php

declare(strict_types=1);

namespace RapidBase\Core;

// Auto-execution block: Cargar dependencias si se ejecuta directamente
if (php_sapi_name() === 'cli' && isset($argv) && realpath($argv[0]) === __FILE__) {
    $baseDir = dirname(__DIR__, 3); // Subir desde tests/Unit/Conn hasta workspace
    
    echo "Loading RapidBase Autoloader from: {$baseDir}/src/\n";
    
    // Cargar el Autoloader del framework
    $autoloaderFile = $baseDir . '/src/RapidBase/Autoloader/Autoloader.php';
    if (file_exists($autoloaderFile)) {
        require_once $autoloaderFile;
        
        // Inicializar y registrar el autoloader
        $autoloader = \RapidBase\Autoloader\Autoloader::getInstance($baseDir . '/src')
            ->enableDebug(false)
            ->enableCache(true)
            ->register();
        
        echo "  Autoloader registered successfully\n";
        
        // Precargar clases frecuentes para mejor rendimiento
        $autoloader->preloadFrequentClasses();
    } else {
        echo "  ERROR: Autoloader not found at {$autoloaderFile}\n";
        exit(1);
    }
}

use RapidBase\Tdd\TestCase;
use PDO;

/**
 * Test Suite for Conn - SQLite3 Tests
 */
class ConnTest extends TestCase
{
    private string $sqliteDb = ':memory:';
    private string $testConnectionId = 'test_conn';

    public function setUp(): void
    {
        // Limpiar conexiones previas
        Conn::close();
    }

    public function tearDown(): void
    {
        // Limpiar después de cada test
        Conn::close();
    }

    public function testAdd(): void
    {
        $this->env('sqlite')->test('should add a new SQLite connection to the pool', function($db) {
            Conn::add($this->testConnectionId, 'sqlite:' . $this->sqliteDb);
            
            $pdo = Conn::get($this->testConnectionId);
            $this->assertInstanceOf(PDO::class, $pdo);
            $this->assertEquals('sqlite', Conn::getDriver($this->testConnectionId));
        });
    }

    public function testSetup(): void
    {
        $this->env('sqlite')->test('should setup and activate a connection', function($db) {
            Conn::setup('sqlite:' . $this->sqliteDb, '', '', $this->testConnectionId);
            
            $this->assertEquals($this->testConnectionId, Conn::getCurrentConnectionId());
            $pdo = Conn::get();
            $this->assertInstanceOf(PDO::class, $pdo);
        });
    }

    public function testSelect(): void
    {
        $this->env('sqlite')->test('should switch active connection', function($db) {
            Conn::add('conn1', 'sqlite:' . $this->sqliteDb);
            Conn::add('conn2', 'sqlite:' . $this->sqliteDb);
            
            Conn::select('conn1');
            $this->assertEquals('conn1', Conn::getCurrentConnectionId());
            
            Conn::select('conn2');
            $this->assertEquals('conn2', Conn::getCurrentConnectionId());
        });
    }

    public function testGet(): void
    {
        $this->env('sqlite')->test('should return PDO instance from active connection', function($db) {
            Conn::setup('sqlite:' . $this->sqliteDb, '', '', $this->testConnectionId);
            
            $pdo = Conn::get();
            $this->assertInstanceOf(PDO::class, $pdo);
            
            // Also test getting specific connection
            $pdo2 = Conn::get($this->testConnectionId);
            $this->assertInstanceOf(PDO::class, $pdo2);
            $this->assertSame($pdo, $pdo2);
        });
    }

    public function testGetThrowsExceptionWhenNotFound(): void
    {
        $this->env('sqlite')->test('should throw exception when connection not found', function($db) {
            try {
                Conn::get('nonexistent');
                $this->fail('Should have thrown RuntimeException');
            } catch (\RuntimeException $e) {
                $this->assertTrue(str_contains($e->getMessage(), 'not available'));
            }
        });
    }

    public function testInTransaction(): void
    {
        $this->env('sqlite')->test('should detect transaction state', function($db) {
            Conn::setup('sqlite:' . $this->sqliteDb, '', '', $this->testConnectionId);
            
            // Initially no transaction
            $this->assertFalse(Conn::inTransaction());
            
            $pdo = Conn::get();
            $pdo->beginTransaction();
            $this->assertTrue(Conn::inTransaction());
            
            $pdo->commit();
            $this->assertFalse(Conn::inTransaction());
        });
    }

    public function testGetCurrentConnectionId(): void
    {
        $this->env('sqlite')->test('should return current connection ID', function($db) {
            $this->assertEquals('default', Conn::getCurrentConnectionId());
            
            Conn::add($this->testConnectionId, 'sqlite:' . $this->sqliteDb);
            Conn::select($this->testConnectionId);
            
            $this->assertEquals($this->testConnectionId, Conn::getCurrentConnectionId());
        });
    }

    public function testGetMetadata(): void
    {
        $this->env('sqlite')->test('should return connection metadata', function($db) {
            Conn::setup('sqlite:' . $this->sqliteDb, '', '', $this->testConnectionId);
            
            $metadata = Conn::getMetadata();
            $this->assertNotNull($metadata);
            $this->assertEquals('sqlite', $metadata['driver']);
            $this->assertEquals($this->testConnectionId, $metadata['connectionId']);
            $this->assertEquals(':memory:', $metadata['dbname']);
        });
    }

    public function testGetDriver(): void
    {
        $this->env('sqlite')->test('should return driver name', function($db) {
            Conn::setup('sqlite:' . $this->sqliteDb, '', '', $this->testConnectionId);
            $this->assertEquals('sqlite', Conn::getDriver());
        });
    }

    public function testGetDatabaseName(): void
    {
        $this->env('sqlite')->test('should extract database name from DSN', function($db) {
            Conn::setup('sqlite:' . $this->sqliteDb, '', '', $this->testConnectionId);
            $this->assertEquals(':memory:', Conn::getDatabaseName());
            
            // Test with file-based SQLite
            $tempFile = tempnam(sys_get_temp_dir(), 'tdd_test_');
            Conn::add('file_conn', 'sqlite:' . $tempFile);
            $this->assertEquals(basename($tempFile), Conn::getDatabaseName('file_conn'));
            unlink($tempFile);
        });
    }

    public function testListConnectionIds(): void
    {
        $this->env('sqlite')->test('should list all registered connection IDs', function($db) {
            Conn::add('conn1', 'sqlite:' . $this->sqliteDb);
            Conn::add('conn2', 'sqlite:' . $this->sqliteDb);
            Conn::add('conn3', 'sqlite:' . $this->sqliteDb);
            
            $ids = Conn::listConnectionIds();
            $this->assertCount(3, $ids);
            $this->assertContains('conn1', $ids);
            $this->assertContains('conn2', $ids);
            $this->assertContains('conn3', $ids);
        });
    }

    public function testClose(): void
    {
        $this->env('sqlite')->test('should close single or all connections', function($db) {
            Conn::add('conn1', 'sqlite:' . $this->sqliteDb);
            Conn::add('conn2', 'sqlite:' . $this->sqliteDb);
            
            // Close one connection
            Conn::close('conn1');
            $ids = Conn::listConnectionIds();
            $this->assertCount(1, $ids);
            $this->assertContains('conn2', $ids);
            
            // Close all
            Conn::add('conn3', 'sqlite:' . $this->sqliteDb);
            Conn::close();
            $this->assertCount(0, Conn::listConnectionIds());
        });
    }

    public function testCloseUpdatesCurrentConnection(): void
    {
        $this->env('sqlite')->test('should update current connection when active is closed', function($db) {
            Conn::add('conn1', 'sqlite:' . $this->sqliteDb);
            Conn::add('conn2', 'sqlite:' . $this->sqliteDb);
            Conn::select('conn1');
            
            $this->assertEquals('conn1', Conn::getCurrentConnectionId());
            
            Conn::close('conn1');
            // Current should switch to remaining connection
            $this->assertEquals('conn2', Conn::getCurrentConnectionId());
        });
    }

    public function testSelectThrowsExceptionWhenNotFound(): void
    {
        $this->env('sqlite')->test('should throw exception when selecting nonexistent connection', function($db) {
            try {
                Conn::select('nonexistent');
                $this->fail('Should have thrown InvalidArgumentException');
            } catch (\InvalidArgumentException $e) {
                $this->assertTrue(str_contains($e->getMessage(), 'not found'));
            }
        });
    }

    public function testMultipleConnectionsIsolation(): void
    {
        $this->env('sqlite')->test('should maintain isolated connections', function($db) {
            // Create two separate in-memory databases
            Conn::add('db1', 'sqlite::memory:');
            Conn::add('db2', 'sqlite::memory:');
            
            // Create table in db1
            Conn::select('db1');
            $pdo1 = Conn::get();
            $pdo1->exec('CREATE TABLE test1 (id INTEGER)');
            
            // Create table in db2
            Conn::select('db2');
            $pdo2 = Conn::get();
            $pdo2->exec('CREATE TABLE test2 (id INTEGER)');
            
            // Verify isolation
            Conn::select('db1');
            $tables1 = Conn::get()->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(\PDO::FETCH_COLUMN);
            $this->assertContains('test1', $tables1);
            $this->assertNotContains('test2', $tables1);
            
            Conn::select('db2');
            $tables2 = Conn::get()->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(\PDO::FETCH_COLUMN);
            $this->assertContains('test2', $tables2);
            $this->assertNotContains('test1', $tables2);
        });
    }
}

// Auto-execution block: permite ejecutar este archivo directamente con php ConnTest.php
if (php_sapi_name() === 'cli' && isset($argv) && realpath($argv[0]) === __FILE__) {
    echo "\n";
    echo str_repeat('=', 70) . "\n";
    echo "  Running " . basename(__FILE__) . " directly (Standalone Mode)\n";
    echo str_repeat('=', 70) . "\n\n";
    
    // Autoload simple para dependencias mínimas
    $baseDir = dirname(__DIR__, 3); // Subir desde tests/Unit/Conn hasta workspace
    
    // Registrar autoloader manual para clases del framework
    spl_autoload_register(function ($class) use ($baseDir) {
        $prefixes = ['RapidBase\\'];
        foreach ($prefixes as $prefix) {
            if (strpos($class, $prefix) === 0) {
                $relativeClass = substr($class, strlen($prefix));
                $file = $baseDir . '/src/' . str_replace('\\', '/', $relativeClass) . '.php';
                if (file_exists($file)) {
                    require $file;
                    return true;
                }
            }
        }
        return false;
    });
    
    // Verificar que las clases críticas estén disponibles
    if (!class_exists('RapidBase\\Tdd\\TestCase')) {
        echo "ERROR: Cannot load RapidBase\\Tdd\\TestCase\n";
        echo "Checked path: {$baseDir}/src/RapidBase/Tdd/TestCase.php\n";
        exit(1);
    }
    
    // Ejecutar pruebas manualmente
    $testClass = new ConnTest();
    $methods = get_class_methods($testClass);
    $testMethods = array_filter($methods, fn($m) => str_starts_with($m, 'test'));
    
    $total = count($testMethods);
    $passed = 0;
    $failed = 0;
    
    foreach ($testMethods as $methodName) {
        try {
            // Setup
            if (method_exists($testClass, 'setUp')) {
                $testClass->setUp();
            }
            
            // Ejecutar test
            $startTime = microtime(true);
            $testClass->$methodName();
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            // Teardown
            if (method_exists($testClass, 'tearDown')) {
                $testClass->tearDown();
            }
            
            echo "  [PASS] {$methodName} ({$duration}ms)\n";
            $passed++;
            
        } catch (\Throwable $e) {
            // Teardown incluso en error
            if (method_exists($testClass, 'tearDown')) {
                try {
                    $testClass->tearDown();
                } catch (\Throwable $te) {}
            }
            
            echo "\n  [FAIL] {$methodName}\n";
            echo "    Error: {$e->getMessage()}\n";
            echo "    File: {$e->getFile()} (Line {$e->getLine()})\n\n";
            $failed++;
        }
    }
    
    echo "\n";
    echo str_repeat('-', 70) . "\n";
    echo "  Results: {$total} total, {$passed} passed, {$failed} failed\n";
    echo str_repeat('=', 70) . "\n";
    
    exit($failed > 0 ? 1 : 0);
}
