<?php

namespace RapidBase\Core\Cache\Adapters;

use RapidBase\Core\Contracts\KeyValueInterface;

/**
 * SessionCacheAdapter - PHP Session Cache Adapter
 * 
 * Ideal para estados de conexión y resultados de queries rápidos.
 * Los datos persisten durante la sesión del usuario.
 */
class SessionCacheAdapter implements KeyValueInterface
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

    /**
     * @inheritDoc
     */
    public function get(string $key): mixed
    {
        if (!isset($_SESSION[$this->sessionKey][$key])) {
            return null;
        }

        $entry = $_SESSION[$this->sessionKey][$key];

        if (time() >= $entry['expires_at']) {
            $this->delete($key);
            return null;
        }

        return $entry['data'];
    }

    /**
     * @inheritDoc
     */
    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $ttl = is_numeric($ttl) && $ttl > 0 ? (int)$ttl : $this->defaultTtl;
        
        $_SESSION[$this->sessionKey][$key] = [
            'data'       => $value,
            'expires_at' => time() + $ttl
        ];
        return true;
    }

    /**
     * @inheritDoc
     */
    public function has(string $key): bool
    {
        if (!isset($_SESSION[$this->sessionKey][$key])) {
            return false;
        }

        $entry = $_SESSION[$this->sessionKey][$key];
        
        if (time() >= $entry['expires_at']) {
            $this->delete($key);
            return false;
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function delete(string $key): bool
    {
        if (isset($_SESSION[$this->sessionKey][$key])) {
            unset($_SESSION[$this->sessionKey][$key]);
        }
        return true;
    }

    /**
     * @inheritDoc
     * 
     * Borrado masivo o por prefijo
     */
    public function clear(?string $prefix = null): bool
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
        return true;
    }

    /**
     * @inheritDoc
     */
    public function all(string $prefix = ''): array
    {
        $results = [];
        
        foreach ($_SESSION[$this->sessionKey] as $key => $entry) {
            // Verificar que no haya expirado
            if (isset($entry['expires_at']) && time() >= $entry['expires_at']) {
                continue;
            }
            
            // Filtrar por prefijo si se especificó
            if ($prefix === '' || str_starts_with($key, $prefix)) {
                $results[$key] = $entry['data'] ?? null;
            }
        }
        
        return $results;
    }
}