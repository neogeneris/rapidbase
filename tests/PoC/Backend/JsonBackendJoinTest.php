<?php

namespace Tests\PoC\Backend;

use PHPUnit\Framework\TestCase;

/**
 * Pruebas para la funcionalidad de JOINs en JsonBackend
 */
class JsonBackendJoinTest extends TestCase
{
    private string $testDir = __DIR__ . '/../../data/test_join';
    private JsonBackend $backend;

    protected function setUp(): void
    {
        // Limpiar directorio de prueba
        if (is_dir($this->testDir)) {
            $this->recursiveDelete($this->testDir);
        }
        mkdir($this->testDir, 0755, true);
        
        $this->backend = new JsonBackend($this->testDir);
    }

    protected function tearDown(): void
    {
        // Limpiar datos de prueba
        if (is_dir($this->testDir)) {
            $this->recursiveDelete($this->testDir);
        }
    }

    private function recursiveDelete(string $dir): void
    {
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->recursiveDelete($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testInnerJoin(): void
    {
        // Crear datos de usuarios
        JsonBackend::into('users')->setBaseDir($this->testDir)->insert([
            ['name' => 'Carlos', 'email' => 'carlos@example.com', 'role_id' => 1],
            ['name' => 'Luis', 'email' => 'luis@example.com', 'role_id' => 2],
            ['name' => 'Ana', 'email' => 'ana@example.com', 'role_id' => 1],
            ['name' => 'Sofia', 'email' => 'sofia@example.com', 'role_id' => 3], // Role que no existe
        ]);

        // Crear datos de roles
        JsonBackend::into('roles')->setBaseDir($this->testDir)->insert([
            ['id' => 1, 'name' => 'Admin', 'level' => 10],
            ['id' => 2, 'name' => 'User', 'level' => 5],
            ['id' => 4, 'name' => 'Guest', 'level' => 1],
        ]);

        // Hacer INNER JOIN entre users y roles
        $result = JsonBackend::from('users')
            ->setBaseDir($this->testDir)
            ->join('roles', 'role_id', 'id', 'INNER')
            ->get();

        // INNER JOIN debe excluir a Sofia (role_id=3 que no existe en roles)
        $this->assertCount(3, $result);
        
        // Verificar que los datos son correctos
        $this->assertEquals('Carlos', $result[0]['name']);
        $this->assertEquals('Admin', $result[0]['roles.name']);
        $this->assertEquals('10', $result[0]['level']);

        $this->assertEquals('Luis', $result[1]['name']);
        $this->assertEquals('User', $result[1]['roles.name']);
        $this->assertEquals('5', $result[1]['level']);
    }

    public function testLeftJoin(): void
    {
        // Crear datos de usuarios
        JsonBackend::into('users')->setBaseDir($this->testDir)->insert([
            ['name' => 'Carlos', 'email' => 'carlos@example.com', 'role_id' => 1],
            ['name' => 'Luis', 'email' => 'luis@example.com', 'role_id' => 2],
            ['name' => 'Ana', 'email' => 'ana@example.com', 'role_id' => 1],
            ['name' => 'Sofia', 'email' => 'sofia@example.com', 'role_id' => 3], // Role que no existe
        ]);

        // Crear datos de roles
        JsonBackend::into('roles')->setBaseDir($this->testDir)->insert([
            ['id' => 1, 'name' => 'Admin', 'level' => 10],
            ['id' => 2, 'name' => 'User', 'level' => 5],
        ]);

        // Hacer LEFT JOIN - debe incluir a Sofia con NULL en los campos de role
        $result = JsonBackend::from('users')
            ->setBaseDir($this->testDir)
            ->leftJoin('roles', 'role_id', 'id')
            ->get();

        // LEFT JOIN debe incluir todos los usuarios (4)
        $this->assertCount(4, $result);
        
        // Buscar a Sofia y verificar que tiene valores NULL para los campos de role
        $sofiaRecord = null;
        foreach ($result as $record) {
            if ($record['name'] === 'Sofia') {
                $sofiaRecord = $record;
                break;
            }
        }
        $this->assertNotNull($sofiaRecord);
        $this->assertNull($sofiaRecord['roles.name'] ?? null);
    }

    public function testJoinWithWhere(): void
    {
        // Crear datos de usuarios
        JsonBackend::into('users')->setBaseDir($this->testDir)->insert([
            ['name' => 'Carlos', 'email' => 'carlos@example.com', 'role_id' => 1],
            ['name' => 'Luis', 'email' => 'luis@example.com', 'role_id' => 2],
            ['name' => 'Ana', 'email' => 'ana@example.com', 'role_id' => 1],
        ]);

        // Crear datos de roles
        JsonBackend::into('roles')->setBaseDir($this->testDir)->insert([
            ['id' => 1, 'name' => 'Admin', 'level' => 10],
            ['id' => 2, 'name' => 'User', 'level' => 5],
        ]);

        // Hacer JOIN con WHERE
        $result = JsonBackend::from('users')
            ->setBaseDir($this->testDir)
            ->join('roles', 'role_id', 'id')
            ->where(['name' => 'Carlos'])
            ->get();

        // Solo debe retornar a Carlos
        $this->assertCount(1, $result);
        $this->assertEquals('Carlos', $result[0]['name']);
        $this->assertEquals('Admin', $result[0]['roles.name']);
    }

    public function testMultipleJoins(): void
    {
        // Crear datos de usuarios
        JsonBackend::into('users')->setBaseDir($this->testDir)->insert([
            ['name' => 'Carlos', 'email' => 'carlos@example.com', 'role_id' => 1, 'department_id' => 1],
            ['name' => 'Luis', 'email' => 'luis@example.com', 'role_id' => 2, 'department_id' => 2],
        ]);

        // Crear datos de roles
        JsonBackend::into('roles')->setBaseDir($this->testDir)->insert([
            ['id' => 1, 'name' => 'Admin', 'level' => 10],
            ['id' => 2, 'name' => 'User', 'level' => 5],
        ]);

        // Crear datos de departamentos
        JsonBackend::into('departments')->setBaseDir($this->testDir)->insert([
            ['id' => 1, 'name' => 'IT', 'budget' => 100000],
            ['id' => 2, 'name' => 'HR', 'budget' => 50000],
        ]);

        // Hacer múltiples JOINs
        $result = JsonBackend::from('users')
            ->setBaseDir($this->testDir)
            ->join('roles', 'role_id', 'id')
            ->join('departments', 'department_id', 'id')
            ->get();

        $this->assertCount(2, $result);
        $this->assertEquals('Carlos', $result[0]['name']);
        $this->assertEquals('Admin', $result[0]['roles.name']);
        $this->assertEquals('IT', $result[0]['departments.name']);
    }

    public function testGetWithoutJoin(): void
    {
        // Cuando no hay JOINs, get() debe funcionar como select()
        JsonBackend::into('users')->setBaseDir($this->testDir)->insert([
            ['name' => 'Carlos', 'email' => 'carlos@example.com'],
            ['name' => 'Luis', 'email' => 'luis@example.com'],
        ]);

        $result = JsonBackend::from('users')
            ->setBaseDir($this->testDir)
            ->where(['name' => 'Carlos'])
            ->get();

        $this->assertCount(1, $result);
        $this->assertEquals('Carlos', $result[0]['name']);
    }
}
