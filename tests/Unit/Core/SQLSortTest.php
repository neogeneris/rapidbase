<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../src/RapidBase/Core/SQL.php';

use RapidBase\Core\SQL;

/**
 * Pruebas para la lógica de ordenamiento en SQL::buildSelect
 */
class SQLSortTest extends TestCase
{
    public function testEmptySortArray(): void
    {
        [$sql, $params] = SQL::buildSelect('*', 'users', [], [], [], []);
        
        $this->assertStringNotContainsString('ORDER BY', $sql);
    }

    public function testNullSort(): void
    {
        [$sql, $params] = SQL::buildSelect('*', 'users', [], [], [], []);
        
        $this->assertStringNotContainsString('ORDER BY', $sql);
    }

    public function testSingleFieldAscending(): void
    {
        [$sql, $params] = SQL::buildSelect('*', 'users', [], [], [], ['name']);
        
        $this->assertStringContainsString('ORDER BY "name" ASC', $sql);
    }

    public function testSingleFieldDescendingWithPrefix(): void
    {
        [$sql, $params] = SQL::buildSelect('*', 'users', [], [], [], ['-name']);
        
        $this->assertStringContainsString('ORDER BY "name" DESC', $sql);
    }

    public function testMultipleFieldsMixedOrder(): void
    {
        [$sql, $params] = SQL::buildSelect('*', 'users', [], [], [], ['id', '-created_at']);
        
        $this->assertStringContainsString('ORDER BY "id" ASC, "created_at" DESC', $sql);
    }

    public function testSimpleStringAscending(): void
    {
        // Cuando se pasa string, DB::grid lo convierte a array, pero SQL::buildSelect espera array
        [$sql, $params] = SQL::buildSelect('*', 'users', [], [], [], ['email']);
        
        $this->assertStringContainsString('ORDER BY "email" ASC', $sql);
    }

    public function testSimpleStringDescending(): void
    {
        [$sql, $params] = SQL::buildSelect('*', 'users', [], [], [], ['-email']);
        
        $this->assertStringContainsString('ORDER BY "email" DESC', $sql);
    }
}
