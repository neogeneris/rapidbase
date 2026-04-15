<?php

namespace RapidBase\Core\Cache\Adapters;

/**
 * DirectoryCacheAdapter: Persistencia en archivos .php con sharding.
 * Almacena la clave original dentro del payload para permitir borrado por prefijo.
 */
class DirectoryCacheAdapter
{
    private string $basePath;
    private int $defaultTtl;
    private array $memL1Cache = [];     // [key => ['data'=>mixed, 'expires_at'=>int]]
    private int $maxL1Size = 500;

    public function __construct(string $basePath, int $defaultTtl = 3600)
    {
        $this->basePath = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $this->defaultTtl = $defaultTtl;
        if (!is_dir($this->basePath)) {
            mkdir($this->basePath, 0775, true);
        }
    }

    public function getPath(): string
    {
        return $this->basePath;
    }

    public function set(string $key, mixed $value, mixed $ttl = null): bool
    {
        $ttl = is_numeric($ttl) ? (int)$ttl : $this->defaultTtl;
        $expiresAt = time() + $ttl;
        $path = $this->getStoragePath($key);

        $payload = [
            'key'        => $key,          // ← guardamos la clave para poder borrar por prefijo
            'expires_at' => $expiresAt,
            'data'       => $value
        ];

        $content = "<?php\nreturn " . var_export($payload, true) . ";";
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }

        $tempFile = tempnam($dir, 'tmp_');
        if ($tempFile === false) return false;
        file_put_contents($tempFile, $content);

        if (rename($tempFile, $path)) {
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($path, true);
            }
            $this->storeInL1($key, $value, $expiresAt);
            return true;
        }
        @unlink($tempFile);
        return false;
    }

    public function get(string $key): mixed
    {
        // 1. Memoria L1
        if (isset($this->memL1Cache[$key])) {
            $entry = $this->memL1Cache[$key];
            if (time() < $entry['expires_at']) {
                return $entry['data'];
            }
            unset($this->memL1Cache[$key]);
        }

        $path = $this->getStoragePath($key);
        if (!file_exists($path)) return null;

        $payload = include $path;
        if (!is_array($payload) || !isset($payload['expires_at'], $payload['data'], $payload['key'])) {
            $this->forget($key);
            return null;
        }

        if (time() >= $payload['expires_at']) {
            $this->forget($key);
            return null;
        }

        $this->storeInL1($payload['key'], $payload['data'], $payload['expires_at']);
        return $payload['data'];
    }

    public function forget(string $key): bool
    {
        unset($this->memL1Cache[$key]);
        $path = $this->getStoragePath($key);
        if (file_exists($path)) {
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($path, true);
            }
            return @unlink($path);
        }
        return true;
    }

    /**
     * Borra entradas de caché que coincidan con el prefijo (tanto en L1 como en disco).
     * Para el borrado físico en disco, escanea todos los archivos y comprueba la clave guardada.
     */
    public function clear(?string $prefix = null): void
    {
        // Limpiar L1
        if ($prefix === null) {
            $this->memL1Cache = [];
        } else {
            foreach ($this->memL1Cache as $k => $v) {
                if (str_starts_with($k, $prefix)) unset($this->memL1Cache[$k]);
            }
        }

        // Limpiar L2 (disco)
        if (!is_dir($this->basePath)) return;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->basePath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if (!$item->isFile()) continue;
            $file = $item->getPathname();
            if (pathinfo($file, PATHINFO_EXTENSION) !== 'php') continue;

            if ($prefix === null) {
                @unlink($file);
            } else {
                // Leer la clave guardada dentro del archivo
                $payload = include $file;
                if (is_array($payload) && isset($payload['key']) && str_starts_with($payload['key'], $prefix)) {
                    @unlink($file);
                }
            }
        }
    }

    private function getStoragePath(string $key): string
    {
        $hash = md5($key);
        return $this->basePath .
               substr($hash, 0, 2) . DIRECTORY_SEPARATOR .
               substr($hash, 2, 2) . DIRECTORY_SEPARATOR .
               $hash . '.php';
    }

    private function storeInL1(string $key, mixed $data, int $expiresAt): void
    {
        if (count($this->memL1Cache) >= $this->maxL1Size) {
            array_shift($this->memL1Cache);
        }
        $this->memL1Cache[$key] = ['data' => $data, 'expires_at' => $expiresAt];
    }
}