<?php
/**
 * RapidBase Test Configuration Central
 * 
 * Este archivo centraliza las configuraciones para todas las pruebas del proyecto.
 * Incluye rutas, configuraciones de bases de datos, y utilidades comunes.
 */

// Definir rutas base
define('TESTS_ROOT', dirname(__DIR__));
define('TESTS_LIB', TESTS_ROOT . '/lib');
define('TESTS_DATA', TESTS_ROOT . '/data');
define('TESTS_TMP', TESTS_ROOT . '/tmp');
define('TESTS_CONFIG', TESTS_ROOT . '/config');
define('PROJECT_ROOT', dirname(TESTS_ROOT));
define('SRC_DIR', PROJECT_ROOT . '/src');

// Configuración de bases de datos de prueba
define('TDD_SQLITE_DB', TESTS_TMP . '/rapidbase_tdd_test.sqlite');
define('AUTLOADER_TEST_DB', TESTS_DATA . '/autoloader_test.sqlite');

// Configuración del autoloader
$autoloadPath = PROJECT_ROOT . '/vendor/autoload.php';
if (file_exists($autoloadPath)) {
    define('AUTOLOADER_TYPE', 'composer');
} else {
    define('AUTOLOADER_TYPE', 'smart');
}

// Función para obtener configuración de base de datos MySQL (si está disponible)
function getTestMysqlConfig(): array {
    $configFile = TESTS_CONFIG . '/mysql-test-config.local.php';
    if (file_exists($configFile)) {
        return require $configFile;
    }
    
    // Configuración por defecto (puede ser sobrescrita)
    return [
        'host' => 'localhost',
        'port' => '3306',
        'database' => 'rapidbase_test',
        'username' => 'root',
        'password' => '',
    ];
}

// Función para limpiar archivos temporales
function cleanupTestFiles(): void {
    $tmpFiles = glob(TESTS_TMP . '/*.sqlite');
    foreach ($tmpFiles as $file) {
        if (strpos($file, 'rapidbase_tdd_test.sqlite') === false) {
            @unlink($file);
        }
    }
}

// Registrar shutdown handler para limpieza automática
register_shutdown_function(function() {
    // Opcional: limpiar archivos temporales al finalizar
    // cleanupTestFiles();
});

echo "RapidBase Test Configuration loaded\n";
echo "  Tests Root: " . TESTS_ROOT . "\n";
echo "  Project Root: " . PROJECT_ROOT . "\n";
echo "  Autoloader Type: " . AUTOLOADER_TYPE . "\n";
