<?php

declare(strict_types=1);

namespace RapidBase\Tdd;

use AssertionError;

/**
 * Clase base para casos de prueba en RapidBase.
 * Proporciona métodos de aserción ligeros e interfaz fluida multi-ambiente.
 */
abstract class TestCase
{
    public ?CoreRunner $runner = null;
    public string $currentDriver = 'sqlite';

    /**
     * Se ejecuta antes de cada método de prueba.
     */
    public function setUp(): void {}

    /**
     * Se ejecuta después de cada método de prueba.
     */
    public function tearDown(): void {}

    /**
     * Inyecta el contexto del orquestador (CoreRunner).
     */
    public function setRunnerContext(CoreRunner $runner): void
    {
        $this->runner = $runner;
    }

    /**
     * Factory para inicializar el entorno fluido multi-db.
     * Si no se especifican drivers, hereda los drivers activos globales del Runner.
     */
    protected function env(string ...$drivers): EnvironmentBuilder
    {
        if ($this->runner === null) {
            // Fallback de seguridad si se corre el archivo aislado sin el CLI runner
            $this->runner = new CoreRunner(get_class($this), dirname(__DIR__));
        }

        $driversList = empty($drivers) ? $this->runner->getActiveDrivers() : $drivers;
        
        return new EnvironmentBuilder($driversList, $this, $this->runner);
    }

    // =========================================================================
    // ASERCIONES ULTRA-LIGERAS (Zero Configuration)
    // =========================================================================

    protected function assertTrue(bool $condition, string $message = ''): void 
    {
        if (!$condition) {
            throw new AssertionError($message ?: 'Expected true but got false');
        }
    }

    protected function assertFalse(bool $condition, string $message = ''): void 
    {
        if ($condition) {
            throw new AssertionError($message ?: 'Expected false but got true');
        }
    }

    protected function assertEquals(mixed $expected, mixed $actual, string $message = ''): void 
    {
        if ($expected !== $actual) {
            $msg = $message ?: "Expected " . var_export($expected, true) . " but got " . var_export($actual, true);
            throw new AssertionError($msg);
        }
    }

    protected function assertCount(int $count, array|string $data, string $message = ''): void 
    {
        $actual = is_array($data) ? count($data) : strlen((string) $data);
        if ($actual !== $count) {
            throw new AssertionError($message ?: "Expected count $count but got $actual");
        }
    }

    protected function assertNull(mixed $value, string $message = ''): void 
    {
        if ($value !== null) {
            throw new AssertionError($message ?: 'Expected null but got ' . gettype($value));
        }
    }

    protected function assertNotNull(mixed $value, string $message = ''): void 
    {
        if ($value === null) {
            throw new AssertionError($message ?: 'Expected value not to be null');
        }
    }

    protected function fail(string $message = 'Test failed explicitly'): void 
    {
        throw new AssertionError($message);
    }

    protected function assertInstanceOf(string $class, mixed $object, string $message = ''): void 
    {
        if (!is_object($object) || !($object instanceof $class)) {
            $actualType = is_object($object) ? get_class($object) : gettype($object);
            throw new AssertionError($message ?: "Expected instance of {$class} but got {$actualType}");
        }
    }

    protected function assertIsArray(mixed $value, string $message = ''): void 
    {
        if (!is_array($value)) {
            throw new AssertionError($message ?: 'Expected value to be an array');
        }
    }

    protected function assertStringContainsString(string $needle, string $haystack, string $message = ''): void 
    {
        if (strpos($haystack, $needle) === false) {
            throw new AssertionError($message ?: "Expected '{$haystack}' to contain '{$needle}'");
        }
    }

    protected function assertGreaterThan(int|float $expected, int|float $actual, string $message = ''): void 
    {
        if ($actual <= $expected) {
            throw new AssertionError($message ?: "Expected {$actual} to be greater than {$expected}");
        }
    }

    protected function assertGreaterThanOrEqual(int|float $expected, int|float $actual, string $message = ''): void 
    {
        if ($actual < $expected) {
            throw new AssertionError($message ?: "Expected {$actual} to be greater than or equal to {$expected}");
        }
    }

    protected function assertLessThan(int|float $expected, int|float $actual, string $message = ''): void 
    {
        if ($actual >= $expected) {
            throw new AssertionError($message ?: "Expected {$actual} to be less than {$expected}");
        }
    }

    protected function assertEmpty(mixed $value, string $message = ''): void 
    {
        if (!empty($value)) {
            throw new AssertionError($message ?: 'Expected value to be empty');
        }
    }

