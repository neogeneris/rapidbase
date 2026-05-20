<?php

namespace Tests\PoC\Backend;

require_once __DIR__ . '/Backend.php';
require_once __DIR__ . '/JsonBackend.php';

use Tests\PoC\Backend\JsonBackend;

/**
 * Prueba de concepto para JsonBackend
 */
class JsonBackendTest
{
    private string $testDir;
    private bool $testsPassed = true;

    public function __construct()
    {
        $this->testDir = __DIR__ . '/../../data/test_' . uniqid();
        
        // Crear directorio de test
        if (!is_dir($this->testDir)) {
            mkdir($this->testDir, 0755, true);
        }
    }

    public function run(): void
    {
        echo "=== Prueba de Concepto: JsonBackend ===\n\n";

        $this->testInsertWithInto();
        $this->testSelectWithFrom();
        $this->testUpdate();
        $this->testDelete();
        $this->testAutoIncrement();
        $this->testWhereClause();
        $this->testFileCreation();
        $this->testFromAlias();

        echo "\n";
        if ($this->testsPassed) {
            echo "✓ Todas las pruebas pasaron exitosamente!\n";
        } else {
            echo "✗ Algunas pruebas fallaron.\n";
        }

        // Limpieza
        $this->cleanup();
    }

    private function testInsertWithInto(): void
    {
        echo "Test 1: Insertar registros con into()...\n";
        
        $backend = new JsonBackend($this->testDir);
        $ids = $backend::into('users')->insert([
            ['name' => 'Carlos', 'email' => 'carlos@example.com'],
            ['name' => 'Luis', 'email' => 'luis@example.com'],
            ['name' => 'Ana', 'email' => 'ana@example.com']
        ]);

        if (count($ids) === 3 && $ids[0] === 1 && $ids[1] === 2 && $ids[2] === 3) {
            echo "  ✓ Insertó 3 registros con IDs correctos: " . implode(', ', $ids) . "\n";
        } else {
            echo "  ✗ Falló al insertar registros\n";
            $this->testsPassed = false;
        }

        // Insertar un solo registro
        $backend2 = new JsonBackend($this->testDir);
        $singleId = $backend2::into('users')->insert(['name' => 'Maria', 'email' => 'maria@example.com']);
        
        if (is_array($singleId) && count($singleId) === 1 && $singleId[0] === 4) {
            echo "  ✓ Insertó un solo registro correctamente con ID: {$singleId[0]}\n";
        } else {
            echo "  ✗ Falló al insertar un solo registro\n";
            $this->testsPassed = false;
        }
    }

    private function testSelectWithFrom(): void
    {
        echo "\nTest 2: Seleccionar registros con from()...\n";
        
        $backend = new JsonBackend($this->testDir);
        $results = $backend::from('users')->select('*');

        if (count($results) === 4) {
            echo "  ✓ Seleccione todos los registros con from(): " . count($results) . " encontrados\n";
        } else {
            echo "  ✗ Falló al seleccionar todos los registros (esperado 4, obtenido " . count($results) . ")\n";
            $this->testsPassed = false;
        }

        // Seleccionar campos específicos
        $backend2 = new JsonBackend($this->testDir);
        $names = $backend2::from('users')->select(['name']);
        
        if (count($names) === 4 && isset($names[0]['name']) && !isset($names[0]['email'])) {
            echo "  ✓ Selección de campos específicos con from() funciona correctamente\n";
        } else {
            echo "  ✗ Falló la selección de campos específicos con from()\n";
            $this->testsPassed = false;
        }
    }

    private function testUpdate(): void
    {
        echo "\nTest 3: Actualizar registros...\n";
        
        $backend = new JsonBackend($this->testDir);
        $affected = $backend::into('users')->update(
            ['email' => 'carlos.updated@example.com'],
            ['name' => 'Carlos']
        );

        if ($affected === 1) {
            echo "  ✓ Actualizó 1 registro correctamente\n";
            
            // Verificar que se actualizó
            $backend2 = new JsonBackend($this->testDir);
            $updated = $backend2::into('users')->select('*', ['email' => 'carlos.updated@example.com']);
            
            if (count($updated) === 1 && $updated[0]['name'] === 'Carlos') {
                echo "  ✓ Verificación de actualización exitosa\n";
            } else {
                echo "  ✗ La actualización no se reflejó correctamente\n";
                $this->testsPassed = false;
            }
        } else {
            echo "  ✗ Falló al actualizar registros (esperado 1, obtenido $affected)\n";
            $this->testsPassed = false;
        }
    }

    private function testDelete(): void
    {
        echo "\nTest 4: Eliminar registros...\n";
        
        $backend = new JsonBackend($this->testDir);
        $deleted = $backend::into('users')->delete(['name' => 'Ana']);

        if ($deleted === 1) {
            echo "  ✓ Eliminó 1 registro correctamente\n";
            
            // Verificar que se eliminó
            $backend2 = new JsonBackend($this->testDir);
            $remaining = $backend2::into('users')->select('*', ['name' => 'Ana']);
            
            if (count($remaining) === 0) {
                echo "  ✓ Verificación de eliminación exitosa\n";
            } else {
                echo "  ✗ El registro no se eliminó correctamente\n";
                $this->testsPassed = false;
            }
        } else {
            echo "  ✗ Falló al eliminar registros (esperado 1, obtenido $deleted)\n";
            $this->testsPassed = false;
        }
    }

