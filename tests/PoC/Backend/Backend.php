<?php

namespace Tests\PoC\Backend;

/**
 * Clase base abstracta para backends de almacenamiento de datos.
 * Define la interfaz común para operaciones CRUD.
 */
abstract class Backend
{
    /**
     * @var string Nombre de la entidad/tabla actual
     */
    protected string $entity = '';

    /**
     * @var float|null Tiempo de ejecución de la última operación en segundos
     */
    protected ?float $executionTime = null;

    /**
     * Establece la entidad sobre la cual se operará
     * 
     * @param string $entity Nombre de la entidad
     * @return static
     */
    public static function into(string $entity): static
    {
        $instance = new static();
        $instance->entity = $entity;
        return $instance;
    }

    /**
     * Alias de into() - Establece la entidad sobre la cual se operará
     * Permite sintaxis tipo: JsonBackend::from('users')->select('*')
     * 
     * @param string $entity Nombre de la entidad
     * @return static
     */
    public static function from(string $entity): static
    {
        return static::into($entity);
    }

    /**
     * Obtiene el tiempo de ejecución de la última operación en segundos
     * 
     * @return float|null Tiempo en segundos o null si no se ha ejecutado ninguna operación
     */
    public function getExecutionTime(): ?float
    {
        return $this->executionTime;
    }

    /**
     * Ejecuta una función midiendo su tiempo de ejecución
     * 
     * @param callable $callback Función a ejecutar
     * @return mixed Resultado de la función
     */
    protected function measureTime(callable $callback): mixed
    {
        $startTime = microtime(true);
        $result = $callback();
        $this->executionTime = microtime(true) - $startTime;
        return $result;
    }

    /**
     * Inserta uno o múltiples registros en la entidad
     * 
     * @param array $records Array de registros a insertar
     * @return array|bool IDs de los registros insertados o true si éxito
     */
    abstract public function insert(array $records);

    /**
     * Actualiza registros que coincidan con el criterio
     * 
     * @param array $data Datos a actualizar
     * @param array|null $where Criterio de filtrado (null para todos)
     * @return int Número de registros afectados
     */
    abstract public function update(array $data, ?array $where = null): int;

    /**
     * Elimina registros que coincidan con el criterio
     * 
     * @param array|null $where Criterio de filtrado (null para todos)
     * @return int Número de registros eliminados
     */
    abstract public function delete(?array $where = null): int;

    /**
     * Selecciona registros de la entidad
     * 
     * @param array|string $fields Campos a seleccionar ('*' para todos)
     * @param array|null $where Criterio de filtrado
     * @return array Resultados de la consulta
     */
    abstract public function select(array|string $fields = '*', ?array $where = null): array;

    /**
     * Realiza un JOIN con otra entidad
     * 
     * @param string $foreignEntity Entidad a unir
     * @param string $localField Campo en la entidad local
     * @param string $foreignField Campo en la entidad foránea
     * @param string $type Tipo de JOIN (INNER, LEFT, RIGHT, FULL)
     * @return static
     */
    abstract public function join(string $foreignEntity, string $localField, string $foreignField, string $type = 'INNER'): static;
}
