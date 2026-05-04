<?php

namespace RapidBase\Infrastructure\UI\Adapters;

use RapidBase\Core\SQL\DB;
use RapidBase\Core\SQL\Q;

/**
 * GridAdapter
 * 
 * Adapta las consultas de la base de datos para ser consumidas por el componente APIDataGrid.
 * Utiliza DB::grid y ResponseQuery para generar respuestas estandarizadas con metadata.
 * 
 * Soporta:
 * - Paginación (offset, limit)
 * - Ordenamiento (sort)
 * - Filtrado avanzado (filter en formato JSON compatible con Q)
 * - Metadata dinámica de columnas
 */
class GridAdapter
{
    /**
     * @var string Nombre de la tabla a consultar
     */
    private string $table;

    /**
     * @var string|null Base de datos específica (opcional)
     */
    private ?string $database = null;

    /**
     * @var string|null ID de conexión (opcional)
     */
    private ?string $connectionId = null;

    public function __construct(string $table, ?string $database = null, ?string $connectionId = null)
    {
        $this->table = $table;
        $this->database = $database;
        $this->connectionId = $connectionId;
    }

    /**
     * Ejecuta la consulta y devuelve los datos formateados para el grid
     * 
     * @param array $params Parámetros de la solicitud (offset, limit, sort, filter)
     * @return array Respuesta estandarizada con data y metadata
     */
    public function getData(array $params = []): array
    {
        $offset = isset($params['offset']) ? (int)$params['offset'] : 0;
        $limit = isset($params['limit']) ? (int)$params['limit'] : 20;
        $sort = $params['sort'] ?? null;
        $filter = $params['filter'] ?? null;

        // Construir la consulta usando Q
        $q = Q::table($this->table);

        // Aplicar filtro si existe
        if ($filter !== null) {
            $filterData = is_string($filter) ? json_decode($filter, true) : $filter;
            if (is_array($filterData) && !empty($filterData)) {
                $this->applyFilter($q, $filterData);
            }
        }

        // Aplicar ordenamiento
        if ($sort !== null) {
            $this->applySort($q, $sort);
        }

        // Ejecutar consulta con DB::grid
        $result = DB::grid($q, [
            'offset' => $offset,
            'limit' => $limit,
            'database' => $this->database,
            'connectionId' => $this->connectionId
        ]);

        // Formatear respuesta
        return [
            'data' => $result->getData(),
            'metadata' => $this->buildMetadata($result->getColumns()),
            'total' => $result->getTotal(),
            'offset' => $offset,
            'limit' => $limit,
            'hasMore' => ($offset + $limit) < $result->getTotal()
        ];
    }

    /**
     * Aplica condiciones de filtrado a la consulta Q
     * 
     * @param Q $q Objeto de consulta
     * @param array $filter Condiciones de filtrado
     */
    private function applyFilter(Q &$q, array $filter): void
    {
        // El formato del filter debe ser compatible con la matriz de condiciones de Q
        // Ejemplo: ['name' => ['like', '%john%'], 'age' => ['>', 18]]
        foreach ($filter as $field => $condition) {
            if (is_array($condition)) {
                // Condición compleja: ['operator', 'value']
                $operator = $condition[0];
                $value = $condition[1] ?? null;
                
                switch ($operator) {
                    case '=':
                    case '==':
                        $q->where($field, '=', $value);
                        break;
                    case '!=':
                    case '<>':
                        $q->where($field, '!=', $value);
                        break;
                    case '>':
                        $q->where($field, '>', $value);
                        break;
                    case '>=':
                        $q->where($field, '>=', $value);
                        break;
                    case '<':
                        $q->where($field, '<', $value);
                        break;
                    case '<=':
                        $q->where($field, '<=', $value);
                        break;
                    case 'like':
                        $q->where($field, 'LIKE', $value);
                        break;
                    case 'in':
                        $q->where($field, 'IN', $value);
                        break;
                    default:
                        $q->where($field, $operator, $value);
                }
            } else {
                // Condición simple: valor exacto
                $q->where($field, '=', $condition);
            }
        }
    }

    /**
     * Aplica ordenamiento a la consulta Q
     * 
     * @param Q $q Objeto de consulta
     * @param string $sort Campo de ordenamiento (puede incluir prefijo - para descendente)
     */
    private function applySort(Q &$q, string $sort): void
    {
        // Formato: &sort=-field (descendente) o &sort=field (ascendente)
        $order = 'ASC';
        if (strpos($sort, '-') === 0) {
            $order = 'DESC';
            $sort = substr($sort, 1);
        }

        $q->orderBy($sort, $order);
    }

    /**
     * Construye metadata para las columnas del grid
     * 
     * @param array $columns Lista de columnas obtenidas de la consulta
     * @return array Metadata formateada
     */
    private function buildMetadata(array $columns): array
    {
        $metadata = [];
        foreach ($columns as $index => $column) {
            $metadata[] = [
                'key' => $column,
                'title' => ucfirst(str_replace('_', ' ', $column)),
                'index' => $index + 1 // Para soporte de plantilla {1}, {2}, etc.
            ];
        }
        return $metadata;
    }
}
