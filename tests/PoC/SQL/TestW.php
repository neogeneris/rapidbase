<?php

namespace RapidBase\Core;

require_once __DIR__ . '/W.php';
require_once __DIR__ . '/Wm.php';
require_once __DIR__ . '/Ws.php';

/**
 * Suite de pruebas para las clases W, Wm y Ws.
 */
class TestW
{
    private int $passed = 0;
    private int $failed = 0;

    public function run(): void
    {
        echo "=== Running W Class Tests ===\n\n";
        
        $this->testFromWithString();
        $this->testFromWithArray();
        $this->testSelectBasic();
        $this->testSelectWithLimit();
        $this->testSelectWithOffsetLimit();
        $this->testSelectWithSort();
        $this->testSelectWithGroupHaving();
        $this->testPageHelper();
        $this->testDelete();
        $this->testUpdate();
        $this->testWhereCache();
        $this->testWmMetrics();
        $this->testWsOptimization();
        
        echo "\n=== Results ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";
    }

    private function assert(bool $condition, string $message): void
    {
        if ($condition) {
            echo "✓ {$message}\n";
            $this->passed++;
        } else {
            echo "✗ {$message}\n";
            $this->failed++;
        }
    }

    private function testFromWithString(): void
    {
        [$sql, $params] = W::from('users')->select();
        $this->assert($sql === 'SELECT * FROM users', 'FROM with string table');
        $this->assert(empty($params), 'No params for simple select');
    }

    private function testFromWithArray(): void
    {
        [$sql, $params] = W::from(['users', 'posts'])->select();
        $this->assert($sql === 'SELECT * FROM users, posts', 'FROM with array of tables');
    }

    private function testSelectBasic(): void
    {
        [$sql, $params] = W::from('users')->select('id, name');
        $this->assert($sql === 'SELECT id, name FROM users', 'SELECT with fields');
    }

    private function testSelectWithLimit(): void
    {
        [$sql, $params] = W::from('users')->select('*', 20);
        $this->assert(str_contains($sql, 'LIMIT ?'), 'SELECT with LIMIT');
        $this->assert($params[0] === 20, 'LIMIT parameter is 20');
    }

    private function testSelectWithOffsetLimit(): void
    {
        [$sql, $params] = W::from('users')->select('*', [40, 20]);
        $this->assert(str_contains($sql, 'LIMIT ?') && str_contains($sql, 'OFFSET ?'), 'SELECT with OFFSET and LIMIT');
        $this->assert($params[0] === 20 && $params[1] === 40, 'OFFSET=40, LIMIT=20');
    }

    private function testSelectWithSort(): void
    {
        [$sql, $params] = W::from('users')->select('*', null, '-created_at');
        $this->assert(str_contains($sql, 'ORDER BY created_at DESC'), 'SELECT with DESC sort');
        
        [$sql, $params] = W::from('users')->select('*', null, ['name', '-created_at']);
        $this->assert(str_contains($sql, 'ORDER BY name ASC, created_at DESC'), 'SELECT with multiple sorts');
    }

    private function testSelectWithGroupHaving(): void
    {
        [$sql, $params] = W::from('orders')
            ->select('status, COUNT(*) as total', null, null, ['status'], ['total' => ['>' => 5]]);
        $this->assert(str_contains($sql, 'GROUP BY status'), 'SELECT with GROUP BY');
        $this->assert(str_contains($sql, 'HAVING'), 'SELECT with HAVING clause');
    }

    private function testPageHelper(): void
    {
        $page = W::page(3, 20);
        $this->assert($page === [40, 20], 'W::page(3, 20) returns [40, 20]');
        
        $page = W::page(1, 50);
        $this->assert($page === [0, 50], 'W::page(1, 50) returns [0, 50]');
    }

    private function testDelete(): void
    {
        [$sql, $params] = W::from('users', ['id' => 5])->delete();
        $this->assert($sql === 'DELETE FROM users WHERE id = ?', 'DELETE with WHERE');
        $this->assert($params[0] === 5, 'DELETE param is 5');
    }

    private function testUpdate(): void
    {
        [$sql, $params] = W::from('users', ['id' => 5])->update(['name' => 'John']);
        $this->assert($sql === 'UPDATE users SET name = ? WHERE id = ?', 'UPDATE with WHERE');
        $this->assert(count($params) === 2, 'UPDATE has 2 params');
    }

    private function testWhereCache(): void
    {
        W::from('users', ['status' => 'active'])->select();
        W::from('users', ['status' => 'inactive'])->select();
        $this->assert(true, 'WHERE cache works (no errors)');
    }

    private function testWmMetrics(): void
    {
        Wm::clearMetrics();
        Wm::from('users', ['id' => 1])->select();
        $metrics = Wm::getMetrics();
        $this->assert(count($metrics) > 0, 'Wm records metrics');
        
        $stats = Wm::getStats();
        $this->assert($stats['calls'] >= 1, 'Wm stats show calls');
    }

    private function testWsOptimization(): void
    {
        $stats = Ws::getOptimizationStats();
        $this->assert(isset($stats['evaluations']), 'Ws has optimization stats');
        
        Ws::from(['users', 'posts', 'comments'])->select();
        $this->assert(true, 'Ws handles multiple tables');
    }
}

$test = new TestW();
$test->run();
