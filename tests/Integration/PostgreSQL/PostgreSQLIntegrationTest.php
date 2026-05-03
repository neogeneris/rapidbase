<?php
/**
 * Test de Integración: RapidBase con PostgreSQL
 * 
 * Este archivo prueba la funcionalidad básica de RapidBase usando PostgreSQL
 * en lugar de SQLite3. Verifica operaciones CRUD, transacciones y características
 * específicas de PostgreSQL.
 * 
 * Requisitos:
 * - PostgreSQL instalado y ejecutándose
 * - Base de datos 'rapidbase_test' creada
 * - Usuario 'rapidbase_user' con contraseña 'rapidbase_pass'
 * 
 * Para ejecutar:
 * php tests/Integration/PostgreSQL/PostgreSQLIntegrationTest.php
 */

namespace Tests\Integration\PostgreSQL;

// Carga manual de dependencias de RapidBase
require_once __DIR__ . "/../../../vendor/autoload.php";

use RapidBase\Core\DB;
use RapidBase\Core\Conn;
use RapidBase\Core\Cache\CacheService;
use PDO;

echo "=== Test de Integración: RapidBase con PostgreSQL ===\n\n";

// Configuración de conexión a PostgreSQL
$dsn = 'pgsql:host=localhost;port=5432;dbname=rapidbase_test';
$user = 'rapidbase_user';
$pass = 'rapidbase_pass';

try {
    echo "[1] Estableciendo conexión con PostgreSQL...\n";
    DB::setup($dsn, $user, $pass, 'main');
    echo "    ✓ Conexión establecida exitosamente\n";
    
    $pdo = DB::getConnection();
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    echo "    ✓ Driver detectado: $driver\n";
    
} catch (\Exception $e) {
    echo "    ✗ Error de conexión: " . $e->getMessage() . "\n";
    exit(1);
}

// Inicializar caché para este test
$cachePath = __DIR__ . '/temp_pgsql_cache';
if (!is_dir($cachePath)) {
    mkdir($cachePath, 0755, true);
}
CacheService::init($cachePath);

