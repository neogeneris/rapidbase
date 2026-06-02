<?php

declare(strict_types=1);

namespace RapidBase\Tests\Performance;

use RapidBase\Core\Contracts\KeyValueWriterInterface;
use RapidBase\Core\Contracts\KeyValueReaderInterface;

/**
 * PhpFileCacheAdapter - Implementación de KeyValueWriterInterface
 * que usa archivos PHP con include (técnica de DirectoryCacheAdapter)
 * Optimizado para OPcache
 */
class PhpFileCacheAdapter implements KeyValueWriterInterface
{
    private string $basePath;
    private array $memL1Cache = [];
    private float $lastReadDuration = 0.0;
    private float $lastWriteDuration = 0.0;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!is_dir($this->basePath)) {
            mkdir($this->basePath, 0775, true);
        }
    }

    private function getStoragePath(string $key): string
    {
        // Usar hash para nombre de archivo seguro
        $hash = function_exists('xxh128') ? xxh128($key) : md5($key);
        return $this->basePath . $hash . '.cache.php';
    }

    public function get(string $key, mixed $default = null): mixed
    {
        // Intentar L1 cache primero
        if (isset($this->memL1Cache[$key])) {
            return $this->memL1Cache[$key];
        }

        // Leer desde archivo PHP
        $file = $this->getStoragePath($key);
        if (!file_exists($file)) {
            return $default;
        }

        $start = microtime(true);
        $value = include $file;
        $this->lastReadDuration = (microtime(true) - $start) * 1000;

        // Guardar en L1
        $this->memL1Cache[$key] = $value;
        
        return $value;
    }

    public function has(string $key): bool
    {
        if (isset($this->memL1Cache[$key])) {
            return true;
        }
        $file = $this->getStoragePath($key);
        return file_exists($file);
    }

    public function set(string $key, mixed $value): void
    {
        $file = $this->getStoragePath($key);
        
        $start = microtime(true);
        $content = "<?php\nreturn " . var_export($value, true) . ";\n";
        file_put_contents($file, $content, LOCK_EX);
        $this->lastWriteDuration = (microtime(true) - $start) * 1000;

        // Actualizar L1
        $this->memL1Cache[$key] = $value;
    }

    public function delete(string $key): void
    {
        unset($this->memL1Cache[$key]);
        $file = $this->getStoragePath($key);
        if (file_exists($file)) {
            unlink($file);
        }
    }

    public function clear(): void
    {
        $this->memL1Cache = [];
        
        if (!is_dir($this->basePath)) {
            return;
        }

        $files = glob($this->basePath . '*.cache.php');
        foreach ($files as $file) {
            unlink($file);
        }
    }

    public function getLastReadDuration(): float
    {
        return $this->lastReadDuration;
    }

    public function getLastWriteDuration(): float
    {
        return $this->lastWriteDuration;
    }

    public function getDataCount(): int
    {
        return count($this->memL1Cache);
    }
}
