<?php

/**
 * Benchmark: buildSelect() - Antes vs Después de la refactorización
 * 
 * Compara el rendimiento del método buildSelect() tradicional (arrays)
 * contra la nueva implementación orientada a objetos con clases Field, From, Join.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use RapidBase\Core\SQL;
use RapidBase\Core\Query\SelectBuilder;

class BuildSelectBenchmark {
    
    private int $iterations = 100;
    private array $results = [];
    
    public function run(): void {
        echo "=== BENCHMARK: buildSelect() Performance ===\n\n";
        echo "Iteraciones: {$this->iterations}\n\n";
        
        // Escenario 1: Consulta Simple
        $this->runScenario('Consulta Simple', function() {
            return SQL::buildSelect('*', 'users', ['status' => 'active']);
        });
        
        // Escenario 2: Múltiples campos
        $this->runScenario('Múltiples Campos', function() {
            return SQL::buildSelect('id, name, email, created_at', 'users', ['status' => 'active']);
        });
        
        // Escenario 3: Campos con alias
        $this->runScenario('Campos con Alias', function() {
            return SQL::buildSelect('u.id as user_id, u.name as user_name, p.title', 'users u', [], ['products p ON u.id = p.user_id']);
        });
        
        // Escenario 4: WHERE complejo
        $this->runScenario('WHERE Complejo', function() {
            return SQL::buildSelect('*', 'products', [
                'price' => ['>' => 100, '<' => 500],
                'status' => 'active',
                'category_id' => [1, 2, 3]
            ]);
        });
        
        // Escenario 5: JOIN simple
        $this->runScenario('JOIN Simple', function() {
            return SQL::buildSelect(
                'u.name, p.title',
                'users u',
                ['u.status' => 'active'],
                ['products p ON u.id = p.user_id']
            );
        });
        
        // Escenario 6: ORDER BY + Paginación
        $this->runScenario('ORDER BY + Paginación', function() {
            return SQL::buildSelect(
                '*',
                'products',
                ['status' => 'active'],
                [],
                [],
                ['created_at' => 'DESC'],
                1,
                20
            );
        });
        
        $this->printSummary();
    }
    
    private function runScenario(string $name, callable $callback): void {
        // Warmup
        for ($i = 0; $i < 100; $i++) {
            $callback();
        }
        
        // Benchmark
        $start = microtime(true);
        $result = null;
        
        for ($i = 0; $i < $this->iterations; $i++) {
            $result = $callback();
        }
        
        $end = microtime(true);
        $totalTime = ($end - $start) * 1000; // ms
        $avgTime = $totalTime / $this->iterations;
        
        // SQL::buildSelect retorna array [sql, params]
        $sql = is_array($result) ? $result[0] : (string)$result;
        
        $this->results[$name] = [
            'total_ms' => $totalTime,
            'avg_ms' => $avgTime,
            'sql_sample' => substr($sql, 0, 100) . '...'
        ];
        
        echo "✓ {$name}\n";
        echo "  Total: " . number_format($totalTime, 2) . " ms\n";
        echo "  Promedio: " . number_format($avgTime, 4) . " ms/op\n";
        echo "  SQL: {$sql}\n\n";
    }
    
    private function printSummary(): void {
        echo "\n=== RESUMEN DE MÉTRICAS ===\n\n";
        echo sprintf("%-30s | %-12s | %-12s\n", "Escenario", "Total (ms)", "Promedio (ms)");
        echo str_repeat("-", 60) . "\n";
        
        foreach ($this->results as $name => $data) {
            printf("%-30s | %12.2f | %12.4f\n", 
                $name, 
                $data['total_ms'], 
                $data['avg_ms']
            );
        }
        
        $totalAvg = array_sum(array_column($this->results, 'avg_ms')) / count($this->results);
        echo str_repeat("-", 60) . "\n";
        echo sprintf("%-30s | %12.2f | %12.4f\n", "PROMEDIO GENERAL", 
            array_sum(array_column($this->results, 'total_ms')), 
            $totalAvg
        );
        
        echo "\n📊 Objetivo: Reducir el tiempo promedio por operación\n";
        echo "💡 Nota: Estas métricas son la línea base ANTES de la refactorización OO\n";
    }
}

$benchmark = new BuildSelectBenchmark();
$benchmark->run();
