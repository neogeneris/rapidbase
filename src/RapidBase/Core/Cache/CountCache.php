<?php

namespace RapidBase\Core\Cache;

use RapidBase\Core\Cache\CacheService;

/**
 * CountCache – Caché especializado para totales de COUNT.
 *
 * La clave se genera con CacheService::hash() para mantener coherencia.
 * La invalidación se hace por prefijo de tabla.
 */
class CountCache
{
    private static int $ttl = 60; // segundos

    public static function setTtl(int $ttl): void
    {
        self::$ttl = $ttl;
    }

    /**
     * Retorna el total cacheado o calcula y guarda.
     *
     * @param string   $table      Nombre real de la tabla
     * @param array    $conditions Condiciones WHERE
     * @param callable $callback   fn(): int
     * @return int
     */
    public static function remember(string $table, array $conditions, callable $callback): int
    {
        $key = self::buildKey($table, $conditions);

        // Intentar recuperar de CacheService
        if (class_exists(CacheService::class)) {
            $cached = CacheService::get($key);
            if ($cached !== null) {
                return (int)$cached;
            }
        }

        // Calcular y guardar
        $total = $callback();

        if (class_exists(CacheService::class)) {
            CacheService::set($key, $total, self::$ttl);
        }

        return $total;
    }

    /**
     * Invalida todas las entradas de caché asociadas a una tabla.
     */
    public static function invalidate(string $table): void
    {
        if (class_exists(CacheService::class)) {
            CacheService::clearByPrefix('cnt_' . $table . '_');
        }
    }

    /**
     * Construye una clave única usando el hash nativo de CacheService.
     */
	public static function buildKey(string $table, array $conditions): string
	{
		$data = json_encode([$table, $conditions]);
		return 'cnt_' . CacheService::hash($data);
	}
}