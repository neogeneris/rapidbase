<?php

declare(strict_types=1);

namespace RapidBase\Core;

use RapidBase\Core\Contracts\KeyValueReaderInterface;

/**
 * Class Translator
 * * Servicio estático global e inmutable para internacionalización (i18n).
 * Carga en bloque el diccionario del idioma actual desde el almacén precompilado
 * y resuelve cadenas jerárquicas con interpolación en memoria RAM.
 * * @package RapidBase\Core
 */
class Translator
{
    /**
     * @var KeyValueReaderInterface|null Motor de lectura inyectado en el arranque
     */
    private static ?KeyValueReaderInterface $cache = null;

    /**
     * @var string Locale por defecto del sistema
     */
    private static string $defaultLocale = 'en';

    /**
     * @var string Locale actualmente activo en la petición actual
     */
    private static string $currentLocale = 'en';

    /**
     * @var string Prefijo de almacenamiento maestro
     */
    private static string $prefix = 'translations/';

    /**
     * @var array Caché de diccionarios cargados en memoria RAM [locale => [data]]
     */
    private static array $loadedLocales = [];

    /**
     * Inicializa el motor de traducciones en el bootstrap de RapidBase.
     * * @param KeyValueReaderInterface $cache Adaptador de lectura (ej: DirectoryCacheAdapter)
     * @param string $defaultLocale Idioma base (ej: 'es')
     */
    public static function init(KeyValueReaderInterface $cache, string $defaultLocale = 'en'): void
    {
        self::$cache = $cache;
        self::$defaultLocale = $defaultLocale;
        self::$currentLocale = $defaultLocale;
        self::$loadedLocales = [];
    }

    /**
     * Cambia el idioma activo en tiempo de ejecución para la petición actual.
     */
    public static function setLocale(string $locale): void
    {
        self::$currentLocale = $locale;
    }

    /**
     * Retorna el idioma activo actual.
     */
    public static function getLocale(): string
    {
        return self::$currentLocale;
    }

    /**
     * Retorna el idioma por defecto.
     */
    public static function getDefaultLocale(): string
    {
        return self::$defaultLocale;
    }

    /**
     * Asegura que el diccionario completo de un idioma específico esté volcado en memoria RAM.
     */
    private static function ensureLoaded(string $locale): void
    {
        if (isset(self::$loadedLocales[$locale])) {
            return;
        }

        if (self::$cache === null) {
            self::$loadedLocales[$locale] = [];
            return;
        }

        // Clave maestra esperada en el DirectoryCacheAdapter: 'translations/es'
        $masterKey = self::$prefix . $locale;
        self::$loadedLocales[$locale] = self::$cache->get($masterKey, []);
    }

    /**
     * Obtiene una traducción por clave jerárquica con soporte para parámetros.
     * * @param string $key Clave de traducción (ej: "auth/login/success" o "errors/invalid_id")
     * @param array $params Parámetros asociativos a interpolar usando {clave}
     * @param string|null $locale Forzar un idioma específico para esta traducción
     * @param string|null $default Valor de fallback si la clave no existe en ningún diccionario
     * @return string|null
     */
    public static function get(string $key, array $params = [], ?string $locale = null, ?string $default = null): ?string
    {
        $targetLocale = $locale ?? self::$currentLocale;
        
        self::ensureLoaded($targetLocale);
        $key = self::normalizeKey($key);

        // 1. Intentar resolver en el idioma solicitado
        $message = self::resolveKey(self::$loadedLocales[$targetLocale], $key);

        // 2. Fallback al idioma por defecto si no se encontró y el solicitado era otro
        if ($message === null && $targetLocale !== self::$defaultLocale) {
            self::ensureLoaded(self::$defaultLocale);
            $message = self::resolveKey(self::$loadedLocales[self::$defaultLocale], $key);
        }

        // 3. Si sigue siendo null, devolvemos el valor por defecto configurado
        if ($message === null) {
            return $default;
        }

        // 4. Interpolar los tokens del mensaje si se suministraron parámetros ({user}, etc.)
        if (!empty($params)) {
            foreach ($params as $paramName => $paramValue) {
                $message = str_replace('{' . $paramName . '}', (string)$paramValue, $message);
            }
        }

        return $message;
    }

    /**
     * Resuelve de manera iterativa/profunda una clave jerárquica dividida por "/"
     */
    private static function resolveKey(array $dictionary, string $key): ?string
    {
        if (isset($dictionary[$key]) && is_string($dictionary[$key])) {
            return $dictionary[$key];
        }

        $segments = explode('/', $key);
        $cursor = $dictionary;

        foreach ($segments as $segment) {
            if (is_array($cursor) && isset($cursor[$segment])) {
                $cursor = $cursor[$segment];
            } else {
                return null;
            }
        }

        return is_string($cursor) ? $cursor : null;
    }

    /**
     * Devuelve el mapa completo del idioma cargado.
     */
    public static function all(?string $locale = null): array
    {
        $targetLocale = $locale ?? self::$currentLocale;
        self::ensureLoaded($targetLocale);
        return self::$loadedLocales[$targetLocale] ?? [];
    }

    /**
     * Normaliza los separadores internos de la clave lingüística.
     */
    private static function normalizeKey(string $key): string
    {
        $key = str_replace(['\\', '.'], '/', $key);
        return trim($key, '/');
    }
}