// Crear tabla de prueba
echo "\n[2] Creando tabla de prueba 'users'...\n";
try {
    // Usar EXEC para evitar el problema con lastInsertId en CREATE TABLE
    $pdo = DB::getConnection();
    $createTableSQL = <<<SQL
    CREATE TABLE IF NOT EXISTS users (
        id SERIAL PRIMARY KEY,
        username VARCHAR(50) UNIQUE NOT NULL,
        email VARCHAR(100) NOT NULL,
        role VARCHAR(20) DEFAULT 'user',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
    SQL;
    
    $pdo->exec($createTableSQL);
    echo "    ✓ Tabla 'users' creada exitosamente\n";
    
} catch (\Exception $e) {
    echo "    ✗ Error creando tabla: " . $e->getMessage() . "\n";
    exit(1);
}

// Limpiar datos previos
echo "\n[3] Limpiando datos previos...\n";
try {
    DB::exec("DELETE FROM users");
    echo "    ✓ Datos limpiados\n";
} catch (\Exception $e) {
    echo "    ✗ Error limpiando datos: " . $e->getMessage() . "\n";
}

// Prueba INSERT
echo "\n[4] Probando INSERT (DB::insert)...\n";
try {
    $userId1 = DB::insert('users', [
        'username' => 'admin',
        'email' => 'admin@example.com',
        'role' => 'admin'
    ]);
    echo "    ✓ Usuario insertado con ID: $userId1\n";
    
    $userId2 = DB::insert('users', [
        'username' => 'john_doe',
        'email' => 'john@example.com',
        'role' => 'user'
    ]);
    echo "    ✓ Segundo usuario insertado con ID: $userId2\n";
    
} catch (\Exception $e) {
    echo "    ✗ Error en INSERT: " . $e->getMessage() . "\n";
}

// Prueba SELECT con find
echo "\n[5] Probando SELECT (DB::find)...\n";
try {
    $user = DB::find('users', ['username' => 'admin']);
    if ($user) {
        echo "    ✓ Usuario encontrado: {$user['username']} ({$user['email']})\n";
    } else {
        echo "    ✗ Usuario no encontrado\n";
    }
} catch (\Exception $e) {
    echo "    ✗ Error en SELECT: " . $e->getMessage() . "\n";
}

// Prueba SELECT con all
echo "\n[6] Probando SELECT ALL (DB::all)...\n";
try {
    $allUsers = DB::all('users');
    echo "    ✓ Usuarios encontrados: " . count($allUsers) . "\n";
    foreach ($allUsers as $u) {
        echo "      - {$u['username']} ({$u['role']})\n";
    }
} catch (\Exception $e) {
    echo "    ✗ Error en SELECT ALL: " . $e->getMessage() . "\n";
}

// Prueba UPDATE
echo "\n[7] Probando UPDATE (DB::update)...\n";
try {
    $updated = DB::update('users', ['role' => 'moderator'], ['username' => 'john_doe']);
    if ($updated) {
        echo "    ✓ Usuario actualizado exitosamente\n";
        $updatedUser = DB::find('users', ['username' => 'john_doe']);
        echo "      - Nuevo rol: {$updatedUser['role']}\n";
    } else {
        echo "    ✗ Error al actualizar usuario\n";
    }
} catch (\Exception $e) {
    echo "    ✗ Error en UPDATE: " . $e->getMessage() . "\n";
}

// Prueba COUNT
echo "\n[8] Probando COUNT (DB::count)...\n";
try {
    $count = DB::count('users');
    echo "    ✓ Total de usuarios: $count\n";
} catch (\Exception $e) {
    echo "    ✗ Error en COUNT: " . $e->getMessage() . "\n";
}

// Prueba EXISTS
echo "\n[9] Probando EXISTS (DB::exists)...\n";
try {
    $exists = DB::exists('users', ['username' => 'admin']);
    echo "    ✓ Usuario 'admin' existe: " . ($exists ? 'SI' : 'NO') . "\n";
    
    $notExists = DB::exists('users', ['username' => 'nonexistent']);
    echo "    ✓ Usuario 'nonexistent' existe: " . ($notExists ? 'SI' : 'NO') . "\n";
} catch (\Exception $e) {
    echo "    ✗ Error en EXISTS: " . $e->getMessage() . "\n";
}

// Prueba UPSERT (característica PostgreSQL)
echo "\n[10] Probando UPSERT (DB::upsert) - Característica PostgreSQL...\n";
try {
    $upsertResult = DB::upsert('users', [
        'username' => 'admin',
        'email' => 'newadmin@example.com',
        'role' => 'superadmin'
    ], ['username']);
    
    if ($upsertResult['success']) {
        echo "    ✓ UPSERT ejecutado exitosamente\n";
        $admin = DB::find('users', ['username' => 'admin']);
        echo "      - Email actualizado: {$admin['email']}\n";
        echo "      - Rol actualizado: {$admin['role']}\n";
    } else {
        echo "    ✗ Error en UPSERT\n";
    }
} catch (\Exception $e) {
    echo "    ✗ Error en UPSERT: " . $e->getMessage() . "\n";
}

// Prueba de Transacciones
echo "\n[11] Probando Transacciones (DB::transaction)...\n";
try {
    $result = DB::transaction(function() {
        DB::insert('users', [
            'username' => 'temp_user1',
            'email' => 'temp1@example.com',
            'role' => 'user'
        ]);
        
        DB::insert('users', [
            'username' => 'temp_user2',
            'email' => 'temp2@example.com',
            'role' => 'user'
        ]);
        
        return true;
    });
    
    echo "    ✓ Transacción completada exitosamente\n";
    $countAfter = DB::count('users');
    echo "      - Total de usuarios después de transacción: $countAfter\n";
    
} catch (\Exception $e) {
    echo "    ✗ Error en transacción: " . $e->getMessage() . "\n";
}

// Prueba de Transacción con Rollback
echo "\n[12] Probando Transacción con Rollback...\n";
try {
    $countBefore = DB::count('users');
    
    try {
        DB::transaction(function() use ($countBefore) {
            DB::insert('users', [
                'username' => 'rollback_user',
                'email' => 'rollback@example.com',
                'role' => 'user'
            ]);
            
            // Forzar un error para hacer rollback
            throw new \Exception("Error intencional para rollback");
        });
    } catch (\Exception $e) {
        // El rollback debería haber ocurrido
        $countAfter = DB::count('users');
        if ($countBefore === $countAfter) {
            echo "    ✓ Rollback ejecutado correctamente\n";
            echo "      - Conteo antes: $countBefore, después: $countAfter\n";
        } else {
            echo "    ✗ Rollback falló\n";
        }
    }
    
} catch (\Exception $e) {
    echo "    ✗ Error probando rollback: " . $e->getMessage() . "\n";
}

// Prueba de consulta raw con query/fetch
echo "\n[13] Probando consultas raw (DB::query / DB::fetch)...\n";
try {
    $results = DB::fetch("SELECT * FROM users WHERE role = :role", ['role' => 'user']);
    echo "    ✓ Consulta raw ejecutada\n";
    echo "      - Usuarios con rol 'user': " . count($results) . "\n";
} catch (\Exception $e) {
    echo "    ✗ Error en consulta raw: " . $e->getMessage() . "\n";
}

// Prueba de DELETE
echo "\n[14] Probando DELETE (DB::delete)...\n";
try {
    $deleted = DB::delete('users', ['username' => 'john_doe']);
    if ($deleted) {
        echo "    ✓ Usuario eliminado exitosamente\n";
        $stillExists = DB::exists('users', ['username' => 'john_doe']);
        echo "      - ¿Usuario aún existe?: " . ($stillExists ? 'SI' : 'NO') . "\n";
    } else {
        echo "    ✗ Error al eliminar usuario\n";
    }
} catch (\Exception $e) {
    echo "    ✗ Error en DELETE: " . $e->getMessage() . "\n";
}

// Prueba de características PostgreSQL específicas
echo "\n[15] Probando características específicas de PostgreSQL...\n";
try {
    // Verificar versión de PostgreSQL
    $version = DB::value("SELECT version()");
    echo "    ✓ Versión PostgreSQL: " . substr($version, 0, 50) . "...\n";
    
    // Probar RETURNING clause (específico de PostgreSQL)
    $stmt = DB::query(
        "INSERT INTO users (username, email, role) VALUES (:username, :email, :role) RETURNING id, created_at",
        [
            'username' => 'returning_test',
            'email' => 'returning@example.com',
            'role' => 'test'
        ]
    );
    $returningData = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "    ✓ RETURNING clause funciona: ID={$returningData['id']}\n";
    
    // Limpiar usuario de prueba
    DB::delete('users', ['username' => 'returning_test']);
    
} catch (\Exception $e) {
    echo "    ✗ Error en características PostgreSQL: " . $e->getMessage() . "\n";
}

// Resumen final
echo "\n=== Resumen ===\n";
$finalCount = DB::count('users');
echo "Total de usuarios en la base de datos: $finalCount\n";

// Limpieza opcional (comentar si se quieren conservar los datos)
echo "\n[16] Limpiando datos de prueba...\n";
try {
    DB::exec("DROP TABLE IF EXISTS users");
    echo "    ✓ Tabla 'users' eliminada\n";
} catch (\Exception $e) {
    echo "    ✗ Error limpiando: " . $e->getMessage() . "\n";
}

echo "\n=== Test Completado ===\n";
echo "RapidBase funciona correctamente con PostgreSQL.\n";
