<?php
/**
 * Benchmark de Comparación: Antes vs Después de la Refactorización
 * 
 * Compara el rendimiento y la correctitud del SQL generado entre:
 * 1. La implementación antigua (SQL.php con array $parts)
 * 2. La nueva implementación refactorizada (usando Builders)
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use RapidBase\Core\SQL;
use RapidBase\Core\SQL\Builders\SelectBuilder;
use RapidBase\Core\SQL\Builders\Field;
use RapidBase\Core\SQL\Builders\Table;
use RapidBase\Core\SQL\Builders\Join;

class RefactorComparison
{
    private array $tests = [
        'Select Simple',
        'Select con WHERE',
        'Campos con Alias',
        'FROM con Alias',
        'JOIN Simple',
        'JOIN con Alias',
        'Multiple JOINs',
        'WHERE complejo',
        'ORDER BY',
        'LIMIT/OFFSET',
        'GROUP BY',
        'HAVING',
        'Consulta Compleja'
    ];

    public function run(): void
    {
        echo "========================================\n";
        echo "  BENCHMARK: ANTES VS DESPUÉS\n";
        echo "  Refactorización de SQL.php\n";
        echo "========================================\n\n";

        // Pruebas de correctitud
        $this->runCorrectnessTests();
        
        // Pruebas de rendimiento
        $this->runPerformanceTests();
    }

    private function runCorrectnessTests(): void
    {
        echo "--- PRUEBAS DE CORRECTITUD ---\n\n";
        
        foreach ($this->tests as $testName) {
            $methodName = 'test' . str_replace(' ', '', $testName);
            if (method_exists($this, $methodName)) {
                echo "Test: $testName\n";
                $this->$methodName();
                echo "\n";
            }
        }
    }

    private function testSelectSimple(): void
    {
        $oldData = SQL::buildSelect(['id', 'name'], 'users');
        $oldQuery = $oldData[0];
        
        $builder = new SelectBuilder();
        $builder->select = ['id', 'name'];
        $builder->from = 'users';
        $newData = $builder->build();
        $newQuery = $newData[0];
        
        echo "  Antiguo: $oldQuery\n";
        echo "  Nuevo:   $newQuery\n";
        echo "  Match:   " . ($oldQuery === $newQuery ? "✓" : "✗") . "\n";
    }

    private function testSelectconWHERE(): void
    {
        $oldData = SQL::buildSelect(['id', 'name'], 'users', ['status' => ['=', 'active']]);
        $oldQuery = $oldData[0];
        
        $builder = new SelectBuilder();
        $builder->select = ['id', 'name'];
        $builder->from = 'users';
        $builder->where = ['status' => ['=', 'active']];
        $newData = $builder->build();
        $newQuery = $newData[0];
        
        echo "  Antiguo: $oldQuery\n";
        echo "  Nuevo:   $newQuery\n";
        echo "  Match:   " . ($oldQuery === $newQuery ? "✓" : "✗") . "\n";
    }

    private function testCamposconAlias(): void
    {
        $oldData = SQL::buildSelect([['id', 'user_id'], ['name', 'user_name']], 'users');
        $oldQuery = $oldData[0];
        
        $builder = new SelectBuilder();
        $builder->select = [
            new Field('id', 'user_id'),
            new Field('name', 'user_name')
        ];
        $builder->from = 'users';
        $newData = $builder->build();
        $newQuery = $newData[0];
        
        echo "  Antiguo: $oldQuery\n";
        echo "  Nuevo:   $newQuery\n";
        echo "  Match:   " . ($oldQuery === $newQuery ? "✓" : "✗") . "\n";
    }

    private function testFROMconAlias(): void
    {
        $oldData = SQL::buildSelect(['id', 'name'], [['users', 'u']]);
        $oldQuery = $oldData[0];
        
        $builder = new SelectBuilder();
        $builder->select = ['id', 'name'];
        $builder->from = new Table('users', 'u');
        $newData = $builder->build();
        $newQuery = $newData[0];
        
        echo "  Antiguo: $oldQuery\n";
        echo "  Nuevo:   $newQuery\n";
        echo "  Match:   " . ($oldQuery === $newQuery ? "✓" : "✗") . "\n";
    }

    private function testJOINSimple(): void
    {
        $oldData = SQL::buildSelect(['id', 'total'], 'orders', [], [], [], [], 0, 10, ['users' => ['on' => 'orders.user_id = users.id']]);
        $oldQuery = $oldData[0];
        
        $builder = new SelectBuilder();
        $builder->select = ['id', 'total'];
        $builder->from = 'orders';
        $builder->join = [new Join('users', 'orders.user_id = users.id')];
        $newData = $builder->build();
        $newQuery = $newData[0];
        
        echo "  Antiguo: $oldQuery\n";
        echo "  Nuevo:   $newQuery\n";
        echo "  Match:   " . ($oldQuery === $newQuery ? "✓" : "✗") . "\n";
    }

    private function testJOINconAlias(): void
    {
        $oldData = SQL::buildSelect(['u.id', 'o.total'], [['orders', 'o']], [], [], [], [], 0, 10, [['users', 'u', 'on' => 'o.user_id = u.id']]);
        $oldQuery = $oldData[0];
        
        $builder = new SelectBuilder();
        $builder->select = ['u.id', 'o.total'];
        $builder->from = new Table('orders', 'o');
        $builder->join = [new Join(new Table('users', 'u'), 'o.user_id = u.id')];
        $newData = $builder->build();
        $newQuery = $newData[0];
        
        echo "  Antiguo: $oldQuery\n";
        echo "  Nuevo:   $newQuery\n";
        echo "  Match:   " . ($oldQuery === $newQuery ? "✓" : "✗") . "\n";
    }

    private function testMultipleJOINs(): void
    {
        $oldData = SQL::buildSelect(['u.id', 'o.total', 'p.name'], [['orders', 'o']], [], [], [], [], 0, 10, [
            ['users', 'u', 'on' => 'o.user_id = u.id'],
            ['products', 'p', 'on' => 'o.product_id = p.id']
        ]);
        $oldQuery = $oldData[0];
        
        $builder = new SelectBuilder();
        $builder->select = ['u.id', 'o.total', 'p.name'];
        $builder->from = new Table('orders', 'o');
        $builder->join = [
            new Join(new Table('users', 'u'), 'o.user_id = u.id'),
            new Join(new Table('products', 'p'), 'o.product_id = p.id')
        ];
        $newData = $builder->build();
        $newQuery = $newData[0];
        
        echo "  Antiguo: $oldQuery\n";
        echo "  Nuevo:   $newQuery\n";
        echo "  Match:   " . ($oldQuery === $newQuery ? "✓" : "✗") . "\n";
    }

    private function testWHEREcomplejo(): void
    {
        $oldData = SQL::buildSelect(['id', 'name'], 'users', [
            'status' => 'active',
            'age' => ['>' => 18],
            'OR' => [['role' => 'admin'], ['role' => 'moderator']]
        ]);
        $oldQuery = $oldData[0];
        
        $builder = new SelectBuilder();
        $builder->select = ['id', 'name'];
        $builder->from = 'users';
        $builder->where = [
            'status' => 'active',
            'age' => ['>' => 18],
            'OR' => [['role' => 'admin'], ['role' => 'moderator']]
        ];
        $newData = $builder->build();
        $newQuery = $newData[0];
        
        echo "  Antiguo: $oldQuery\n";
        echo "  Nuevo:   $newQuery\n";
        echo "  Match:   " . ($oldQuery === $newQuery ? "✓" : "✗") . "\n";
    }

    private function testORDERBY(): void
    {
        $oldData = SQL::buildSelect(['id', 'name'], 'users', [], [], [], ['name' => 'ASC', '-created_at']);
        $oldQuery = $oldData[0];
        
        $builder = new SelectBuilder();
        $builder->select = ['id', 'name'];
        $builder->from = 'users';
        $builder->orderBy = ['name' => 'ASC', '-created_at'];
        $newData = $builder->build();
        $newQuery = $newData[0];
        
        echo "  Antiguo: $oldQuery\n";
        echo "  Nuevo:   $newQuery\n";
        echo "  Match:   " . ($oldQuery === $newQuery ? "✓" : "✗") . "\n";
    }

    private function testLIMITOFFSET(): void
    {
        $oldData = SQL::buildSelect(['id', 'name'], 'users', [], [], [], [], 2, 20);
        $oldQuery = $oldData[0];
        
        $builder = new SelectBuilder();
        $builder->select = ['id', 'name'];
        $builder->from = 'users';
        $builder->limit = 20;
        $builder->offset = 20;
        $newData = $builder->build();
        $newQuery = $newData[0];
        
        echo "  Antiguo: $oldQuery\n";
        echo "  Nuevo:   $newQuery\n";
        echo "  Match:   " . ($oldQuery === $newQuery ? "✓" : "✗") . "\n";
    }

    private function testGROUPBY(): void
    {
        $oldData = SQL::buildSelect(['status', 'COUNT(*) as count'], 'users', [], ['status']);
        $oldQuery = $oldData[0];
        
        $builder = new SelectBuilder();
        $builder->select = ['status', 'COUNT(*) as count'];
        $builder->from = 'users';
        $builder->groupBy = ['status'];
        $newData = $builder->build();
        $newQuery = $newData[0];
        
        echo "  Antiguo: $oldQuery\n";
        echo "  Nuevo:   $newQuery\n";
        echo "  Match:   " . ($oldQuery === $newQuery ? "✓" : "✗") . "\n";
    }

    private function testHAVING(): void
    {
        $oldData = SQL::buildSelect(['status', 'COUNT(*) as count'], 'users', [], ['status'], ['count' => ['>' => 5]]);
        $oldQuery = $oldData[0];
        
        $builder = new SelectBuilder();
        $builder->select = ['status', 'COUNT(*) as count'];
        $builder->from = 'users';
        $builder->groupBy = ['status'];
        $builder->having = ['count' => ['>' => 5]];
        $newData = $builder->build();
        $newQuery = $newData[0];
        
        echo "  Antiguo: $oldQuery\n";
        echo "  Nuevo:   $newQuery\n";
        echo "  Match:   " . ($oldQuery === $newQuery ? "✓" : "✗") . "\n";
    }

    private function testConsultaCompleja(): void
    {
        $oldData = SQL::buildSelect(
            [['u.id', 'user_id'], ['u.name', 'user_name'], ['COUNT(o.id)', 'order_count']],
            [['users', 'u']],
            ['u.active' => 1],
            ['u.status'],
            ['order_count' => ['>' => 5]],
            ['order_count' => 'DESC'],
            1,
            50,
            [['orders', 'o', 'on' => 'u.id = o.user_id']]
        );
        $oldQuery = $oldData[0];
        
        $builder = new SelectBuilder();
        $builder->select = [
            new Field('u.id', 'user_id'),
            new Field('u.name', 'user_name'),
            new Field('COUNT(o.id)', 'order_count')
        ];
        $builder->from = new Table('users', 'u');
        $builder->where = ['u.active' => 1];
        $builder->groupBy = ['u.status'];
        $builder->having = ['order_count' => ['>' => 5]];
        $builder->orderBy = ['order_count' => 'DESC'];
        $builder->limit = 50;
        $builder->offset = 0;
        $builder->join = [new Join(new Table('orders', 'o'), 'u.id = o.user_id')];
        $newData = $builder->build();
        $newQuery = $newData[0];
        
        echo "  Antiguo: $oldQuery\n";
        echo "  Nuevo:   $newQuery\n";
        echo "  Match:   " . ($oldQuery === $newQuery ? "✓" : "✗") . "\n";
    }

    private function runPerformanceTests(): void
    {
        echo "--- PRUEBAS DE RENDIMIENTO ---\n\n";
        
        $iterations = 10000;
        echo "Iteraciones: $iterations\n\n";
        
        // Test 1: buildSelect antiguo
        echo "Test 1: SQL::buildSelect() (implementación actual con Builder)\n";
        $start = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            SQL::buildSelect(['id', 'name', 'email'], 'users', ['active' => 1], [], [], ['name' => 'ASC'], 1, 10);
        }
        $time1 = (microtime(true) - $start) * 1000;
        echo "  Tiempo: " . number_format($time1, 2) . " ms\n";
        echo "  Por operación: " . number_format($time1 / $iterations, 4) . " ms\n\n";
        
        // Test 2: Builder directo
        echo "Test 2: SelectBuilder directo\n";
        $start = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $builder = new SelectBuilder();
            $builder->select = ['id', 'name', 'email'];
            $builder->from = 'users';
            $builder->where = ['active' => 1];
            $builder->orderBy = ['name' => 'ASC'];
            $builder->limit = 10;
            $builder->offset = 0;
            $builder->build();
        }
        $time2 = (microtime(true) - $start) * 1000;
        echo "  Tiempo: " . number_format($time2, 2) . " ms\n";
        echo "  Por operación: " . number_format($time2 / $iterations, 4) . " ms\n\n";
        
        // Comparación
        echo "--- COMPARACIÓN ---\n";
        $diff = $time2 - $time1;
        $percent = $time1 > 0 ? (($time2 - $time1) / $time1) * 100 : 0;
        echo "  Diferencia: " . number_format($diff, 2) . " ms (" . number_format($percent, 2) . "%)\n";
        if ($diff > 0) {
            echo "  El Builder directo es más lento por " . number_format($diff, 2) . " ms\n";
        } elseif ($diff < 0) {
            echo "  El Builder directo es más rápido por " . number_format(abs($diff), 2) . " ms\n";
        } else {
            echo "  Rendimiento similar\n";
        }
    }
}

// Ejecutar benchmark
$benchmark = new RefactorComparison();
$benchmark->run();
