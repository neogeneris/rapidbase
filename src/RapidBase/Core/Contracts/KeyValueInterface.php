<?php

namespace RapidBase\Core\Contracts;

/**
 * KeyValueInterface - Contrato para operaciones básicas de caché clave-valor.
 * 
 * Define la interfaz estándar que deben implementar todos los adaptadores de caché.
 * Permite operaciones CRUD básicas sobre un almacén de caché.
 */
interface KeyValueInterface
{
    /**
     * Recupera un valor del almacén de caché por su clave.
     *
     * @param string $key La clave única del elemento a recuperar.
     * @return mixed El valor almacenado o null si la clave no existe.
     */
    public function get(string $key): mixed;

    /**
     * Almacena un valor en el caché con una clave específica.
     *
     * @param string $key La clave única para identificar el valor.
     * @param mixed $value El valor a almacenar (debe ser serializable).
     * @param int $ttl Tiempo de vida en segundos. 0 significa sin expiración.
     * @return bool True si la operación fue exitosa, false en caso contrario.
     */
    public function set(string $key, mixed $value, int $ttl = 0): bool;

    /**
     * Verifica si una clave existe en el almacén de caché y no ha expirado.
     *
     * @param string $key La clave a verificar.
     * @return bool True si la clave existe y es válida, false en caso contrario.
     */
    public function has(string $key): bool;

    /**
     * Elimina un elemento del caché por su clave.
     *
     * @param string $key La clave del elemento a eliminar.
     * @return bool True si la operación fue exitosa, false en caso contrario.
     */
    public function delete(string $key): bool;

    /**
     * Limpia/elimina todos los elementos del almacén de caché.
     * 
     * Dependiendo de la implementación, puede limpiar solo las claves con un
     * prefijo específico o todo el almacén.
     *
     * @param string|null $prefix Opcional. Si se proporciona, solo elimina las claves 
     *                            que comienzan con este prefijo.
     * @return bool True si la operación fue exitosa, false en caso contrario.
     */
    public function clear(?string $prefix = null): bool;

    /**
     * Obtiene todos los valores que coinciden con un prefijo dado.
     *
     * @param string $prefix Prefijo para filtrar las claves.
     * @return array Array asociativo con las claves y sus valores.
     */
    public function all(string $prefix = ''): array;
}
