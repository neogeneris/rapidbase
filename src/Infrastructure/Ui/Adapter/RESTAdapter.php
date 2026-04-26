<?php

declare(strict_types=1);

namespace RapidBase\Infrastructure\Ui\Adapter;

use RapidBase\Core\QueryExecutor;

/**
 * RESTAdapter
 * 
 * Adapts RapidBase query results for standard REST API consumption.
 * Unlike Grid adapters, this returns data directly with standard pagination metadata.
 * 
 * URL Parameters Supported:
 * - page: Page number (e.g., &page=2) or Page:Limit (e.g., &page=2:50)
 * - sort: Sort field with optional direction prefix (e.g., &sort=-created_at,id)
 *         Prefix '-' indicates DESC, default is ASC
 * - search: Global search text (e.g., &search=john)
 * - filter: JSON encoded filters (e.g., &filter={"age":">18","status":"active"})
 * 
 * @package RapidBase\Infrastructure\Ui\Adapter
 */
class RESTAdapter
{
    private QueryExecutor $executor;
    
    // Default pagination settings
    private int $defaultPerPage = 20;
    
    // Columns to search when 'search' parameter is used
    private array $searchableColumns = [];

    /**
     * Constructor
     * 
     * @param QueryExecutor $executor The query executor instance
     * @param array $searchableColumns Columns to include in global search
     */
    public function __construct(QueryExecutor $executor, array $searchableColumns = [])
    {
        $this->executor = $executor;
        $this->searchableColumns = $searchableColumns;
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
     * Parse request parameters and execute query
     * 
     * @param array $params GET parameters from request
     * @return array Standardized REST response with data and metadata
     */
    public function handle(array $params = []): array
    {
        // Reset executor state
        $this->executor->reset();

        // 1. Handle Pagination
        $page = 1;
        $perPage = $this->defaultPerPage;
        
        if (isset($params['page'])) {
            $pageParam = (string)$params['page'];
            if (strpos($pageParam, ':') !== false) {
                // Format: page=2:50
                [$page, $perPage] = explode(':', $pageParam);
                $page = (int)$page;
                $perPage = (int)$perPage;
            } else {
                // Format: page=2
                $page = max(1, (int)$pageParam);
            }
        }
        
        // Ensure valid values
        $page = max(1, $page);
        $perPage = max(1, min($perPage, 1000)); // Safety limit
        
        $this->executor->setPage($page, $perPage);

        // 2. Handle Sorting
        if (isset($params['sort'])) {
            $sortFields = explode(',', (string)$params['sort']);
            $orderBy = [];
            
            foreach ($sortFields as $field) {
                $field = trim($field);
                if (empty($field)) continue;
                
                // Check for DESC prefix (-)
                if (strpos($field, '-') === 0) {
                    $fieldName = substr($field, 1);
                    $direction = 'DESC';
                } else {
                    $fieldName = $field;
                    $direction = 'ASC';
                }
                
                $orderBy[$fieldName] = $direction;
            }
            
            if (!empty($orderBy)) {
                $this->executor->orderBy($orderBy);
            }
        }

        // 3. Handle Global Search
        if (isset($params['search']) && !empty($params['search'])) {
            $searchTerm = (string)$params['search'];
            if (!empty($this->searchableColumns)) {
                $this->executor->search($searchTerm, $this->searchableColumns);
            }
        }

        // 4. Handle Advanced Filters (JSON)
        if (isset($params['filter']) && !empty($params['filter'])) {
            $filterJson = (string)$params['filter'];
            $filters = json_decode($filterJson, true);
            
            if (json_last_error() === JSON_ERROR_NONE && is_array($filters)) {
                $this->applyFilters($filters);
            }
        }

        // Execute query
        $result = $this->executor->execute();
        
        // Get total count for metadata (requires a separate count query usually)
        // For now, we estimate based on result or assume executor tracks it
        $total = $this->executor->getTotalCount() ?? count($result->getAll());

        // Build response
        return [
            'data' => $result->getAll(),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int)ceil($total / $perPage),
            ]
        ];
    }

    /**
     * Apply filters from decoded JSON
     * Supports simple equality and operators (>, <, >=, <=, !=, LIKE)
     * 
     * Example input: ["age" => ">18", "status" => "active", "name" => "%John%"]
     */
    private function applyFilters(array $filters): void
    {
        $whereConditions = [];
        
        foreach ($filters as $field => $value) {
            $operator = '=';
            $finalValue = $value;
            
            // Detect operators
            if (is_string($value)) {
                if (strpos($value, '>=') === 0) {
                    $operator = '>=';
                    $finalValue = substr($value, 2);
                } elseif (strpos($value, '<=') === 0) {
                    $operator = '<=';
                    $finalValue = substr($value, 2);
                } elseif (strpos($value, '!=') === 0) {
                    $operator = '!=';
                    $finalValue = substr($value, 2);
                } elseif (strpos($value, '>') === 0) {
                    $operator = '>';
                    $finalValue = substr($value, 1);
                } elseif (strpos($value, '<') === 0) {
                    $operator = '<';
                    $finalValue = substr($value, 1);
                } elseif (strpos($value, '%') !== false) {
                    $operator = 'LIKE';
                }
            }
            
            $whereConditions[] = [$field, $operator, $finalValue];
        }
        
        if (!empty($whereConditions)) {
            $this->executor->where($whereConditions);
        }
    }
}
