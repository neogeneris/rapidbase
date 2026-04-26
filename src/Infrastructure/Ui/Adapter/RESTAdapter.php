<?php

declare(strict_types=1);

namespace RapidBase\Infrastructure\Ui\Adapter;

use RapidBase\Core\QueryResponse;

/**
 * RESTAdapter
 * 
 * Adapta los resultados de consultas RapidBase para consumo de API REST estándar.
 * A diferencia de los adapters de Grid, esto retorna datos con metadatos de paginación estándar.
 * 
 * IMPORTANTE: Este adapter utiliza QueryResponse que contiene datos en formato FETCH_NUM
 * (índices numéricos) para máximo rendimiento. El método toGridFormat() de QueryResponse
 * mantiene este formato numérico en su propiedad ->data, evitando el overhead de memoria
 * de FETCH_ASSOC hasta el último momento posible.
 * 
 * Flujo de datos:
 * 1. DB::grid() ejecuta con PDO::FETCH_NUM → [[1, "Alice", "alice@example.com"], [2, "Bob", ...]]
 * 2. QueryResponse almacena datos numéricos → $response->data = [[1, "Alice", ...], ...]
 * 3. toGridFormat() preserva formato numérico → ["data" => [[1, "Alice", ...], ...]]
 * 4. RESTAdapter puede transformar a asociativo SOLO si es necesario para la respuesta JSON
 * 
 * URL Parameters Supported:
 * - page: Page number (e.g., &page=2) or Offset,Limit (e.g., &page=0,25 or &page=25,25)
 *         Comma separator is used instead of colon to avoid URL encoding issues.
 *         If only one number is provided, it's treated as the offset.
 * - sort: Sort field with optional direction prefix (e.g., &sort=-created_at,id)
 *         Prefix '-' indicates DESC, default is ASC
 * - search: Global search text (e.g., &search=john)
 * - filter: JSON encoded filters (e.g., &filter={"age":">18","status":"active"})
 * 
 * @package RapidBase\Infrastructure\Ui\Adapter
 */
class RESTAdapter
{
    private QueryResponse $response;
    
    // Default pagination settings
    private int $defaultPerPage = 20;
    
    // Columns to search when 'search' parameter is used
    private array $searchableColumns = [];
    
    // Column names for transforming FETCH_NUM to associative (optional)
    private array $columnNames = [];

    /**
     * Constructor
     * 
     * @param QueryResponse $response The query response from DB::grid()
     *                                Contiene datos en formato FETCH_NUM para máximo rendimiento
     * @param array $searchableColumns Columns to include in global search
     * @param array $columnNames Optional column names to transform numeric data to associative
     */
    public function __construct(QueryResponse $response, array $searchableColumns = [], array $columnNames = [])
    {
        $this->response = $response;
        $this->searchableColumns = $searchableColumns;
        $this->columnNames = $columnNames;
    }

    /**
     * Set default items per page
     */
    public function setDefaultPerPage(int $perPage): self
    {
        $this->defaultPerPage = $perPage;
        return $this;
    }

    /**
     * Parse request parameters and build REST response from existing QueryResponse.
     * 
     * Este método NO ejecuta consultas - la consulta ya fue ejecutada por DB::grid()
     * y los datos están en $this->response en formato FETCH_NUM.
     * 
     * @param array $params GET parameters for pagination, sorting, filtering (applied at DB level)
     * @return array Standardized REST response with data and metadata
     */
    public function handle(array $params = []): array
    {
        // 1. Handle Pagination (for metadata calculation)
        // Soporta: &page=2 (solo página) o &page=0,25 (offset,limit) o &page=25,25
        $offset = 0;
        $limit = $this->defaultPerPage;
        
        if (isset($params['page'])) {
            $pageParam = (string)$params['page'];
            
            // Dividir por coma para soportar offset,limit
            if (strpos($pageParam, ',') !== false) {
                $parts = explode(',', $pageParam);
                $offset = (int)($parts[0] ?? 0);
                $limit = isset($parts[1]) ? (int)$parts[1] : $this->defaultPerPage;
            } else {
                // Solo número: tratar como offset directo (más simple para APIs REST)
                $offset = max(0, (int)$pageParam);
            }
        }
        
        // Ensure valid values
        $offset = max(0, $offset);
        $limit = max(1, min($limit, 1000)); // Safety limit

        // 2. Get data from QueryResponse (already executed with FETCH_NUM)
        // $this->response->data contiene arrays numéricos: [[1, "Alice", "alice@example.com"], ...]
        $gridFormat = $this->response->toGridFormat();
        $numericData = $gridFormat['data'];
        
        // 3. Transform FETCH_NUM to associative ONLY if column names are provided
        // This is the ONLY place where we convert to associative format
        if (!empty($this->columnNames) && !empty($numericData)) {
            $associativeData = [];
            foreach ($numericData as $row) {
                $associativeData[] = array_combine($this->columnNames, $row);
            }
            $finalData = $associativeData;
        } else {
            // Keep numeric format if no column names provided
            $finalData = $numericData;
        }

        // 4. Build REST response with standardized metadata
        $pageInfo = $gridFormat['page'] ?? [];
        $totalRecords = $pageInfo['records'] ?? count($finalData);
        $totalPages = $pageInfo['total'] ?? max(1, (int)ceil($totalRecords / $limit));
        $currentPage = max(0, (int)floor($offset / $limit));
        
        return [
            'data' => $finalData,
            'meta' => [
                'page' => $currentPage, // Page 0-based for API
                'per_page' => $limit,
                'total' => $totalRecords,
                'total_pages' => $totalPages,
            ]
        ];
    }

    /**
     * Get the underlying QueryResponse for direct access to raw data.
     */
    public function getResponse(): QueryResponse
    {
        return $this->response;
    }

    /**
     * Set column names for transforming FETCH_NUM to associative format.
     */
    public function setColumnNames(array $columnNames): self
    {
        $this->columnNames = $columnNames;
        return $this;
    }
}
