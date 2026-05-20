<?php

namespace RapidBase\Core\Backend;

/**
 * Backend abstracto que define la interfaz para diferentes implementaciones
 * de almacenamiento (JSON, SQLite, MySQL, etc.)
 */
abstract class Backend
{
    protected string $connectionId;
    protected string $table = '';
    protected array $filter = [];

    /**
     * Constructor protegido para usar el patrón estático con con()
     */
    protected function __construct(string $connectionId)
    {
        $this->connectionId = $connectionId;
    }

    /**
     * Establece la conexión activa y retorna una nueva instancia
     */
    public static function con(string $connectionId): static
    {
        return new static($connectionId);
    }

    /**
     * Define la tabla/colección para operar
     */
    public function from(string $table, array $filter = []): static
    {
        $this->table = $table;
        $this->filter = $filter;
        return $this;
    }

    /**
     * Alias semántico para INSERT operations
     */
    public function into(string $table, array $filter = []): static
    {
        return $this->from($table, $filter);
    }

    /**
     * Inserta un registro
     */
    abstract public function insert(array $data): BackendResponse;

    /**
     * Actualiza registros que coincidan con el filtro
     */
    abstract public function update(array $data, ?int $limit = null): BackendResponse;

    /**
     * Inserta o actualiza (upsert/save)
     */
    abstract public function upsert(array $data, array $conflictColumns = []): BackendResponse;

    /**
     * Alias para upsert
     */
    public function save(array $data, array $conflictColumns = []): BackendResponse
    {
        return $this->upsert($data, $conflictColumns);
    }

    /**
     * Lee un solo registro
     */
    abstract public function read(): ?array;

    /**
     * Selecciona múltiples registros con paginación y orden
     */
    abstract public function select(
        string|array $fields = '*',
        mixed $pagination = null,
        string|array $sort = [],
        bool $withTotal = false
    ): BackendResponse;

    /**
     * Elimina registros que coincidan con el filtro
     */
    abstract public function delete(?int $limit = null): BackendResponse;

    /**
     * Cuenta registros
     */
    abstract public function count(): int;

    /**
     * Verifica existencia
     */
    abstract public function exists(): bool;

    /**
     * Obtiene el primer registro
     */
    public function first(): ?array
    {
        $result = $this->select('*', [0, 1]);
        return $result->data[0] ?? null;
    }

    /**
     * Resuelve el nombre de la tabla actual
     */
    protected function resolveTable(): string
    {
        if (empty($this->table)) {
            throw new \RuntimeException("Table not specified. Use from() or into() first.");
        }
        return $this->table;
    }
}
