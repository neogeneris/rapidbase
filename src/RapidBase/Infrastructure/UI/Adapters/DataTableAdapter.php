<?php
/**
 * DataTables Adapter for RapidBase
 * Formats data for jQuery DataTables server-side processing
 */

namespace RapidBase\Infrastructure\UI\Adapters;

class DataTableAdapter
{
    /**
     * Format data for DataTables response
     * 
     * @param array $data Array of records (associative arrays)
     * @param int $total Total number of records (filtered)
     * @param int $totalAll Total number of records (unfiltered)
     * @param int $draw Draw counter from request
     * @return array Formatted response for DataTables
     */
    public static function format(array $data, int $total, int $totalAll = 0, int $draw = 1): array
    {
        if ($totalAll === 0) {
            $totalAll = $total;
        }
        
        return [
            'draw' => $draw,
            'recordsTotal' => $totalAll,
            'recordsFiltered' => $total,
            'data' => $data
        ];
    }

    /**
     * Extract pagination parameters from request
     * DataTables uses 0-based start index
     * 
     * @return array [page, limit]
     */
    public static function getPaginationParams(): array
    {
        $start = isset($_GET['start']) ? (int)$_GET['start'] : 0;
        $limit = isset($_GET['length']) ? (int)$_GET['length'] : 10;
        
        // Convert start index to page number (1-based)
        $page = $limit > 0 ? floor($start / $limit) + 1 : 1;
        
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
        $orderIndex = $_GET['order'][0]['column'] ?? null;
        $orderDir = $_GET['order'][0]['dir'] ?? 'asc';
        $columnName = $_GET['columns'][$orderIndex]['data'] ?? null;
        
        if (!$columnName) {
            return [];
        }
        
        return [
            $columnName => strtoupper($orderDir) === 'DESC' ? 'DESC' : 'ASC'
        ];
    }

    /**
     * Extract search parameters from request
     * 
     * @return array Search conditions
     */
    public static function getSearchParams(): array
    {
        $searchValue = $_GET['search']['value'] ?? '';
        
        if (empty($searchValue)) {
            return [];
        }
        
        return ['_search' => $searchValue];
    }

    /**
     * Get draw counter from request
     * 
     * @return int
     */
    public static function getDraw(): int
    {
        return isset($_GET['draw']) ? (int)$_GET['draw'] : 1;
    }
}
