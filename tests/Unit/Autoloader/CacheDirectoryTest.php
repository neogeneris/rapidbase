<?php

declare(strict_types=1);

namespace Tests\Unit\Autoloader;

use RapidBase\Tdd\TestCase;
use RapidBase\Autoloader\Autoloader;

/**
 * Pruebas para el método setCacheDirectory() del Autoloader.
 */
class CacheDirectoryTest extends TestCase
{
    private string $basePath;
    private string $customCacheDir;
    
    public function setUp(): void
    {
        $this->basePath = __DIR__ . '/../../fixtures/Autoloader';
        $this->customCacheDir = $this->basePath . '/cache_custom_' . uniqid();
        
        // Limpiar directorios de prueba
        $this->cleanup();
        mkdir($this->basePath, 0777, true);
    }
    
    public function tearDown(): void
    {
        $this->cleanup();
        
        // Reset singleton
        $reflection = new \ReflectionClass(Autoloader::class);
        $instanceProperty = $reflection->getProperty('instance');
        $instanceProperty->setAccessible(true);
        $instanceProperty->setValue(null);
    }
    
    private function cleanup(): void
    {
        if (is_dir($this->basePath)) {
            $files = glob($this->basePath . '/*.dat');
            foreach ($files as $file) {
                unlink($file);
            }
            
            $cacheDirs = glob($this->basePath . '/cache_custom_*', GLOB_ONLYDIR);
            foreach ($cacheDirs as $dir) {
                array_map('unlink', glob($dir . '/*.dat'));
                rmdir($dir);
            }
        }
    }
    
    public function testSetCustomCacheDirectory(): void
    {
        $this->env()->test('Set custom cache directory', function ($test) {
            mkdir($this->customCacheDir, 0777, true);
            
            // Crear una clase simple en el basePath ANTES de inicializar el autoloader
            // La clase debe estar en un archivo llamado exactamente como la clase
            $className = 'TestClassForCache';
            $tempFile = $this->basePath . '/TestClassForCache.php';
            file_put_contents($tempFile, '<?php class TestClassForCache {}');
            
            $autoloader = Autoloader::getInstance($this->basePath);
            $autoloader->setCacheDirectory($this->customCacheDir);
            
            $test->assertTrue(
                $autoloader->getCacheDirectory() === $this->customCacheDir,
                'El directorio de caché debe ser el personalizado'
            );
            
            // Verificar que los archivos se crean en el nuevo directorio
            $autoloader->enableDebug(false);
            $autoloader->register();
            
            // Cargar la clase (esto debería crear cache)
            $autoloader->loadClass($className);
            
            // Verificar que el archivo de cache existe en el directorio personalizado
            $cacheExists = file_exists($this->customCacheDir . '/autoloader_cache.dat');
            $test->assertTrue($cacheExists, 'El archivo de cache debe existir en el directorio personalizado');
            
            // Verificar que NO existe en el directorio base
            $cacheNotInBase = !file_exists($this->basePath . '/autoloader_cache.dat');
            $test->assertTrue($cacheNotInBase, 'El archivo de cache NO debe existir en el directorio base');
            
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        });
    }
    
    public function testExceptionWhenDirectoryDoesNotExist(): void
    {
        $this->env()->test('Exception when directory does not exist', function ($test) {
            $nonExistentDir = '/path/that/does/not/exist';
            
            try {
                $autoloader = Autoloader::getInstance($this->basePath);
                $autoloader->setCacheDirectory($nonExistentDir);
                $test->fail('Debería haber lanzado RuntimeException');
            } catch (\RuntimeException $e) {
                $test->assertTrue(
                    strpos($e->getMessage(), 'no existe') !== false,
                    'El mensaje de error debe indicar que el directorio no existe'
                );
            }
        });
    }
    
    public function testMethodReturnsThisForFluentInterface(): void
    {
        $this->env()->test('Method returns $this for fluent interface', function ($test) {
            mkdir($this->customCacheDir, 0777, true);
            
            $autoloader = Autoloader::getInstance($this->basePath);
            $result = $autoloader->setCacheDirectory($this->customCacheDir);
            
            $test->assertTrue(
                $result instanceof Autoloader,
                'setCacheDirectory() debe retornar una instancia de Autoloader'
            );
        });
    }
    
    public function testGetCacheDirectoryReturnsNullByDefault(): void
    {
        $this->env()->test('getCacheDirectory returns null by default', function ($test) {
            $autoloader = Autoloader::getInstance($this->basePath);
            
            $test->assertTrue(
                $autoloader->getCacheDirectory() === null,
                'El directorio de caché debe ser null por defecto'
            );
        });
    }
    
    public function testChangingDirectoryUpdatesBothCacheAndStatsPaths(): void
    {
        $this->env()->test('Changing directory updates both cache and stats paths', function ($test) {
            mkdir($this->customCacheDir, 0777, true);
            
            $autoloader = Autoloader::getInstance($this->basePath);
            $autoloader->setCacheDirectory($this->customCacheDir);
            $autoloader->enableStats(true);
            
            // Forzar guardado de stats
            $autoloader->saveStats();
            
            $statsExists = file_exists($this->customCacheDir . '/autoloader_stats.dat');
            $test->assertTrue($statsExists, 'El archivo de stats debe existir en el directorio personalizado');
        });
    }
}
