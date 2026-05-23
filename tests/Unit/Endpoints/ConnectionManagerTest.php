<?php

declare(strict_types=1);

namespace RapidBase\Endpoints;

// Auto-cargar dependencias si se ejecuta directamente
if (php_sapi_name() === 'cli') {
    $baseDir = dirname(__DIR__, 4);
    if (!file_exists($baseDir . '/src/RapidBase/Autoloader/Autoloader.php')) {
        $baseDir = dirname(__DIR__, 3); // Ajustar si estamos en tests/Unit/Endpoints
    }
    if (!file_exists($baseDir . '/src/RapidBase/Autoloader/Autoloader.php')) {
        $baseDir = dirname(__DIR__, 2); // Ajustar si estamos en tests/Unit
    }
    
    $autoloaderFile = $baseDir . '/src/RapidBase/Autoloader/Autoloader.php';
    if (file_exists($autoloaderFile)) {
        require_once $autoloaderFile;
        \RapidBase\Autoloader\Autoloader::getInstance($baseDir . '/src')
            ->enableDebug(false)
            ->enableCache(true)
            ->register();
    } else {
        // Fallback: autoloader manual mínimo
        spl_autoload_register(function ($class) use ($baseDir) {
            if (strpos($class, 'RapidBase\\') === 0) {
                $file = $baseDir . '/src/' . str_replace('\\', '/', $class) . '.php';
                if (file_exists($file)) {
                    require_once $file;
                }
            }
        });
    }
    
    // Carga manual de clases del QueryBrowser (están fuera del bundle compilado)
    require_once $baseDir . '/examples/querybrowser/api/v1/ApiContext.php';
    require_once $baseDir . '/examples/querybrowser/api/v1/BaseEndpoint.php';
    require_once $baseDir . '/examples/querybrowser/api/v1/Models/Connection.php';
    require_once $baseDir . '/examples/querybrowser/api/v1/Endpoints/ConnectionManager.php';
}

use RapidBase\Tdd\TestCase;
use RapidBase\Api\ApiContext;
use RapidBase\Core\DB;
use RapidBase\Core\Conn;
use RapidBase\Core\Cache\CacheService;
use RapidBase\Models\Connection as ConnectionModel;

/**
 * Test Suite for ConnectionManager Endpoint using RapidBase TDD Framework
 * 
 * Valida la gestión de conexiones a bases de datos:
 * - CRUD de conexiones en la BD interna (sqlite)
 * - Test de conexión a diferentes drivers (sqlite, mysql, pgsql, sqlsrv)
 * - Activación y ping de conexiones
 * - Prevención de conexión errónea a 'main'
 */
class ConnectionManagerTest extends TestCase
{
    private string $testDbFile = '';
    private string $testCachePath = '';

    public function setUp(): void
    {
        // Configurar base de datos temporal para pruebas
        $this->testDbFile = sys_get_temp_dir() . '/rapidbase_test_connections_' . uniqid() . '.sqlite';
        $this->testCachePath = sys_get_temp_dir() . '/rapidbase_test_cache_' . uniqid();
        
        // Siempre definir CONNECTIONS_DB para cada test (usando eval para evitar error de redefinición)
        if (!defined('CONNECTIONS_DB')) {
            define('CONNECTIONS_DB', $this->testDbFile);
        } else {
            // Si ya está definida, necesitamos usar una técnica especial para cambiarla
            // Esto es necesario porque cada test debe tener su propia BD
            putenv('CONNECTIONS_DB_OVERRIDE=' . $this->testDbFile);
        }
        
        // Inicializar caché temporal
        if (!is_dir($this->testCachePath)) {
            mkdir($this->testCachePath, 0777, true);
        }
        CacheService::init($this->testCachePath);
        
        // Limpiar conexiones previas
        Conn::close();
        
        // Forzar recreación de la BD interna para cada test usando el método público
        ConnectionManager::resetInstance();
    }

    public function tearDown(): void
    {
        // Limpieza de archivos temporales
        if (file_exists($this->testDbFile)) {
            unlink($this->testDbFile);
        }
        if (is_dir($this->testCachePath)) {
            // Usar recursive directory iterator para limpiar correctamente
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->testCachePath, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $file) {
                if ($file->isDir()) {
                    rmdir($file->getPathname());
                } else {
                    unlink($file->getPathname());
                }
            }
            rmdir($this->testCachePath);
        }
        
