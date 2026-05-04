<?php

namespace RapidBase\Infrastructure\UI\Adapters;

use RapidBase\Core\DB;
use RapidBase\Core\SQL\Q;
use RapidBase\Core\SchemaMap;
use RapidBase\Core\SQL\ConditionMatrix;

/**
 * GridAdapter
 * 
 * Adapta las consultas de la base de datos para ser consumidas por el componente APIDataGrid.
 * Utiliza DB::grid y QueryResponse para generar respuestas estandarizadas con metadata.
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

        // Configurar SchemaMap para esta conexión
        if ($this->connectionId && isset($_SESSION['connections'][$this->connectionId])) {
            $connInfo = $_SESSION['connections'][$this->connectionId];
            $map = $connInfo['map'] ?? null;
            
            if ($map) {
                SchemaMap::setMap($map, $this->connectionId);
                SchemaMap::setDefaultConnection($this->connectionId);
                ConditionMatrix::setDriver($map['driver'] ?? 'mysql');
            }
        }

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
        // DB::grid usa FETCH_NUM por defecto cuando no se especifica clase
        $result = DB::grid($q, [], $offset, $sort ? [$sort] : []);

        // Formatear respuesta para el grid frontend
        return [
            'data' => $result->data, // Array numérico puro (FETCH_NUM)
            'metadata' => $this->buildMetadata($result->metadata),
            'total' => $result->total,
            'offset' => $offset,
            'limit' => $limit,
            'hasMore' => ($offset + $limit) < $result->total
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
    private function buildMetadata(array $metadata): array
    {
        $columns = $metadata['columns'] ?? [];
        $titles = $metadata['titles'] ?? [];
        
        $result = [];
        foreach ($columns as $index => $column) {
            $result[] = [
                'key' => $column,
                'title' => $titles[$index] ?? ucfirst(str_replace('_', ' ', $column)),
                'index' => $index // Índice para interpolación {0}, {1}, etc.
            ];
        }
        return $result;
    }
}
