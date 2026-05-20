<?php

namespace Tests\PoC\Backend;

use PHPUnit\Framework\TestCase;

/**
 * Prueba de concepto para medir tiempos de ejecución en JsonBackend
 */
class JsonBackendTimingTest extends TestCase
{
    private string $testDir;
    private JsonBackend $backend;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testDir = sys_get_temp_dir() . '/json_backend_timing_test_' . uniqid();
        @mkdir($this->testDir, 0755, true);
        $this->backend = new JsonBackend($this->testDir);
    }

    protected function tearDown(): void
    {
        // Limpiar archivos de prueba
        if (is_dir($this->testDir)) {
            array_map('unlink', glob($this->testDir . '/*.json'));
            rmdir($this->testDir);
        }
        parent::tearDown();
    }

    public function testInsertTiming(): void
    {
        echo "\n=== PRUEBA DE TIEMPO: INSERT ===\n";
        
        // Insertar un registro simple
        $ids = $this->backend->insert([
            ['name' => 'Carlos', 'email' => 'carlos@example.com'],
            ['name' => 'Luis', 'email' => 'luis@example.com']
        ]);

        $time = $this->backend->getExecutionTime();
        echo "Tiempo de inserción de 2 registros: " . number_format($time * 1000, 4) . " ms\n";
        echo "IDs insertados: " . implode(', ', $ids) . "\n";
        
        $this->assertIsFloat($time);
        $this->assertGreaterThan(0, $time);
        $this->assertCount(2, $ids);
    }

    public function testSelectTiming(): void
    {
        echo "\n=== PRUEBA DE TIEMPO: SELECT ===\n";
        
        // Primero insertamos datos
        $this->backend->insert([
            ['name' => 'Usuario1', 'email' => 'user1@test.com', 'age' => 25],
            ['name' => 'Usuario2', 'email' => 'user2@test.com', 'age' => 30],
            ['name' => 'Usuario3', 'email' => 'user3@test.com', 'age' => 35],
        ]);

        // Select todos
        $results = $this->backend->select('*');
        $time = $this->backend->getExecutionTime();
        echo "Tiempo de SELECT *: " . number_format($time * 1000, 4) . " ms\n";
        echo "Registros encontrados: " . count($results) . "\n";

        // Select con WHERE
        $results = $this->backend->select('*', ['age' => 30]);
        $time = $this->backend->getExecutionTime();
        echo "Tiempo de SELECT con WHERE: " . number_format($time * 1000, 4) . " ms\n";
        echo "Registros encontrados: " . count($results) . "\n";

        $this->assertIsFloat($time);
        $this->assertGreaterThan(0, $time);
    }

    public function testUpdateTiming(): void
    {
        echo "\n=== PRUEBA DE TIEMPO: UPDATE ===\n";
        
        // Insertar datos
        $this->backend->insert([
            ['name' => 'Original', 'email' => 'original@test.com'],
        ]);

        // Actualizar
        $affected = $this->backend->update(
            ['name' => 'Updated', 'email' => 'updated@test.com'],
            ['name' => 'Original']
        );
        
        $time = $this->backend->getExecutionTime();
        echo "Tiempo de UPDATE: " . number_format($time * 1000, 4) . " ms\n";
        echo "Registros afectados: $affected\n";

        $this->assertIsFloat($time);
        $this->assertGreaterThan(0, $time);
        $this->assertEquals(1, $affected);
    }

    public function testDeleteTiming(): void
    {
        echo "\n=== PRUEBA DE TIEMPO: DELETE ===\n";
        
        // Insertar datos
        $this->backend->insert([
            ['name' => 'ToDelete1', 'email' => 'delete1@test.com'],
            ['name' => 'ToDelete2', 'email' => 'delete2@test.com'],
            ['name' => 'ToKeep', 'email' => 'keep@test.com'],
        ]);

        // Eliminar
        $deleted = $this->backend->delete(['name' => 'ToDelete1']);
        
        $time = $this->backend->getExecutionTime();
        echo "Tiempo de DELETE: " . number_format($time * 1000, 4) . " ms\n";
        echo "Registros eliminados: $deleted\n";

        $this->assertIsFloat($time);
        $this->assertGreaterThan(0, $time);
        $this->assertEquals(1, $deleted);
    }

    public function testJoinTiming(): void
    {
        echo "\n=== PRUEBA DE TIEMPO: JOIN ===\n";
        
        // Crear backend para usuarios
        $usersBackend = new JsonBackend($this->testDir);
        $usersBackend->dropEntity();
        
        // Insertar usuarios
        $userIds = $usersBackend->insert([
            ['name' => 'Carlos', 'email' => 'carlos@example.com'],
            ['name' => 'Luis', 'email' => 'luis@example.com'],
            ['name' => 'Ana', 'email' => 'ana@example.com'],
        ]);

        // Crear backend para posts
        $postsBackend = new JsonBackend($this->testDir);
        $postsBackend->entity = 'posts';
        $postsBackend->dropEntity();
        
        // Insertar posts
        $postsBackend->insert([
            ['title' => 'Post 1', 'content' => 'Contenido 1', 'user_id' => $userIds[0]],
            ['title' => 'Post 2', 'content' => 'Contenido 2', 'user_id' => $userIds[0]],
            ['title' => 'Post 3', 'content' => 'Contenido 3', 'user_id' => $userIds[1]],
            ['title' => 'Post 4', 'content' => 'Contenido 4', 'user_id' => $userIds[2]],
        ]);

        // Hacer JOIN
        $results = $usersBackend
            ->join('posts', 'id', 'user_id', 'INNER')
            ->selectFields(['users.name', 'users.email', 'posts.title', 'posts.content'])
            ->get();

        $time = $usersBackend->getExecutionTime();
        echo "Tiempo de JOIN: " . number_format($time * 1000, 4) . " ms\n";
        echo "Resultados del JOIN: " . count($results) . " registros\n";
        
        foreach ($results as $i => $row) {
            echo "  Resultado " . ($i + 1) . ": {$row['name']} - {$row['title']}\n";
        }

        $this->assertIsFloat($time);
        $this->assertGreaterThan(0, $time);
        $this->assertGreaterThan(0, count($results));
    }

    public function testBulkInsertTiming(): void
    {
        echo "\n=== PRUEBA DE TIEMPO: BULK INSERT (100 registros) ===\n";
        
        $bulkBackend = new JsonBackend($this->testDir);
        $bulkBackend->entity = 'bulk_test';
        $bulkBackend->dropEntity();
        
        // Generar 100 registros
        $records = [];
        for ($i = 0; $i < 100; $i++) {
            $records[] = [
                'name' => "Usuario $i",
                'email' => "usuario$i@test.com",
                'age' => rand(18, 80),
                'city' => "Ciudad " . rand(1, 10)
            ];
        }

        $ids = $bulkBackend->insert($records);
        $time = $bulkBackend->getExecutionTime();
        
        echo "Tiempo de inserción de 100 registros: " . number_format($time * 1000, 4) . " ms\n";
        echo "Tiempo promedio por registro: " . number_format(($time * 1000) / 100, 6) . " ms\n";

        $this->assertIsFloat($time);
        $this->assertGreaterThan(0, $time);
        $this->assertCount(100, $ids);
    }

    public function testBulkSelectTiming(): void
    {
        echo "\n=== PRUEBA DE TIEMPO: BULK SELECT (100 registros) ===\n";
        
        // Primero insertamos 100 registros
        $bulkBackend = new JsonBackend($this->testDir);
        $bulkBackend->entity = 'bulk_select_test';
        $bulkBackend->dropEntity();
        
        $records = [];
        for ($i = 0; $i < 100; $i++) {
            $records[] = [
                'name' => "Usuario $i",
                'email' => "usuario$i@test.com",
                'age' => rand(18, 80),
            ];
        }
        $bulkBackend->insert($records);

        // Medir tiempo de select
        $results = $bulkBackend->select('*');
        $time = $bulkBackend->getExecutionTime();
        
        echo "Tiempo de SELECT de 100 registros: " . number_format($time * 1000, 4) . " ms\n";
        echo "Tiempo promedio por registro: " . number_format(($time * 1000) / 100, 6) . " ms\n";

        $this->assertIsFloat($time);
        $this->assertGreaterThan(0, $time);
        $this->assertCount(100, $results);
    }

    public function testFromAliasWithTiming(): void
    {
        echo "\n=== PRUEBA DE TIEMPO: FROM() ALIAS ===\n";
        
        $fromBackend = new JsonBackend($this->testDir);
        $fromBackend->entity = 'from_test';
        $fromBackend->dropEntity();
        
        // Usando el alias from()
        $ids = $fromBackend::from('from_test')->insert([
            ['name' => 'Test1', 'value' => 100],
            ['name' => 'Test2', 'value' => 200],
        ]);

        $time = $fromBackend::from('from_test')->select('*')->getExecutionTime();
        
        echo "Tiempo usando from(): " . number_format($time * 1000, 4) . " ms\n";
        echo "IDs insertados: " . implode(', ', $ids) . "\n";

        $this->assertIsFloat($time);
        $this->assertGreaterThan(0, $time);
    }
}
