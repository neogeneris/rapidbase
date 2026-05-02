<?php

namespace RapidBase\PoC\Flat;

require_once __DIR__ . '/Q.php';
require_once __DIR__ . '/QType.php';
require_once __DIR__ . '/ConditionParser.php';
require_once __DIR__ . '/JoinStrategy.php';
require_once __DIR__ . '/DeterministicJoin.php';
require_once __DIR__ . '/SqlCompiler.php';

/**
 * Benchmark completo para Flat Engine.
 * Compara todas las operaciones CRUD + INSERT múltiple.
 */
class BenchFlat
{
    private const ITERATIONS = 10000;

    public function run(): void
    {
        echo "🚀 Benchmark Flat Engine - " . self::ITERATIONS . " iteraciones\n\n";

        $results = [];

        // SELECT Simple
        $results['select_simple'] = $this->bench('SELECT Simple', function() {
            return Q::from('users', ['status' => 'active'])
                ->build(QType::SELECT, 'id, name');
        });

        // SELECT Complejo
        $results['select_complex'] = $this->bench('SELECT Complejo', function() {
            return Q::from('users', [
                'status' => 'active',
                '_order' => '-created_at',
                '_limit' => [0, 20],
                '_group' => 'role'
            ])->build(QType::SELECT, 'id, name, COUNT(*) as total');
        });

        // INSERT Múltiple (100 registros)
        $datosInsert = array_map(function($i) {
            return ['name' => "User $i", 'email' => "user$i@test.com", 'role' => 'user'];
        }, range(1, 100));

        $results['insert_multi'] = $this->bench('INSERT Multi (100)', function() use ($datosInsert) {
            return Q::from('users')->build(QType::INSERT, $datosInsert);
        });

        // UPDATE
        $results['update'] = $this->bench('UPDATE', function() {
            return Q::from('users', ['id' => 1])
                ->build(QType::UPDATE, ['name' => 'Nuevo Nombre', 'email' => 'nuevo@test.com']);
        });

        // DELETE
        $results['delete'] = $this->bench('DELETE', function() {
            return Q::from('users', ['status' => 'inactive'])
                ->build(QType::DELETE);
        });

        // COUNT
        $results['count'] = $this->bench('COUNT', function() {
            return Q::from('orders', ['user_id' => 42])
                ->build(QType::COUNT);
        });

        // EXISTS
        $results['exists'] = $this->bench('EXISTS', function() {
            return Q::from('users', ['email' => 'test@example.com'])
                ->build(QType::EXISTS);
        });

        // Resumen
        $total = array_sum($results);
        echo "\n" . str_repeat('-', 60) . "\n";
        echo sprintf("%-25s %15s\n", 'Operación', 'Tiempo (ms)');
        echo str_repeat('-', 60) . "\n";
        
        foreach ($results as $op => $time) {
            printf("%-25s %15.2f\n", $op, $time);
        }
        
        echo str_repeat('-', 60) . "\n";
        printf("%-25s %15.2f ms\n", 'TOTAL', $total);
        echo str_repeat('-', 60) . "\n\n";

        // Ejemplos de salida SQL
        echo "📝 Ejemplos de SQL generado:\n\n";
        
        list($sql, $params) = Q::from('users', ['status' => 'active'])
            ->build(QType::SELECT, 'id, name');
        echo "SELECT Simple:\n  $sql\n  Params: [" . implode(', ', array_map(fn($v) => is_string($v) ? "'$v'" : $v, $params)) . "]\n\n";

        list($sql, $params) = Q::from('users')->build(QType::INSERT, [
            ['name' => 'Ana', 'email' => 'ana@test.com'],
            ['name' => 'Luis', 'email' => 'luis@test.com']
        ]);
        echo "INSERT Multi:\n  $sql\n  Params: [" . implode(', ', array_map(fn($v) => is_string($v) ? "'$v'" : $v, $params)) . "]\n\n";

        list($sql, $params) = Q::from('users', ['id' => 1])
            ->build(QType::UPDATE, ['name' => 'Nuevo']);
        echo "UPDATE:\n  $sql\n  Params: [" . implode(', ', array_map(fn($v) => is_string($v) ? "'$v'" : $v, $params)) . "]\n\n";
    }

    private function bench(string $name, callable $fn): float
    {
        $start = microtime(true);
        
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $fn();
        }
        
        $end = microtime(true);
        $time = ($end - $start) * 1000; // ms
        
        printf("%-25s ... %.2f ms\n", $name, $time);
        return $time;
    }
}

// Ejecutar si se llama directamente
if (php_sapi_name() === 'cli' && realpath($argv[0]) === realpath(__FILE__)) {
    $bench = new BenchFlat();
    $bench->run();
}
