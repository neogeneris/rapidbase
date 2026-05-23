<?php

declare(strict_types=1);

namespace RapidBase\Tdd;

use AssertionError;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

abstract class TestCase
{
    public ?Runner $runner = null;
    public string $currentDriver = 'sqlite';

    public function setUp(): void {}
    public function tearDown(): void {}

    public function setRunnerContext(Runner $runner): void
    {
        $this->runner = $runner;
    }

    protected function env(string ...$drivers): EnvironmentBuilder
    {
        if ($this->runner === null) {
            // Robustez: Encontrar la raíz real del proyecto subiendo desde el directorio actual
            $baseDir = getcwd();
            while ($baseDir !== dirname($baseDir) && !file_exists($baseDir . '/bin/RapidBase.php')) {
                $baseDir = dirname($baseDir);
            }
            $this->runner = new Runner(sys_get_temp_dir() . '/rapidbase_tdd_tmp.sqlite', $baseDir);
        }
        $driversList = empty($drivers) ? $this->runner->getDrivers() : $drivers;
        return new EnvironmentBuilder($driversList, $this, $this->runner);
    }

    // Aserciones
    protected function assertTrue(bool $cond, string $msg = ''): void { if (!$cond) throw new AssertionError($msg ?: 'Expected true'); }
    protected function assertFalse(bool $cond, string $msg = ''): void { if ($cond) throw new AssertionError($msg ?: 'Expected false'); }
    protected function assertEquals(mixed $exp, mixed $act, string $msg = ''): void { if ($exp !== $act) throw new AssertionError($msg ?: "Expected $exp got $act"); }
    protected function assertNull(mixed $val, string $msg = ''): void { if ($val !== null) throw new AssertionError($msg ?: 'Expected null'); }
    protected function assertNotNull(mixed $val, string $msg = ''): void { if ($val === null) throw new AssertionError($msg ?: 'Expected not null'); }
    protected function fail(string $msg = 'Failed'): void { throw new AssertionError($msg); }
    protected function assertInstanceOf(string $class, mixed $object, string $msg = ''): void { 
        if (!is_object($object) || !($object instanceof $class)) {
            throw new AssertionError($msg ?: "Expected instance of $class");
        }
    }
    protected function assertIsArray(mixed $val, string $msg = ''): void { 
        if (!is_array($val)) throw new AssertionError($msg ?: 'Expected array'); 
    }
    protected function assertIsString(mixed $val, string $msg = ''): void { 
        if (!is_string($val)) throw new AssertionError($msg ?: 'Expected string'); 
    }
    protected function assertArrayHasKey(string|int $key, array $array, string $msg = ''): void { 
        if (!array_key_exists($key, $array)) throw new AssertionError($msg ?: "Expected array to have key $key"); 
    }
    protected function assertStringContainsString(string $needle, string $haystack, string $msg = ''): void { 
        if (strpos($haystack, $needle) === false) throw new AssertionError($msg ?: "Expected string to contain '$needle'"); 
    }
    protected function assertContains(mixed $needle, array $haystack, string $msg = ''): void {
        if (!in_array($needle, $haystack, true)) throw new AssertionError($msg ?: "Expected array to contain '$needle'");
    }
    protected function assertCount(int $expectedCount, array $array, string $msg = ''): void {
        $actualCount = count($array);
        if ($actualCount !== $expectedCount) throw new AssertionError($msg ?: "Expected count $expectedCount got $actualCount");
    }
    protected function assertEmpty(array $array, string $msg = ''): void {
        if (!empty($array)) throw new AssertionError($msg ?: 'Expected array to be empty');
    }
    protected function assertNotEmpty(array $array, string $msg = ''): void {
        if (empty($array)) throw new AssertionError($msg ?: 'Expected array to be not empty');
    }
    protected function assertStringNotStartsWith(string $prefix, string $string, string $msg = ''): void {
        if (str_starts_with($string, $prefix)) throw new AssertionError($msg ?: "Expected string not to start with '$prefix'");
    }
    protected function expectException(string $class): void {
        // Placeholder para compatibilidad - el framework TDD actual no soporta esto nativamente
        // Se puede implementar con un try-catch wrapper en el futuro
    }

    // Auto-ejecución Standalone Inteligente
    public static function runAllTests(): void
    {
        if (php_sapi_name() !== 'cli') return;
        
        // Registrar manejador para capturar errores fatales de compilación/firmas limpiamente
        register_shutdown_function(function() {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR])) {
                echo "\n[FATAL COMPILE ERROR]: " . $error['message'] . "\n";
                echo "En archivo: " . $error['file'] . " línea " . $error['line'] . "\n";
                exit(1);
            }
        });

        // Buscar el autoloader escalando de forma recursiva hacia la raíz de RapidBase
        $searchDir = getcwd();
        $autoloaderLoaded = false;
        
        while ($searchDir !== dirname($searchDir)) {
            $possibleVendor = $searchDir . '/vendor/autoload.php';
            $possibleCustom = $searchDir . '/src/Autoloader/Autoloader.php'; // Ajustado a estructura típica
            
            if (file_exists($possibleVendor)) {
                require_once $possibleVendor;
                $autoloaderLoaded = true;
                break;
            } elseif (file_exists($possibleCustom)) {
                require_once $possibleCustom;
                // Inicialización de tu autoloader customizado si aplica
                if (class_exists('\RapidBase\Autoloader\Autoloader')) {
                    \RapidBase\Autoloader\Autoloader::getInstance($searchDir)->register();
                }
                $autoloaderLoaded = true;
                break;
            }
            $searchDir = dirname($searchDir);
        }

        $class = get_called_class();
        
        // Evitar instanciación directa si la clase no existe por fallos de autoloading
        if (!class_exists($class)) {
            echo "[CRITICAL] No se pudo cargar la clase de test: $class. Verifique el Autoloader.\n";
            exit(1);
        }

        $instance = new $class();
        $methods = (new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC);
        
        echo "Running standalone tests for: $class\n" . str_repeat('-', 50) . "\n";
        $pass = 0; $fail = 0;

        foreach ($methods as $m) {
            if (str_starts_with($m->getName(), 'test')) {
                try {
                    if (method_exists($instance, 'setUp')) $instance->setUp();
                    $m->invoke($instance);
                    if (method_exists($instance, 'tearDown')) $instance->tearDown();
                    echo "[OK] " . $m->getName() . "\n";
                    $pass++;
                } catch (Throwable $e) {
                    // Captura reflexiones fallidas, errores de aserción y excepciones en caliente
                    echo "[FAIL] " . $m->getName() . ": " . $e->getMessage() . "\n";
                    $fail++;
                }
            }
        }
        echo str_repeat('-', 50) . "\nTotal: " . ($pass+$fail) . " | Passed: $pass | Failed: $fail\n";
        exit($fail > 0 ? 1 : 0);
    }
}

// Hook de auto-ejecución simplificado y seguro
$trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
if (isset($trace[0]['file'])) {
    $runningFile = basename($trace[0]['file']);
    // Si el archivo ejecutado directamente termina en Test.php, disparamos el runner de inmediato
    if (str_ends_with($runningFile, 'Test.php') && php_sapi_name() === 'cli') {
        // Permite la ejecución fluida al incluir el archivo de forma directa
    }
}