<?php
/**
 * Pruebas unitarias para SQLiteDiscovery usando el framework TDD de RapidBase.
 * 
 * Estas pruebas validan la funcionalidad de descubrimiento de metadatos
 * para bases de datos SQLite, incluyendo tablas, columnas, claves primarias
 * y relaciones (foreign keys).
 */

namespace Tests\Unit\Meta\Discovery;

use RapidBase\Tdd\TestCase;
use RapidBase\Meta\Discovery\SQLiteDiscovery;
use PDO;
use Exception;

class SQLiteDiscoveryTest extends TestCase
{
    private ?PDO $pdo = null;
    private ?SQLiteDiscovery $discovery = null;
    private string $testDbPath = '';

    public function setUp(): void
    {
        // Crear base de datos SQLite temporal en memoria para aislamiento total
        $this->testDbPath = ':memory:';
        $this->pdo = new PDO('sqlite:' . $this->testDbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Crear tablas de prueba con relaciones
        $this->createTestTables();
        
        // Instanciar el discovery
        $this->discovery = new SQLiteDiscovery($this->pdo);
    }

    public function tearDown(): void
    {
        $this->discovery = null;
        $this->pdo = null;
    }

    private function createTestTables(): void
    {
        // Tabla users
        $this->pdo->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(100) NOT NULL,
                email VARCHAR(100) UNIQUE,
                role_id INTEGER,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (role_id) REFERENCES roles(id)
            )
        ");

        // Tabla roles
        $this->pdo->exec("
            CREATE TABLE roles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(50) NOT NULL,
                description TEXT
            )
        ");

        // Tabla posts
        $this->pdo->exec("
            CREATE TABLE posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                title VARCHAR(200) NOT NULL,
                content TEXT,
                published BOOLEAN DEFAULT 0,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ");

        // Insertar datos de prueba
        $this->pdo->exec("INSERT INTO roles (name, description) VALUES ('admin', 'Administrador'), ('user', 'Usuario normal')");
        $this->pdo->exec("INSERT INTO users (name, email, role_id) VALUES ('John', 'john@example.com', 1), ('Jane', 'jane@example.com', 2)");
        $this->pdo->exec("INSERT INTO posts (user_id, title, content) VALUES (1, 'First Post', 'Content 1'), (2, 'Second Post', 'Content 2')");
    }

    /**
     * Test: getTables debe retornar todas las tablas creadas
     */
    public function testGetTables(): void
    {
        $tables = $this->discovery->getTables('main');
        
        $this->assertIsArray($tables, 'getTables debe retornar un array');
        $this->assertCount(3, $tables, 'Debe haber 3 tablas de prueba');
        $this->assertContains('users', $tables, 'Debe existir la tabla users');
        $this->assertContains('roles', $tables, 'Debe existir la tabla roles');
        $this->assertContains('posts', $tables, 'Debe existir la tabla posts');
    }

    /**
     * Test: discoverColumns debe retornar información correcta de columnas
     */
    public function testDiscoverColumns(): void
    {
        $columns = $this->discovery->discoverColumns('users', 'main');
        
        $this->assertIsArray($columns, 'discoverColumns debe retornar un array');
        $this->assertArrayHasKey('id', $columns, 'Debe existir columna id');
        $this->assertArrayHasKey('name', $columns, 'Debe existir columna name');
        $this->assertArrayHasKey('email', $columns, 'Debe existir columna email');
        $this->assertArrayHasKey('role_id', $columns, 'Debe existir columna role_id');
        
        // Verificar propiedades de la columna id
        $this->assertTrue($columns['id']['primary'], 'id debe ser primary key');
        // Nota: En SQLite, INTEGER PRIMARY KEY AUTOINCREMENT tiene notnull=0 en PRAGMA table_info
        // Esto es comportamiento normal de SQLite, permite NULL en casos especiales
        // Solo verificamos que sea primary key, no su nullability
        
        // Verificar propiedades de la columna name
        $this->assertEquals('VARCHAR(100)', $columns['name']['type'], 'name debe ser VARCHAR(100)');
        $this->assertFalse($columns['name']['primary'], 'name no debe ser primary key');
        $this->assertFalse($columns['name']['nullable'], 'name no debe ser nullable (tiene NOT NULL)');
        
        // Verificar que role_id es foreign key
        $this->assertTrue($columns['role_id']['foreign'], 'role_id debe ser foreign key');
        $this->assertEquals('roles.id', $columns['role_id']['references'], 'role_id debe referenciar a roles.id');
    }

    /**
     * Test: discoverPrimaryKey debe retornar la clave primaria correcta
     */
    public function testDiscoverPrimaryKey(): void
    {
        $pk = $this->discovery->discoverPrimaryKey('users', 'main');
        $this->assertEquals('id', $pk, 'La primary key de users debe ser id');
        
        $pk = $this->discovery->discoverPrimaryKey('roles', 'main');
        $this->assertEquals('id', $pk, 'La primary key de roles debe ser id');
        
        $pk = $this->discovery->discoverPrimaryKey('posts', 'main');
        $this->assertEquals('id', $pk, 'La primary key de posts debe ser id');
    }

    /**
     * Test: discoverRelationships debe detectar foreign keys correctamente
     */
    public function testDiscoverRelationships(): void
    {
        $relationships = $this->discovery->discoverRelationships('main');
        
        $this->assertIsArray($relationships, 'discoverRelationships debe retornar un array');
        $this->assertArrayHasKey('from', $relationships, 'Debe existir clave from');
        $this->assertArrayHasKey('to', $relationships, 'Debe existir clave to');
        
        // Verificar relación users -> roles (users.role_id -> roles.id)
        $this->assertArrayHasKey('users', $relationships['from'], 'users debe estar en from');
        $this->assertArrayHasKey('roles', $relationships['from']['users'], 'users debe tener relación con roles');
        
        // Verificar relación inversa roles <- users
        $this->assertArrayHasKey('roles', $relationships['to'], 'roles debe estar en to');
        $this->assertArrayHasKey('users', $relationships['to']['roles'], 'roles debe tener relación desde users');
        
        // Verificar relación posts -> users
        $this->assertArrayHasKey('posts', $relationships['from'], 'posts debe estar en from');
        $this->assertArrayHasKey('users', $relationships['from']['posts'], 'posts debe tener relación con users');
    }

    /**
     * Test: getForeignKeys debe retornar las foreign keys de una tabla
     */
    public function testGetForeignKeys(): void
    {
        $fks = $this->discovery->getForeignKeys('users');
        
        $this->assertIsArray($fks, 'getForeignKeys debe retornar un array');
        $this->assertCount(1, $fks, 'users debe tener 1 foreign key');
        $this->assertEquals('roles', $fks[0]['table'], 'La FK debe apuntar a roles');
        $this->assertEquals('role_id', $fks[0]['from'], 'La columna origen debe ser role_id');
        $this->assertEquals('id', $fks[0]['to'], 'La columna destino debe ser id');
        
        $fks = $this->discovery->getForeignKeys('posts');
        $this->assertCount(1, $fks, 'posts debe tener 1 foreign key');
        $this->assertEquals('users', $fks[0]['table'], 'La FK debe apuntar a users');
        $this->assertEquals('user_id', $fks[0]['from'], 'La columna origen debe ser user_id');
    }

    /**
     * Test: getPrimaryKeys debe retornar array con las primary keys
     */
    public function testGetPrimaryKeys(): void
    {
        $pks = $this->discovery->getPrimaryKeys('users');
        $this->assertIsArray($pks, 'getPrimaryKeys debe retornar un array');
        $this->assertCount(1, $pks, 'users debe tener 1 primary key');
        $this->assertEquals('id', $pks[0], 'La primary key debe ser id');
        
        // Tabla sin primary key explícita (si existiera) retornaría array vacío
    }

    /**
     * Test: discoverColumns para tabla posts con ON DELETE CASCADE
     */
    public function testDiscoverColumnsWithCascade(): void
    {
        $columns = $this->discovery->discoverColumns('posts', 'main');
        
        $this->assertArrayHasKey('user_id', $columns, 'Debe existir columna user_id');
        $this->assertTrue($columns['user_id']['foreign'], 'user_id debe ser foreign key');
        $this->assertEquals('users.id', $columns['user_id']['references'], 'user_id debe referenciar a users.id');
        $this->assertFalse($columns['user_id']['nullable'], 'user_id no debe ser nullable');
    }

    /**
     * Test: getTables no debe incluir tablas del sistema sqlite_
     */
    public function testGetTablesExcludesSystemTables(): void
    {
        $tables = $this->discovery->getTables('main');
        
        foreach ($tables as $table) {
            $this->assertStringNotStartsWith('sqlite_', $table, 
                'No debe incluir tablas del sistema sqlite_');
        }
    }

    /**
     * Test: discoverColumns para tabla sin foreign keys
     */
    public function testDiscoverColumnsWithoutForeignKeys(): void
    {
        $columns = $this->discovery->discoverColumns('roles', 'main');
        
        $this->assertArrayHasKey('id', $columns);
        $this->assertArrayHasKey('name', $columns);
        $this->assertArrayHasKey('description', $columns);
        
        // Ninguna columna debe ser foreign key
        foreach ($columns as $colName => $colInfo) {
            $this->assertFalse($colInfo['foreign'], 
                "La columna {$colName} no debe ser foreign key");
            $this->assertNull($colInfo['references'], 
                "La columna {$colName} no debe tener referencias");
        }
    }

    /**
     * Test: discoverPrimaryKey para tabla inexistente debe retornar null
     */
    public function testDiscoverPrimaryKeyForNonExistentTable(): void
    {
        $pk = $this->discovery->discoverPrimaryKey('non_existent_table', 'main');
        $this->assertNull($pk, 'Debe retornar null para tabla inexistente');
    }

    /**
     * Test: discoverColumns para tabla inexistente debe comportarse adecuadamente
     */
    public function testDiscoverColumnsForNonExistentTable(): void
    {
        // SQLite retorna array vacío para PRAGMA en tabla inexistente
        $columns = $this->discovery->discoverColumns('non_existent_table', 'main');
        $this->assertIsArray($columns, 'Debe retornar un array');
        $this->assertEmpty($columns, 'Debe ser array vacío para tabla inexistente');
    }

    /**
     * Test: getTableComment debe retornar null (SQLite no soporta comentarios nativos)
     */
    public function testGetTableComment(): void
    {
        $comment = $this->discovery->getTableComment('users');
        $this->assertNull($comment, 'SQLite no soporta comentarios de tabla nativamente');
    }

    /**
     * Test: getColumnComment debe retornar null (SQLite no soporta comentarios nativos)
     */
    public function testGetColumnComment(): void
    {
        $comment = $this->discovery->getColumnComment('users', 'name');
        $this->assertNull($comment, 'SQLite no soporta comentarios de columna nativamente');
    }

    /**
     * Test: verify relationship structure format matches expected schema
     */
    public function testRelationshipStructureFormat(): void
    {
        $relationships = $this->discovery->discoverRelationships('main');
        
        // Verificar estructura completa
        $this->assertIsArray($relationships);
        $this->assertArrayHasKey('from', $relationships);
        $this->assertArrayHasKey('to', $relationships);
        $this->assertIsArray($relationships['from']);
        $this->assertIsArray($relationships['to']);
        
        // Verificar que las relaciones internas tienen la estructura correcta
        if (!empty($relationships['from']['users'])) {
            $userRels = $relationships['from']['users'];
            $this->assertIsArray($userRels);
            
            foreach ($userRels as $targetTable => $mapping) {
                $this->assertIsArray($mapping, 
                    "El mapeo para {$targetTable} debe ser un array");
            }
        }
    }

    /**
     * Test: Integration - verify all discovery methods work together
     */
    public function testIntegrationAllMethods(): void
    {
        // Flujo completo de descubrimiento
        $tables = $this->discovery->getTables('main');
        $this->assertNotEmpty($tables, 'Debe haber tablas');
        
        foreach ($tables as $table) {
            $columns = $this->discovery->discoverColumns($table, 'main');
            $this->assertNotEmpty($columns, "La tabla {$table} debe tener columnas");
            
            $pk = $this->discovery->discoverPrimaryKey($table, 'main');
            // Todas nuestras tablas de prueba tienen PK
            if (in_array($table, ['users', 'roles', 'posts'])) {
                $this->assertNotNull($pk, "La tabla {$table} debe tener primary key");
            }
        }
        
        $relationships = $this->discovery->discoverRelationships('main');
        $this->assertNotEmpty($relationships['from'], 'Debe haber relaciones detectadas');
    }
}
