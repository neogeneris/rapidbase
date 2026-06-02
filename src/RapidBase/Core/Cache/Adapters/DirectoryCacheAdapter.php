<?php

declare(strict_types=1);

namespace RapidBase\Core\Cache\Adapters;

use RapidBase\Core\Contracts\CacheInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * DirectoryCacheAdapter: Persistencia de alto rendimiento en archivos .php con sharding.
 * Combina almacenamiento L1 en memoria y L2 en archivos nativos listos para OPcache.
 * * Implementa CacheInterface con soporte estricto para claves jerárquicas '/'.
 */
class DirectoryCacheAdapter implements CacheInterface
{
    private string $basePath;
    private array $memL1Cache = []; // [key => ['value' => mixed, 'expires_at' => int]]
    private int $maxL1Size = 500;
    private float $lastReadDuration = 0.0;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!is_dir($this->basePath)) {
            mkdir($this->basePath, 0775, true);
        }
    }

    /**
     * Obtiene la ruta física del archivo utilizando Sharding para evitar colapsar directorios.
     */
    private function getStoragePath(string $key): string
    {
        // Limpiamos o aplanamos sutilmente para el hash, pero usamos el separador original
        $hash = function_exists('xxh128') ? xxh128($key) : md5($key);
        
        // Estructura: basePath/ab/cd/hash.cache.php
        return $this->basePath .
               substr($hash, 0, 2) . DIRECTORY_SEPARATOR .
               substr($hash, 2, 2) . DIRECTORY_SEPARATOR .
               $hash . '.cache.php';
    }

    public function get(string $key, mixed $default = null): mixed
    {
        // 1. Intentar recuperar de Capa 1 (Memoria RAM del proceso)
        if (isset($this->memL1Cache[$key])) {
            $item = $this->memL1Cache[$key];
            if ($item['expires_at'] === 0 || time() < $item['expires_at']) {
                return $item['value'];
            }
            $this->delete($key);
            return $default;
        }

        // 2. Intentar recuperar de Capa 2 (Disco / OPcache)
        $file = $this->getStoragePath($key);
        if (!file_exists($file)) {
            return $default;
        }

        $start = microtime(true);
        $payload = include $file;
        $this->lastReadDuration = (microtime(true) - $start) * 1000;

        if (!is_array($payload) || !isset($payload['expires_at'], $payload['value'])) {
            return $default;
        }

        // Verificar expiración
        if ($payload['expires_at'] > 0 && time() >= $payload['expires_at']) {
            $this->delete($key);
            return $default;
        }

        // Guardar en L1 para futuras lecturas en la misma petición
        $this->storeInL1($key, $payload['value'], $payload['expires_at']);

        return $payload['value'];
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function set(string $key, mixed $value): void
    {
        // Un set normal guarda de forma persistente (TTL = 0)
        $this->setWithTtl($key, $value, 0);
    }

    public function setWithTtl(string $key, mixed $value, int $ttl): void
    {
        $file = $this->getStoragePath($key);
        $dir = dirname($file);
        
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $expiresAt = ($ttl === 0) ? 0 : time() + $ttl;

        $payload = [
            'key'        => $key, // Preservamos la llave con sus '/' originales
            'expires_at' => $expiresAt,
            'value'      => $value
        ];

        // Exportamos código ejecutable PHP nativo para activar OPcache
        $content = "<?php\nreturn " . var_export($payload, true) . ";\n";
        
        if (file_put_contents($file, $content, LOCK_EX) !== false) {
            $this->storeInL1($key, $value, $expiresAt);
        }
    }

    public function delete(string $key): void
    {
        // Remover de L1
        unset($this->memL1Cache[$key]);

        // Remover de L2 (Disco)
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

        // Recorremos recursivamente las subcarpetas creadas por el sharding
        $dirIterator = new RecursiveDirectoryIterator($this->basePath, RecursiveDirectoryIterator::SKIP_DOTS);
        $iterator = new RecursiveIteratorIterator($dirIterator, RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($iterator as $item) {
            if ($item->isFile() && $item->getExtension() === 'php') {
                unlink($item->getPathname());
            } elseif ($item->isDir()) {
                rmdir($item->getPathname());
            }
        }
    }

    private function storeInL1(string $key, mixed $value, int $expiresAt): void
    {
        if (count($this->memL1Cache) >= $this->maxL1Size) {
            array_shift($this->memL1Cache); // Evitamos desbordamiento de memoria
        }
        $this->memL1Cache[$key] = [
            'value'      => $value,
            'expires_at' => $expiresAt
        ];
    }

    public function getLastReadDuration(): float
    {
        return $this->lastReadDuration;
    }
}