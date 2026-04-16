<?php

namespace RapidBase\Core\Cache\Adapters;

/**
 * MemcachedCacheAdapter - Memcached Cache Adapter for Distributed Caching
 * 
 * High-performance cache adapter using Memcached server.
 * Supports TTL, persistence across requests, and distributed environments.
 */
class MemcachedCacheAdapter
{
    /**
     * @var \Memcached Memcached client instance
     */
    private \Memcached $memcached;
    
    /**
     * @var string Key prefix
     */
    private string $prefix;

    public function __construct(array $config = [])
    {
        $this->memcached = new \Memcached();
        
        $servers = $config['servers'] ?? [
            ['host' => '127.0.0.1', 'port' => 11211, 'weight' => 100]
        ];
        
        foreach ($servers as $server) {
            $this->memcached->addServer(
                $server['host'] ?? '127.0.0.1',
                $server['port'] ?? 11211,
                $server['weight'] ?? 100
            );
        }
        
        // Optional SASL authentication
        if (isset($config['username']) && isset($config['password'])) {
            $this->memcached->setOption(\Memcached::OPT_BINARY_PROTOCOL, true);
            $this->memcached->setSaslAuthData($config['username'], $config['password']);
        }
        
        // Set options for better performance
        $this->memcached->setOption(\Memcached::OPT_COMPRESSION, true);
        $this->memcached->setOption(\Memcached::OPT_SERIALIZER, \Memcached::SERIALIZER_PHP);
        
        $this->prefix = $config['prefix'] ?? 'rb_memc_';
    }

    /**
     * Retrieve value from Memcached
     */
    public function get(string $key): mixed
    {
        $fullKey = $this->prefix . $key;
        $value = $this->memcached->get($fullKey);
        
        if ($this->memcached->getResultCode() === \Memcached::RES_NOTFOUND) {
            return null;
        }
        
        return $value;
    }

    /**
     * Store value in Memcached with optional TTL
     */
    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $fullKey = $this->prefix . $key;
        return $this->memcached->set($fullKey, $value, $ttl > 0 ? $ttl : 0);
    }

    /**
     * Check if key exists in Memcached
     */
    public function has(string $key): bool
    {
        $fullKey = $this->prefix . $key;
        $this->memcached->get($fullKey);
        return $this->memcached->getResultCode() !== \Memcached::RES_NOTFOUND;
    }

    /**
     * Delete key from Memcached
     */
    public function delete(string $key): bool
    {
        $fullKey = $this->prefix . $key;
        return $this->memcached->delete($fullKey);
    }

    /**
     * Clear all keys with current prefix (Memcached doesn't support pattern deletion)
     * This flushes ALL keys - use with caution!
     */
    public function flush(): bool
    {
        return $this->memcached->flush();
    }

    /**
     * Get multiple keys from Memcached
     */
    public function getMultiple(array $keys): array
    {
        $fullKeys = array_map(fn($k) => $this->prefix . $k, $keys);
        $result = $this->memcached->getMulti($fullKeys);
        
        if ($result === false) {
            $result = [];
        }
        
        // Re-index with original keys
        $finalResult = [];
        foreach ($keys as $key) {
            $fullKey = $this->prefix . $key;
            $finalResult[$key] = $result[$fullKey] ?? null;
        }
        
        return $finalResult;
    }

    /**
     * Set multiple keys in Memcached
     */
    public function setMultiple(array $values, int $ttl = 0): bool
    {
        $items = [];
        foreach ($values as $key => $value) {
            $items[$this->prefix . $key] = $value;
        }
        return $this->memcached->setMulti($items, $ttl > 0 ? $ttl : 0);
    }

    /**
     * Delete multiple keys from Memcached
     */
    public function deleteMultiple(array $keys): bool
    {
        $fullKeys = array_map(fn($k) => $this->prefix . $k, $keys);
        return $this->memcached->deleteMulti($fullKeys);
    }

    /**
     * Get Memcached stats
     */
    public function getStats(): array
    {
        $stats = $this->memcached->getStats();
        $version = $this->memcached->getVersion();
        
        return [
            'adapter' => 'MemcachedCacheAdapter',
            'type' => 'distributed_persistent',
            'memcached_version' => reset($version) ?? 'unknown',
            'bytes_used' => $stats['bytes'] ?? 0,
            'bytes_available' => $stats['limit_maxbytes'] ?? 0,
            'curr_items' => $stats['curr_items'] ?? 0,
            'total_items' => $stats['total_items'] ?? 0,
            'curr_connections' => $stats['curr_connections'] ?? 0,
            'hit_rate' => $stats['get_hits'] / max(1, $stats['get_hits'] + $stats['get_misses']) ?? 0
        ];
    }

    /**
     * Increment a counter atomically
     */
    public function increment(string $key, int $step = 1): int
    {
        $fullKey = $this->prefix . $key;
        $result = $this->memcached->increment($fullKey, $step);
        return $result !== false ? $result : 0;
    }

    /**
     * Decrement a counter atomically
     */
    public function decrement(string $key, int $step = 1): int
    {
        $fullKey = $this->prefix . $key;
        $result = $this->memcached->decrement($fullKey, $step);
        return $result !== false ? $result : 0;
    }
}
