<?php
/**
 * Grid.js Adapter for RapidBase
 * Bidirectional adapter: translates Grid.js inputs to RapidBase params
 * and formats RapidBase outputs for Grid.js consumption.
 */

namespace RapidBase\Infrastructure\UI\Adapters;

use RapidBase\Core\QueryResponse;

class GridjsAdapter
{
    /**
     * Prepara los argumentos para DB::grid(table, conditions, page, sort)
     * 
     * @param array $input Datos crudos (ej. $_GET)
     * @param array $searchableColumns Columnas donde se aplicará el LIKE
     * @return array [conditions, page, sort] listos para pasar a DB::grid
     */
    public static function build(array $input, array $searchableColumns = []): array
    {
        // 1. Paginación -> [page, limit]
        $limit = (int)($input['limit'] ?? 10);
        $offset = (int)($input['offset'] ?? 0);
        
        // Calcula página actual (1-based) o usa 1 si no hay límite
        $pageInfo = [$limit > 0 ? (int)floor($offset / $limit) + 1 : 1, $limit];

        // 2. Búsqueda (LIKE con lógica OR)
        $conditions = [];
        if (!empty($input['search']) && !empty($searchableColumns)) {
            $term = "%{$input['search']}%";
            foreach ($searchableColumns as $col) {
                // El motor de RapidBase interpretará esto como un grupo OR
                $conditions['OR'][] = [$col => ['LIKE' => $term]];
            }
        }

        // 3. Ordenamiento (Formato compacto: '-field' para DESC, 'field' para ASC)
        $sort = [];
        if (!empty($input['sort'])) {
            $sortData = json_decode($input['sort'], true);
            if (isset($sortData['column'])) {
                $prefix = (strtoupper($sortData['direction'] ?? 'ASC') === 'DESC') ? '-' : '';
                $sort[] = $prefix . $sortData['column'];
            }
        }

        return [
            'conditions' => $conditions,
            'page'       => $pageInfo, 
            'sort'       => $sort      
        ];
    }

    /**
     * Formatea la respuesta de RapidBase para Grid.js
     */
    public static function format(QueryResponse $response): array
    {
        $pack = $response->toRapidPack();
        return [
            'data'  => $pack['body'],
            'total' => $pack['meta']['total']
        ];
    }
}
