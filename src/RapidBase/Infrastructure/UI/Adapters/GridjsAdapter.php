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
     * Normaliza la entrada (lo que Grid.js manda por GET)
     * Traduce los parámetros de Grid.js al formato interno de RapidBase.
     * 
     * @param array $input Parámetros recibidos (ej. $_GET)
     * @return array Parámetros normalizados [page, limit, sort]
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
        $data = $response->toRapidPack();
        
        return [
            'data'  => $data['data'],
            'total' => $data['page']['records'] ?? count($data['data'])
        ];
    }

    /**
     * Parsea el parámetro de ordenamiento de Grid.js
     * Grid.js envía un JSON con { column, direction }
     * 
     * @param string|null $sortJson JSON de ordenamiento
     * @return array|null ['column' => string, 'dir' => 'ASC'|'DESC'] o null
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