    protected function assertNotEmpty(mixed $value, string $message = ''): void 
    {
        if (empty($value)) {
            throw new AssertionError($message ?: 'Expected value to not be empty');
        }
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void 
    {
        if ($expected !== $actual) {
            throw new AssertionError($message ?: 'Expected values to be the same');
        }
    }

    protected function assertNotSame(mixed $expected, mixed $actual, string $message = ''): void 
    {
        if ($expected === $actual) {
            throw new AssertionError($message ?: 'Expected values to not be the same');
        }
    }

    protected function assertContains(mixed $needle, array|string $haystack, string $message = ''): void 
    {
        if (is_array($haystack)) {
            if (!in_array($needle, $haystack, true)) {
                throw new AssertionError($message ?: 'Expected array to contain the value');
            }
        } else {
            if (strpos((string) $haystack, (string) $needle) === false) {
                throw new AssertionError($message ?: 'Expected string to contain the substring');
            }
        }
    }

    protected function assertNotContains(mixed $needle, array|string $haystack, string $message = ''): void 
    {
        if (is_array($haystack)) {
            if (in_array($needle, $haystack, true)) {
                throw new AssertionError($message ?: 'Expected array to not contain the value');
            }
        } else {
            if (strpos((string) $haystack, (string) $needle) !== false) {
                throw new AssertionError($message ?: 'Expected string to not contain the substring');
            }
        }
    }

    /**
     * Auto-ejecución cuando se instancia directamente desde CLI
     */
    public static function runAllTests(): void
    {
        // Solo ejecutar si se está corriendo directamente desde CLI
        if (php_sapi_name() !== 'cli') {
            return;
        }

        $calledClass = static::class;
        $reflection = new \ReflectionClass($calledClass);
        
        // Verificar si este archivo es el que se está ejecutando directamente
        $scriptFile = realpath($_SERVER['SCRIPT_FILENAME'] ?? '');
        $classFile = realpath($reflection->getFileName());
        
        // Si SCRIPT_FILENAME no coincide, verificar si estamos en ejecución directa
        if ($scriptFile !== $classFile) {
            // Verificación alternativa: el script actual es el mismo que el archivo de la clase
            $currentScript = realpath($GLOBALS['_SERVER']['SCRIPT_FILENAME'] ?? $_SERVER['SCRIPT_FILENAME'] ?? '');
            if ($currentScript !== $classFile) {
                return; // No es ejecución directa
            }
        }

        echo "\n";
        echo str_repeat('=', 70) . "\n";
        echo "  Running {$calledClass} directly (Standalone Mode)\n";
        echo str_repeat('=', 70) . "\n\n";

        // Intentar cargar Autoloader si existe
        $basePath = dirname($classFile);
        while ($basePath !== '/' && $basePath !== '.') {
            $autoloaderPath = $basePath . '/src/RapidBase/Autoloader/Autoloader.php';
            if (file_exists($autoloaderPath)) {
                require_once $autoloaderPath;
                \RapidBase\Autoloader\Autoloader::getInstance($basePath . '/src')
                    ->enableDebug(false)
                    ->enableCache(true)
                    ->register();
                break;
            }
            $basePath = dirname($basePath);
        }

        // Ejecutar pruebas manualmente
        $testInstance = new $calledClass();
        $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);
        $testMethods = array_filter($methods, fn($m) => str_starts_with($m->getName(), 'test'));

        $total = count($testMethods);
        $passed = 0;
        $failed = 0;

        foreach ($testMethods as $method) {
            $methodName = $method->getName();
            try {
                // Setup
                if (method_exists($testInstance, 'setUp')) {
                    $testInstance->setUp();
                }

                // Ejecutar test
                $startTime = microtime(true);
                $method->invoke($testInstance);
                $duration = round((microtime(true) - $startTime) * 1000, 2);

                // Teardown
                if (method_exists($testInstance, 'tearDown')) {
                    $testInstance->tearDown();
                }

                echo "  [PASS] {$methodName} ({$duration}ms)\n";
                $passed++;

            } catch (\Throwable $e) {
                // Teardown incluso en error
                if (method_exists($testInstance, 'tearDown')) {
                    try {
                        $testInstance->tearDown();
                    } catch (\Throwable $te) {}
                }

                echo "\n  [FAIL] {$methodName}\n";
                echo "    Error: {$e->getMessage()}\n";
                echo "    File: {$e->getFile()} (Line {$e->getLine()})\n\n";
                $failed++;
            }
        }

        echo "\n";
        echo str_repeat('-', 70) . "\n";
        echo "  Results: {$total} total, {$passed} passed, {$failed} failed\n";
        echo str_repeat('=', 70) . "\n";

        exit($failed > 0 ? 1 : 0);
    }
}

// Auto-invocación para cualquier clase que herede de TestCase
if (php_sapi_name() === 'cli') {
    $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
    $lastFrame = end($backtrace);
    if (isset($lastFrame['file']) && isset($lastFrame['line'])) {
        $currentFile = realpath($lastFrame['file']);
        $thisFile = realpath(__FILE__);
        
        // Si el último archivo ejecutado es una subclase de TestCase y no es este archivo
        if ($currentFile && $thisFile && $currentFile !== $thisFile) {
            $className = __NAMESPACE__ . '\\' . basename($currentFile, '.php');
            if (class_exists($className) && is_subclass_of($className, __NAMESPACE__ . '\\TestCase')) {
                if (method_exists($className, 'runAllTests')) {
                    $className::runAllTests();
                }
            }
        }
    }
}
