<?php

namespace RapidBase\PoC\SQLEngine_v3;

require_once __DIR__ . '/Q.php';
require_once __DIR__ . '/Q3.php';

/**
 * Benchmark comparativo: 2 vs 3 eslabones
 */
class BenchmarkChains {
    private int $iterations = 10000;
    
    public function run(): void {
        echo "=== Benchmark: Longitud de Cadena ===\n";
        echo "Iteraciones: {$this->iterations}\n\n";
        
        // Test 1: SELECT simple
        $this->test('select_simple', 
            fn() => \RapidBase\PoC\SQLEngine_v3\Q::from('users', ['status' => 'active'])->select('id, name'),
            fn() => \RapidBase\PoC\SQLEngine_v3\Q3::from('users', ['status' => 'active'])->select('id, name')
        );
        
        // Test 2: SELECT con ordenamiento y límite
        $this->test('select_order_limit',
            fn() => \RapidBase\PoC\SQLEngine_v3\Q::from('users', ['status' => 'active'], ['name'], [10])->select(),
            fn() => \RapidBase\PoC\SQLEngine_v3\Q3::from('users', ['status' => 'active'])->configure(['name'], [10])->select()
        );
        
        // Test 3: SELECT complejo
        $this->test('select_complex',
            fn() => \RapidBase\PoC\SQLEngine_v3\Q::from('orders', ['status' => 'pending'], ['-created_at'], [20, 50], ['user_id'])->select(),
            fn() => \RapidBase\PoC\SQLEngine_v3\Q3::from('orders', ['status' => 'pending'])
                     ->configure(['-created_at'], [20, 50], ['user_id'])
                     ->select()
        );
        
        // Test 4: DELETE
        $this->test('delete',
            fn() => \RapidBase\PoC\SQLEngine_v3\Q::from('users', ['id' => 1])->exec('delete'),
            fn() => \RapidBase\PoC\SQLEngine_v3\Q3::from('users', ['id' => 1])->exec('delete')
        );
        
        // Test 5: COUNT
        $this->test('count',
            fn() => \RapidBase\PoC\SQLEngine_v3\Q::from('users', ['status' => 'active'])->exec('count'),
            fn() => \RapidBase\PoC\SQLEngine_v3\Q3::from('users', ['status' => 'active'])->exec('count')
        );
        
        // Test 6: EXISTS
        $this->test('exists',
            fn() => \RapidBase\PoC\SQLEngine_v3\Q::from('users', ['id' => 1])->exec('exists'),
            fn() => \RapidBase\PoC\SQLEngine_v3\Q3::from('users', ['id' => 1])->exec('exists')
        );
        
        // Test 7: UPDATE
        $this->test('update',
            fn() => \RapidBase\PoC\SQLEngine_v3\Q::from('users', ['id' => 1])->exec('update', ['name' => 'Test']),
            fn() => \RapidBase\PoC\SQLEngine_v3\Q3::from('users', ['id' => 1])->exec('update', ['name' => 'Test'])
        );
        
        echo "\n=== Resumen ===\n";
        echo "2 eslabones: Q::from()->select()\n";
        echo "3 eslabones: Q3::from()->configure()->select()\n";
    }
    
    private function test(string $name, callable $v2, callable $v3): void {
        // Calentar
        for ($i = 0; $i < 100; $i++) {
            $v2();
            $v3();
        }
        
        // Medir v2 (2 eslabones)
        $start = microtime(true);
        $memStart = memory_get_usage();
        for ($i = 0; $i < $this->iterations; $i++) {
            $v2();
        }
        $timeV2 = microtime(true) - $start;
        $memV2 = memory_get_usage() - $memStart;
        
        // Medir v3 (3 eslabones)
        $start = microtime(true);
        $memStart = memory_get_usage();
        for ($i = 0; $i < $this->iterations; $i++) {
            $v3();
        }
        $timeV3 = microtime(true) - $start;
        $memV3 = memory_get_usage() - $memStart;
        
        $diff = (($timeV3 - $timeV2) / $timeV2) * 100;
        $winner = $diff > 0 ? '2 eslabones' : '3 eslabones';
        $symbol = $diff > 0 ? '🏆' : '⚡';
        
        printf("%-20s | 2 esl: %8.2f ms | 3 esl: %8.2f ms | Diff: %+6.2f%% | %s %s\n",
            $name, $timeV2*1000, $timeV3*1000, $diff, $symbol, $winner
        );
    }
}

// Ejecutar
$bench = new BenchmarkChains();
$bench->run();
