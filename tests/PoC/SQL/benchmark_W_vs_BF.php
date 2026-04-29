<?php

/**
 * Test comparativo: W vs B+F (cadena fragmentada)
 * 
 * Este benchmark compara el rendimiento de:
 * 1. Clase W original (monolítica)
 * 2. Clases B + F (fragmentadas en Builder y Finalizer)
 */

require_once __DIR__ . '/W.php';
require_once __DIR__ . '/Wm.php';
require_once __DIR__ . '/B.php';
require_once __DIR__ . '/F.php';

use RapidBase\Core\W;
use RapidBase\Core\Wm;
use RapidBase\Core\B;
use RapidBase\Core\F;

class BenchmarkWvsBF
{
    private int $iterations;
    private array $results = [];
    
    public function __construct(int $iterations = 10000)
    {
        $this->iterations = $iterations;
    }
    
    public function run(): void
    {
        echo "=== Benchmark: W vs B+F ===\n";
        echo "Iteraciones: {$this->iterations}\n\n";
        
        // Test 1: SELECT simple
        $this->testSelectSimple();
        
        // Test 2: SELECT con WHERE
        $this->testSelectWithWhere();
        
        // Test 3: SELECT con JOINs
        $this->testSelectWithJoins();
        
        // Test 4: DELETE
        $this->testDelete();
        
        // Test 5: UPDATE
        $this->testUpdate();
        
        // Test 6: COUNT
        $this->testCount();
        
        // Test 7: EXISTS
        $this->testExists();
        
        // Mostrar resumen
        $this->printSummary();
    }
    
    private function testSelectSimple(): void
    {
        echo "Test 1: SELECT simple\n";
        
        // W
        Wm::clearMetrics();
        $start = microtime(true);
        for ($i = 0; $i < $this->iterations; $i++) {
            Wm::from('users')->select('id, name');
        }
        $timeW = (microtime(true) - $start) * 1000;
        $memW = memory_get_usage();
        
        // B+F
        B::clearMetrics();
        F::clearMetrics();
        $start = microtime(true);
        for ($i = 0; $i < $this->iterations; $i++) {
            F::fromBuilder(B::from('users')->selectFields('id, name'))->select();
        }
        $timeBF = (microtime(true) - $start) * 1000;
        $memBF = memory_get_usage();
        
        $this->results['select_simple'] = [
            'W_time_ms' => round($timeW, 2),
            'BF_time_ms' => round($timeBF, 2),
            'diff_percent' => round((($timeBF - $timeW) / $timeW) * 100, 2),
            'W_mem_bytes' => $memW,
            'BF_mem_bytes' => $memBF
        ];
        
        echo "  W:   {$timeW} ms\n";
        echo "  B+F: {$timeBF} ms (" . $this->results['select_simple']['diff_percent'] . "%)\n\n";
    }
    
    private function testSelectWithWhere(): void
    {
        echo "Test 2: SELECT con WHERE\n";
        
        // W
        Wm::clearMetrics();
        $start = microtime(true);
        for ($i = 0; $i < $this->iterations; $i++) {
            Wm::from('users', ['status' => 'active'])->select('id, name', 10);
        }
        $timeW = (microtime(true) - $start) * 1000;
        
        // B+F
        B::clearMetrics();
        F::clearMetrics();
        $start = microtime(true);
        for ($i = 0; $i < $this->iterations; $i++) {
            F::fromBuilder(B::from('users', ['status' => 'active'])->limit(10))->select();
        }
        $timeBF = (microtime(true) - $start) * 1000;
        
        $this->results['select_where'] = [
            'W_time_ms' => round($timeW, 2),
            'BF_time_ms' => round($timeBF, 2),
            'diff_percent' => round((($timeBF - $timeW) / $timeW) * 100, 2)
        ];
        
        echo "  W:   {$timeW} ms\n";
        echo "  B+F: {$timeBF} ms (" . $this->results['select_where']['diff_percent'] . "%)\n\n";
    }
    