    private function testAutoIncrement(): void
    {
        echo "\nTest 5: Autoincremento de IDs...\n";
        
        $backend = new JsonBackend($this->testDir);
        $newIds = $backend::into('users')->insert([
            ['name' => 'Pedro', 'email' => 'pedro@example.com'],
            ['name' => 'Sofia', 'email' => 'sofia@example.com']
        ]);

        if ($newIds[0] === 5 && $newIds[1] === 6) {
            echo "  ✓ Autoincremento funciona correctamente (IDs: " . implode(', ', $newIds) . ")\n";
        } else {
            echo "  ✗ Falló el autoincremento (esperado [5, 6], obtenido [" . implode(', ', $newIds) . "])\n";
            $this->testsPassed = false;
        }
    }

    private function testWhereClause(): void
    {
        echo "\nTest 6: Cláusula WHERE...\n";
        
        $backend = new JsonBackend($this->testDir);
        $results = $backend::into('users')->select('*', ['name' => 'Luis']);

        if (count($results) === 1 && $results[0]['name'] === 'Luis') {
            echo "  ✓ Cláusula WHERE funciona correctamente\n";
        } else {
            echo "  ✗ Falló la cláusula WHERE\n";
            $this->testsPassed = false;
        }

        // WHERE múltiple
        $backend2 = new JsonBackend($this->testDir);
        $multiWhere = $backend2::into('users')->select('*', ['name' => 'Carlos', 'email' => 'carlos.updated@example.com']);
        
        if (count($multiWhere) === 1) {
            echo "  ✓ WHERE múltiple funciona correctamente\n";
        } else {
            echo "  ✗ Falló WHERE múltiple\n";
            $this->testsPassed = false;
        }
    }

    private function testFileCreation(): void
    {
        echo "\nTest 7: Creación de archivo JSON...\n";
        
        $filePath = $this->testDir . DIRECTORY_SEPARATOR . 'users.json';
        
        if (file_exists($filePath)) {
            echo "  ✓ Archivo users.json fue creado\n";
            
            $content = file_get_contents($filePath);
            $data = json_decode($content, true);
            
            if (is_array($data) && count($data) > 0) {
                echo "  ✓ El archivo contiene datos válidos en formato JSON\n";
                echo "  ✓ Muestra del contenido del archivo:\n";
                echo "    " . substr(json_encode($data[0]), 0, 80) . "...\n";
            } else {
                echo "  ✗ El archivo no contiene datos válidos\n";
                $this->testsPassed = false;
            }
        } else {
            echo "  ✗ El archivo users.json no fue creado\n";
            $this->testsPassed = false;
        }
    }

    private function testFromAlias(): void
    {
        echo "\nTest 8: Alias from() como alternativa a into()...\n";
        
        // Probar que from() y into() son equivalentes
        $backend1 = new JsonBackend($this->testDir);
        $backend2 = new JsonBackend($this->testDir);
        
        $resultFrom = $backend1::from('products')->select('*');
        $resultInto = $backend2::into('products')->select('*');
        
        if ($resultFrom === $resultInto) {
            echo "  ✓ from() e into() son equivalentes\n";
        } else {
            echo "  ✗ from() e into() no son equivalentes\n";
            $this->testsPassed = false;
        }
        
        // Probar sintaxis completa con from()
        $backend3 = new JsonBackend($this->testDir);
        $ids = $backend3::from('products')->insert([
            ['name' => 'Laptop', 'price' => 999.99],
            ['name' => 'Mouse', 'price' => 29.99]
        ]);
        
        if (count($ids) === 2) {
            echo "  ✓ from()->insert() funciona correctamente\n";
            
            $backend4 = new JsonBackend($this->testDir);
            $products = $backend4::from('products')->select('*');
            
            if (count($products) === 2 && $products[0]['name'] === 'Laptop') {
                echo "  ✓ from()->select('*') recupera datos insertados con from()->insert()\n";
            } else {
                echo "  ✗ Error al recuperar datos con from()->select()\n";
                $this->testsPassed = false;
            }
        } else {
            echo "  ✗ from()->insert() falló\n";
            $this->testsPassed = false;
        }
    }

    private function cleanup(): void
    {
        echo "\nLimpiando archivos de prueba...\n";
        
        // Eliminar todos los archivos JSON del directorio de test
        $files = glob($this->testDir . '/*.json');
        foreach ($files as $file) {
            unlink($file);
        }
        
        // Eliminar directorio
        rmdir($this->testDir);
        
        echo "  ✓ Limpieza completada\n";
    }
}

// Ejecutar las pruebas
$test = new JsonBackendTest();
$test->run();
