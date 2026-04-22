<?php
/**
 * Ejemplo de Discovery para SQL Server (sin necesidad de instalar)
 * 
 * Este script muestra cómo configurar RapidBase para trabajar con SQL Server.
 * Requiere: 
 * - PHP con driver sqlsrv o pdo_sqlsrv instalado
 * - Conexión a una instancia de SQL Server
 * 
 * Uso típico en Windows con IIS/Azure, o Linux con MS ODBC Driver
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use RapidBase\Core\DB;
use RapidBase\Meta\SchemaMapper;

// Configuración de conexión a SQL Server
// Opción 1: Driver nativo Microsoft (recomendado)
$dsn = 'sqlsrv:Server=localhost;Database=rapidbase_test';
$username = 'sa';
$password = 'YourStrong@Passw0rd';

// Opción 2: DSN configurado en odbc.ini
// $dsn = 'odbc:DSN=MySqlServer';

try {
    // Crear conexión PDO
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    echo "✅ Conectado a SQL Server\n";
    
    // Ejecutar discovery
    // El schema por defecto en SQL Server es 'dbo'
    $success = SchemaMapper::generate($pdo, null, 'dbo');
    
    if ($success) {
        echo "✅ Schema map generado exitosamente\n";
        echo "📁 Archivo: src/RapidBase/Meta/schema_map.php\n";
        
        // Cargar y mostrar resumen
        $map = SchemaMapper::loadMap();
        if ($map) {
            echo "\n📊 Resumen del Schema:\n";
            echo "   Checksum: {$map['checksum']}\n";
            echo "   Generado: {$map['generated_at']}\n";
            echo "   Tablas: " . count($map['tables']) . "\n";
            echo "   Relaciones: " . count($map['relationships']) . "\n";
            
            foreach ($map['tables'] as $table => $columns) {
                echo "\n   📋 Tabla: $table\n";
                echo "      Columnas: " . count($columns) . "\n";
                foreach ($columns as $col) {
                    $pk = $col['isPrimaryKey'] ? ' [PK]' : '';
                    $ai = $col['autoIncrement'] ? ' [AI]' : '';
                    $nullable = $col['nullable'] ? ' NULL' : ' NOT NULL';
                    echo "      - {$col['name']}: {$col['type']}{$pk}{$ai}{$nullable}\n";
                }
            }
        }
    } else {
        echo "❌ Error al generar schema map\n";
    }

} catch (PDOException $e) {
    echo "❌ Error de conexión: " . $e->getMessage() . "\n";
    echo "\n💡 Tips para SQL Server:\n";
    echo "   1. Instala el driver: pecl install sqlsrv pdo_sqlsrv\n";
    echo "   2. En Ubuntu/Debian: sigue las instrucciones de Microsoft para MS ODBC Driver\n";
    echo "   3. Verifica que el servicio SQL Server esté corriendo\n";
    echo "   4. Habilita autenticación mixta (SQL + Windows) si usas usuario/password\n";
}
