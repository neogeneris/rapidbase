<?php

namespace RapidBase\Core;

use RapidBase\Core\Contracts\KeyValueInterface;

/**
 * Class Translator
 * 
 * Gestiona traducciones y mensajes utilizando un almacén clave-valor.
 * Usa la barra "/" como separador para claves jerárquicas (ej: "en/messages/welcome").
 * Soporta interpolación de parámetros en los mensajes.
 * 
 * @package RapidBase\Core
 */
class Translator
{
    /**
     * @var KeyValueInterface
     */
    private KeyValueInterface $cache;

    /**
     * @var string Prefijo para todas las claves de traducción
     */
    private string $prefix = 'translations/';

    /**
     * @var string Locale por defecto
     */
    private string $defaultLocale = 'en';

    /**
     * @var string Locale actual
     */
    private string $currentLocale = 'en';

    /**
     * Constructor
     * 
     * @param KeyValueInterface $cache Instancia del almacén clave-valor
     * @param string $prefix Prefijo opcional para las claves
     * @param string $defaultLocale Locale por defecto
     */
    public function __construct(
        KeyValueInterface $cache,
        string $prefix = 'translations/',
        string $defaultLocale = 'en'
    ) {
        $this->cache = $cache;
        $this->prefix = $prefix;
        $this->defaultLocale = $defaultLocale;
        $this->currentLocale = $defaultLocale;
    }

    /**
     * Establece el locale actual para las traducciones.
     * 
     * @param string $locale Código de locale (ej: "en", "es", "fr")
     * @return self
     */
    public function setLocale(string $locale): self
    {
        $this->currentLocale = $locale;
        return $this;
    }

    /**
     * Obtiene el locale actual.
     * 
     * @return string
     */
    public function getLocale(): string
    {
        return $this->currentLocale;
    }

    /**
     * Obtiene el locale por defecto.
     * 
     * @return string
     */
    public function getDefaultLocale(): string
    {
        return $this->defaultLocale;
    }

    /**
     * Obtiene una traducción por clave.
     * Las claves usan "/" como separadores jerárquicos.
     * Soporta interpolación de parámetros usando {nombre} en el mensaje.
     * 
     * @param string $key Clave de traducción (ej: "messages/welcome" o "en/messages/welcome")
     * @param array $params Parámetros para interpolar en el mensaje
     * @param string|null $locale Locale opcional (usa el actual si no se especifica)
     * @param string|null $default Valor por defecto si la clave no existe
     * @return string|null La traducción o el valor por defecto
     */
    public function get(string $key, array $params = [], ?string $locale = null, ?string $default = null): ?string
    {
        if ($locale === null) {
            $locale = $this->currentLocale;
        }

        // Si la clave ya incluye el locale, lo usamos directamente
        if (!str_contains($key, '/')) {
            $fullKey = $this->normalizeKey($locale . '/' . $key);
        } else {
            // Verificamos si la clave ya empieza con un locale válido
            $parts = explode('/', $key);
            if (strlen($parts[0]) === 2 || strlen($parts[0]) === 5) {
                // Parece un locale, usamos la clave tal cual
                $fullKey = $this->normalizeKey($key);
            } else {
                // Añadimos el locale al inicio
                $fullKey = $this->normalizeKey($locale . '/' . $key);
            }
        }

        $message = $this->cache->get($fullKey);

        if ($message === null) {
            // Intentar con el locale por defecto si no es el actual
            if ($locale !== $this->defaultLocale) {
                $fallbackKey = $this->normalizeKey($this->defaultLocale . '/' . $key);
                $message = $this->cache->get($fallbackKey);
            }

            if ($message === null) {
                return $default;
            }
        }

        // Interpolar parámetros si existen
        if (!empty($params)) {
            foreach ($params as $paramName => $paramValue) {
                $message = str_replace('{' . $paramName . '}', (string)$paramValue, $message);
            }
        }

        return $message;
    }

    /**
     * Alias de get() para obtener una traducción.
     * 
     * @param string $key Clave de traducción
     * @param array $params Parámetros para interpolar
     * @param string|null $locale Locale opcional
     * @param string|null $default Valor por defecto
     * @return string|null La traducción
     */
    public function trans(string $key, array $params = [], ?string $locale = null, ?string $default = null): ?string
    {
        return $this->get($key, $params, $locale, $default);
    }

    /**
     * Establece una traducción.
     * 
     * @param string $key Clave de traducción (ej: "messages/welcome")
     * @param string $value El mensaje traducido
     * @param string|null $locale Locale opcional (usa el actual si no se especifica)
     * @param int $ttl Tiempo de vida en segundos (0 para persistente)
     * @return bool True si se estableció correctamente
     */
    public function set(string $key, string $value, ?string $locale = null, int $ttl = 0): bool
    {
        if ($locale === null) {
            $locale = $this->currentLocale;
        }

        $fullKey = $this->normalizeKey($locale . '/' . $key);
        return $this->cache->set($fullKey, $value, $ttl);
    }

    /**
     * Verifica si una clave de traducción existe.
     * 
     * @param string $key Clave de traducción
     * @param string|null $locale Locale opcional
     * @return bool True si existe, false en caso contrario
     */
    public function has(string $key, ?string $locale = null): bool
    {
        if ($locale === null) {
            $locale = $this->currentLocale;
        }

        $fullKey = $this->normalizeKey($locale . '/' . $key);
        return $this->cache->has($fullKey);
    }

    /**
     * Elimina una traducción por clave.
     * 
     * @param string $key Clave de traducción
     * @param string|null $locale Locale opcional
     * @return bool True si se eliminó, false si no existía
     */
    public function delete(string $key, ?string $locale = null): bool
    {
        if ($locale === null) {
            $locale = $this->currentLocale;
        }

        $fullKey = $this->normalizeKey($locale . '/' . $key);
        return $this->cache->delete($fullKey);
    }

    /**
     * Obtiene todas las traducciones para un locale y prefijo específicos.
     * 
     * @param string $prefix Prefijo para filtrar claves (ej: "messages/")
     * @param string|null $locale Locale opcional
     * @return array Array asociativo con las claves y valores
     */
    public function all(string $prefix = '', ?string $locale = null): array
    {
        if ($locale === null) {
            $locale = $this->currentLocale;
        }

        $searchPrefix = $this->prefix . $locale . '/' . $prefix;
        $results = [];

        if (method_exists($this->cache, 'all')) {
            $allKeys = $this->cache->all($searchPrefix);
            foreach ($allKeys as $key => $value) {
                // Removemos el prefijo interno para devolver solo la parte relativa
                $relativeKey = str_starts_with($key, $searchPrefix)
                    ? substr($key, strlen($searchPrefix))
                    : $key;
                $results[$relativeKey] = $value;
            }
        }

        return $results;
    }

    /**
     * Limpia todas las traducciones o aquellas que coincidan con un locale/prefijo.
     * 
     * @param string|null $locale Locale opcional para limpiar solo un idioma
     * @param string|null $prefix Prefijo opcional dentro del locale
     * @return bool True si se limpió correctamente
     */
    public function clear(?string $locale = null, ?string $prefix = null): bool
    {
        if ($locale === null) {
            $locale = $this->currentLocale;
        }

        $clearPath = $locale;
        if ($prefix !== null) {
            $clearPath .= '/' . $prefix;
        }

        $fullPrefix = $this->normalizeKey($clearPath);
        return $this->cache->clear($fullPrefix);
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
