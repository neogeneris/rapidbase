<?php
/**
 * Prueba unitaria para el endpoint table_description del API
 * Verifica que X::description() funcione correctamente
 */

require_once __DIR__ . '/RapidBase.php';
require_once __DIR__ . '/config.php';

use RapidBase\X;

echo "=== Prueba de X::description() ===\n\n";

// 1. Probar con la base de datos demo directamente
echo "1. Probando conexión directa a demo.sqlite:\n";
try {
    $demoPath = __DIR__ . '/data/demo.sqlite';
    if (!file_exists($demoPath)) {
        echo "   ERROR: No existe $demoPath\n";
        echo "   Ejecuta primero: php db-init.php\n";
        exit(1);
    }
    
    echo "   Base de datos encontrada: $demoPath\n";
    
    // Conexión directa
    $db = new SQLite3($demoPath);
    $result = $db->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
    echo "   Tablas disponibles en demo.sqlite:\n";
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        echo "      - {$row['name']}\n";
    }
    $db->close();
    
} catch (Exception $e) {
    echo "   ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n2. Probando X::con()->from([...])->description():\n";
try {
    // Usar la configuración de RapidBase
    $connId = 'demo_direct';
    
    // Crear conexión temporal para prueba
    $demoPath = __DIR__ . '/data/demo.sqlite';
    
    // Registrar conexión si no existe
    $connectionsDb = new SQLite3(__DIR__ . '/data/connections.sqlite');
    $stmt = $connectionsDb->prepare("SELECT id FROM connections WHERE name = :name");
    $stmt->bindValue(':name', 'Demo Local', SQLITE3_TEXT);
    $result = $stmt->execute();
    $existing = $result->fetchArray(SQLITE3_ASSOC);
    
    if (!$existing) {
        echo "   Registrando conexión Demo Local...\n";
        $insert = $connectionsDb->prepare("INSERT INTO connections (name, driver, dsn, username, description, environment, created_at) VALUES (:name, :driver, :dsn, :user, :desc, :env, :now)");
        $insert->bindValue(':name', 'Demo Local', SQLITE3_TEXT);
        $insert->bindValue(':driver', 'sqlite', SQLITE3_TEXT);
        $insert->bindValue(':dsn', $demoPath, SQLITE3_TEXT);
        $insert->bindValue(':user', '', SQLITE3_TEXT);
        $insert->bindValue(':desc', 'Base de datos demo local', SQLITE3_TEXT);
        $insert->bindValue(':env', 'dev', SQLITE3_TEXT);
        $insert->bindValue(':now', date('Y-m-d H:i:s'), SQLITE3_TEXT);
        $insert->execute();
        $connId = $connectionsDb->lastInsertRowID();
        echo "   Conexión creada con ID: $connId\n";
    } else {
        $connId = $existing['id'];
        echo "   Conexión existente encontrada con ID: $connId\n";
    }
    $connectionsDb->close();
    
    // Probar description con una tabla específica
    echo "\n   Probando description para 'rb_test_products':\n";
    $desc = X::con($connId)->from(['rb_test_products'])->description();
    echo "   Resultado:\n";
    print_r($desc);
    
    // Verificar estructura
    if (isset($desc['rb_test_products'])) {
        $tableInfo = $desc['rb_test_products'];
        echo "\n   Estructura de rb_test_products:\n";
        echo "      Columnas: " . count($tableInfo['columns']) . "\n";
        foreach ($tableInfo['columns'] as $col => $type) {
            echo "         - $col: $type\n";
        }
        echo "      PKs: " . implode(', ', $tableInfo['pks']) . "\n";
        echo "      Relaciones: " . count($tableInfo['relations']) . "\n";
    } else {
        echo "   ERROR: No se encontró información para rb_test_products\n";
    }
    
    // Probar con múltiples tablas
    echo "\n   Probando description para múltiples tablas:\n";
    $desc2 = X::con($connId)->from(['rb_test_products', 'rb_test_categories'])->description();
    echo "   Tablas obtenidas: " . implode(', ', array_keys($desc2)) . "\n";
    
} catch (Exception $e) {
    echo "   ERROR: " . $e->getMessage() . "\n";
    echo "   Trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}

echo "\n=== Prueba completada exitosamente ===\n";
