<?php

declare(strict_types=1);

namespace RapidBase\Models;

use RapidBase\Tdd\TestCase;
use RapidBase\Core\DB;
use RapidBase\Core\X;

// Cargar el modelo manualmente para tests
require_once __DIR__ . '/../../../examples/querybrowser/api/v1/Models/Connection.php';

/**
 * Test Suite for Connection Model
 * 
 * Valida las operaciones CRUD del modelo Connection:
 * - Creación de conexiones en SQLite y MySQL
 * - Lectura, actualización y eliminación
 * - Generación de DSN para diferentes drivers
 * - Métodos de utilidad (toSafeArray, fill, etc.)
 */
class ConnectionTest extends TestCase
{
    private string $testDbFile = '';
    private string $mysqlDbName = 'rapidbase_test';

    public function setUp(): void
    {
        // Configurar base de datos temporal para pruebas SQLite
        $this->testDbFile = sys_get_temp_dir() . '/rapidbase_test_connection_' . uniqid() . '.sqlite';
        
        // Crear tabla connections en SQLite
        if (!file_exists($this->testDbFile)) {
            $pdo = new \PDO("sqlite:{$this->testDbFile}");
            $pdo->exec("CREATE TABLE connections (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                driver TEXT NOT NULL,
                host TEXT,
                port INTEGER,
                database TEXT,
                username TEXT,
                password TEXT,
                description TEXT,
                environment TEXT DEFAULT 'development',
                status TEXT DEFAULT 'active',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
        }
        
        // Configurar conexión 'internal' para el modelo
        DB::setup("sqlite:{$this->testDbFile}", '', '', 'internal');
    }

    public function tearDown(): void
    {
        // Limpieza de archivos temporales
        if (file_exists($this->testDbFile)) {
            unlink($this->testDbFile);
        }
    }

    // ======================================================
    // TEST 1: Crear conexión SQLite
    // ======================================================
    public function testCreate(): void
    {
        $this->env('sqlite')->test('should create SQLite connection successfully', function($test) {
            $data = [
                'name' => 'TestSQLite',
                'driver' => 'sqlite',
                'database' => ':memory:',
                'description' => 'Prueba SQLite',
                'environment' => 'test',
                'status' => 'active'
            ];
            
            $result = Connection::create($data);
            
            $this->assertTrue($result > 0, 'create() retorna ID > 0');
            
            // Verificar que se guardó correctamente
            $conn = Connection::read($result);
            $this->assertTrue($conn !== null, 'Se puede leer la conexión creada');
            $this->assertTrue($conn->name === 'TestSQLite', 'El nombre se guardó correctamente');
            $this->assertTrue($conn->driver === 'sqlite', 'El driver se guardó correctamente');
        });
    }

    // ======================================================
    // TEST 2: Crear conexión MySQL (registro)
    // ======================================================
    public function testCreateMySQL(): void
    {
        $this->env('mysql')->test('should register MySQL connection data', function($test) {
            // Reconfigurar para MySQL
            DB::setup("mysql:host=localhost;dbname={$this->mysqlDbName}", '', '', 'internal');
            
            // Crear tabla si no existe
            try {
                X::con('internal')->raw("CREATE TABLE IF NOT EXISTS connections (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(255) NOT NULL,
                    driver VARCHAR(50) NOT NULL,
                    host VARCHAR(255),
                    port INT,
                    database VARCHAR(255),
                    username VARCHAR(255),
                    password VARCHAR(255),
                    description TEXT,
                    environment VARCHAR(50) DEFAULT 'development',
                    status VARCHAR(50) DEFAULT 'active',
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )")->execute();
            } catch (\Throwable $e) {
                // Ignorar si ya existe
            }
            
            $data = [
                'name' => 'TestMySQL',
                'driver' => 'mysql',
                'host' => 'localhost',
                'port' => 3306,
                'database' => 'mydb',
                'username' => 'root',
                'password' => 'secret',
                'description' => 'Prueba MySQL',
                'environment' => 'production',
                'status' => 'active'
            ];
            
            $result = Connection::create($data);
            
            $this->assertTrue($result > 0, 'create() MySQL retorna ID > 0');
            
            // Verificar que se guardó correctamente
            $conn = Connection::read($result);
            $this->assertTrue($conn !== null, 'Se puede leer la conexión MySQL creada');
            $this->assertTrue($conn->name === 'TestMySQL', 'El nombre se guardó correctamente');
            $this->assertTrue($conn->driver === 'mysql', 'El driver se guardó correctamente');
            $this->assertTrue($conn->host === 'localhost', 'El host se guardó correctamente');
            $this->assertTrue($conn->port === 3306, 'El puerto se guardó correctamente');
        });
    }

    // ======================================================
    // TEST 3: Leer conexión
    // ======================================================
    public function testRead(): void
    {
        $this->env('sqlite')->test('should read connection by ID', function($test) {
            // Crear una conexión primero
            $data = [
                'name' => 'ConnectionToRead',
                'driver' => 'sqlite',
                'database' => '/path/to/db.sqlite',
                'description' => 'Para probar lectura'
            ];
            $id = Connection::create($data);
            
            // Leer la conexión
            $conn = Connection::read($id);
            
            $this->assertTrue($conn !== null, 'read() retorna un objeto Connection');
            $this->assertTrue((int)$conn->id === (int)$id, 'El ID coincide');
            $this->assertTrue($conn->name === 'ConnectionToRead', 'El nombre coincide');
            $this->assertTrue($conn->driver === 'sqlite', 'El driver coincide');
        });
    }

    // ======================================================
    // TEST 4: Listar todas las conexiones
    // ======================================================
    public function testAll(): void
    {
        $this->env('sqlite')->test('should return all connections', function($test) {
            // Limpiar tabla primero
            try {
                X::con('internal')->from('connections')->delete();
            } catch (\Throwable $e) {
                // Ignorar
            }
            
            // Crear varias conexiones
            Connection::create(['name' => 'Conn1', 'driver' => 'sqlite', 'database' => ':memory:']);
            Connection::create(['name' => 'Conn2', 'driver' => 'mysql', 'host' => 'localhost']);
            Connection::create(['name' => 'Conn3', 'driver' => 'pgsql', 'host' => 'localhost']);
            
            $connections = Connection::all();
            
            $this->assertTrue(count($connections) >= 3, 'all() retorna al menos 3 conexiones');
            
            // Verificar que todos son objetos Connection
            foreach ($connections as $conn) {
                $this->assertTrue($conn instanceof Connection, 'Cada elemento es instancia de Connection');
            }
        });
    }

    // ======================================================
    // TEST 5: Eliminar conexión
    // ======================================================
    public function testDelete(): void
    {
        $this->env('sqlite')->test('should delete connection by ID', function($test) {
            // Crear una conexión para eliminar
            $data = ['name' => 'ToDelete', 'driver' => 'sqlite', 'database' => ':memory:'];
            $id = Connection::create($data);
            
            // Verificar que existe
            $conn = Connection::read($id);
            $this->assertTrue($conn !== null, 'La conexión existe antes de eliminar');
            
            // Eliminar
            $result = Connection::delete($id);
            $this->assertTrue($result === true, 'delete() retorna true');
            
            // Verificar que ya no existe
            $connAfter = Connection::read($id);
            $this->assertTrue($connAfter === null, 'La conexión fue eliminada');
        });
    }

    // ======================================================
    // TEST 6: Build DSN - SQLite
    // ======================================================
    public function testBuildDsnSqlite(): void
    {
        $this->env('sqlite')->test('should build correct SQLite DSN', function($test) {
            $conn = new Connection([
                'driver' => 'sqlite',
                'database' => '/path/to/database.sqlite'
            ]);
            
            $dsn = $conn->buildDsn();
            
            $this->assertTrue($dsn === 'sqlite:/path/to/database.sqlite', 'DSN SQLite es correcto');
        });
    }

    // ======================================================
    // TEST 7: Build DSN - MySQL
    // ======================================================
    public function testBuildDsnMySQL(): void
    {
        $this->env('sqlite')->test('should build correct MySQL DSN', function($test) {
            $conn = new Connection([
                'driver' => 'mysql',
                'host' => 'localhost',
                'port' => 3306,
                'database' => 'mydb'
            ]);
            
            $dsn = $conn->buildDsn();
            
            $this->assertTrue(strpos($dsn, 'mysql:host=localhost') === 0, 'DSN MySQL comienza correctamente');
            $this->assertTrue(strpos($dsn, ';port=3306') !== false, 'DSN MySQL incluye puerto');
            $this->assertTrue(strpos($dsn, ';dbname=mydb') !== false, 'DSN MySQL incluye base de datos');
            $this->assertTrue(strpos($dsn, 'charset=utf8mb4') !== false, 'DSN MySQL incluye charset');
        });
    }

    // ======================================================
    // TEST 8: Build DSN - PostgreSQL
    // ======================================================
    public function testBuildDsnPgSQL(): void
    {
        $this->env('sqlite')->test('should build correct PostgreSQL DSN', function($test) {
            $conn = new Connection([
                'driver' => 'pgsql',
                'host' => 'localhost',
                'port' => 5432,
                'database' => 'mydb'
            ]);
            
            $dsn = $conn->buildDsn();
            
            $this->assertTrue(strpos($dsn, 'pgsql:host=localhost') === 0, 'DSN PgSQL comienza correctamente');
            $this->assertTrue(strpos($dsn, ';port=5432') !== false, 'DSN PgSQL incluye puerto');
            $this->assertTrue(strpos($dsn, ';dbname=mydb') !== false, 'DSN PgSQL incluye base de datos');
        });
    }

    // ======================================================
    // TEST 9: Build DSN - SQLServer
    // ======================================================
    public function testBuildDsnSQLServer(): void
    {
        $this->env('sqlite')->test('should build correct SQLServer DSN', function($test) {
            $conn = new Connection([
                'driver' => 'sqlsrv',
                'host' => 'localhost\\SQLEXPRESS',
                'port' => 1433,
                'database' => 'master'
            ]);
            
            $dsn = $conn->buildDsn();
            
            $this->assertTrue(strpos($dsn, 'sqlsrv:Server=localhost\\SQLEXPRESS') === 0, 'DSN SQLServer comienza correctamente');
            $this->assertTrue(strpos($dsn, ',1433') !== false, 'DSN SQLServer incluye puerto');
            $this->assertTrue(strpos($dsn, ';Database=master') !== false, 'DSN SQLServer incluye base de datos');
            $this->assertTrue(strpos($dsn, 'Encrypt=0') !== false, 'DSN SQLServer incluye Encrypt=0');
        });
    }

    // ======================================================
    // TEST 10: toSafeArray (sin password)
    // ======================================================
    public function testToSafeArray(): void
    {
        $this->env('sqlite')->test('should exclude password from safe array', function($test) {
            $conn = new Connection([
                'name' => 'SecureConn',
                'driver' => 'mysql',
                'host' => 'localhost',
                'database' => 'mydb',
                'username' => 'admin',
                'password' => 'supersecret'
            ]);
            
            $safeArray = $conn->toSafeArray();
            
            $this->assertTrue(!isset($safeArray['password']), 'toSafeArray() excluye password');
            $this->assertTrue($safeArray['name'] === 'SecureConn', 'toSafeArray() incluye otros campos');
            $this->assertTrue($safeArray['username'] === 'admin', 'toSafeArray() incluye username');
        });
    }

    // ======================================================
    // TEST 11: fill() method
    // ======================================================
    public function testFill(): void
    {
        $this->env('sqlite')->test('should fill attributes correctly', function($test) {
            $conn = new Connection();
            $conn->fill([
                'name' => 'FilledConn',
                'driver' => 'sqlite',
                'database' => '/tmp/test.db',
                'non_existent_field' => 'ignored'
            ]);
            
            $this->assertTrue($conn->name === 'FilledConn', 'fill() establece name');
            $this->assertTrue($conn->driver === 'sqlite', 'fill() establece driver');
            $this->assertTrue($conn->database === '/tmp/test.db', 'fill() establece database');
        });
    }

    // ======================================================
    // TEST 12: toArray() method
    // ======================================================
    public function testToArray(): void
    {
        $this->env('sqlite')->test('should convert to array correctly', function($test) {
            $data = [
                'name' => 'ArrayConn',
                'driver' => 'mysql',
                'host' => 'localhost',
                'port' => 3306,
                'database' => 'testdb'
            ];
            $conn = new Connection($data);
            
            $array = $conn->toArray();
            
            $this->assertTrue(is_array($array), 'toArray() retorna un array');
            $this->assertTrue($array['name'] === 'ArrayConn', 'toArray() incluye name');
            $this->assertTrue($array['driver'] === 'mysql', 'toArray() incluye driver');
        });
    }

    // ======================================================
    // TEST 13: getTable() method
    // ======================================================
    public function testGetTable(): void
    {
        $this->env('sqlite')->test('should return correct table name', function($test) {
            $table = Connection::getTable();
            $this->assertTrue($table === 'connections', 'getTable() retorna "connections"');
        });
    }

    // ======================================================
    // TEST 14: isDirty() method
    // ======================================================
    public function testIsDirty(): void
    {
        $this->env('sqlite')->test('should track dirty state', function($test) {
            $conn = new Connection(['name' => 'Initial', 'driver' => 'sqlite']);
            $conn->syncOriginal();
            
            $this->assertTrue($conn->isDirty() === false, 'No está dirty después de syncOriginal');
            
            $conn->name = 'Modified';
            $this->assertTrue($conn->isDirty() === true, 'Está dirty después de modificar');
        });
    }

    // ======================================================
    // TEST 15: save() method
    // ======================================================
    public function testSave(): void
    {
        $this->env('sqlite')->test('should save new and existing records', function($test) {
            // Guardar nuevo registro
            $conn = new Connection([
                'name' => 'SavedConn',
                'driver' => 'sqlite',
                'database' => ':memory:'
            ]);
            $result = $conn->save();
            $this->assertTrue($result > 0, 'save() crea nuevo registro y retorna ID');
            
            // Verificar que se guardó correctamente
            $loaded = Connection::read($conn->id);
            $this->assertTrue($loaded !== null, 'Se puede cargar el registro guardado');
            $this->assertTrue($loaded->name === 'SavedConn', 'El nombre se guardó correctamente');
        });
    }
}
