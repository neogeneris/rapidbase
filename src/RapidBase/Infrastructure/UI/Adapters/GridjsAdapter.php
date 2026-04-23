<?php
/**
 * Grid.js Adapter for RapidBase
 * Formats data for Grid.js server-side processing
 */

namespace RapidBase\Infrastructure\UI\Adapters;

class GridjsAdapter
{
    /**
     * Format data for Grid.js response
     * 
     * @param array $data Array of records (numeric arrays)
     * @param int $total Total number of records
     * @param int $page Current page (1-based)
     * @param int $limit Records per page
     * @return array Formatted response for Grid.js
     */
    public static function format(array $data, int $total, int $page = 1, int $limit = 10): array
    {
        return [
            'data' => $data,
            'page' => [
                'current' => $page,
                'size' => $limit,
                'records' => $total
            ]
        ];
    }

    /**
     * Extract pagination parameters from request
     * Grid.js uses 0-based page index
     * 
     * @return array [page, limit]
     */
    public static function getPaginationParams(): array
    {
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
        
        // Grid.js sends 0-based page, convert to 1-based
        if ($page < 1) {
            $page = 1;
        }
        
        return [$page, $limit];
    }

    /**
     * Extract sort parameters from request
     * 
     * @return array Sort configuration
     */
    public static function getSortParams(): array
    {
        $sortField = $_GET['sort'] ?? null;
        $sortOrder = $_GET['order'] ?? 'asc';
        
        if (!$sortField) {
            return [];
        }
        
        return [
            $sortField => strtoupper($sortOrder) === 'DESC' ? 'DESC' : 'ASC'
        ];
    }

    /**
     * Extract search parameters from request
     * 
     * @return array Search conditions
     */
    public static function getSearchParams(): array
    {
        $search = $_GET['search'] ?? null;
        
        if (!$search) {
            return [];
        }
        
        // Simple search across all fields (implementation depends on DB layer)
        return ['_search' => $search];
    }
}
