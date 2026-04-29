<?php
/**
 * Unit tests for Q::select() method.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../../vendor/autoload.php';

use RapidBase\Core\SQL\Q;
use RapidBase\Core\SchemaMap;

// Setup a minimal schema map for testing
$testMap = [
    'users' => ['alias' => 'u', 'fields' => ['id', 'name', 'email', 'status']],
    'posts' => ['alias' => 'p', 'fields' => ['id', 'user_id', 'title', 'content']],
];
SchemaMap::setMap($testMap, 'default');

echo "Testing Q::select()...\n\n";

// Test 1: Basic select with single table
list($sql, $params) = Q::from('users', ['status' => 'active'])->select();
assertContains($sql, 'SELECT', 'Basic select contains SELECT keyword');
assertContains($sql, 'FROM', 'Basic select contains FROM keyword');
assertContains($sql, 'WHERE', 'Basic select contains WHERE keyword');
assertEq($params, ['active'], 'Basic select has correct params');

// Test 2: Select with specific fields
list($sql, $params) = Q::from('users', ['id' => 5])->fields(['id', 'name'])->select();
assertContains($sql, 'id, name', 'Select with specific fields');
assertEq($params, [5], 'Params match filter');

// Test 3: Select with pagination (array format)
list($sql, $params) = Q::from('users')->select('*', [10, 20]);
assertContains($sql, 'LIMIT', 'Select with pagination contains LIMIT');
assertEq($params, [20, 10], 'Pagination params in correct order (limit, offset)');

// Test 4: Select with limit only
list($sql, $params) = Q::from('users')->select('*', 50);
assertContains($sql, 'LIMIT ?', 'Select with limit only');
assertEq($params, [50], 'Limit param is correct');

// Test 5: Select with ordering
list($sql, $params) = Q::from('users')->select('*', null, ['-created_at']);
assertContains($sql, 'ORDER BY', 'Select with ordering contains ORDER BY');
assertContains($sql, 'DESC', 'Order contains DESC for - prefix');
assertContains($sql, '"created_at"', 'Field is quoted in ORDER BY');

// Test 6: Select with multiple sort fields
list($sql, $params) = Q::from('users')->select('*', null, ['name', '-created_at']);
assertContains($sql, '"name" ASC', 'First field defaults to ASC');
assertContains($sql, '"created_at" DESC', 'Second field with - prefix is DESC');

// Test 7: Select using static page helper
list($sql, $params) = Q::from('users')->select('*', Q::page(2, 10));
assertEq($params, [10, 10], 'Page helper generates correct offset and limit');

// Test 8: Select with fluent methods
list($sql, $params) = Q::from('users', ['status' => 'active'])
    ->fields(['id', 'name'])
    ->orderBy(['-created_at'])
    ->limit(100)
    ->select();
assertContains($sql, 'id, name', 'Fluent select has correct fields');
assertContains($sql, 'ORDER BY', 'Fluent select has ORDER BY');
assertContains($sql, 'LIMIT', 'Fluent select has LIMIT');
assertEq($params, ['active', 100], 'Fluent select has merged params');

// Test 9: Select with empty filter
list($sql, $params) = Q::from('users')->select();
assertEq($params, [], 'Empty filter produces empty params');
assertContains($sql, 'FROM', 'Query still valid without WHERE');

// Test 10: Select with multiple conditions
list($sql, $params) = Q::from('users', ['status' => 'active', 'name' => 'John'])->select();
assertEq(count($params), 2, 'Multiple conditions have multiple params');
assertContains($sql, 'AND', 'Multiple conditions joined with AND');

echo "\n";
