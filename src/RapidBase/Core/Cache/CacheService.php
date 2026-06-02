<?php

namespace RapidBase\Core\Cache;

use RapidBase\Core\Cache\Adapters\DirectoryCacheAdapter;
use RapidBase\Core\Contracts\CacheInterface;

class CacheService
{
    private static ?CacheInterface $adapter = null;
    private static bool $enabled = true;

    public static function init(string $path): void
    {
        try {
            self::$adapter = new DirectoryCacheAdapter($path);
            self::$enabled = true;
        } catch (\Exception $e) {
            self::$enabled = false;
        }
    }

    public static function enable(): void
    {
        self::$enabled = true;
    }

    public static function disable(): void
    {
        self::$enabled = false;
    }
	
    public static function hash(string $data): string
    {
        if (function_exists('xxh128')) {
            return xxh128($data);
        }
        return hash('crc32', $data);
    }

    /**
     * Obtiene un valor de la caché.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (!self::$enabled || !self::$adapter) {
            return $default;
        }
        return self::$adapter->get($key, $default);
    }

    /**
     * Guarda un valor en la caché con un tiempo de vida específico.
     */
    public static function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        if (!self::$enabled || !self::$adapter) {
            return false;
        }
        self::$adapter->setWithTtl($key, $value, $ttl);
        return true;
    }

    /**
     * Recupera o genera un valor mediante un callback ejecutable.
     */
    public static function remember(string $key, int $ttl, callable $callback): mixed
    {
        if (!self::$enabled || $ttl <= 0) {
            return $callback();
        }

        $value = self::get($key);
        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        self::set($key, $value, $ttl);
        return $value;
    }

    /**
     * Limpia entradas que coincidan con un prefijo.
     */
    public static function clearByPrefix(string $prefix): void
    {
        if (self::$enabled && self::$adapter) {
            self::$adapter->clear($prefix);
        }
    }

    /**
     * Limpia toda la caché o un prefijo específico.
     */
    public static function clear(?string $prefix = null): void
    {
        if (self::$enabled && self::$adapter) {
            self::$adapter->clear($prefix);
        }
    }

    public static function getPath(): ?string
    {
        return self::$adapter ? self::$adapter->getPath() : null;
    }

    public static function getLastReadDuration(): float
    {
        return self::$adapter ? self::$adapter->getLastReadDuration() : 0.0;
    }
}