<?php

namespace RapidBase\Core\Cache;

use RapidBase\Core\Cache\Adapters\DirectoryCacheAdapter;

class CacheService
{
    private static ?DirectoryCacheAdapter $adapter = null;
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

    /**
     * Obtiene un valor de la caché.
     */
    public static function get(string $key): mixed
    {
        if (!self::$enabled || !self::$adapter) {
            return null;
        }
        return self::$adapter->get($key);
    }

    /**
     * Guarda un valor en la caché.
     */
    public static function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        if (!self::$enabled || !self::$adapter) {
            return false;
        }
        return self::$adapter->set($key, $value, $ttl);
    }

    /**
     * Recupera o genera un valor mediante callback.
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
     * Limpia toda la caché o un prefijo (alias).
     */
    public static function clear(?string $prefix = null): void
    {
        if (self::$enabled && self::$adapter) {
            self::$adapter->clear($prefix);
        }
    }

    /**
     * Retorna la ruta base del adaptador.
     */
    public static function getPath(): ?string
    {
        return self::$adapter ? self::$adapter->getPath() : null;
    }
}