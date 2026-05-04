<?php

namespace RapidBase\Infrastructure\UI\Adapters;

use RapidBase\Core\DB;
use RapidBase\Core\QueryResponse;

/**
 * GridAdapter - Adapta consultas DB::grid para componentes UI modernos.
 * 
 * Utiliza el formato compacto de DB::grid (FETCH_NUM) y responde con
 * QueryResponse.toGridFormat() para compatibilidad con grids frontend.
 */
class GridAdapter
{
    /**
     * Obtiene datos para un grid desde una tabla específica.
     * 
     * @param string $connectionId ID de la conexión registrada
     * @param string $table Nombre de la tabla
     * @param int $offset Desplazamiento inicial
     * @param int $limit Número de registros por página
     * @param string|null $sort Campo a ordenar (ej: "name" o "-name" para descendente)
     * @param array|string|null $filter Condiciones de filtrado (formato Q o JSON)
     * @return QueryResponse
     */
    public static function getTableData(
        string $connectionId,
        string $table,
        int $offset = 0,
        int $limit = 10,
        ?string $sort = null,
        array|string|null $filter = null
    ): QueryResponse {
        // Obtener la conexión desde la base de datos SQLite interna
        $conn = self::getConnectionById($connectionId);
        
        if (!$conn) {
            throw new \Exception("Conexión no encontrada: {$connectionId}");
        }

        // Construir consulta con DB::grid
        $grid = DB::grid($conn['driver'], $conn['config']);
        
        // Aplicar filtro si existe
        if ($filter !== null) {
            if (is_string($filter)) {
                $filter = json_decode($filter, true);
            }
            if (is_array($filter) && !empty($filter)) {
                $grid->where($filter);
            }
        }

        // Aplicar ordenamiento
        if ($sort !== null) {
            if (str_starts_with($sort, '-')) {
                $field = substr($sort, 1);
                $grid->orderBy($field, 'DESC');
            } else {
                $grid->orderBy($sort, 'ASC');
            }
        }

        // Ejecutar consulta con paginación
        $response = $grid->from($table)->paginate($offset, $limit);

        return $response;
    }

    /**
     * Obtiene una conexión por su ID desde la base de datos SQLite interna.
     * 
     * @param string $connectionId
     * @return array|null
     */
private static function getConnectionById(string $connectionId): ?array
    {
        // Usar la misma ruta que el api.php del QueryBrowser
        if (!defined('CONNECTIONS_DB')) {
            // Intentar cargar config.php si existe
            $configFile = __DIR__ . '/../../config.php';
            if (file_exists($configFile)) {
                require_once $configFile;
            }
        }
        
        $dbFile = defined('CONNECTIONS_DB') ? CONNECTIONS_DB : __DIR__ . '/../../data/connections.sqlite';
        
        if (!file_exists($dbFile)) {
            return null;
        }

        try {
            $sqlite = new \PDO('sqlite:' . $dbFile);
            $sqlite->setAttribute(\PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            $stmt = $sqlite->prepare("SELECT * FROM connections WHERE id = ?");
            $stmt->execute([$connectionId]);
            
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if (!$result) {
                return null;
            }

            // Construir configuración según el driver
            $config = [];
            $driver = $result['driver'];
            
            if ($driver === 'sqlite') {
                $config['database'] = $result['database'];
            } else {
                $config['host'] = $result['host'];
                $config['port'] = $result['port'];
                $config['database'] = $result['database'];
                $config['username'] = $result['username'];
                $config['password'] = $result['password'];
            }

            return [
                'driver' => $driver,
                'config' => $config
            ];
        } catch (\Exception $e) {
            error_log("Error obteniendo conexión: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Endpoint API para obtener datos de grid en formato JSON.
     * 
     * Parámetros esperados (GET/POST):
     * - connection_id: ID de la conexión
     * - table: Nombre de la tabla
     * - offset: Desplazamiento (default: 0)
     * - limit: Límite de registros (default: 10)
     * - sort: Campo de ordenamiento (opcional, prefijo - para DESC)
     * - filter: JSON con condiciones de filtrado (opcional)
     */
    public static function apiEndpoint(): void
    {
        header('Content-Type: application/json');
        
        // Permitir CORS para desarrollo
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            exit(0);
        }

        try {
            $params = array_merge($_GET, $_POST);
            
            $connectionId = $params['connection_id'] ?? '';
            $table = $params['table'] ?? '';
            $offset = (int)($params['offset'] ?? 0);
            $limit = (int)($params['limit'] ?? 10);
            $sort = $params['sort'] ?? null;
            $filter = $params['filter'] ?? null;

            if (empty($connectionId) || empty($table)) {
                http_response_code(400);
                echo json_encode(['error' => 'Parámetros requeridos: connection_id y table']);
                return;
            }

            // Limitar el máximo de registros por seguridad
            $limit = min($limit, 1000);

            $response = self::getTableData(
                $connectionId,
                $table,
                $offset,
                $limit,
                $sort,
                $filter
            );

            // Devolver en formato Grid moderno
            echo $response->toJsonGrid();
            
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode([
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}
