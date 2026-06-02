<?php

declare(strict_types=1);

/**
 * Benchmark comparativo entre:
 * 1. SerialFileCacheAdapter (serialize/unserialize en archivo .dat)
 * 2. PhpFileCacheAdapter (archivos PHP con include + OPcache)
 * 
 * Este test simula el uso del Autoloader con diferentes volúmenes de datos
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/SerialFileCacheAdapter.php';
require_once __DIR__ . '/PhpFileCacheAdapter.php';

use RapidBase\Tests\Performance\SerialFileCacheAdapter;
use RapidBase\Tests\Performance\PhpFileCacheAdapter;

class CacheBenchmark
{
    private string $testDir;
    private array $results = [];

    public function __construct()
    {
        $this->testDir = sys_get_temp_dir() . '/cache_benchmark_' . uniqid();
        if (!is_dir($this->testDir)) {
            mkdir($this->testDir, 0775, true);
        }
    }

    public function runAll(): void
    {
        echo "=== CACHE ADAPTER BENCHMARK ===\n\n";
        
        $dataSizes = [10, 100, 500, 1000];
        
        foreach ($dataSizes as $size) {
            echo "Testing with $size cache entries...\n";
            $this->runComparison($size);
            echo "\n";
        }

        $this->printSummary();
        $this->cleanup();
    }

    private function runComparison(int $dataSize): void
    {
        // Preparar datos de prueba
        $testData = $this->generateTestData($dataSize);

        // Test SerialFileCacheAdapter
        $serialFile = $this->testDir . '/serial_cache.dat';
        $serialAdapter = new SerialFileCacheAdapter($serialFile);
        $serialResults = $this->benchmarkAdapter($serialAdapter, $testData, 'SerialFile');
        
        // Test PhpFileCacheAdapter
        $phpDir = $this->testDir . '/php_cache/';
        if (!is_dir($phpDir)) {
            mkdir($phpDir, 0775, true);
        }
        $phpAdapter = new PhpFileCacheAdapter($phpDir);
        $phpResults = $this->benchmarkAdapter($phpAdapter, $testData, 'PhpFile');

        // Guardar resultados
        $this->results[$dataSize] = [
            'serial' => $serialResults,
            'php' => $phpResults
        ];

        // Mostrar comparación
        $this->printComparison($dataSize, $serialResults, $phpResults);
    }

    private function generateTestData(int $count): array
    {
        $data = [];
        for ($i = 0; $i < $count; $i++) {
            $className = "App\\Module" . ($i % 10) . "\\Controller\\Class$i";
            $filePath = "/var/www/html/app/module" . ($i % 10) . "/controller/Class$i.php";
            $data[$className] = $filePath;
        }
        return $data;
    }

    private function benchmarkAdapter(object $adapter, array $testData, string $name): array
    {
        $results = [
            'write_all_ms' => 0,
            'read_all_cold_ms' => 0,
            'read_all_hot_ms' => 0,
            'mixed_operations_ms' => 0,
        ];

        // 1. Write Performance - escribir todos los datos
        $start = microtime(true);
        foreach ($testData as $key => $value) {
            $adapter->set($key, $value);
        }
        $results['write_all_ms'] = (microtime(true) - $start) * 1000;

        // Forzar recarga para test de lectura fría
        unset($adapter);
        
        // Recrear adapter para lectura fría
        if ($name === 'SerialFile') {
            $adapter = new SerialFileCacheAdapter($this->testDir . '/serial_cache.dat');
        } else {
            $adapter = new PhpFileCacheAdapter($this->testDir . '/php_cache/');
        }

        // 2. Read Performance - Cold Cache (sin L1)
        $start = microtime(true);
        foreach ($testData as $key => $value) {
            $adapter->get($key);
        }
        $results['read_all_cold_ms'] = (microtime(true) - $start) * 1000;

        // 3. Read Performance - Hot Cache (con L1)
        $start = microtime(true);
        foreach ($testData as $key => $value) {
            $adapter->get($key);
        }
        $results['read_all_hot_ms'] = (microtime(true) - $start) * 1000;

        // 4. Mixed Operations (simular autoloader real)
        $keys = array_keys($testData);
        $start = microtime(true);
        for ($i = 0; $i < 100; $i++) {
            $randomKey = $keys[array_rand($keys)];
            $adapter->get($randomKey);
            if ($i % 10 === 0) {
                $adapter->set("new_key_$i", "new_value_$i");
            }
        }
        $results['mixed_operations_ms'] = (microtime(true) - $start) * 1000;

        return $results;
    }

    private function printComparison(int $dataSize, array $serial, array $php): void
    {
        echo str_repeat('-', 80) . "\n";
        echo sprintf("Data Size: %d entries\n", $dataSize);
        echo str_repeat('-', 80) . "\n";
        
        $tests = [
            'Write All' => 'write_all_ms',
            'Read All (Cold)' => 'read_all_cold_ms',
            'Read All (Hot)' => 'read_all_hot_ms',
            'Mixed Ops (100 iter)' => 'mixed_operations_ms',
        ];

        printf("%-25s | %-15s | %-15s | %-10s\n", 'Test', 'Serial (.dat)', 'PHP (include)', 'Winner');
        echo str_repeat('-', 80) . "\n";

        foreach ($tests as $label => $key) {
            $serialVal = round($serial[$key], 2);
            $phpVal = round($php[$key], 2);
            $winner = $serialVal < $phpVal ? 'Serial' : 'PHP';
            $minVal = min($serialVal, $phpVal);
            $speedup = $minVal > 0 ? round(max($serialVal, $phpVal) / $minVal, 2) : 1.00;
            
            printf(
                "%-25s | %10.2f ms   | %10.2f ms   | %s (%.2fx)\n",
                $label,
                $serialVal,
                $phpVal,
                $winner,
                $speedup
            );
        }
        echo "\n";
    }

    private function printSummary(): void
    {
        echo str_repeat('=', 80) . "\n";
        echo "SUMMARY\n";
        echo str_repeat('=', 80) . "\n\n";

        echo "Observations:\n";
        echo "- Serial (.dat): Mejor para escrituras masivas y archivos únicos\n";
        echo "- PHP (include): Mejor para lecturas con OPcache activado\n";
        echo "- El enfoque PHP escala mejor con muchos archivos independientes\n";
        echo "- El enfoque Serial puede tener cuellos de botella en archivos grandes\n\n";

        echo "Recomendación para Autoloader:\n";
        echo "- Mantener el enfoque Serial para caché de mapeo de clases (pocas escrituras)\n";
        echo "- Considerar enfoque híbrido si se necesita TTL por clase\n";
        echo "- La inyección de dependencias permite cambiar sin modificar el core\n";
    }

    private function cleanup(): void
    {
        // Limpiar archivos de test
        $files = glob($this->testDir . '/*');
        foreach ($files as $file) {
            if (is_dir($file)) {
                $subFiles = glob($file . '/*');
                foreach ($subFiles as $subFile) {
                    unlink($subFile);
                }
                rmdir($file);
            } else {
                unlink($file);
            }
        }
        rmdir($this->testDir);
    }
}

// Ejecutar benchmark
$benchmark = new CacheBenchmark();
$benchmark->runAll();
