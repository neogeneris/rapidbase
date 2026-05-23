<?php

declare(strict_types=1);

namespace RapidBase\Endpoints;

use RapidBase\Tdd\TestCase;
use RapidBase\Api\ApiContext;

/**
 * Auto-generated Test Suite for QueryBuilder
 */
class QueryBuilderTest extends TestCase
{
    private ApiContext $mockContext;
    private string $testDbPath;

    public function setUp(): void
    {
        parent::setUp();
        $this->testDbPath = tempnam(sys_get_temp_dir(), 'qb_test_') . '.sqlite';
        $this->mockContext = new ApiContext();
        $this->mockContext->params['connectionId'] = 'test_conn';
        
        // Setup test database
        \RapidBase\Core\DB::setup("sqlite:{$this->testDbPath}", '', '', 'test_conn');
        
        // Create a test table using raw SQL
        \RapidBase\Core\X::con('test_conn')->raw("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, name TEXT, email TEXT)");
        \RapidBase\Core\X::con('test_conn')->raw("INSERT INTO users (name, email) VALUES ('John Doe', 'john@example.com')");
        \RapidBase\Core\X::con('test_conn')->raw("INSERT INTO users (name, email) VALUES ('Jane Smith', 'jane@example.com')");
    }

    public function tearDown(): void
    {
        if (file_exists($this->testDbPath)) {
            unlink($this->testDbPath);
        }
        parent::tearDown();
    }

    public function testAutoQuery(): void
    {
        $this->env()->test('verify autoQuery generates valid SELECT statement', function($test) {
            $endpoint = new QueryBuilder();
            $endpoint->setContext($this->mockContext);
            
            $result = $endpoint->autoQuery(json_encode(['users']));
            
            $test->assertTrue($result['success'], "Query should succeed");
            $test->assertStringContainsString('SELECT', $result['sql'], "SQL should contain SELECT");
            $test->assertStringContainsString('FROM', $result['sql'], "SQL should contain FROM");
            $test->assertStringContainsString('users', $result['sql'], "SQL should reference users table");
        });
    }

    public function testAutoQueryWithColumns(): void
    {
        $this->env()->test('verify autoQuery with specific columns', function($test) {
            $this->mockContext->params['columns'] = json_encode(['id', 'name']);
            
            $endpoint = new QueryBuilder();
            $endpoint->setContext($this->mockContext);
            
            $result = $endpoint->autoQuery(json_encode(['users']));
            
            $test->assertTrue($result['success'], "Query should succeed");
            $test->assertStringContainsString('id', $result['sql'], "SQL should contain id column");
            $test->assertStringContainsString('name', $result['sql'], "SQL should contain name column");
        });
    }

    public function testAutoQueryWithPagination(): void
    {
        $this->env()->test('verify autoQuery with pagination parameters', function($test) {
            $this->mockContext->params['page'] = 1;
            $this->mockContext->params['limit'] = 10;
            
            $endpoint = new QueryBuilder();
            $endpoint->setContext($this->mockContext);
            
            $result = $endpoint->autoQuery(json_encode(['users']));
            
            $test->assertTrue($result['success'], "Query should succeed");
            $test->assertStringContainsString('LIMIT', $result['sql'], "SQL should contain LIMIT clause");
        });
    }

    public function testAutoQueryWithSort(): void
    {
        $this->env()->test('verify autoQuery with sort parameters', function($test) {
            $this->mockContext->params['sort'] = json_encode([['field' => 'name', 'dir' => 'ASC']]);
            
            $endpoint = new QueryBuilder();
            $endpoint->setContext($this->mockContext);
            
            $result = $endpoint->autoQuery(json_encode(['users']));
            
            $test->assertTrue($result['success'], "Query should succeed");
            $test->assertStringContainsString('ORDER BY', $result['sql'], "SQL should contain ORDER BY clause");
        });
    }

    public function testAutoQueryInvalidTables(): void
    {
        $this->env()->test('verify autoQuery handles invalid tables list', function($test) {
            $endpoint = new QueryBuilder();
            $endpoint->setContext($this->mockContext);
            
            $result = $endpoint->autoQuery('invalid json');
            
            $test->assertFalse($result['success'], "Should fail with invalid JSON");
            $test->assertArrayHasKey('error', $result, "Should return error message");
        });
    }

    public function testAutoQueryEmptyTables(): void
    {
        $this->env()->test('verify autoQuery handles empty tables list', function($test) {
            $endpoint = new QueryBuilder();
            $endpoint->setContext($this->mockContext);
            
            $result = $endpoint->autoQuery(json_encode([]));
            
            $test->assertFalse($result['success'], "Should fail with empty tables");
            $test->assertEquals('Invalid tables list', $result['error'], "Should return specific error");
        });
    }

    public function testSetContext(): void
    {
        $this->env()->test('verify setContext properly assigns context', function($test) {
            $endpoint = new QueryBuilder();
            $endpoint->setContext($this->mockContext);
            
            // Use reflection to verify context was set since getContext() doesn't exist
            $reflection = new \ReflectionClass($endpoint);
            $property = $reflection->getProperty('context');
            $property->setAccessible(true);
            $context = $property->getValue($endpoint);
            
            $test->assertEquals($this->mockContext, $context, "Context should be set correctly");
        });
    }

    public function testDescribe(): void
    {
        $this->env()->test('verify describe endpoint method exists', function($test) {
            $endpoint = new QueryBuilder();
            $test->assertTrue(method_exists($endpoint, 'describe'), "describe method should exist");
        });
    }

    public function testVersion(): void
    {
        $this->env()->test('verify version endpoint method exists', function($test) {
            $endpoint = new QueryBuilder();
            $test->assertTrue(method_exists($endpoint, 'version'), "version method should exist");
        });
    }

    public function testCatalog(): void
    {
        $this->env()->test('verify catalog endpoint method exists', function($test) {
            $endpoint = new QueryBuilder();
            $test->assertTrue(method_exists($endpoint, 'catalog'), "catalog method should exist");
        });
    }

}