        Conn::close();
    }

    private function cleanup(): void
    {
        try {
            DB::con('internal')->exec("DELETE FROM connections");
            DB::con('internal')->exec("DELETE FROM sqlite_sequence WHERE name='connections'");
        } catch (\Throwable $e) {
            // Ignorar errores si la tabla no existe aún
        }
    }

    private function createContext(array $params = []): ApiContext
    {
        return new ApiContext($params, [], []);
    }

    // ======================================================
    // TEST 1: Listado de conexiones vacío
    // ======================================================
    public function testList(): void
    {
        $this->env('sqlite')->test('should return empty list when no connections exist', function($test) {
            $this->cleanup();
            
            $context = $this->createContext([]);
            $endpoint = new ConnectionManager();
            $endpoint->setContext($context);
            
            $result = $endpoint->list();
            
            $this->assertTrue($result['success'] === true, 'list() retorna success=true');
            $this->assertTrue($result['count'] === 0, 'list() retorna count=0');
            $this->assertTrue(empty($result['connections']), 'list() retorna array vacío de conexiones');
        });
    }

    // ======================================================
    // TEST 2: Crear conexión SQLite
    // ======================================================
    public function testCreateSqlite(): void
    {
        $this->env('sqlite')->test('should create SQLite connection successfully', function($test) {
            $this->cleanup();
            
            $params = [
                'name' => 'TestSQLite',
                'driver' => 'sqlite',
                'host' => null,
                'port' => null,
                'database' => ':memory:',
                'username' => '',
                'password' => '',
                'description' => 'Base de datos de prueba en memoria',
                'environment' => 'test',
                'status' => 'active'
            ];
            
            $context = $this->createContext($params);
            $endpoint = new ConnectionManager();
            $endpoint->setContext($context);
            
            $result = $endpoint->create();
            
            $this->assertTrue($result['success'] === true, 'create() retorna success=true');
            $this->assertTrue(isset($result['id']) && $result['id'] > 0, 'create() retorna id > 0');
            $this->assertTrue(isset($result['connection']), 'create() retorna connection data');
            $this->assertTrue($result['connection']['name'] === 'TestSQLite', 'connection tiene nombre correcto');
            $this->assertTrue(!isset($result['connection']['password']), 'connection no expone password');
        });
    }

    // ======================================================
    // TEST 3: Crear conexión MySQL (registro)
    // ======================================================
    public function testCreateMySQL(): void
    {
        $this->env('sqlite')->test('should register MySQL connection without connecting', function($test) {
            $this->cleanup();
            
            $params = [
                'name' => 'TestMySQL',
                'driver' => 'mysql',
                'host' => 'localhost',
                'port' => 3306,
                'database' => 'test_db',
                'username' => 'root',
                'password' => 'secret',
                'description' => 'MySQL local',
                'environment' => 'development',
                'status' => 'active'
            ];
            
            $context = $this->createContext($params);
            $endpoint = new ConnectionManager();
            $endpoint->setContext($context);
            
            $result = $endpoint->create();
            
            $this->assertTrue($result['success'] === true, 'create() MySQL retorna success=true');
            $this->assertTrue(isset($result['id']) && $result['id'] > 0, 'create() MySQL guarda registro');
        });
    }

    // ======================================================
    // TEST 4: Crear conexión SQLServer (registro)
    // ======================================================
    public function testCreateSQLServer(): void
    {
        $this->env('sqlite')->test('should register SQLServer connection and build correct DSN', function($test) {
            $this->cleanup();
            
            $params = [
                'name' => 'TestSQLServer',
                'driver' => 'sqlsrv',
                'host' => 'localhost\\SQLEXPRESS',
                'port' => 1433,
                'database' => 'master',
                'username' => 'sa',
                'password' => 'YourPassword123',
                'description' => 'SQL Server local',
                'environment' => 'development',
                'status' => 'active'
            ];
            
            $context = $this->createContext($params);
            $endpoint = new ConnectionManager();
            $endpoint->setContext($context);
            
            $result = $endpoint->create();
            
            $this->assertTrue($result['success'] === true, 'create() SQLServer retorna success=true');
            $this->assertTrue(isset($result['id']) && $result['id'] > 0, 'create() SQLServer guarda registro');
            
            // Verificar que el DSN se construye correctamente usando el modelo directamente
            $connModel = new ConnectionModel($params);
            $dsn = $connModel->buildDsn();
            $this->assertTrue(strpos($dsn, 'sqlsrv:Server=') === 0, 'DSN SQLServer es correcto');
            $this->assertTrue(strpos($dsn, 'Encrypt=0') !== false, 'DSN SQLServer incluye Encrypt=0');
        });
    }

    // ======================================================
    // TEST 5: Test de conexión SQLite real
    // ======================================================
    public function testConnectionSQLite(): void
    {
        $this->env('sqlite')->test('should test real SQLite connection successfully', function($test) {
            $this->cleanup();
            
            // Crear una BD SQLite temporal para probar
            $tempDbFile = sys_get_temp_dir() . '/rapidbase_test_real_' . uniqid() . '.sqlite';
            file_put_contents($tempDbFile, '');
            
            $params = [
                'name' => 'RealSQLite',
                'driver' => 'sqlite',
                'database' => $tempDbFile,
            ];
            
            $context = $this->createContext($params);
            $endpoint = new ConnectionManager();
            $endpoint->setContext($context);
            
            $result = $endpoint->test();
            
            $this->assertTrue($result['success'] === true, 'test() SQLite retorna success=true');
            $this->assertTrue(isset($result['latency']), 'test() SQLite reporta latencia');
            
            unlink($tempDbFile);
        });
    }

    // ======================================================
    // TEST 6: Test de conexión fallida
    // ======================================================
    public function testConnectionFailed(): void
    {
        $this->env('sqlite')->test('should fail with invalid host', function($test) {
            $params = [
                'name' => 'InvalidMySQL',
                'driver' => 'mysql',
                'host' => 'nonexistent-host',
                'port' => 3306,
                'database' => 'nonexistent_db',
                'username' => 'invalid',
                'password' => 'invalid'
            ];
            
            $context = $this->createContext($params);
            $endpoint = new ConnectionManager();
            $endpoint->setContext($context);
            
            $result = $endpoint->test();
            
            $this->assertTrue($result['success'] === false, 'test() falla con host inválido');
            $this->assertTrue(isset($result['error']) && !empty($result['error']), 'test() retorna error descriptivo');
        });
    }

    // ======================================================
    // TEST 7: Listar múltiples conexiones
    // ======================================================
    public function testListMultipleConnections(): void
    {
        $this->env('sqlite')->test('should list multiple connections', function($test) {
            $this->cleanup();
            
            // Crear varias conexiones
            $connections = [
                ['name' => 'Conn1', 'driver' => 'sqlite', 'database' => ':memory:'],
                ['name' => 'Conn2', 'driver' => 'sqlite', 'database' => ':memory:'],
                ['name' => 'Conn3', 'driver' => 'sqlite', 'database' => ':memory:'],
            ];
            
            foreach ($connections as $connData) {
                $ctx = $this->createContext($connData);
                $ep = new ConnectionManager();
                $ep->setContext($ctx);
                $ep->create();
            }
            
            $context = $this->createContext([]);
            $endpoint = new ConnectionManager();
            $endpoint->setContext($context);
            
            $result = $endpoint->list();
            
            $this->assertTrue($result['count'] === 3, 'list() retorna 3 conexiones');
            $this->assertTrue(count(array_filter($result['connections'], fn($c) => isset($c['driver']))) === 3, 'list() todas tienen driver');
        });
    }

    // ======================================================
    // TEST 8: Activar conexión
    // ======================================================
    public function testActivate(): void
    {
        $this->env('sqlite')->test('should activate connection successfully', function($test) {
            $this->cleanup();
            
            $params = [
                'name' => 'ActivatableSQLite',
                'driver' => 'sqlite',
                'database' => ':memory:'
            ];
            
            $context = $this->createContext($params);
            $endpoint = new ConnectionManager();
            $endpoint->setContext($context);
            $createResult = $endpoint->create();
            
            $context = $this->createContext(['connectionId' => "saved_{$createResult['id']}"]);
            $endpoint = new ConnectionManager();
            $endpoint->setContext($context);
            
            $result = $endpoint->activate();
            
            $this->assertTrue($result['success'] === true, 'activate() retorna success=true');
            $this->assertTrue(isset($result['connectionId']), 'activate() retorna connectionId');
            $this->assertTrue(strpos($result['connectionId'], 'saved_') === 0, 'activate() connectionId tiene prefijo saved_');
        });
    }

    // ======================================================
    // TEST 9: Ping a conexión activada
    // ======================================================
    public function testPing(): void
    {
        $this->env('sqlite')->test('should ping activated connection successfully', function($test) {
            $this->cleanup();
            
            // Crear y activar conexión
            $params = ['name' => 'PingableSQLite', 'driver' => 'sqlite', 'database' => ':memory:'];
            $context = $this->createContext($params);
            $endpoint = new ConnectionManager();
            $endpoint->setContext($context);
            $createResult = $endpoint->create();
            
            $context = $this->createContext(['connectionId' => "saved_{$createResult['id']}"]);
            $endpoint = new ConnectionManager();
            $endpoint->setContext($context);
            $endpoint->activate();
            
            $result = $endpoint->ping();
            
            $this->assertTrue($result['success'] === true, 'ping() retorna success=true');
            $this->assertTrue(isset($result['latency']), 'ping() retorna latency');
            $this->assertTrue(isset($result['database_name']), 'ping() retorna database_name');
            $this->assertTrue(isset($result['driver']) && $result['driver'] === 'sqlite', 'ping() retorna driver');
        });
    }

    // ======================================================
    // TEST 10: Eliminar conexión
    // ======================================================
    public function testDelete(): void
    {
        $this->env('sqlite')->test('should delete connection successfully', function($test) {
            $this->cleanup();
            
            $params = ['name' => 'ToDelete', 'driver' => 'sqlite', 'database' => ':memory:'];
            $context = $this->createContext($params);
            $endpoint = new ConnectionManager();
            $endpoint->setContext($context);
            $createResult = $endpoint->create();
            
            $context = $this->createContext(['id' => $createResult['id']]);
            $endpoint = new ConnectionManager();
            $endpoint->setContext($context);
            
            $result = $endpoint->delete();
            
            $this->assertTrue($result['success'] === true, 'delete() retorna success=true');
            $this->assertTrue(isset($result['deleted_id']) && $result['deleted_id'] == $createResult['id'], 'delete() retorna deleted_id');
            
            // Verificar que fue eliminada
            $context = $this->createContext([]);
            $endpoint = new ConnectionManager();
            $endpoint->setContext($context);
            $result = $endpoint->list();
            $this->assertTrue($result['count'] === 0, 'delete() realmente elimina (count=0)');
        });
    }

    // ======================================================
    // TEST 11: Prevenir conexión accidental a 'main'
    // ======================================================
    public function testPreventMainConnection(): void
    {
        $this->env('sqlite')->test('should prevent accidental connection to main', function($test) {
            $this->cleanup();
            
            // Crear una conexión explícita
            $params = ['name' => 'NotMain', 'driver' => 'sqlite', 'database' => ':memory:'];
            $context = $this->createContext($params);
            $endpoint = new ConnectionManager();
            $endpoint->setContext($context);
            $createResult = $endpoint->create();
            
            // Intentar hacer ping sin especificar connectionId debería fallar
            $context = $this->createContext([]);
            $endpoint = new ConnectionManager();
            $endpoint->setContext($context);
            
            $result = $endpoint->ping();
            
            $this->assertTrue($result['success'] === false, 'ping() sin connectionId falla');
            $this->assertTrue(strpos($result['error'], 'Missing') !== false, 'ping() sin connectionId indica missing connectionId');
        });
    }

    // ======================================================
    // TEST 12: Validar buildDsn para todos los drivers
    // ======================================================
    public function testBuildDsnAllDrivers(): void
    {
        $this->env('sqlite')->test('should build correct DSN for all supported drivers', function($test) {
            $drivers = [
                'sqlite' => ['database' => '/path/to/db.sqlite', 'expected' => 'sqlite:/path/to/db.sqlite'],
                'mysql' => ['host' => 'localhost', 'port' => 3306, 'database' => 'mydb', 'expected' => 'mysql:host=localhost;port=3306;dbname=mydb;charset=utf8mb4'],
                'mariadb' => ['host' => 'localhost', 'database' => 'mydb', 'expected' => 'mysql:host=localhost;dbname=mydb;charset=utf8mb4'],
                'pgsql' => ['host' => 'localhost', 'port' => 5432, 'database' => 'mydb', 'expected' => 'pgsql:host=localhost;port=5432;dbname=mydb'],
                'sqlsrv' => ['host' => 'localhost\\SQLEXPRESS', 'port' => 1433, 'database' => 'mydb', 'expected' => 'sqlsrv:Server=localhost\\SQLEXPRESS,1433;Database=mydb;Encrypt=0;TrustServerCertificate=1'],
            ];
            
            foreach ($drivers as $driver => $config) {
                $conn = new ConnectionModel();
                $conn->fill(array_merge(['driver' => $driver], $config));
                $dsn = $conn->buildDsn();
                $this->assertTrue($dsn === $config['expected'], "buildDsn() para $driver es correcto");
            }
        });
    }
}
