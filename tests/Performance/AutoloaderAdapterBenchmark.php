<?php

/**
 * Autoloader Adapter Benchmark
 * 
 * Compara el rendimiento del Autoloader usando:
 * 1. Cache interno actual (clase anónima con serialize/unserialize)
 * 2. Cache externo usando DirectoryCacheAdapter (KeyValueWriterInterface)
 * 
 * Esto prueba si vale la pena refactorizar el Autoloader para usar los contratos existentes.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use RapidBase\Autoloader\Autoloader;
use RapidBase\Autoloader\CacheAdapter;
use RapidBase\Core\Cache\Adapters\DirectoryCacheAdapter;

echo "==============================================\n";
echo "  AUTOLOADER ADAPTER BENCHMARK\n";
echo "  Internal Cache vs External Adapter\n";
echo "==============================================\n\n";

// Configuración
$testDir = sys_get_temp_dir() . '/autoloader_adapter_bench_' . uniqid();
if (!is_dir($testDir)) {
    mkdir($testDir, 0775, true);
}

$cacheDir = $testDir . '/cache';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0775, true);
}

// Generar clases de prueba
$testClassesDir = $testDir . '/classes';
if (!is_dir($testClassesDir)) {
    mkdir($testClassesDir, 0775, true);
}

echo "Generating test classes...\n";
$classCount = 50;
for ($i = 0; $i < $classCount; $i++) {
    $className = "TestClass$i";
    $fileContent = "<?php\n\nclass $className {\n    public function method$i() {\n        return 'value$i';\n    }\n}\n";
    file_put_contents("$testClassesDir/$className.php", $fileContent);
}
echo "✓ Generated $classCount test classes\n\n";

// ==========================================
// PRUEBA 1: Autoloader con cache interno (actual)
// ==========================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "  TEST 1: Internal Cache (serialize/.dat)\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$internalCacheFile = $testDir . '/internal_cache.dat';

// Crear autoloader con cache interno
$autoloaderInternal = new class($testDir) {
    private array $searchPaths = [];
    private object $cache;
    private string $cacheFile;

    public function __construct(string $basePath)
    {
        $this->cacheFile = $basePath . '/internal_cache.dat';
        $this->searchPaths[] = rtrim($basePath, '/\\');
        
        // Cache interno (igual que Autoloader original)
        $this->cache = new class ($this->cacheFile) {
            private string $filePath;
            private array $data = [];
            private bool $loaded = false;

            public function __construct(string $filePath)
            {
                $this->filePath = $filePath;
            }

            public function get(string $key): mixed
            {
                $this->load();
                return $this->data[$key] ?? null;
            }

            public function set(string $key, mixed $value): void
            {
                $this->load();
                $this->data[$key] = $value;
                $this->persist();
            }

            private function persist(): void
            {
                if ($this->data !== []) {
                    file_put_contents($this->filePath, serialize($this->data), LOCK_EX);
                } elseif (file_exists($this->filePath)) {
                    unlink($this->filePath);
                }
            }

            private function load(): void
            {
                if (!$this->loaded) {
                    $this->data = file_exists($this->filePath) 
                        ? unserialize(file_get_contents($this->filePath)) ?: [] 
                        : [];
                    $this->loaded = true;
                }
            }
        };
    }

    public function loadClass(string $fullClassName): bool
    {
        // Intento 1: Caché
        $cachedPath = $this->cache->get($fullClassName);
        if ($cachedPath && file_exists($cachedPath)) {
            require_once $cachedPath;
            return true;
        }

        // Intento 2: Búsqueda en directorios
        foreach ($this->searchPaths as $path) {
            $fullPath = $path . DIRECTORY_SEPARATOR . $fullClassName . '.php';
            if (file_exists($fullPath)) {
                require_once $fullPath;
                $this->cache->set($fullClassName, $fullPath);
                return true;
            }
        }

        return false;
    }

    public function clearCache(): void
    {
        if (file_exists($this->cacheFile)) {
            unlink($this->cacheFile);
        }
    }

    public function getCache(): object
    {
        return $this->cache;
    }
};

// Warm-up: Cargar todas las clases una vez
echo "Warm-up: Loading all classes (first time - cache miss)...\n";
$start = microtime(true);
for ($i = 0; $i < $classCount; $i++) {
    $className = "TestClass$i";
    $autoloaderInternal->loadClass($className);
}
$warmupTime = (microtime(true) - $start) * 1000;
echo "  Warm-up time: " . number_format($warmupTime, 2) . " ms\n\n";

// Prueba de carga con cache caliente
echo "Loading all classes with warm cache (10 iterations)...\n";
$iterations = 10;
$start = microtime(true);
for ($iter = 0; $iter < $iterations; $iter++) {
    for ($i = 0; $i < $classCount; $i++) {
        $className = "TestClass$i";
        // Verificar si está en cache (no necesitamos cargarla realmente porque ya existe)
        $cachedPath = $autoloaderInternal->getCache()->get($className);
        if ($cachedPath && file_exists($cachedPath)) {
            // Cache hit - contar como operación exitosa
            continue;
        }
    }
}
$internalTime = ((microtime(true) - $start) * 1000) / $iterations;
echo "  Avg time per iteration: " . number_format($internalTime, 4) . " ms\n\n";

// ==========================================
// PRUEBA 2: Autoloader con DirectoryCacheAdapter
// ==========================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "  TEST 2: External Adapter (DirectoryCache)\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

// Crear adapter externo
$directoryAdapter = new DirectoryCacheAdapter($cacheDir);
$cacheAdapter = new CacheAdapter($directoryAdapter);

// Autoloader usando adapter externo
$autoloaderExternal = new class($testDir, $cacheAdapter) {
    private array $searchPaths = [];
    private object $cache;

    public function __construct(string $basePath, object $cache)
    {
        $this->cache = $cache;
        $this->searchPaths[] = rtrim($basePath, '/\\');
    }

    public function loadClass(string $fullClassName): bool
    {
        // Intento 1: Caché
        $cachedPath = $this->cache->get($fullClassName);
        if ($cachedPath && file_exists($cachedPath)) {
            require_once $cachedPath;
            return true;
        }

        // Intento 2: Búsqueda en directorios
        foreach ($this->searchPaths as $path) {
            $fullPath = $path . DIRECTORY_SEPARATOR . $fullClassName . '.php';
            if (file_exists($fullPath)) {
                require_once $fullPath;
                $this->cache->set($fullClassName, $fullPath);
                return true;
            }
        }

        return false;
    }

    public function clearCache(): void
    {
        $this->cache->clear();
    }

    public function getCache(): object
    {
        return $this->cache;
    }
};

// Warm-up: Cargar todas las clases una vez
echo "Warm-up: Loading all classes (first time - cache miss)...\n";
$start = microtime(true);
for ($i = 0; $i < $classCount; $i++) {
    $className = "TestClass$i";
    $autoloaderExternal->loadClass($className);
}
$warmupTime = (microtime(true) - $start) * 1000;
echo "  Warm-up time: " . number_format($warmupTime, 2) . " ms\n\n";

// Prueba de carga con cache caliente
echo "Loading all classes with warm cache (10 iterations)...\n";
$start = microtime(true);
for ($iter = 0; $iter < $iterations; $iter++) {
    for ($i = 0; $i < $classCount; $i++) {
        $className = "TestClass$i";
        // Verificar si está en cache
        $cachedPath = $autoloaderExternal->getCache()->get($className);
        if ($cachedPath && file_exists($cachedPath)) {
            continue;
        }
    }
}
$externalTime = ((microtime(true) - $start) * 1000) / $iterations;
echo "  Avg time per iteration: " . number_format($externalTime, 4) . " ms\n\n";

// ==========================================
// COMPARACIÓN Y RESULTADOS
// ==========================================
echo "==============================================\n";
echo "  RESULTS COMPARISON\n";
echo "==============================================\n\n";

printf("%-30s | %-20s | %-20s\n", "Metric", "Internal Cache", "External Adapter");
echo str_repeat("-", 75) . "\n";
printf("%-30s | %-20.4f ms | %-20.4f ms\n", "Avg Load Time (hot cache)", $internalTime, $externalTime);

$difference = $externalTime - $internalTime;
$percentageDiff = ($internalTime > 0) ? (($difference / $internalTime) * 100) : 0;

echo "\n";
if ($difference > 0) {
    echo "  ⚠ External adapter is " . number_format(abs($percentageDiff), 2) . "% SLOWER\n";
    echo "  → Overhead from KeyValueWriterInterface abstraction\n";
} else {
    echo "  ✓ External adapter is " . number_format(abs($percentageDiff), 2) . "% FASTER\n";
    echo "  → OPcache benefits from PHP include files\n";
}

echo "\n==============================================\n";
echo "  ANALYSIS\n";
echo "==============================================\n\n";

echo "Internal Cache Pros:\n";
echo "  • Simple implementation\n";
echo "  • Direct serialize/unserialize\n";
echo "  • Single file I/O\n\n";

echo "Internal Cache Cons:\n";
echo "  • Hardcoded implementation\n";
echo "  • No flexibility for different storage backends\n";
echo "  • Must rewrite entire file on each change\n";
echo "  • No TTL support\n\n";

echo "External Adapter Pros:\n";
echo "  • Uses existing contracts (KeyValueWriterInterface)\n";
echo "  • Can swap implementations (SQLite, Redis, etc.)\n";
echo "  • Supports TTL via CacheInterface\n";
echo "  • Better testability\n";
echo "  • Individual key deletion possible\n\n";

echo "External Adapter Cons:\n";
echo "  • Additional abstraction layer\n";
echo "  • May have slight performance overhead\n";
echo "  • More complex directory structure\n\n";

echo "==============================================\n";
echo "  RECOMMENDATION\n";
echo "==============================================\n\n";

if ($percentageDiff < 10) {
    echo "  ✓ MIGRATE to External Adapter\n";
    echo "  • Performance difference is negligible (< 10%)\n";
    echo "  • Benefits of using contracts outweigh minor overhead\n";
    echo "  • Enables future optimizations (Redis, SQLite, etc.)\n";
} else {
    echo "  ⚠ CONSIDER hybrid approach\n";
    echo "  • Performance gap is significant (>= 10%)\n";
    echo "  • Keep internal cache for critical path\n";
    echo "  • Use external adapter only when specific features needed\n";
}

// Limpieza
if (file_exists($internalCacheFile)) {
    unlink($internalCacheFile);
}
$directoryAdapter->clear();

// Eliminar directorio temporal
array_map('unlink', glob("$testDir/classes/*.php"));
rmdir("$testDir/classes");
rmdir($cacheDir);
rmdir($testDir);

echo "\n✓ Benchmark complete. Temp files cleaned.\n";
echo "==============================================\n";
