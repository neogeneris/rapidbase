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
     * Traduce la entrada de Grid.js a los parámetros de la nueva firma de DB::grid
     * @param array $input Generalmente $_GET
     * @param array $searchableColumns Columnas donde se aplicará el LIKE
     * @return array [conditions, page, sort]
     */
    public static function build(array $input, array $searchableColumns = []): array
    {
        // 1. Paginación: Retorna [page, limit] para el tercer parámetro de DB::grid
        $limit = (int)($input['limit'] ?? 10);
        $offset = (int)($input['offset'] ?? 0);
        $pageInfo = [$limit > 0 ? (int)floor($offset / $limit) + 1 : 1, $limit];

        // 2. Búsqueda: Genera el array de condiciones
        $conditions = [];
        if (!empty($input['search']) && !empty($searchableColumns)) {
            $term = "%{$input['search']}%";
            foreach ($searchableColumns as $col) {
                // RapidBase maneja esto como OR internamente si es array de condiciones
                $conditions[] = [$col => ['LIKE' => $term]];
            }
        }

        // 3. Ordenamiento: Formato prefix-based ('-col' o 'col')
        $sort = [];
        if (!empty($input['sort'])) {
            $sortData = json_decode($input['sort'], true);
            if (isset($sortData['column'])) {
                $dir = strtoupper($sortData['direction'] ?? 'ASC');
                $sort[] = ($dir === 'DESC' ? '-' : '') . $sortData['column'];
            }
        }

        return [
            'conditions' => $conditions,
            'page'       => $pageInfo,
            'sort'       => $sort
        ];
    }

    /**
     * Normaliza la entrada (lo que Grid.js manda por GET)
     * Traduce los parámetros de Grid.js al formato interno de RapidBase.
     * 
     * @param array $input Parámetros recibidos (ej. $_GET)
     * @return array Parámetros normalizados [page, limit, sort]
     * @deprecated Usar build() en su lugar
     */
    public static function translateParams(array $input): array
    {
        // Grid.js usa offset (0-based) y limit
        $limit = (int)($input['limit'] ?? 10);
        $offset = (int)($input['offset'] ?? 0);
        
        // Evitar división por cero
        if ($limit <= 0) {
            $limit = 10;
        }
        
        // Convertir offset a page (1-based)
        $page = (int)floor($offset / $limit) + 1;
        
        return [
            'page'  => $page,
            'limit' => $limit,
            'sort'  => self::parseSort($input['sort'] ?? null)
        ];
    }

    /**
     * Normaliza la salida (lo que Grid.js espera ver)
     * Formatea la respuesta de RapidBase para Grid.js.
     * 
     * @param QueryResponse $response Respuesta de RapidBase
     * @return array Formato esperado por Grid.js
     */
    public static function format(QueryResponse $response): array
    {
        // Obtenemos los datos en formato RapidPack
        $rapidPack = $response->toRapidPack();
        
        return [
            'data'  => $rapidPack['body'] ?? [],
            'total' => $rapidPack['meta']['total'] ?? count($rapidPack['body'] ?? [])
        ];
    }

    /**
     * Parsea el parámetro de ordenamiento de Grid.js
     * Grid.js envía un JSON con { column, direction }
     * 
     * @param string|null $sortJson JSON de ordenamiento
     * @return array|null ['column' => string, 'dir' => 'ASC'|'DESC'] o null
     * @deprecated Usar build() en su lugar
     */
    private static function parseSort($sortJson): ?array
    {
        if (!$sortJson) {
            return null;
        }
        
        $decoded = json_decode($sortJson, true);
        
        if (!is_array($decoded)) {
            return null;
        }
        
        return [
            'column' => $decoded['column'] ?? null,
            'dir'    => strtoupper($decoded['direction'] ?? 'ASC')
        ];
    }
}
