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
}
