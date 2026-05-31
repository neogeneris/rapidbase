<?php

namespace RapidBase\Core;

use RapidBase\Core\Contracts\KeyValueInterface;

/**
 * Class Settings
 * 
 * Gestiona configuraciones de la aplicación utilizando un almacén clave-valor.
 * Usa la barra "/" como separador para claves jerárquicas.
 * 
 * @package RapidBase\Core
 */
class Settings
{
    /**
     * @var KeyValueInterface
     */
    private KeyValueInterface $cache;

    /**
     * @var string Prefijo para todas las claves de configuración
     */
    private string $prefix = 'settings/';

    /**
     * Constructor
     * 
     * @param KeyValueInterface $cache Instancia del almacén clave-valor
     * @param string $prefix Prefijo opcional para las claves
     */
    public function __construct(KeyValueInterface $cache, string $prefix = 'settings/')
    {
        $this->cache = $cache;
        $this->prefix = $prefix;
    }

    /**
     * Obtiene el valor de una configuración por clave.
     * Las claves pueden usar "/" para separadores jerárquicos.
     * 
     * @param string $key Clave de configuración (ej: "database/host")
     * @param mixed $default Valor por defecto si la clave no existe
     * @return mixed El valor almacenado o el valor por defecto
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $fullKey = $this->normalizeKey($key);
        
        if ($this->cache->has($fullKey)) {
            return $this->cache->get($fullKey);
        }
        
        return $default;
    }

    /**
     * Establece un valor de configuración.
     * 
     * @param string $key Clave de configuración (ej: "database/host")
     * @param mixed $value Valor a almacenar
     * @param int $ttl Tiempo de vida en segundos (0 para persistente)
     * @return bool True si se estableció correctamente
     */
    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $fullKey = $this->normalizeKey($key);
        return $this->cache->set($fullKey, $value, $ttl);
    }

    /**
     * Verifica si una clave de configuración existe.
     * 
     * @param string $key Clave de configuración
     * @return bool True si existe, false en caso contrario
     */
    public function has(string $key): bool
    {
        $fullKey = $this->normalizeKey($key);
        return $this->cache->has($fullKey);
    }

    /**
     * Elimina una configuración por clave.
     * 
     * @param string $key Clave de configuración
     * @return bool True si se eliminó, false si no existía
     */
    public function delete(string $key): bool
    {
        $fullKey = $this->normalizeKey($key);
        return $this->cache->delete($fullKey);
    }

    /**
     * Obtiene todas las configuraciones que coinciden con un prefijo.
     * 
     * @param string $prefix Prefijo para filtrar claves (ej: "database/")
     * @return array Array asociativo con las claves y valores
     */
    public function all(string $prefix = ''): array
    {
        $searchPrefix = $this->normalizeKey($prefix);
        return $this->cache->all($searchPrefix);
    }

    /**
     * Limpia todas las configuraciones o aquellas que coincidan con un prefijo.
     * 
     * @param string|null $prefix Prefijo opcional para limpiar solo un grupo
     * @return bool True si se limpió correctamente
     */
    public function clear(?string $prefix = null): bool
    {
        if ($prefix !== null) {
            $fullPrefix = $this->normalizeKey($prefix);
            return $this->cache->clear($fullPrefix);
        }
        
        return $this->cache->clear($this->prefix);
    }

    /**
     * Normaliza una clave asegurando que tenga el prefijo correcto.
     * Usa "/" como separador obligatorio.
     * 
     * @param string $key Clave a normalizar
     * @return string Clave normalizada con prefijo
     */
    private function normalizeKey(string $key): string
    {
        // Aseguramos que la clave use "/" como separador
        $key = str_replace('\\', '/', $key);
        $key = str_replace('.', '/', $key);
        
        // Eliminamos slash inicial si existe para evitar duplicados
        $key = ltrim($key, '/');
        
        return $this->prefix . $key;
    }
}
