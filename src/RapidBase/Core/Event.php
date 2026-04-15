<?php

namespace Core;

class Event {
    /** @var array Almacena los callbacks agrupados por nombre de evento */
    private static array $listeners = [];

    /**
     * Suscribirse a un evento.
     * @param string $name Nombre del evento (ej: 'db.query')
     * @param callable $callback Función que se ejecutará
     */
    public static function listen(string $name, callable $callback): void {
        self::$listeners[$name][] = $callback;
    }

    /**
     * Disparar un evento.
     * @param string $name Nombre del evento
     * @param mixed $data Datos asociados al evento
     */
    public static function fire(string $name, mixed $data = null): void {
        // Ejecutar los específicos
        if (isset(self::$listeners[$name])) {
            foreach (self::$listeners[$name] as $callback) {
                $callback($data);
            }
        }

        // Ejecutar los globales (comodín '*')
        if (isset(self::$listeners['*'])) {
            foreach (self::$listeners['*'] as $callback) {
                $callback($name, $data);
            }
        }
    }
}