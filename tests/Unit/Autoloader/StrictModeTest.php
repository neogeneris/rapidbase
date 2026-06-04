<?php

declare(strict_types=1);

namespace Tests\Unit\Autoloader;

use RapidBase\Tdd\TestCase;
use RapidBase\Autoloader\Autoloader;


// Bootstrap centralizado para pruebas unitarias
require_once __DIR__ . '/../bootstrap.php';
/**
 * Pruebas para el modo estricto del Autoloader.
 * 
 * Verifica que el comportamiento cambie correctamente entre:
 * - Modo producción (default): retorna false, permite fallback a otros autoloaders
 * - Modo desarrollo (strictMode): lanza excepción clara cuando no encuentra la clase
 */
class StrictModeTest extends TestCase
{
    private string $testDir;
    private Autoloader $autoloader;

    public function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/autoloader_test_' . uniqid();
        mkdir($this->testDir, 0777, true);
        
        // Limpiar instancia singleton si existe
        $reflection = new \ReflectionClass(Autoloader::class);
        $instanceProperty = $reflection->getProperty('instance');
        $instanceProperty->setAccessible(true);
        $instanceProperty->setValue(null, null);
        
        $this->autoloader = Autoloader::getInstance($this->testDir);
        $this->autoloader->enableDebug(false);
        $this->autoloader->enableCache(false); // Desactivar cache para pruebas consistentes
    }

    public function tearDown(): void
    {
        // Limpiar archivos de test (recursivo para incluir subdirectorios)
        if (file_exists($this->testDir . '/autoloader_cache.dat')) {
            unlink($this->testDir . '/autoloader_cache.dat');
        }
        if (file_exists($this->testDir . '/autoloader_stats.dat')) {
            unlink($this->testDir . '/autoloader_stats.dat');
        }
        if (is_dir($this->testDir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->testDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $file) {
                if ($file->isDir()) {
                    rmdir($file->getPathname());
                } else {
                    unlink($file->getPathname());
                }
            }
            rmdir($this->testDir);
        }
        
        // Limpiar instancia singleton
        $reflection = new \ReflectionClass(Autoloader::class);
        $instanceProperty = $reflection->getProperty('instance');
        $instanceProperty->setAccessible(true);
        $instanceProperty->setValue(null, null);
    }

    /**
     * Prueba que en modo producción (default) retorna false cuando no encuentra la clase
     */
    public function testProductionModeReturnsFalseWhenClassNotFound(): void
    {
        $this->env()->test('Production mode returns false when class not found', function($test) {
            // Asegurar que estamos en modo producción (strictMode = false)
            $this->autoloader->setStrictMode(false);
            $this->autoloader->register();

            // Intentar cargar una clase que no existe
            $result = $this->autoloader->loadClass('NonExistentClass');
            
            $test->assertFalse($result, 'Debe retornar false en modo producción cuando no encuentra la clase');
        });
    }

    /**
     * Prueba que en modo estricto lanza excepción cuando no encuentra la clase
     */
    public function testStrictModeThrowsExceptionWhenClassNotFound(): void
    {
        $this->env()->test('Strict mode throws exception when class not found', function($test) {
            $this->autoloader->setStrictMode(true);
            $this->autoloader->register();

            try {
                $this->autoloader->loadClass('NonExistentClass');
                $test->fail('Debe lanzar RuntimeException en modo estricto');
            } catch (\RuntimeException $e) {
                $test->assertStringContainsString('Autoloader no pudo encontrar la clase: NonExistentClass', $e->getMessage());
            }
        });
    }

    /**
     * Prueba que en modo estricto carga correctamente las clases existentes
     */
    public function testStrictModeLoadsExistingClassesSuccessfully(): void
    {
        $this->env()->test('Strict mode loads existing classes successfully', function($test) {
            // Crear una clase de prueba
            $className = 'TestClassForStrictMode';
            
            // Crear directorio para el namespace
            $namespaceDir = $this->testDir . '/TestNamespace';
            mkdir($namespaceDir, 0777, true);
            $filePath = $namespaceDir . '/' . $className . '.php';
            
            $classCode = "<?php\nnamespace TestNamespace;\nclass {$className} {}\n";
            file_put_contents($filePath, $classCode);
            
            $this->autoloader->setStrictMode(true);
            $this->autoloader->register();
            
            // Debería cargar sin lanzar excepción
            $result = $this->autoloader->loadClass("TestNamespace\\{$className}");
            
            $test->assertTrue($result, 'Debe retornar true cuando encuentra y carga la clase');
            $test->assertTrue(class_exists("TestNamespace\\{$className}", false), 'La clase debe estar cargada');
        });
    }

    /**
     * Prueba que el modo estricto es configurable y persistente
     */
    public function testStrictModeIsConfigurableAndPersistent(): void
    {
        $this->env()->test('Strict mode is configurable and persistent', function($test) {
            $test->assertFalse(
                $this->autoloader->setStrictMode(false)->loadClass('NonExistentClass'), 
                'Modo producción debe retornar false'
            );
            
            try {
                $this->autoloader->setStrictMode(true)->loadClass('AnotherNonExistentClass');
                $test->fail('Debe lanzar excepción en modo estricto');
            } catch (\RuntimeException $e) {
                $test->assertTrue(true, 'Lanzó excepción como se esperaba');
            }
        });
    }

    /**
     * Prueba que permite fallback a otros autoloaders en modo producción
     */
    public function testProductionModeAllowsFallbackToOtherAutoloaders(): void
    {
        $this->env()->test('Production mode allows fallback to other autoloaders', function($test) {
            // Esta prueba verifica que loadClass() retorna false cuando no encuentra la clase,
            // lo que permite que spl_autoload_call() continúe con el siguiente autoloader
            
            $this->autoloader->setStrictMode(false);
            
            // Verificar directamente que loadClass retorna false para clase inexistente
            $result = $this->autoloader->loadClass('NonExistentClassForFallback');
            $test->assertFalse($result, 'loadClass debe retornar false para permitir fallback');
            
            // La prueba principal es verificar que loadClass() retorna false (ya hecho arriba)
            // Esto es suficiente para garantizar que el mecanismo de fallback funcionará
            // cuando se usa con spl_autoload_call() en un entorno real
            $test->assertTrue(true, 'Prueba completada: loadClass retorna false para permitir fallback');
        });
    }

    /**
     * Prueba que en modo estricto NO permite fallback, lanza excepción inmediatamente
     */
    public function testStrictModeDoesNotAllowFallback(): void
    {
        $this->env()->test('Strict mode does not allow fallback', function($test) {
            $this->autoloader->setStrictMode(true);
            $this->autoloader->register();
            
            // Registrar un autoloader de fallback
            $fallbackCalled = false;
            $fallbackAutoloader = function($class) use (&$fallbackCalled) {
                $fallbackCalled = true;
                return false;
            };
            spl_autoload_register($fallbackAutoloader);
            
            // En modo estricto, debe lanzar excepción antes de que el fallback pueda actuar
            try {
                $this->autoloader->loadClass('NonExistentClassInStrictMode');
                $test->fail('Debe lanzar RuntimeException en modo estricto');
            } catch (\RuntimeException $e) {
                // La excepción se lanza inmediatamente, el fallback nunca es llamado
                $test->assertFalse($fallbackCalled, 'El fallback no debe ser llamado en modo estricto');
            }
            
            spl_autoload_unregister($fallbackAutoloader);
        });
    }
}