    private function testSelectWithJoins(): void
    {
        echo "Test 3: SELECT con JOINs\n";
        
        // W
        Wm::clearMetrics();
        $start = microtime(true);
        for ($i = 0; $i < $this->iterations; $i++) {
            Wm::from(['users', 'posts'])->select('u.id, p.title');
        }
        $timeW = (microtime(true) - $start) * 1000;
        
        // B+F
        B::clearMetrics();
        F::clearMetrics();
        $start = microtime(true);
        for ($i = 0; $i < $this->iterations; $i++) {
            F::fromBuilder(B::from(['users', 'posts']))->select('u.id, p.title');
        }
        $timeBF = (microtime(true) - $start) * 1000;
        
        $this->results['select_joins'] = [
            'W_time_ms' => round($timeW, 2),
            'BF_time_ms' => round($timeBF, 2),
            'diff_percent' => round((($timeBF - $timeW) / $timeW) * 100, 2)
        ];
        
        echo "  W:   {$timeW} ms\n";
        echo "  B+F: {$timeBF} ms (" . $this->results['select_joins']['diff_percent'] . "%)\n\n";
    }
    
    private function testDelete(): void
    {
        echo "Test 4: DELETE\n";
        
        // W
        Wm::clearMetrics();
        $start = microtime(true);
        for ($i = 0; $i < $this->iterations; $i++) {
            Wm::from('users', ['id' => 1])->delete();
        }
        $timeW = (microtime(true) - $start) * 1000;
        
        // B+F
        B::clearMetrics();
        F::clearMetrics();
        $start = microtime(true);
        for ($i = 0; $i < $this->iterations; $i++) {
            F::fromBuilder(B::from('users', ['id' => 1]))->delete();
        }
        $timeBF = (microtime(true) - $start) * 1000;
        
        $this->results['delete'] = [
            'W_time_ms' => round($timeW, 2),
            'BF_time_ms' => round($timeBF, 2),
            'diff_percent' => round((($timeBF - $timeW) / $timeW) * 100, 2)
        ];
        
        echo "  W:   {$timeW} ms\n";
        echo "  B+F: {$timeBF} ms (" . $this->results['delete']['diff_percent'] . "%)\n\n";
    }
    
    private function testUpdate(): void
    {
        echo "Test 5: UPDATE\n";
        
        // W
        Wm::clearMetrics();
        $start = microtime(true);
        for ($i = 0; $i < $this->iterations; $i++) {
            Wm::from('users', ['id' => 1])->update(['name' => 'test']);
        }
        $timeW = (microtime(true) - $start) * 1000;
        
        // B+F
        B::clearMetrics();
        F::clearMetrics();
        $start = microtime(true);
        for ($i = 0; $i < $this->iterations; $i++) {
            F::fromBuilder(B::from('users', ['id' => 1]))->update(['name' => 'test']);
        }
        $timeBF = (microtime(true) - $start) * 1000;
        
        $this->results['update'] = [
            'W_time_ms' => round($timeW, 2),
            'BF_time_ms' => round($timeBF, 2),
            'diff_percent' => round((($timeBF - $timeW) / $timeW) * 100, 2)
        ];
        
        echo "  W:   {$timeW} ms\n";
        echo "  B+F: {$timeBF} ms (" . $this->results['update']['diff_percent'] . "%)\n\n";
    }
    
    private function testCount(): void
    {
        echo "Test 6: COUNT\n";
        
        // W
        Wm::clearMetrics();
        $start = microtime(true);
        for ($i = 0; $i < $this->iterations; $i++) {
            Wm::from('users', ['status' => 'active'])->count();
        }
        $timeW = (microtime(true) - $start) * 1000;
        
        // B+F
        B::clearMetrics();
        F::clearMetrics();
        $start = microtime(true);
        for ($i = 0; $i < $this->iterations; $i++) {
            F::fromBuilder(B::from('users', ['status' => 'active']))->count();
        }
        $timeBF = (microtime(true) - $start) * 1000;
        
        $this->results['count'] = [
            'W_time_ms' => round($timeW, 2),
            'BF_time_ms' => round($timeBF, 2),
            'diff_percent' => round((($timeBF - $timeW) / $timeW) * 100, 2)
        ];
        
        echo "  W:   {$timeW} ms\n";
        echo "  B+F: {$timeBF} ms (" . $this->results['count']['diff_percent'] . "%)\n\n";
    }
    
