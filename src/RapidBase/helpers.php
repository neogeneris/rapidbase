<?php

use RapidBase\Core\Settings;
use RapidBase\Core\Translator;

if (!function_exists('setting')) {
    /**
     * Helper global para recuperar configuraciones de forma ultra-corta.
     * * @param string|null $key Clave de configuración (ej: 'app/name' o 'db/host')
     * @param mixed $default Valor de retorno si la clave no existe
     * @return mixed El valor configurado, el valor por defecto, o el mapa completo si no se pasa clave.
     */
    function setting(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return Settings::all();
        }

        return Settings::get($key, $default);
    }
}



if (!function_exists('__')) {
    /**
     * Helper global corto para traducir textos de manera instantánea.
     * * @param string $key Clave jerárquica del texto (ej: 'messages/welcome')
     * @param array $params Parámetros de sustitución [ 'name' => 'John' ]
     * @param string|null $locale Forzar un idioma específico
     * @param string|null $default Texto de escape si no se encuentra
     * @return string
     */
    function __(string $key, array $params = [], ?string $locale = null, ?string $default = null): string
    {
        // Si no encuentra la traducción, una excelente práctica en frameworks es 
        // retornar la clave limpia para que la interfaz no se rompa ni quede en blanco.
        return Translator::get($key, $params, $locale, $default) ?? $key;
    }
}