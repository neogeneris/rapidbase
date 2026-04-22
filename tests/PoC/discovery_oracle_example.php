<?php
/**
 * Ejemplo de Discovery para Oracle Database (sin necesidad de instalar)
 * 
 * Este script muestra cómo configurar RapidBase para trabajar con Oracle.
 * Requiere: 
 * - PHP con extensión OCI8 o PDO_OCI instalada
 * - Oracle Instant Client configurado
 * - Conexión a una instancia de Oracle (XE, Express, Standard o Enterprise)
 * 
 * Uso típico en entornos empresariales con Oracle DB
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use RapidBase\Core\DB;
use RapidBase\Meta\SchemaMapper;

// Configuración de conexión a Oracle
// Formato 1: Easy Connect (recomendado para Oracle 11g+)
$dsn = 'oci:dbname=localhost:1521/XEPDB1;charset=UTF8';

// Formato 2: TNS Name (si tienes tnsnames.ora configurado)
// $dsn = 'oci:tns=MYORACLEDB;charset=UTF8';

$username = 'SYSTEM'; // O tu usuario de aplicación
$password = 'Oracle18'; // Cambiar por tu password

try {
    // Crear conexión PDO
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_ORA_NULLS => true, // Manejar NULLs de Oracle correctamente
    ]);

    echo "✅ Conectado a Oracle Database\n";
    
    // Ejecutar discovery
    // En Oracle el schema es obligatorio y equivale al usuario/owner
    $schema = 'RAPIDBASE'; // O el nombre de tu schema (en mayúsculas)
    $success = SchemaMapper::generate($pdo, null, $schema);
    
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
    echo "\n💡 Tips para Oracle:\n";
    echo "   1. Instala Oracle Instant Client: https://www.oracle.com/database/technologies/instant-client.html\n";
    echo "   2. Instala extensiones PHP: pecl install oci8 pdo_oci\n";
    echo "   3. Configura variables de entorno (LD_LIBRARY_PATH, ORACLE_HOME)\n";
    echo "   4. Para Docker: usa imágenes oficiales oracle/database:18.4.0-xe\n";
    echo "   5. El schema en Oracle equivale al usuario de la base de datos\n";
    echo "   6. Los identificadores se guardan en MAYÚSCULAS por defecto\n";
}
