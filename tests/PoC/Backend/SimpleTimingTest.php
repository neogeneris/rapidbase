<?php

namespace Tests\PoC\Backend;

require_once __DIR__ . '/Backend.php';
require_once __DIR__ . '/JsonBackend.php';

use Tests\PoC\Backend\JsonBackend;

/**
 * Prueba de concepto para medir tiempos de ejecución en JsonBackend
 */
class SimpleTimingTest
{
    private string $testDir;
    
    public function __construct()
    {
        $this->testDir = sys_get_temp_dir() . '/json_backend_simple_timing_' . uniqid();
        @mkdir($this->testDir, 0755, true);
    }

    public function run(): void
    {
        echo "=== PRUEBA DE TIEMPOS: JsonBackend ===\n\n";
        
        $this->testInsertTiming();
        $this->testSelectTiming();
        $this->testUpdateTiming();
        $this->testDeleteTiming();
        $this->testJoinTiming();
        $this->testBulkInsertTiming();
        $this->testBulkSelectTiming();
        
        // Limpieza
        array_map('unlink', glob($this->testDir . '/*.json'));
        rmdir($this->testDir);
        echo "\n✓ Limpieza completada\n";
    }

    private function testInsertTiming(): void
    {
        echo "=== PRUEBA DE TIEMPO: INSERT ===\n";
        
        $backend = new JsonBackend($this->testDir);
        $ids = $backend::into('users')->insert([
            ['name' => 'Carlos', 'email' => 'carlos@example.com'],
            ['name' => 'Luis', 'email' => 'luis@example.com']
        ]);

        $time = $backend::into('users')->getExecutionTime();
        echo "Tiempo de inserción de 2 registros: " . number_format($time * 1000, 4) . " ms\n";
        echo "IDs insertados: " . implode(', ', $ids) . "\n\n";
    }

    private function testSelectTiming(): void
    {
        echo "=== PRUEBA DE TIEMPO: SELECT ===\n";
        
        $backend = new JsonBackend($this->testDir);
        
        // Select todos
        $results = $backend::from('users')->select('*');
        $time = $backend::from('users')->getExecutionTime();
        echo "Tiempo de SELECT *: " . number_format($time * 1000, 4) . " ms\n";
        echo "Registros encontrados: " . count($results) . "\n";

        // Select con WHERE
        $results = $backend::from('users')->select('*', ['name' => 'Carlos']);
        $time = $backend::from('users')->getExecutionTime();
        echo "Tiempo de SELECT con WHERE: " . number_format($time * 1000, 4) . " ms\n";
        echo "Registros encontrados: " . count($results) . "\n\n";
    }

    private function testUpdateTiming(): void
    {
        echo "=== PRUEBA DE TIEMPO: UPDATE ===\n";
        
        $backend = new JsonBackend($this->testDir);
        $affected = $backend::into('users')->update(
            ['email' => 'carlos.updated@example.com'],
            ['name' => 'Carlos']
        );
        
        $time = $backend::into('users')->getExecutionTime();
        echo "Tiempo de UPDATE: " . number_format($time * 1000, 4) . " ms\n";
        echo "Registros afectados: $affected\n\n";
    }

    private function testDeleteTiming(): void
    {
        echo "=== PRUEBA DE TIEMPO: DELETE ===\n";
        
        $backend = new JsonBackend($this->testDir);
        $deleted = $backend::into('users')->delete(['name' => 'Luis']);
        
        $time = $backend::into('users')->getExecutionTime();
        echo "Tiempo de DELETE: " . number_format($time * 1000, 4) . " ms\n";
        echo "Registros eliminados: $deleted\n\n";
    }

    private function testJoinTiming(): void
    {
        echo "=== PRUEBA DE TIEMPO: JOIN ===\n";
        
        // Crear backend para usuarios
        $usersBackend = new JsonBackend($this->testDir);
        $usersBackend::into('users')->dropEntity();
        
        // Insertar usuarios
        $userIds = $usersBackend::into('users')->insert([
            ['name' => 'Carlos', 'email' => 'carlos@example.com'],
            ['name' => 'Luis', 'email' => 'luis@example.com'],
            ['name' => 'Ana', 'email' => 'ana@example.com'],
        ]);

        // Crear backend para posts
        $postsBackend = new JsonBackend($this->testDir);
        $postsBackend::into('posts')->dropEntity();
        
        // Insertar posts
        $postsBackend::into('posts')->insert([
            ['title' => 'Post 1', 'content' => 'Contenido 1', 'user_id' => $userIds[0]],
            ['title' => 'Post 2', 'content' => 'Contenido 2', 'user_id' => $userIds[0]],
            ['title' => 'Post 3', 'content' => 'Contenido 3', 'user_id' => $userIds[1]],
            ['title' => 'Post 4', 'content' => 'Contenido 4', 'user_id' => $userIds[2]],
        ]);

        // Hacer JOIN
        $results = $usersBackend::from('users')
            ->join('posts', 'id', 'user_id', 'INNER')
            ->selectFields(['users.name', 'users.email', 'posts.title', 'posts.content'])
            ->get();

        $time = $usersBackend::from('users')->getExecutionTime();
        echo "Tiempo de JOIN: " . number_format($time * 1000, 4) . " ms\n";
        echo "Resultados del JOIN: " . count($results) . " registros\n";
        
        foreach ($results as $i => $row) {
            echo "  Resultado " . ($i + 1) . ": {$row['name']} - {$row['title']}\n";
        }
        echo "\n";
    }

    private function testBulkInsertTiming(): void
    {
        echo "=== PRUEBA DE TIEMPO: BULK INSERT (100 registros) ===\n";
        
        $bulkBackend = new JsonBackend($this->testDir);
        $bulkBackend::into('bulk_test')->dropEntity();
        
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

        $ids = $bulkBackend::into('bulk_test')->insert($records);
        $time = $bulkBackend::into('bulk_test')->getExecutionTime();
        
        echo "Tiempo de inserción de 100 registros: " . number_format($time * 1000, 4) . " ms\n";
        echo "Tiempo promedio por registro: " . number_format(($time * 1000) / 100, 6) . " ms\n\n";
    }

    private function testBulkSelectTiming(): void
    {
        echo "=== PRUEBA DE TIEMPO: BULK SELECT (100 registros) ===\n";
        
        // Medir tiempo de select
        $bulkBackend = new JsonBackend($this->testDir);
        $results = $bulkBackend::from('bulk_test')->select('*');
        $time = $bulkBackend::from('bulk_test')->getExecutionTime();
        
        echo "Tiempo de SELECT de 100 registros: " . number_format($time * 1000, 4) . " ms\n";
        echo "Tiempo promedio por registro: " . number_format(($time * 1000) / 100, 6) . " ms\n\n";
    }
}

// Ejecutar las pruebas
$test = new SimpleTimingTest();
$test->run();

