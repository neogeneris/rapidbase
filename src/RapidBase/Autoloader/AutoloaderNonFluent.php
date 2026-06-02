<?php

namespace RapidBase\Autoloader;

use RapidBase\Core\Contracts\KeyValueWriterInterface;

/**
 * Autoloader optimizado sin patrón Fluent.
 * 
 * Los métodos no retornan $this, lo que reduce ligeramente la sobrecarga
 * de la pila de llamadas y permite un análisis estático más claro.
 */
class AutoloaderNonFluent
{
    private static ?self $instance = null;
    private string $basePath;
    private array $cache = [];
    private bool $strictMode = false;
    private bool $debug = false;
    private string $cacheDirectory;
    private ?KeyValueWriterInterface $customCache = null;
    private array $stats = [
        'hits' => 0,
        'misses' => 0,
        'load_time_total' => 0.0,
        'calls' => 0
    ];

    private function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, DIRECTORY_SEPARATOR);
        $this->cacheDirectory = $this->basePath;
        $this->loadCache();
    }

    public static function getInstance(string $basePath): self
    {
        if (self::$instance === null) {
            self::$instance = new self($basePath);
        }
        return self::$instance;
    }

    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    public function register(): void
    {
        spl_autoload_register([$this, 'loadClass']);
    }

    public function unregister(): void
    {
        spl_autoload_unregister([$this, 'loadClass']);
    }

    public function setStrictMode(bool $mode): void
    {
        $this->strictMode = $mode;
    }

    public function isStrictMode(): bool
    {
        return $this->strictMode;
    }

    public function enableDebug(bool $enable): void
    {
        $this->debug = $enable;
    }

    public function isDebug(): bool
    {
        return $this->debug;
    }

    public function setCacheDirectory(string $path): void
    {
        if (!is_dir($path)) {
            throw new \RuntimeException("El directorio de caché no existe: {$path}");
        }
        if (!is_writable($path)) {
            throw new \RuntimeException("El directorio de caché no es escribible: {$path}");
        }
        $this->cacheDirectory = rtrim($path, DIRECTORY_SEPARATOR);
    }

    public function getCacheDirectory(): string
    {
        return $this->cacheDirectory;
    }

    public function setCache(KeyValueWriterInterface $cache): void
    {
        $this->customCache = $cache;
    }

    public function loadClass(string $class): bool
    {
        $startTime = microtime(true);
        $this->stats['calls']++;

        // 1. Revisar cache RAM
        if (isset($this->cache[$class])) {
            $file = $this->cache[$class];
            if (file_exists($file)) {
                require_once $file;
                $this->recordHit(microtime(true) - $startTime);
                return true;
            }
            // Archivo eliminado, limpiar cache
            unset($this->cache[$class]);
        }

        // 2. Buscar en filesystem
        $file = $this->findFile($class);

        if ($file) {
            require_once $file;
            $this->cache[$class] = $file;
            $this->persist();
            $this->recordHit(microtime(true) - $startTime);
            return true;
        }

        // 3. No encontrado
        $this->recordMiss();

        if ($this->strictMode) {
            throw new \RuntimeException("Autoloader: Clase no encontrada '{$class}'");
        }

        return false;
    }

    public function flushCache(): void
    {
        $this->cache = [];
        $this->persist();
    }

    public function getStats(): array
    {
        return $this->stats;
    }

    private function findFile(string $class): ?string
    {
        // Convertir namespace a ruta
        $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php';
        
        // Intentar en basePath
        $fullPath = $this->basePath . DIRECTORY_SEPARATOR . $relativePath;
        if (file_exists($fullPath)) {
            return $fullPath;
        }

        // Aquí se podrían agregar más estrategias (PSR-4 dirs, etc.)
        return null;
    }

    private function loadCache(): void
    {
        if ($this->customCache !== null) {
            $data = $this->customCache->get('autoloader_map');
            if ($data && is_array($data)) {
                $this->cache = $data;
            }
            return;
        }

        $cacheFile = $this->cacheDirectory . DIRECTORY_SEPARATOR . 'autoloader_cache.dat';
        
        if (file_exists($cacheFile)) {
            $content = file_get_contents($cacheFile);
            if ($content !== false) {
                $data = @unserialize($content);
                if (is_array($data)) {
                    $this->cache = $data;
                }
            }
        }
    }

    private function persist(): void
    {
        if ($this->customCache !== null) {
            $this->customCache->set('autoloader_map', $this->cache);
            return;
        }

        $cacheFile = $this->cacheDirectory . DIRECTORY_SEPARATOR . 'autoloader_cache.dat';
        $content = serialize($this->cache);
        
        file_put_contents($cacheFile, $content, LOCK_EX);
    }

    private function recordHit(float $time): void
    {
        $this->stats['hits']++;
        $this->stats['load_time_total'] += $time;
        
        if ($this->debug) {
            echo "[HIT] Cargada en " . number_format($time * 1000, 4) . "ms\n";
        }
    }

    private function recordMiss(): void
    {
        $this->stats['misses']++;
        
        if ($this->debug) {
            echo "[MISS] Clase no encontrada\n";
        }
    }
}
