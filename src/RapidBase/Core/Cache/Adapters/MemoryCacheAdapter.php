<?php

namespace RapidBase\Core\Cache\Adapters;

/**
 * MemoryCacheAdapter - In-Memory Cache for Single Request Lifecycle
 * 
 * Ultra-fast cache adapter using static PHP array.
 * Data persists only during the current request execution.
 * Ideal for L0 caching (faster than Redis/Memcached for repeated calls in same request).
 */
class MemoryCacheAdapter
{
    /**
     * @var array Static storage shared across all instances in same request
     */
    private static array $storage = [];
    
    /**
     * @var string Namespace prefix for keys
     */
    private string $prefix;

    public function __construct(array $config = [])
    {
        $this->prefix = $config['prefix'] ?? 'rb_mem_';
    }

    /**
     * Retrieve value from memory
     */
    public function get(string $key): mixed
    {
        $fullKey = $this->prefix . $key;
        return self::$storage[$fullKey] ?? null;
    }

    /**
     * Store value in memory
     */
    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $fullKey = $this->prefix . $key;
        self::$storage[$fullKey] = [
            'value' => $value,
            'expires' => $ttl > 0 ? time() + $ttl : PHP_INT_MAX
        ];
        return true;
    }

    /**
     * Check if key exists and is not expired
     */
    public function has(string $key): bool
    {
        $fullKey = $this->prefix . $key;
        if (!isset(self::$storage[$fullKey])) {
            return false;
        }
        
        $entry = self::$storage[$fullKey];
        if (time() > $entry['expires']) {
            unset(self::$storage[$fullKey]);
            return false;
        }
        
        return true;
    }

    /**
     * Delete key from memory
     */
    public function delete(string $key): bool
    {
        $fullKey = $this->prefix . $key;
        unset(self::$storage[$fullKey]);
        return true;
    }

    /**
     * Clear all keys with current prefix
     */
    public function flush(): bool
    {
        foreach (array_keys(self::$storage) as $key) {
            if (strpos($key, $this->prefix) === 0) {
                unset(self::$storage[$key]);
            }
        }
        return true;
    }

    /**
     * Get multiple keys
     */
    public function getMultiple(array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key);
        }
        return $result;
    }

    /**
     * Set multiple keys
     */
    public function setMultiple(array $values, int $ttl = 0): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }
        return true;
    }

    /**
     * Delete multiple keys
     */
    public function deleteMultiple(array $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }
        return true;
    }

    /**
     * Clear entire memory storage (use with caution)
     */
    public function flushAll(): bool
    {
        self::$storage = [];
        return true;
    }

    /**
     * Get stats (count of items)
     */
    public function getStats(): array
    {
        $count = 0;
        foreach (self::$storage as $key => $entry) {
            if (strpos($key, $this->prefix) === 0 && time() <= $entry['expires']) {
                $count++;
            }
        }
        
        return [
            'adapter' => 'MemoryCacheAdapter',
            'items' => $count,
            'type' => 'volatile_request'
        ];
    }
}
