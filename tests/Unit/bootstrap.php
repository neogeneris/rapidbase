<?php
/**
 * Bootstrap centralizado para pruebas unitarias de RapidBase
 * 
 * Este archivo carga el autoloader del framework y las dependencias necesarias
 * para ejecutar los tests unitarios sin necesidad de repetir el código en cada test.
 * 
 * Uso:
 *   require_once __DIR__ . '/bootstrap.php';
 * 
 * @package RapidBase\Tests
 */

// Determinar la ruta base del proyecto (desde tests/Unit hacia la raíz)
$baseDir = dirname(__DIR__, 2); // tests/Unit -> tests -> workspace root

// Función para cargar el autoloader
$loadAutoloader = function() use ($baseDir) {
    $srcAutoloader = $baseDir . '/src/RapidBase/Autoloader/Autoloader.php';
    $vendorAutoloader = $baseDir . '/vendor/autoload.php';
    
    if (file_exists($srcAutoloader)) {
        // Modo desarrollo: usar autoloader desde código fuente
        require_once $srcAutoloader;
        return \RapidBase\Autoloader\Autoloader::getInstance($baseDir . '/src')
            ->enableDebug(false)
            ->enableCache(true)
            ->register();
    } elseif (file_exists($vendorAutoloader)) {
        // Modo producción: usar autoloader de Composer
        require_once $vendorAutoloader;
        return true;
    } else {
        throw new \RuntimeException(
            'No se pudo cargar RapidBase. Verifique que exista ' .
            'src/RapidBase/Autoloader/Autoloader.php o vendor/autoload.php'
        );
    }
};

// Cargar autoloader
$loadAutoloader();

// Cargar TestCase del framework TDD si existe
$tddTestCase = $baseDir . '/src/RapidBase/Tdd/TestCase.php';
if (file_exists($tddTestCase)) {
    require_once $tddTestCase;
}

// Cargar Runner del framework TDD si existe
$tddRunner = $baseDir . '/src/RapidBase/Tdd/Runner.php';
if (file_exists($tddRunner)) {
    require_once $tddRunner;
}

// Inicializar conexión por defecto para tests que requieren DB
try {
    if (class_exists('\RapidBase\Core\Conn')) {
        $testDbPath = $baseDir . '/rapidbase_test.sqlite';
        \RapidBase\Core\Conn::setup('sqlite:' . $testDbPath, '', '', 'default');
    }
} catch (\Throwable $e) {
    // Si ya existe la conexión o falla, continuar silenciosamente
}

// Funciones helper globales para aserciones simples (compatibilidad con tests legacy)
if (!function_exists('assert_core')) {
    function assert_core(string $name, bool $condition, string $details = ""): void {
        if ($condition) {
            echo "\033[32m[OK]\033[0m $name\n";
        } else {
            echo "\033[31m[FAIL]\033[0m $name\n";
            if ($details) echo "  Detalles: $details\n";
            exit(1);
        }
    }
}

// Registrar shutdown handler para capturar errores fatales
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR])) {
        echo "\n\033[31m[FATAL ERROR]\033[0m: " . $error['message'] . "\n";
        echo "En archivo: " . $error['file'] . " línea " . $error['line'] . "\n";
        exit(1);
    }
});
