<?php

declare(strict_types=1);

namespace RapidBase\Core;

use RapidBase\Core\Contracts\KeyValueReaderInterface;

/**
 * Class Settings
 * * Acceso estático global e inmutable a las configuraciones precompiladas de la aplicación.
 * Carga el bloque consolidado desde SQLite en un solo viaje a disco y resuelve en memoria RAM.
 * * @package RapidBase\Core
 */
class Settings
{
    /**
     * @var KeyValueReaderInterface|null Motor de lectura inyectado en el arranque
     */
    private static ?KeyValueReaderInterface $cache = null;

    /**
     * @var array Mapa de configuraciones en memoria RAM
     */
    private static array $data = [];

    /**
     * @var bool Estado de la carga del bloque maestro
     */
    private static bool $loaded = false;

    /**
     * @var string Llave del archivo compilado en el DirectoryCacheAdapter
     */
    private static string $masterKey = 'settings/app';

    /**
     * Inicializa el servicio de configuraciones en el bootstrap del framework.
     * * @param KeyValueReaderInterface $cache Adaptador de lectura (ej: DirectoryCacheAdapter)
     * @param string $masterKey Llave del bloque consolidado
     */
    public static function init(KeyValueReaderInterface $cache, string $masterKey = 'settings/app'): void
    {
        self::$cache = $cache;
        self::$masterKey = $masterKey;
        self::$data = [];
        self::$loaded = false;
    }

    /**
     * Carga el bloque completo de configuración a la memoria RAM.
     * Al ser un archivo .php plano, OPcache se encarga de mantenerlo en bytecode.
     */
    private static function ensureLoaded(): void
    {
        if (self::$loaded) {
            return;
        }

        if (self::$cache === null) {
            // Failsafe por si se intenta leer antes de inicializar el framework
            self::$data = [];
            self::$loaded = true;
            return;
        }

        // Recuperamos el array consolidado completo
        self::$data = self::$cache->get(self::$masterKey, []);
        self::$loaded = true;
    }

    /**
     * Obtiene el valor de una configuración por clave.
     * Soporta búsquedas de primer nivel o profundas mediante el separador "/".
     * * @param string $key Clave de configuración (ej: "app/timezone" o "db/main/host")
     * @param mixed $default Valor de retorno si la clave no existe
     * @return mixed El valor configurado o el valor por defecto
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        self::ensureLoaded();

        $key = self::normalizeKey($key);

        // Optimización de primer nivel: acceso directo si la llave está plana en la raíz
        if (isset(self::$data[$key])) {
            return self::$data[$key];
        }

        // Búsqueda profunda para arrays multidimensionales anidados
        $segments = explode('/', $key);
        $cursor = self::$data;

        foreach ($segments as $segment) {
            if (is_array($cursor) && isset($cursor[$segment])) {
                $cursor = $cursor[$segment];
            } else {
                return $default;
            }
        }

        return $cursor;
    }

    /**
     * Verifica la existencia de una configuración.
     * * @param string $key Clave de configuración
     * @return bool True si existe, false en caso contrario
     */
    public static function has(string $key): bool
    {
        self::ensureLoaded();

        $key = self::normalizeKey($key);

        if (isset(self::$data[$key])) {
            return true;
        }

        $segments = explode('/', $key);
        $cursor = self::$data;

        foreach ($segments as $segment) {
            if (is_array($cursor) && isset($cursor[$segment])) {
                $cursor = $cursor[$segment];
            } else {
                return false;
            }
        }

        return true;
    }

    /**
     * Retorna el universo completo de configuraciones cargadas en la petición.
     * * @return array
     */
    public static function all(): array
    {
        self::ensureLoaded();
        return self::$data;
    }

    /**
     * Normaliza los separadores de la clave de búsqueda.
     */
    private static function normalizeKey(string $key): string
    {
        $key = str_replace(['\\', '.'], '/', $key);
        return trim($key, '/');
    }
}