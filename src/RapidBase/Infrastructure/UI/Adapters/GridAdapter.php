<?php

namespace RapidBase\Infrastructure\UI\Adapters;

use RapidBase\Core\DB;

/**
 * GridAdapter
 * 
 * Adaptador simple que pasa los parámetros del GET directamente a DB::grid.
 * No transforma nada, solo facilita la comunicación entre el frontend y DB::grid.
 */
class GridAdapter
{
    /**
     * @var string Nombre de la tabla a consultar
     */
    private string $table;

    /**
     * @var string|null ID de conexión
     */
    private ?string $connectionId = null;

    public function __construct(string $table, ?string $connectionId = null)
    {
        $this->table = $table;
        $this->connectionId = $connectionId;
    }

    /**
     * Ejecuta la consulta y devuelve los datos formateados para el grid
     * Los parámetros vienen directamente del $_GET sin transformación
     * 
     * @param array $params Parámetros del GET (page, sort, filter)
     * @return array Respuesta de QueryResponse->toGridFormat()
     */
    public function getData(array $params = []): array
    {
        // Parámetros directos del GET
        $page = isset($params['page']) ? (int)$params['page'] : 1;
        $sort = $params['sort'] ?? null;
        $filter = $params['filter'] ?? [];

        // Ejecutar consulta con DB::grid directamente
        $result = DB::grid($this->table, $filter, $page, $sort);

        // Retornar formato para grid
        return $result->toGridFormat();
    }
}
