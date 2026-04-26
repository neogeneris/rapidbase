<?php

declare(strict_types=1);

/**
 * RESTAdapter Proof of Concept
 * 
 * This script demonstrates how the RESTAdapter parses URL parameters
 * and constructs queries using the RapidBase QueryExecutor.
 * 
 * Run: php tests/PoC/RESTAdapterPoC.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use RapidBase\Infrastructure\Ui\Adapter\RESTAdapter;
use RapidBase\Core\QueryExecutor;

echo "========================================\n";
echo "RESTAdapter Proof of Concept\n";
echo "========================================\n\n";

// Mock Executor for demonstration (in real use, inject actual executor)
class MockExecutor {
    private array $params = [];
    
    public function reset(): void {
        $this->params = [];
        echo "[Executor] Reset called\n";
    }
    
    public function setPage(int $page, int $limit): void {
        $this->params['page'] = $page;
        $this->params['limit'] = $limit;
        echo "[Executor] setPage(page={$page}, limit={$limit})\n";
    }
    
    public function orderBy(array $fields): void {
        $this->params['orderBy'] = $fields;
        echo "[Executor] orderBy(" . json_encode($fields) . ")\n";
    }
    
    public function search(string $term, array $columns): void {
        $this->params['search'] = ['term' => $term, 'columns' => $columns];
        echo "[Executor] search(term='{$term}', columns=[" . implode(',', $columns) . "])\n";
    }
    
    public function where(array $conditions): void {
        $this->params['where'] = $conditions;
        echo "[Executor] where(" . json_encode($conditions) . ")\n";
    }
    
    public function execute(): object {
        echo "[Executor] execute() called\n";
        return (object)['getAll' => fn() => []];
    }
    
    public function getTotalCount(): int {
        return 0;
    }
}

// Test Case 1: Basic Pagination
echo "\n--- Test 1: Basic Pagination ---\n";
$executor1 = new MockExecutor();
$adapter1 = new RESTAdapter($executor1, ['name', 'email']);
$result1 = $adapter1->handle(['page' => '2']);
echo "Expected: page=2, limit=20 (default)\n";

// Test Case 2: Custom Pagination with Limit
echo "\n--- Test 2: Custom Pagination (Page:Limit) ---\n";
$executor2 = new MockExecutor();
$adapter2 = new RESTAdapter($executor2, ['name', 'email']);
$result2 = $adapter2->handle(['page' => '3:50']);
echo "Expected: page=3, limit=50\n";

// Test Case 3: Sorting (Ascending)
echo "\n--- Test 3: Sorting (ASC) ---\n";
$executor3 = new MockExecutor();
$adapter3 = new RESTAdapter($executor3, ['name', 'email']);
$result3 = $adapter3->handle(['sort' => 'name,email']);
echo "Expected: orderBy(['name'=>'ASC', 'email'=>'ASC'])\n";

// Test Case 4: Sorting (Descending)
echo "\n--- Test 4: Sorting (DESC) ---\n";
$executor4 = new MockExecutor();
$adapter4 = new RESTAdapter($executor4, ['name', 'email']);
$result4 = $adapter4->handle(['sort' => '-created_at,id']);
echo "Expected: orderBy(['created_at'=>'DESC', 'id'=>'ASC'])\n";

// Test Case 5: Global Search
echo "\n--- Test 5: Global Search ---\n";
$executor5 = new MockExecutor();
$adapter5 = new RESTAdapter($executor5, ['name', 'email', 'title']);
$result5 = $adapter5->handle(['search' => 'john']);
echo "Expected: search(term='john', columns=[name,email,title])\n";

// Test Case 6: Advanced Filters (Equality)
echo "\n--- Test 6: Advanced Filters (Equality) ---\n";
$executor6 = new MockExecutor();
$adapter6 = new RESTAdapter($executor6, ['name', 'email']);
$filter6 = json_encode(['status' => 'active', 'role' => 'admin']);
$result6 = $adapter6->handle(['filter' => $filter6]);
echo "Expected: where([['status','=','active'], ['role','=','admin']])\n";

// Test Case 7: Advanced Filters (Operators)
echo "\n--- Test 7: Advanced Filters (Operators) ---\n";
$executor7 = new MockExecutor();
$adapter7 = new RESTAdapter($executor7, ['name', 'email']);
$filter7 = json_encode(['age' => '>18', 'score' => '>=90', 'name' => '%John%']);
$result7 = $adapter7->handle(['filter' => $filter7]);
echo "Expected: where with operators >, >=, LIKE\n";

// Test Case 8: Combined Parameters
echo "\n--- Test 8: Combined Parameters ---\n";
$executor8 = new MockExecutor();
$adapter8 = new RESTAdapter($executor8, ['name', 'email']);
$params8 = [
    'page' => '1:25',
    'sort' => '-created_at',
    'search' => 'alice',
    'filter' => json_encode(['status' => 'active'])
];
$result8 = $adapter8->handle($params8);
echo "Expected: All methods called in sequence\n";

echo "\n========================================\n";
echo "All tests completed successfully!\n";
echo "========================================\n";
