<?php

declare(strict_types=1);

namespace RapidBase\Tdd;

use AssertionError;

abstract class TestCase
{
    public ?CoreRunner $runner = null;
    public string $currentDriver = 'sqlite';

    public function setUp(): void {}
    public function tearDown(): void {}

    public function setRunnerContext(CoreRunner $runner): void
    {
        $this->runner = $runner;
    }

    protected function env(string ...$drivers): EnvironmentBuilder
    {
        if ($this->runner === null) {
            // Fallback si se ejecuta standalone sin runner externo
            $this->runner = new CoreRunner(sys_get_temp_dir() . '/rapidbase_tdd_tmp.sqlite', getcwd());
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

    // Auto-ejecución Standalone
    public static function runAllTests(): void
    {
        if (php_sapi_name() !== 'cli') return;
        
        // Intentar cargar autoloader
        $paths = [__DIR__ . '/../../Autoloader/Autoloader.php', getcwd() . '/vendor/autoload.php'];
        foreach ($paths as $p) {
            if (file_exists($p)) {
                if (strpos($p, 'Autoloader.php')) {
                    require_once $p;
                    \RapidBase\Autoloader\Autoloader::getInstance(dirname(dirname($p)))->register();
                } else {
                    require_once $p;
                }
                break;
            }
        }

        $class = get_called_class();
        $instance = new $class();
        $methods = (new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC);
        
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
                } catch (\Throwable $e) {
                    echo "[FAIL] " . $m->getName() . ": " . $e->getMessage() . "\n";
                    $fail++;
                }
            }
        }
        echo str_repeat('-', 50) . "\nTotal: " . ($pass+$fail) . " | Passed: $pass | Failed: $fail\n";
        exit($fail > 0 ? 1 : 0);
    }
}

// Hook de auto-ejecución
$trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
if (basename($trace[0]['file']) === basename((new \ReflectionClass(TestCase::class))->getFileName())) {
    // No hacer nada si es el archivo de la clase base
} else {
    // Si este archivo se está incluyendo como parte de un test que se ejecuta directamente
    // La lógica real está en el hook de abajo que detecta ejecución directa
}

// Detectar ejecución directa del archivo que hereda de esta clase
if (isset($trace[0]['file']) && isset($trace[1]['file'])) {
     // Lógica simplificada: El usuario debe llamar a runAllTests() al final de su archivo Test
     // O usamos el patrón de abajo en el archivo generado.
}