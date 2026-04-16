<?php

namespace RapidBase\Core\Cache\Adapters;

/**
 * RedisCacheAdapter - Redis Cache Adapter for Distributed Caching
 * 
 * High-performance cache adapter using Redis server.
 * Supports TTL, persistence across requests, and distributed environments.
 */
class RedisCacheAdapter
{
    /**
     * @var \Redis Redis client instance
     */
    private \Redis $redis;
    
    /**
     * @var string Key prefix
     */
    private string $prefix;

    public function __construct(array $config = [])
    {
        $this->redis = new \Redis();
        
        $host = $config['host'] ?? '127.0.0.1';
        $port = $config['port'] ?? 6379;
        $timeout = $config['timeout'] ?? 2.5;
        $password = $config['password'] ?? null;
        $database = $config['database'] ?? 0;
        
        $this->redis->connect($host, $port, $timeout);
        
        if ($password) {
            $this->redis->auth($password);
        }
        
        if ($database > 0) {
            $this->redis->select($database);
        }
        
        $this->prefix = $config['prefix'] ?? 'rb_redis_';
    }

    /**
     * Retrieve value from Redis
     */
    public function get(string $key): mixed
    {
        $fullKey = $this->prefix . $key;
        $value = $this->redis->get($fullKey);
        
        if ($value === false) {
            return null;
        }
        
        return unserialize($value);
    }

    /**
     * Store value in Redis with optional TTL
     */
    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $fullKey = $this->prefix . $key;
        $serialized = serialize($value);
        
        if ($ttl > 0) {
            return $this->redis->setex($fullKey, $ttl, $serialized);
        }
        
        return $this->redis->set($fullKey, $serialized);
    }

    /**
     * Check if key exists in Redis
     */
    public function has(string $key): bool
    {
        $fullKey = $this->prefix . $key;
        return $this->redis->exists($fullKey) > 0;
    }

    /**
     * Delete key from Redis
     */
    public function delete(string $key): bool
    {
        $fullKey = $this->prefix . $key;
        return $this->redis->del($fullKey) > 0;
    }

    /**
     * Clear all keys with current prefix
     */
    public function flush(): bool
    {
        $keys = $this->redis->keys($this->prefix . '*');
        if (!empty($keys)) {
            $this->redis->del($keys);
        }
        return true;
    }

    /**
     * Get multiple keys from Redis
     */
    public function getMultiple(array $keys): array
    {
        $fullKeys = array_map(fn($k) => $this->prefix . $k, $keys);
        $values = $this->redis->mGet($fullKeys);
        
        $result = [];
        foreach ($keys as $index => $key) {
            $result[$key] = $values[$index] !== false ? unserialize($values[$index]) : null;
        }
        
        return $result;
    }

    /**
     * Set multiple keys in Redis
     */
    public function setMultiple(array $values, int $ttl = 0): bool
    {
        $pipe = $this->redis->multi(\Redis::PIPELINE);
        
        foreach ($values as $key => $value) {
            $fullKey = $this->prefix . $key;
            $serialized = serialize($value);
            
            if ($ttl > 0) {
                $pipe->setex($fullKey, $ttl, $serialized);
            } else {
                $pipe->set($fullKey, $serialized);
            }
        }
        
        return $pipe->exec() !== false;
    }

    /**
     * Delete multiple keys from Redis
     */
    public function deleteMultiple(array $keys): bool
    {
        $fullKeys = array_map(fn($k) => $this->prefix . $k, $keys);
        return $this->redis->del($fullKeys) > 0;
    }

    /**
     * Get Redis stats
     */
    public function getStats(): array
    {
        $info = $this->redis->info();
        $keysCount = count($this->redis->keys($this->prefix . '*'));
        
        return [
            'adapter' => 'RedisCacheAdapter',
            'items' => $keysCount,
            'type' => 'distributed_persistent',
            'redis_version' => $info['redis_version'] ?? 'unknown',
            'used_memory' => $info['used_memory_human'] ?? 'unknown',
            'connected_clients' => $info['connected_clients'] ?? 0
        ];
    }

    /**
     * Increment a counter atomically
     */
    public function increment(string $key, int $step = 1): int
    {
        $fullKey = $this->prefix . $key;
        return $this->redis->incrBy($fullKey, $step);
    }

    /**
     * Decrement a counter atomically
     */
    public function decrement(string $key, int $step = 1): int
    {
        $fullKey = $this->prefix . $key;
        return $this->redis->decrBy($fullKey, $step);
    }
}
