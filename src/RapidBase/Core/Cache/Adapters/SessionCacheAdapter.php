<?php

namespace RapidBase\Core\Cache\Adapters;

/**
 * SessionCacheAdapter: Persistencia en la sesión de PHP.
 * Ideal para estados de conexión y resultados de queries rápidos.
 */
class SessionCacheAdapter
{
    private string $sessionKey;
    private int $defaultTtl;

    public function __construct(string $namespace = 'rapidbase_cache', int $defaultTtl = 3600)
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $this->sessionKey = $namespace;
        $this->defaultTtl = $defaultTtl;

        if (!isset($_SESSION[$this->sessionKey])) {
            $_SESSION[$this->sessionKey] = [];
        }
    }

    public function set(string $key, mixed $value, mixed $ttl = null): bool
    {
        $ttl = is_numeric($ttl) ? (int)$ttl : $this->defaultTtl;
        
        $_SESSION[$this->sessionKey][$key] = [
            'data'       => $value, // No necesitamos serialize() manual, PHP lo hace en sesión
            'expires_at' => time() + $ttl
        ];
        return true;
    }

    public function get(string $key): mixed
    {
        if (!isset($_SESSION[$this->sessionKey][$key])) {
            return null;
        }

        $entry = $_SESSION[$this->sessionKey][$key];

        if (time() >= $entry['expires_at']) {
            $this->forget($key);
            return null;
        }

        return $entry['data'];
    }

    public function forget(string $key): bool
    {
        if (isset($_SESSION[$this->sessionKey][$key])) {
            unset($_SESSION[$this->sessionKey][$key]);
        }
        return true;
    }

    /**
     * Borrado masivo o por prefijo
     */
    public function clear(?string $prefix = null): void
    {
        if ($prefix === null) {
            $_SESSION[$this->sessionKey] = [];
        } else {
            foreach ($_SESSION[$this->sessionKey] as $k => $v) {
                if (str_starts_with($k, $prefix)) {
                    unset($_SESSION[$this->sessionKey][$k]);
                }
            }
        } 
    }

    // Métodos de compatibilidad con tu CacheService
    public function getPath(): string { return 'php://session'; }
    public function getLastReadDuration(): float { return 0.0001; } // Virtualmente instantáneo
}