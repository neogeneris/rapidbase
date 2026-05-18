<?php

declare(strict_types=1);

namespace RapidBase\Tdd;

use Closure;
use ReflectionFunction;
use Throwable;

/**
 * EnvironmentBuilder - Fluent Interface para pruebas multi-entorno.
 * Permite ejecutar closures de prueba en múltiples drivers de base de datos.
 */
class EnvironmentBuilder
{
    private array $drivers;
    private object $testInstance;
    private CoreRunner $runner;
    private ?array $dataset = null;

    public function __construct(array $drivers, object $testInstance, CoreRunner $runner)
    {
        $this->drivers = $drivers;
        $this->testInstance = $testInstance;
        $this->runner = $runner;
    }

    /**
     * Configura un dataset para ser insertado antes de la prueba.
     */
    public function dataset(array $data, string $table = 'test_data'): self
    {
        $this->dataset = ['data' => $data, 'table' => $table];
        return $this;
    }

    /**
     * Ejecuta el closure de prueba en cada driver configurado.
     * Registra los resultados en el CoreRunner.
     */
    public function test(string $description, Closure $callback): void
    {
        // Obtener el nombre del método contenedor para el reporte (ej: testSelectBasic)
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $methodContainer = $backtrace[1]['function'] ?? 'unknownTest';

        // Filtrar drivers: Solo ejecutar si el driver solicitado está permitido globalmente por el runner
        $allowedDrivers = array_intersect($this->drivers, $this->runner->getActiveDrivers());

        foreach ($allowedDrivers as $driver) {
            $displayName = "{$methodContainer}::{$description} ({$driver})";
            $startTime = microtime(true);
            
            // Configurar contexto dinámico en la clase de test antes de correr el closure
            if (property_exists($this->testInstance, 'currentDriver')) {
                $this->testInstance->currentDriver = $driver;
            }
            
            // Simulación de inicialización de Base de Datos
            $dbConnection = null;
            
            // Si hay dataset, inyectarlo en el driver correspondiente
            if ($this->dataset && method_exists($this->runner, 'insertDataset')) {
                try {
                    $this->runner->insertDataset($this->dataset['data'], $this->dataset['table']);
                } catch (Throwable $e) {
                    // Ignorar errores de dataset si no hay conexión activa
                }
            }

            try {
                // Ejecutar el closure de prueba inyectando la conexión simulada u objeto DB
                $callback($dbConnection);
                
                $duration = round((microtime(true) - $startTime) * 1000, 2);

                // Registrar éxito en el runner de forma centralizada
                $this->runner->recordResult([
                    'name' => $displayName,
                    'method' => $methodContainer,
                    'description' => $description,
                    'status' => 'SUCCESS',
                    'duration' => $duration,
                    'driver' => $driver,
                    'message' => '',
                    'callback' => $callback // Guardamos la referencia para extraer el código luego
                ]);

                if ($this->runner->isVerbose()) {
                    echo "  [SUCCESS] {$displayName}\n";
                }

            } catch (StopTestExecutionException $e) {
                // Re-lanzar excepción de control para detener ejecución
                throw $e;
                
            } catch (Throwable $e) {
                $duration = round((microtime(true) - $startTime) * 1000, 2);
                
                // Registrar falla en el runner
                $this->runner->recordResult([
                    'name' => $displayName,
                    'method' => $methodContainer,
                    'description' => $description,
                    'status' => 'FAILURE',
                    'duration' => $duration,
                    'driver' => $driver,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                    'callback' => $callback
                ]);

                $this->runner->printFailureBlock($displayName, $e);

                if ($this->runner->shouldStopOnFirst()) {
                    // Lanzamos una excepción de control interna para romper el ciclo de pruebas
                    throw new StopTestExecutionException();
                }
            }
        }
    }
}

/**
 * Excepción interna de control para el modo --first
 */
class StopTestExecutionException extends \Exception {}
