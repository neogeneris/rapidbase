<?php

declare(strict_types=1);

namespace RapidBase\Tdd;

use Closure;
use Throwable;

class EnvironmentBuilder
{
    private array $drivers;
    private TestCase $testInstance;
    private CoreRunner $runner;
    private ?array $dataset = null;

    public function __construct(array $drivers, TestCase $testInstance, CoreRunner $runner)
    {
        $this->drivers = $drivers;
        $this->testInstance = $testInstance;
        $this->runner = $runner;
    }

    public function dataset(array $data, string $table = 'test_data'): self
    {
        $this->dataset = ['data' => $data, 'table' => $table];
        return $this;
    }

    public function test(string $description, Closure $callback): void
    {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $methodContainer = $backtrace[1]['function'] ?? 'unknownTest';
        $allowedDrivers = array_intersect($this->drivers, $this->runner->getDrivers());

        foreach ($allowedDrivers as $driver) {
            $displayName = "{$methodContainer} :: {$description} ({$driver})";
            $startTime = microtime(true);
            $this->testInstance->currentDriver = $driver;

            try {
                $callback($this->testInstance);
                $duration = round((microtime(true) - $startTime) * 1000, 2);
                $this->runner->recordRuntimeResult([
                    'category' => 'Unit', 'class' => get_class($this->testInstance),
                    'method' => $methodContainer . ' (' . $description . ')', 'driver' => $driver,
                    'status' => 'PASS', 'duration' => $duration, 'error' => ''
                ]);
                if ($this->runner->isVerbose()) echo "  [SUCCESS] {$displayName}\n";
            } catch (Throwable $e) {
                $duration = round((microtime(true) - $startTime) * 1000, 2);
                $this->runner->recordRuntimeResult([
                    'category' => 'Unit', 'class' => get_class($this->testInstance),
                    'method' => $methodContainer . ' (' . $description . ')', 'driver' => $driver,
                    'status' => 'FAIL', 'duration' => $duration, 'error' => $e->getMessage()
                ]);
                $this->runner->printImmediateFailure($displayName, $e);
                if ($this->runner->shouldStopOnFirstFail()) throw new StopSuiteExecutionException();
            }
        }
    }
}

class StopSuiteExecutionException extends \Exception {}