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
    
    // Schema map for column definitions
    private array $schemaMap = [];
    
    // Table name for schema lookup
    private string $tableName = '';

    /**
     * Constructor
     * 
     * @param QueryResponse $response The query response from DB::grid()
     *                                Contiene datos en formato FETCH_NUM para máximo rendimiento
     * @param array $searchableColumns Columns to include in global search
     * @param array $schemaMap Schema map con definición de columnas
     * @param string $tableName Nombre de la tabla para buscar en el schema map
     */
    public function __construct(
        QueryResponse $response, 
        array $searchableColumns = [],
        array $schemaMap = [],
        string $tableName = ''
    )
    {
        $this->response = $response;
        $this->searchableColumns = $searchableColumns;
        $this->schemaMap = $schemaMap;
        $this->tableName = $tableName;
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
     * Retorna el formato compacto de QueryResponse::toGridFormat():
     * - head.columns: nombres de columnas ['id', 'name', 'email', ...]
     * - head.titles: títulos de columnas ['Id', 'Name', 'Email', ...]
     * - data: arrays numéricos [[1, "Alice", "alice@example.com"], ...]
     * - page: información de paginación
     * - stats: estadísticas de la consulta
     * 
     * @param array $params GET parameters for pagination, sorting, filtering (applied at DB level)
     * @return array Formato compacto con head, data (FETCH_NUM), page y stats
     */
    public function handle(array $params = []): array
    {
        // Obtener formato base desde QueryResponse
        $result = $this->response->toGridFormat();
        
        // Si tenemos schema map y nombre de tabla, actualizar definición de columnas
        if (!empty($this->schemaMap) && !empty($this->tableName) && !empty($this->schemaMap['tables'][$this->tableName])) {
            $tableSchema = $this->schemaMap['tables'][$this->tableName];
            
            // Obtener nombres de columnas del schema map
            $columnNames = array_keys($tableSchema);
            
            // Actualizar head.columns con los nombres reales desde schema_map
            $result['head']['columns'] = $columnNames;
            
            // Actualizar head.titles usando descripción o nombre formateado
            $columnTitles = [];
            foreach ($tableSchema as $colName => $colDef) {
                $columnTitles[] = $colDef['description'] ?? self::formatTitle($colName);
            }
            $result['head']['titles'] = $columnTitles;
        }
        
        return $result;
    }
    
    /**
     * Format column name to title case
     */
    private static function formatTitle(string $name): string
    {
        return ucwords(str_replace('_', ' ', $name));
    }

    /**
     * Get the underlying QueryResponse for direct access to raw data.
     */
    public function getResponse(): QueryResponse
    {
        return $this->response;
    }
}
