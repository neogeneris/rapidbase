<?php

declare(strict_types=1);

namespace RapidBase\Autoloader;

use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use FilesystemIterator;

/**
 * Autoloader - Sistema de carga de clases con caché, estadísticas y debug.
 * 
 * Este autoloader busca clases recursivamente dentro de los directorios registrados,
 * utiliza un caché en disco para mejorar el rendimiento y recopila estadísticas
 * para optimizaciones inteligentes. Funciona incluso si los archivos se mueven
 * de carpeta, mientras estén dentro de los searchPaths.
 */
final class Autoloader
{
    private static ?Autoloader $instance = null;
    private array $searchPaths = [];
    private object $cache;
    private bool $debug = false;
    private bool $cacheEnabled = true;
    private bool $statsEnabled = false;
    private bool $strictMode = false; // Si es true, lanza excepción cuando no encuentra una clase
    private array $classUsageStats = [];
    private array $fileDependencies = [];
    private array $fileExecutionCount = [];
    private array $classLoadPercentage = [];
    private int $preloadThreshold = 3;
    private string $statsFile;
    private string $cacheFile;
    private int $globalExecutionCounter = 0;
    private int $maxExecutionForStats = 0;
    private ?string $cacheDirectory = null;

    /**
     * Establece el directorio donde se guardarán los archivos de caché y estadísticas del autoloader.
     * 
     * @param string $directory Directorio donde se guardarán los archivos .dat
     * @return self
     * @throws \RuntimeException Si el directorio no existe o no es escribible
     */
    public function setCacheDirectory(string $directory): self
    {
        $directory = rtrim($directory, '/\\\\');
        
        if (!is_dir($directory)) {
            throw new \RuntimeException("El directorio de caché no existe: $directory");
        }
        
        if (!is_writable($directory)) {
            throw new \RuntimeException("El directorio de caché no es escribible: $directory");
        }
        
        $this->cacheDirectory = $directory;
        $this->cacheFile = $directory . '/autoloader_cache.dat';
        $this->statsFile = $directory . '/autoloader_stats.dat';
        
        // Reinicializar el cache con la nueva ruta
        $this->initDefaultCache();
        
        return $this;
    }
    
    /**
     * Obtiene el directorio actual de caché.
     * 
     * @return string|null La ruta del directorio de caché o null si usa el default
     */
    public function getCacheDirectory(): ?string
    {
        return $this->cacheDirectory;
    }

    /**
     * Constructor privado para el patrón Singleton.
     */
    private function __construct(string $basePath)
    {
        $basePath = rtrim($basePath, '/\\');
        if (!is_dir($basePath)) {
            throw new \RuntimeException("El directorio base no existe: $basePath");
        }

        $this->statsFile = $basePath . '/autoloader_stats.dat';
        $this->cacheFile = $basePath . '/autoloader_cache.dat';

        $this->addPath($basePath);
        $this->initDefaultCache();
        $this->loadStats();
    }

    /**
     * Obtiene la instancia única del autoloader.
     */
    public static function getInstance(string $basePath): self
    {
        if (self::$instance === null) {
            self::$instance = new self($basePath);
        }
        return self::$instance;
    }

