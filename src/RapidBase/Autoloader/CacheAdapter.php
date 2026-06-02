<?php

declare(strict_types=1);

namespace RapidBase\Autoloader;

use RapidBase\Core\Contracts\KeyValueWriterInterface;

/**
 * CacheAdapter para Autoloader - Implementación simple usando KeyValueWriterInterface
 * 
 * Esta clase envuelve cualquier implementación de KeyValueWriterInterface
 * para que pueda ser usada como reemplazo de la clase cache anónima interna
 * del Autoloader.
 */
class CacheAdapter implements KeyValueWriterInterface
{
    private KeyValueWriterInterface $adapter;
    private array $data = [];
    private bool $loaded = false;
    private string $cacheKeyPrefix = 'autoloader:';

    public function __construct(KeyValueWriterInterface $adapter)
    {
        $this->adapter = $adapter;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        // Intentar primero en memoria local (L1 cache)
        if (isset($this->data[$key])) {
            return $this->data[$key];
        }

        // Intentar desde el adapter subyacente
        $value = $this->adapter->get($this->cacheKeyPrefix . $key);
        
        if ($value !== null) {
            // Guardar en L1 cache
            $this->data[$key] = $value;
            return $value;
        }

        return $default;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function set(string $key, mixed $value): void
    {
        // Actualizar L1 cache
        $this->data[$key] = $value;
        
        // Persistir en el adapter
        $this->adapter->set($this->cacheKeyPrefix . $key, $value);
    }

    public function delete(string $key): void
    {
        unset($this->data[$key]);
        $this->adapter->delete($this->cacheKeyPrefix . $key);
    }

    public function clear(): void
    {
        $this->data = [];
        $this->adapter->clear();
    }

    /**
     * Obtiene estadísticas del adapter subyacente si están disponibles
     */
    public function getLastReadDuration(): float
    {
        if (method_exists($this->adapter, 'getLastReadDuration')) {
            return $this->adapter->getLastReadDuration();
        }
        return 0.0;
    }
}
