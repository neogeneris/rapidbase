<?php
/**
 * dhtmlxGrid Adapter for RapidBase
 * Formats data for dhtmlxGrid server-side processing
 */

namespace RapidBase\Infrastructure\UI\Adapters;

class DhtmlxAdapter
{
    /**
     * Format data for dhtmlxGrid response
     * 
     * @param array $data Array of records (numeric arrays)
     * @param int $total Total number of records
     * @param int $page Current page (1-based)
     * @param int $limit Records per page
     * @return array Formatted response for dhtmlxGrid
     */
    public static function format(array $data, int $total, int $page = 1, int $limit = 10): array
    {
        $rows = [];
        foreach ($data as $index => $record) {
            // dhtmlxGrid expects rows with id and data
            $rowId = is_array($record) && isset($record[0]) ? $record[0] : $index + 1;
            $rows[] = [
                'id' => $rowId,
                'data' => $record
            ];
        }
        
        return [
            'rows' => $rows,
            'pos' => ($page - 1) * $limit,
            'total_count' => $total
        ];
    }

    /**
     * Format data as XML for legacy dhtmlxGrid
     * 
     * @param array $data Array of records
     * @param int $total Total number of records
     * @param int $page Current page
     * @param int $limit Records per page
     * @return string XML response
     */
    public static function formatXml(array $data, int $total, int $page = 1, int $limit = 10): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
        $xml .= '<rows pos="' . (($page - 1) * $limit) . '" total_count="' . $total . '">' . PHP_EOL;
        
        foreach ($data as $index => $record) {
            $rowId = is_array($record) && isset($record[0]) ? $record[0] : $index + 1;
            $xml .= '  <row id="' . htmlspecialchars($rowId) . '">' . PHP_EOL;
            
            if (is_array($record)) {
                foreach ($record as $cell) {
                    $xml .= '    <cell><![CDATA[' . htmlspecialchars($cell) . ']]></cell>' . PHP_EOL;
                }
            }
            
            $xml .= '  </row>' . PHP_EOL;
        }
        
        $xml .= '</rows>';
        
        return $xml;
    }

    /**
     * Extract pagination parameters from request
     * dhtmlxGrid uses 0-based start index
     * 
     * @return array [page, limit]
     */
    public static function getPaginationParams(): array
    {
        $pos = isset($_GET['posStart']) ? (int)$_GET['posStart'] : 0;
        $count = isset($_GET['count']) ? (int)$_GET['count'] : 10;
        
        // Convert position to page number (1-based)
        $page = $count > 0 ? floor($pos / $count) + 1 : 1;
        
        if ($page < 1) {
            $page = 1;
        }
        
        return [$page, $count];
    }

    /**
     * Extract sort parameters from request
     * 
     * @return array Sort configuration
     */
    public static function getSortParams(): array
    {
        $sortField = $_GET['orderBy'] ?? null;
        $sortOrder = $_GET['orderDir'] ?? 'asc';
        
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
        // dhtmlxGrid typically handles filtering client-side or via custom implementation
        return [];
    }
}