    private function testExists(): void
    {
        echo "Test 7: EXISTS\n";
        
        // W
        Wm::clearMetrics();
        $start = microtime(true);
        for ($i = 0; $i < $this->iterations; $i++) {
            Wm::from('users', ['id' => 1])->exists();
        }
        $timeW = (microtime(true) - $start) * 1000;
        
        // B+F
        B::clearMetrics();
        F::clearMetrics();
        $start = microtime(true);
        for ($i = 0; $i < $this->iterations; $i++) {
            F::fromBuilder(B::from('users', ['id' => 1]))->exists();
        }
        $timeBF = (microtime(true) - $start) * 1000;
        
        $this->results['exists'] = [
            'W_time_ms' => round($timeW, 2),
            'BF_time_ms' => round($timeBF, 2),
            'diff_percent' => round((($timeBF - $timeW) / $timeW) * 100, 2)
        ];
        
        echo "  W:   {$timeW} ms\n";
        echo "  B+F: {$timeBF} ms (" . $this->results['exists']['diff_percent'] . "%)\n\n";
    }
    
    private function printSummary(): void
    {
        echo "=== RESUMEN ===\n";
        echo str_pad("Test", 20) . str_pad("W (ms)", 12) . str_pad("B+F (ms)", 12) . "Diferencia\n";
        echo str_repeat("-", 60) . "\n";
        
        $totalW = 0;
        $totalBF = 0;
        
        foreach ($this->results as $test => $data) {
            echo str_pad($test, 20);
            echo str_pad($data['W_time_ms'], 12);
            echo str_pad($data['BF_time_ms'], 12);
            echo sprintf("%+.2f%%", $data['diff_percent']) . "\n";
            $totalW += $data['W_time_ms'];
            $totalBF += $data['BF_time_ms'];
        }
        
        echo str_repeat("-", 60) . "\n";
        echo str_pad("TOTAL", 20);
        echo str_pad(round($totalW, 2), 12);
        echo str_pad(round($totalBF, 2), 12);
        echo sprintf("%+.2f%%", (($totalBF - $totalW) / $totalW) * 100) . "\n";
        
        // Métricas detalladas de Wm
        echo "\n=== Métricas Wm ===\n";
        $stats = Wm::getStats();
        echo "Calls: {$stats['calls']}\n";
        echo "Tiempo total: {$stats['total_time_ms']} ms\n";
        echo "Tiempo promedio: {$stats['avg_time_ms']} ms\n";
        echo "Memoria promedio: {$stats['avg_mem_bytes']} bytes\n";
        
        // Métricas detalladas de B+F
        echo "\n=== Métricas B ===\n";
        $statsB = B::getStats();
        echo "Calls: {$statsB['calls']}\n";
        echo "Tiempo total: {$statsB['total_time_ms']} ms\n";
        echo "Tiempo promedio: {$statsB['avg_time_ms']} ms\n";
        echo "Memoria promedio: {$statsB['avg_mem_bytes']} bytes\n";
        
        echo "\n=== Métricas F ===\n";
        $statsF = F::getStats();
        echo "Calls: {$statsF['calls']}\n";
        echo "Tiempo total: {$statsF['total_time_ms']} ms\n";
        echo "Tiempo promedio: {$statsF['avg_time_ms']} ms\n";
        echo "Memoria promedio: {$statsF['avg_mem_bytes']} bytes\n";
    }
}

// Ejecutar benchmark
$benchmark = new BenchmarkWvsBF(10000);
$benchmark->run();
