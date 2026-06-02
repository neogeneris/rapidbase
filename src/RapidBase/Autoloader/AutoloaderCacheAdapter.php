<?php

declare(strict_types=1);

namespace RapidBase\Autoloader;

use RapidBase\Core\Contracts\KeyValueWriterInterface;

/**
 * AutoloaderCacheAdapter - Adapter específico para el Autoloader
 * Implementa KeyValueWriterInterface usando serialize/unserialize
 * 
 * Este adapter permite inyectar dependencias en el Autoloader mientras
 * mantiene la misma técnica de persistencia original (archivo .dat)
 */
class AutoloaderCacheAdapter implements KeyValueWriterInterface
{
    private string $filePath;
    private array $data = [];
    private bool $loaded = false;

    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->load();
        return $this->data[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        $this->load();
        return isset($this->data[$key]);
    }

    public function set(string $key, mixed $value): void
    {
        $this->load();
        $this->data[$key] = $value;
        $this->persist();
    }

    public function delete(string $key): void
    {
        $this->load();
        unset($this->data[$key]);
        $this->persist();
    }

    public function clear(): void
    {
        $this->data = [];
        $this->persist();
    }

    /**
     * Persiste los datos en disco
     * Método público para permitir flush manual si se necesita
     */
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

    /**
     * Obtiene el número de entradas en cache
     */
    public function count(): int
    {
        $this->load();
        return count($this->data);
    }

    /**
     * Obtiene todos los datos (para debugging)
     */
    public function all(): array
    {
        $this->load();
        return $this->data;
    }
}
