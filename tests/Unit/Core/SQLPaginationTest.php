<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../src/RapidBase/Core/SQL.php';

use RapidBase\Core\SQL;

/**
 * Pruebas para la lógica de paginación en SQL::buildSelect
 */
class SQLPaginationTest extends TestCase
{
    public function testPageZeroReturnsNoLimit(): void
    {
        [$sql, $params] = SQL::buildSelect('*', 'users', [], [], [], [], 0, 10);
        
        $this->assertStringNotContainsString('LIMIT', $sql);
        $this->assertStringNotContainsString('OFFSET', $sql);
    }

    public function testPageOneStartsAtOffsetZero(): void
    {
        [$sql, $params] = SQL::buildSelect('*', 'users', [], [], [], [], 1, 10);
        
        $this->assertStringContainsString('LIMIT 10', $sql);
        $this->assertStringContainsString('OFFSET 0', $sql);
    }

    public function testPageTwoStartsAtOffsetLimit(): void
    {
        [$sql, $params] = SQL::buildSelect('*', 'users', [], [], [], [], 2, 10);
        
        $this->assertStringContainsString('LIMIT 10', $sql);
        $this->assertStringContainsString('OFFSET 10', $sql);
    }

    public function testPageThreeWithLimitTwenty(): void
    {
        [$sql, $params] = SQL::buildSelect('*', 'users', [], [], [], [], 3, 20);
        
        // OFFSET = (3 - 1) * 20 = 40
        $this->assertStringContainsString('LIMIT 20', $sql);
        $this->assertStringContainsString('OFFSET 40', $sql);
    }

    public function testDefaultLimitIsTen(): void
    {
        [$sql, $params] = SQL::buildSelect('*', 'users', [], [], [], [], 1);
        
        $this->assertStringContainsString('LIMIT 10', $sql);
        $this->assertStringContainsString('OFFSET 0', $sql);
    }
}