    /**
     * Resetea la instancia singleton (útil para testing y benchmarks).
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    /**
     * Inicializa el sistema de caché en disco.
     */
    private function initDefaultCache(): void
    {
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

            public function flush(): void
            {
                $this->data = [];
                $this->persist();
            }

            public function persist(): void
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

    // --------------------------
    // Configuración
    // --------------------------

    public function setCache(object $cache): self
    {
        $this->cache = $cache;
        return $this;
    }

    public function enableCache(bool $enabled = true): self
    {
        $this->cacheEnabled = $enabled;
        return $this;
    }

    public function addPath(string $path): self
    {
        if ($realPath = realpath($path)) {
            $this->searchPaths[] = rtrim($realPath, '/\\');
            $this->debug("Added path: $realPath");
        } else {
            $this->debug("Failed to add path: $path", true);
        }
        return $this;
    }

    public function enableDebug(bool $debug = true): self
    {
        $this->debug = $debug;
        return $this;
    }

    public function enableStats(bool $enabled = true): self
    {
        $this->statsEnabled = $enabled;
        if ($enabled) {
            $this->loadStats();
            $this->fileDependencies = $this->fileDependencies ?? [];
        }
        return $this;
    }

    /**
     * Establece el modo estricto para el autoloader.
     * 
     * En modo estricto (desarrollo), el autoloader lanza una excepción cuando no encuentra una clase.
     * En modo normal (producción), retorna false silenciosamente permitiendo que otros autoloaders intenten cargarla.
     */
    public function setStrictMode(bool $strict = true): self
    {
        $this->strictMode = $strict;
        return $this;
    }

    /**
     * Establece el umbral máximo de ejecuciones para la recolección de estadísticas.
     */
    public function setMaxExecutionForStats(int $maxExecutions): self
    {
        $this->maxExecutionForStats = max(0, $maxExecutions);
        return $this;
    }

    public function setPreloadThreshold(int $threshold): self
    {
        $this->preloadThreshold = $threshold;
        return $this;
    }

    // --------------------------
    // Registro y Carga
    // --------------------------

    public function register(): self
    {
        spl_autoload_register([$this, 'loadClass']);
        return $this;
    }

    public function loadClass(string $fullClassName): bool
    {
        $this->incrementCallerExecution();

        $this->debug("Trying to load: $fullClassName", ['className' => $fullClassName]);

        if (class_exists($fullClassName, false) || interface_exists($fullClassName, false) || trait_exists($fullClassName, false)) {
            $this->debug("Already loaded: $fullClassName");
            return true;
        }

        // Intento 1: Caché
        if ($this->cacheEnabled) {
            $cachedPath = $this->cache->get($fullClassName);
            if ($cachedPath && file_exists($cachedPath)) {
                $this->loadFile($cachedPath);
                $this->trackUsage($fullClassName);
                return true;
            }
            $this->debug("Cache miss for: $fullClassName");
        }

        // Intento 2: Búsqueda estándar PSR-4
        $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, $fullClassName) . '.php';
        foreach ($this->searchPaths as $path) {
            $fullPath = $path . DIRECTORY_SEPARATOR . $relativePath;
            if (file_exists($fullPath)) {
                $this->loadFile($fullPath);
                if ($this->cacheEnabled) {
                    $this->cache->set($fullClassName, $fullPath);
                }
                $this->trackUsage($fullClassName);
                return true;
            }
        }

        // Intento 3: Búsqueda recursiva
        foreach ($this->searchPaths as $path) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $filePath = $file->getRealPath();
                    if ($this->fileContainsClass($filePath, $fullClassName)) {
                        $this->loadFile($filePath);
                        if ($this->cacheEnabled) {
                            $this->cache->set($fullClassName, $filePath);
                        }
                        $this->trackUsage($fullClassName);
                        return true;
                    }
                }
            }
        }

        $this->debug("Failed to load class: $fullClassName", [], true);
        
        if ($this->strictMode) {
            throw new \RuntimeException("Autoloader no pudo encontrar la clase: $fullClassName. Verifica que el archivo exista y esté en uno de los directorios registrados.");
        }
        
        return false;
    }

    /**
     * Incrementa el contador de ejecución del archivo principal.
     */
    private function incrementCallerExecution(): void
    {
        if (!$this->statsEnabled) {
            return;
        }

        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        $callerFile = $backtrace[count($backtrace) - 1]['file'] ?? null;

        if ($callerFile) {
            $this->fileExecutionCount[$callerFile] = ($this->fileExecutionCount[$callerFile] ?? 0) + 1;
            $this->updateLoadPercentages($callerFile);

            $this->globalExecutionCounter++;
            if ($this->maxExecutionForStats > 0 && $this->globalExecutionCounter >= $this->maxExecutionForStats) {
                $this->enableStats(false);
                $this->saveStats();
                $this->debug("Stats collection automatically disabled after reaching {$this->maxExecutionForStats} executions.", [], true);
            }
        }
    }

    private function loadFile(string $filePath): void
    {
        require_once $filePath;
        $classCount = $this->countClassesInFile($filePath);
        $this->debug("Loaded file: $filePath", [
            'filePath' => $filePath,
            'classCount' => $classCount
        ]);
    }

    // --------------------------
    // Estadísticas y Tracking
    // --------------------------

    private function updateLoadPercentages(string $callerFile): void
    {
        $executionCount = $this->fileExecutionCount[$callerFile] ?? 1;
        if (isset($this->fileDependencies[$callerFile])) {
            foreach ($this->fileDependencies[$callerFile] as $className => $loadCount) {
                $safeExecutionCount = max(1, $executionCount);
                $this->classLoadPercentage[$className] = ($loadCount / $safeExecutionCount) * 100;
            }
        }
    }

    private function trackUsage(string $className): void
    {
        if (!$this->statsEnabled) {
            return;
        }

        $this->classUsageStats[$className] = ($this->classUsageStats[$className] ?? 0) + 1;

        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        if (isset($backtrace[2]['file'])) {
            $callingFile = $backtrace[2]['file'];
            $this->fileDependencies[$callingFile][$className] = ($this->fileDependencies[$callingFile][$className] ?? 0) + 1;

            $this->debug("Tracking usage for: $className", [
                'className' => $className,
                'callerFile' => $callingFile
            ]);
        }
    }

    // --------------------------
    // Métodos Auxiliares
    // --------------------------

    private function fileContainsClass(string $filePath, string $className): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }
        $content = file_get_contents($filePath);
        $pattern = '/\b(?:class|interface|trait)\s+' . preg_quote($className, '/') . '\b/i';
        return preg_match($pattern, $content) === 1;
    }

    private function countClassesInFile(string $filePath): int
    {
        if (!file_exists($filePath)) {
            return 0;
        }
        $content = file_get_contents($filePath);
        preg_match_all('/\b(?:class|interface|trait)\s+(\w+)/i', $content, $matches);
        return count($matches[1]);
    }

    // --------------------------
    // Debug y Formateo
    // --------------------------

    private function debug(string $message, array $context = [], bool $isError = false): void
    {
        if ($this->debug) {
            $prefix = $isError ? "ERROR: " : "";
            $output = "[Autoloader] " . date('Y-m-d H:i:s') . " - $prefix$message";

            if (isset($context['className']) && (!empty($this->classUsageStats) || !empty($this->fileExecutionCount))) {
                $className = $context['className'];
                $usage = $this->classUsageStats[$className] ?? 0;

                $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
                $mainScriptFile = $backtrace[count($backtrace) - 1]['file'] ?? 'unknown_main_script';
                $executions = $this->fileExecutionCount[$mainScriptFile] ?? 1;

                if ($executions === 0) {
                    $executions = 1;
                }

                $percentage = ($usage / $executions) * 100;

                $output .= sprintf(
                    "\n  | Load rate: %.2f%% (%d/%d)",
                    $percentage,
                    $usage,
                    $executions
                );

                if (!$this->statsEnabled) {
                    $output .= " (Stats collection OFF)";
                }
            }

            echo $output . PHP_EOL;
        }
    }

    // --------------------------
    // Precarga Inteligente
    // --------------------------

    public function preloadSmart(): void
    {
        foreach ($this->classLoadPercentage as $class => $percentage) {
            $callerFile = $this->getCallerOfFile($class);
            $mainScriptFile = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)[count(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)) - 1]['file'] ?? 'unknown_main_script';
            $executionCount = $this->fileExecutionCount[$mainScriptFile] ?? 0;

            if ($percentage >= 99.9 && $executionCount >= $this->preloadThreshold && !class_exists($class, false)) {
                $this->debug("Smart preloading high-probability class: $class", ['className' => $class]);
                $this->loadClass($class);
            }
        }

        $this->preloadFrequentClasses();
    }

    public function preloadFrequentClasses(): void
    {
        foreach ($this->classUsageStats as $class => $count) {
            if ($count >= $this->preloadThreshold && !class_exists($class, false)) {
                $this->debug("Preloading frequent class: $class (used $count times)", ['className' => $class]);
                $this->loadClass($class);
            }
        }
    }

    private function getCallerOfFile(string $className): ?string
    {
        foreach ($this->fileDependencies as $file => $classes) {
            if (isset($classes[$className])) {
                return $file;
            }
        }
        return null;
    }

    // --------------------------
    // Persistencia
    // --------------------------

    private function loadStats(): void
    {
        if (file_exists($this->statsFile)) {
            $data = unserialize(file_get_contents($this->statsFile));
            $this->classUsageStats = $data['classUsageStats'] ?? [];
            $this->fileDependencies = $data['fileDependencies'] ?? [];
            $this->fileExecutionCount = $data['fileExecutionCount'] ?? [];
            $this->globalExecutionCounter = $data['globalExecutionCounter'] ?? 0;
        }
    }

    public function saveStats(): void
    {
        if ($this->statsEnabled) {
            $data = [
                'classUsageStats' => $this->classUsageStats,
                'fileDependencies' => $this->fileDependencies,
                'fileExecutionCount' => $this->fileExecutionCount,
                'globalExecutionCounter' => $this->globalExecutionCounter
            ];
            file_put_contents($this->statsFile, serialize($data), LOCK_EX);
        }
    }

    // --------------------------
    // Utilidades
    // --------------------------

    public function clearCache(): void
    {
        $this->cache->flush();
        if (file_exists($this->statsFile)) {
            unlink($this->statsFile);
        }
        $this->classUsageStats = [];
        $this->fileDependencies = [];
        $this->fileExecutionCount = [];
        $this->classLoadPercentage = [];
        $this->globalExecutionCounter = 0;
    }
}
