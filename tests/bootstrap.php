<?php
/**
 * Bootstrap para pruebas unitarias de RapidBase
 * 
 * Este archivo proporciona un punto de carga flexible para las pruebas:
 * - Opción 1: Usar el bundle RapidBase.php desde tests/lib/
 * - Opción 2: Usar el autoloader inteligente del proyecto (src/)
 * 
 * Uso en cada subcarpeta de tests/Unit:
 *   require_once __DIR__ . '/../../bootstrap.php';
 */

// Determinar modo de carga
$useBundle = false;
if (defined('TEST_USE_BUNDLE')) {
    $useBundle = TEST_USE_BUNDLE;
} elseif (getenv('RAPIDBASE_TEST_MODE') === 'bundle') {
    $useBundle = true;
}

$testsRoot = dirname(__DIR__);
$projectRoot = dirname($testsRoot);

if ($useBundle) {
    // Modo Bundle: usar RapidBase.php consolidado
    $bundlePath = $testsRoot . '/lib/RapidBase.php';
    if (file_exists($bundlePath)) {
        require_once $bundlePath;
        echo "[Bootstrap] Using RapidBase Bundle\n";
    } else {
        throw new RuntimeException("RapidBase Bundle not found at: $bundlePath");
    }
} else {
    // Modo Source: usar autoloader inteligente desde src/
    $autoloadPath = $projectRoot . '/vendor/autoload.php';
    
    if (file_exists($autoloadPath)) {
        // Composer autoloader
        require_once $autoloadPath;
        echo "[Bootstrap] Using Composer Autoloader\n";
    } else {
        // Smart Autoloader from src
        $autoloaderFile = $projectRoot . '/src/RapidBase/Autoloader/Autoloader.php';
        if (file_exists($autoloaderFile)) {
            require_once $autoloaderFile;
            \RapidBase\Autoloader\Autoloader::getInstance($projectRoot . '/src')
                ->enableDebug(false)
                ->enableCache(true)
                ->register();
            echo "[Bootstrap] Using Smart Autoloader from src/\n";
        } else {
            // Fallback: autoloader manual mínimo
            spl_autoload_register(function ($class) use ($projectRoot) {
                if (strpos($class, 'RapidBase\\') === 0) {
                    $file = $projectRoot . '/src/' . str_replace('\\', '/', $class) . '.php';
                    if (file_exists($file)) {
                        require_once $file;
                    }
                }
            });
            echo "[Bootstrap] Using Fallback Manual Autoloader\n";
        }
    }
}

// Cargar configuración centralizada
$configFile = $testsRoot . '/config/test-config.php';
if (file_exists($configFile)) {
    require_once $configFile;
}

// Función helper para obtener paths de bases de datos de prueba
function getTestDbPath(string $name = 'test'): string {
    return TESTS_TMP . "/{$name}.sqlite";
}

// Función helper para crear base de datos temporal
function createTempTestDb(string $name = 'test'): string {
    $dbPath = getTestDbPath($name);
    if (file_exists($dbPath)) {
        unlink($dbPath);
    }
    
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    return $dbPath;
}

// Función helper para limpiar después de las pruebas
function cleanupTestDb(string $dbPath): void {
    if (file_exists($dbPath)) {
        @unlink($dbPath);
    }
}

echo "[Bootstrap] Test environment ready\n";
