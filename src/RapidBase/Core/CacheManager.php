<?php

namespace RapidBase\Core;

use RapidBase\Core\Contracts\KeyValueInterface;

/**
 * CacheManager - Gestor de instancias de adaptadores de caché.
 * 
 * Proporciona una interfaz unificada para trabajar con diferentes adaptadores
 * de caché que implementan KeyValueInterface.
 */
class CacheManager
{
    /**
     * @var array<string, KeyValueInterface> Instancias de adaptadores registradas
     */
    private static array $adapters = [];

    /**
     * @var KeyValueInterface Adaptador por defecto
     */
    private KeyValueInterface $defaultAdapter;

    /**
     * Constructor del gestor de caché.
     *
     * @param KeyValueInterface $adapter El adaptador de caché a utilizar.
     */
    public function __construct(KeyValueInterface $adapter)
    {
        $this->defaultAdapter = $adapter;
    }

    /**
     * Registra un adaptador con un nombre específico.
     *
     * @param string $name Nombre del adaptador.
     * @param KeyValueInterface $adapter Instancia del adaptador.
     * @return void
     */
    public static function register(string $name, KeyValueInterface $adapter): void
    {
        self::$adapters[$name] = $adapter;
    }

    /**
     * Obtiene un adaptador registrado por nombre.
     *
     * @param string $name Nombre del adaptador.
     * @return KeyValueInterface|null El adaptador o null si no existe.
     */
    public static function getAdapter(string $name): ?KeyValueInterface
    {
        return self::$adapters[$name] ?? null;
    }

    /**
     * Verifica si un adaptador está registrado.
     *
     * @param string $name Nombre del adaptador.
     * @return bool True si el adaptador existe.
     */
    public static function hasAdapter(string $name): bool
    {
        return isset(self::$adapters[$name]);
    }

    /**
     * Elimina un adaptador registrado.
     *
     * @param string $name Nombre del adaptador.
     * @return bool True si fue eliminado, false si no existía.
     */
    public static function removeAdapter(string $name): bool
    {
        if (isset(self::$adapters[$name])) {
            unset(self::$adapters[$name]);
            return true;
        }
        return false;
    }

    /**
     * Obtiene el adaptador por defecto.
     *
     * @return KeyValueInterface El adaptador por defecto.
     */
    public function getDefaultAdapter(): KeyValueInterface
    {
        return $this->defaultAdapter;
    }

    /**
     * @inheritDoc
     */
    public function get(string $key): mixed
    {
        return $this->defaultAdapter->get($key);
    }

    /**
     * @inheritDoc
     */
    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        return $this->defaultAdapter->set($key, $value, $ttl);
    }

    /**
     * @inheritDoc
     */
    public function has(string $key): bool
    {
        return $this->defaultAdapter->has($key);
    }

    /**
     * @inheritDoc
     */
    public function delete(string $key): bool
    {
        return $this->defaultAdapter->delete($key);
    }

    /**
     * @inheritDoc
     */
    public function clear(?string $prefix = null): bool
    {
        return $this->defaultAdapter->clear($prefix);
    }

    /**
     * Obtiene un valor de la caché o ejecuta un callback si no existe.
     *
     * @param string $key La clave del elemento.
     * @param callable $callback Función que genera el valor si no existe.
     * @param int $ttl Tiempo de vida en segundos.
     * @return mixed El valor obtenido de la caché o generado por el callback.
     */
    public function remember(string $key, callable $callback, int $ttl = 3600): mixed
    {
        $value = $this->get($key);
        
        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        $this->set($key, $value, $ttl);
        
        return $value;
    }
}