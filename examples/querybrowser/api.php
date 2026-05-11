<?php
/**
 * RapidBase API – Query Browser (optimizado con X::cached y Search)
 * Estrategia de total: 'separate' para SQLite, 'window' para MySQL
 */

session_start();

require_once __DIR__ . '/RapidBase.php';
require_once 'config.php';

use RapidBase\Core\X;
use RapidBase\Core\Gateway;
use RapidBase\Core\SchemaMap;
use RapidBase\Core\SQL\Q;
use RapidBase\Core\Cache\CacheService;
use RapidBase\Core\Cache\CountCache;
use RapidBase\Search\Search;
use RapidBase\Meta\Discovery\DiscoveryFactory;
use RapidBase\Meta\Discovery\FeatureDetector;

use Throwable;
use Exception;

header('Content-Type: application/json; charset=utf-8');

// Configuración
if (!defined('MAX_CACHE_TTL')) {
    define('MAX_CACHE_TTL', 3600);
}
if (!defined('CACHE_CLEAR_KEY')) {
    define('CACHE_CLEAR_KEY', 'default-key-change-me');
}

function jsonResponse($data, $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
    exit;
}

function errorResponse(string $message, int $status = 400, ?Throwable $e = null): void
{
    $resp = ['error' => $message];
    if ($e && ($_ENV['APP_DEBUG'] ?? false)) {
        $resp['file'] = $e->getFile();
        $resp['line'] = $e->getLine();
    }
    jsonResponse($resp, $status);
}

// --- Conexiones DB (almacenamiento) ---
function initConnectionsDB(): void
{
    $dbFile = CONNECTIONS_DB;
    $dir = dirname($dbFile);
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    if (!file_exists($dbFile)) {
        $pdo = new PDO("sqlite:$dbFile");
        $pdo->exec("CREATE TABLE connections (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE, driver TEXT NOT NULL, host TEXT, port INTEGER, database TEXT, username TEXT, password TEXT, description TEXT, status TEXT DEFAULT 'dev', created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    } else {
        // Migrate: add columns if they don't exist yet
        $pdo = new PDO("sqlite:$dbFile");
        $cols = array_column($pdo->query("PRAGMA table_info(connections)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('description', $cols)) $pdo->exec("ALTER TABLE connections ADD COLUMN description TEXT");
        if (!in_array('status', $cols))      $pdo->exec("ALTER TABLE connections ADD COLUMN status TEXT DEFAULT 'dev'");
    }
}

initConnectionsDB();
\RapidBase\Core\DB::setup("sqlite:" . CONNECTIONS_DB, '', '', 'main');

function buildDSN(array $conn): string
{
    return match ($conn['driver']) {
        'sqlite' => "sqlite:{$conn['database']}",
        'mysql'  => "mysql:host={$conn['host']};port=" . ($conn['port'] ?? 3306) . ";dbname={$conn['database']};charset=utf8mb4",
        'pgsql'  => "pgsql:host={$conn['host']};port=" . ($conn['port'] ?? 5432) . ";dbname={$conn['database']}",
        default  => throw new Exception("Unsupported driver: {$conn['driver']}"),
    };
}

/**
 * Valida una conexión y persiste el estado en sesión si tiene ID.
 * Retorna array [success, latency, error] en lugar de booleano.
 */
function testConnection(string|array $target): array
{
    // Si es un string (connectionKey ya activo) → usar X::ping
    if (is_string($target)) {
        $res = X::con($target)->ping(1);
        if ($res['success']) {
            $_SESSION['active_conns'][$target] = [
                'status'  => 'online',
                'latency' => $res['latency'],
                'at'      => time()
            ];
        }
        return $res;
    }

    // Si es un array (credenciales nuevas o guardadas no activas)
    $dsn = buildDSN($target);
    $config = [
        'dsn'  => $dsn,
        'user' => $target['username'] ?? '',
        'pass' => $target['password'] ?? ''
    ];
    $res = Gateway::ping($target['driver'] ?? 'mysql', $config, 1);

    return $res;
}

function discoverSchema(string $dsn, string $user, string $pass, string $connectionKey, array $connRow): array
{
    $driverName = $connRow['driver'];
    $databaseName = $connRow['database'] ?? null;
    $schema = ($driverName === 'pgsql') ? 'public' : null;

    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $discovery = DiscoveryFactory::create($pdo, $schema);
    $allTables = $discovery->getTables($databaseName);

    $tablesMetadata = [];
    foreach ($allTables as $table) {
        $tablesMetadata[$table] = $discovery->discoverColumns($table, $databaseName);
    }

    $relationships = $discovery->discoverRelationships($databaseName);
    $detector = new FeatureDetector($pdo);
    $features = $detector->detect();

    $signature = '';
    if ($driverName === 'sqlite') {
        $schemas = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        $signature = md5(implode("\n", $schemas));
    }

    return [
        'connection'    => $connectionKey,
        'driver'        => $driverName,
        'checksum'      => $signature,
        'generated_at'  => date('Y-m-d H:i:s'),
        'features'      => $features,
        'relationships' => $relationships,
        'tables'        => $tablesMetadata,
    ];
}

function activateConnection(string $connectionKey): void
{
    $c = $_SESSION['connections'][$connectionKey] ?? throw new Exception("Connection not found");
    \RapidBase\Core\DB::setup($c['dsn'], $c['user'] ?? '', $c['pass'] ?? '', $connectionKey);
    SchemaMap::setMap($c['map'], $connectionKey);
    SchemaMap::setDefaultConnection($connectionKey);
    \RapidBase\Core\SQL\ConditionMatrix::setDriver($c['map']['driver']);
}

// --- Router ---
$action = $_REQUEST['action'] ?? '';

try {
    switch ($action) {
        // ==================== CONEXIONES ====================
        case 'list_connections':
            $res = X::con('main')->from('connections')->select();
            jsonResponse(['connections' => $res->data]);
            break;

        case 'test_connection':
            $input = json_decode(file_get_contents('php://input'), true);
            
            // ¿Es una conexión guardada?
            if (isset($input['id'])) {
                $id = (int) $input['id'];
                $connectionKey = "saved_{$id}";
                
                // Si ya está activa (en sesión), usamos X::ping directamente
                if (isset($_SESSION['connections'][$connectionKey])) {
                    activateConnection($connectionKey);
                    $result = testConnection($connectionKey);
                } else {
                    // No activa → obtener credenciales de la BD de control y probar
                    $connRow = X::con('main')->from('connections', ['id' => $id])->first();
                    if (!$connRow) errorResponse('Conexión no encontrada');
                    $result = testConnection($connRow); // array con los datos
                }
                jsonResponse($result);
                break;
            }
            
            // Sin ID → probar credenciales directamente (modal de nueva conexión)
            if (empty($input['driver']) || empty($input['database'])) {
                errorResponse('Falta driver o database');
            }
            $result = testConnection($input);
            jsonResponse($result);
            break;

        case 'add_connection':
            $data = json_decode(file_get_contents('php://input'), true);
            if (empty($data['name']) || empty($data['driver']) || empty($data['database'])) {
                errorResponse('Missing required fields');
            }
            // testConnection ahora retorna array, verificamos la clave 'success'
            $test = testConnection($data);
            if (!$test['success']) {
                errorResponse('Could not connect to database.');
            }
            $res = X::con('main')->from('connections')->insert([
                'name' => $data['name'], 'driver' => $data['driver'],
                'host' => $data['host'] ?? null, 'port' => $data['port'] ?? null,
                'database' => $data['database'], 'username' => $data['username'] ?? null,
                'password' => $data['password'] ?? null,
                'description' => $data['description'] ?? null,
                'status' => $data['status'] ?? 'dev',
            ]);
            jsonResponse(['success' => true, 'id' => $res->lastId]);
            break;

        case 'ping_connection':
            $input = json_decode(file_get_contents('php://input'), true);
            $id = (int)($input['id'] ?? 0);
            if (!$id) errorResponse('Se requiere id de conexión');

            $connRow = X::con('main')->from('connections', ['id' => $id])->first();
            if (!$connRow) errorResponse('Conexión no encontrada');

            // Hacer ping ligero (sin descubrir esquema)
            $pingResult = testConnection($connRow);

            $response = [
                'success'       => $pingResult['success'],
                'latency'       => $pingResult['latency'] ?? null,
                'error'         => $pingResult['error'] ?? null,
                'id'            => $id,
                'name'          => $connRow['name'],
                'driver'        => $connRow['driver'],
                'host'          => $connRow['host'] ?? null,
                'port'          => $connRow['port'] ?? null,
                'database_name' => $connRow['database'] ?? null,
            ];
            jsonResponse($response);
            break;

        case 'remove_connection':
            $id = $_REQUEST['id'] ?? 0;
            if ($id) X::con('main')->from('connections', ['id' => $id])->delete();
            jsonResponse(['success' => true]);
            break;

        case 'list_databases':
            if (!is_dir(DATA_PATH)) mkdir(DATA_PATH, 0777, true);
            jsonResponse(['databases' => array_map('basename', glob(DATA_PATH . '/*.sqlite'))]);
            break;

        case 'connect':
            $dbFile = $_POST['db'] ?? '';
            if (empty($dbFile)) errorResponse('Database name required');
            $fullPath = DATA_PATH . '/' . basename($dbFile);
            if (!file_exists($fullPath)) errorResponse('Database file not found');
            $connectionKey = md5($fullPath);
            if (!isset($_SESSION['connections'][$connectionKey])) {
                $map = discoverSchema("sqlite:$fullPath", '', '', $connectionKey, ['driver' => 'sqlite', 'database' => $fullPath]);
                $_SESSION['connections'][$connectionKey] = ['dsn' => "sqlite:$fullPath", 'map' => $map, 'user' => '', 'pass' => ''];
            }
            activateConnection($connectionKey);
            jsonResponse(['status' => 'ok', 'connectionId' => $connectionKey]);
            break;

        case 'connect_saved':
            $connId = $_POST['connId'] ?? 0;
            $connRow = X::con('main')->from('connections', ['id' => $connId])->first();
            if (!$connRow) errorResponse('Connection not found');
            $connectionKey = "saved_{$connId}";
            $dsn = buildDSN($connRow);
            $map = discoverSchema($dsn, $connRow['username'] ?? '', $connRow['password'] ?? '', $connectionKey, $connRow);
            $_SESSION['connections'][$connectionKey] = [
                'dsn' => $dsn, 'map' => $map,
                'user' => $connRow['username'] ?? '', 'pass' => $connRow['password'] ?? '',
            ];
            activateConnection($connectionKey);
            jsonResponse(['status' => 'ok', 'connectionId' => $connectionKey]);
            break;

        // ==================== ESQUEMA Y TABLAS ====================
        case 'list_tables':
    $connectionId = $_REQUEST['connectionId'] ?? '';
    if (!$connectionId) errorResponse('Falta connectionId');

    // Si la conexión no está en sesión, la activamos bajo demanda
    if (!isset($_SESSION['connections'][$connectionId])) {
        if (str_starts_with($connectionId, 'saved_')) {
            $id = (int)substr($connectionId, 6);
            $connRow = X::con('main')->from('connections', ['id' => $id])->first();
            if (!$connRow) errorResponse('Conexión no encontrada en DB principal');

            $dsn = buildDSN($connRow);
            $map = discoverSchema($dsn, $connRow['username'] ?? '', $connRow['password'] ?? '',
                                 $connectionId, $connRow);
            $_SESSION['connections'][$connectionId] = [
                'dsn'  => $dsn,
                'map'  => $map,
                'user' => $connRow['username'] ?? '',
                'pass' => $connRow['password'] ?? ''
            ];
        } else {
            errorResponse('Conexión no activa y no se puede reconstruir automáticamente');
        }
    }

    activateConnection($connectionId);

    $map = SchemaMap::getMap();
    $tables = array_keys($map['tables'] ?? []);

    // Obtenemos metadatos reales desde el pool de Conn
    $meta = \RapidBase\Core\Conn::getMetadata($connectionId);

    // IMPORTANTE: la llave correcta es 'dbname' (sin guion bajo)
    $realDbName = $meta['dbname'] ?? 'Unknown';
    $driver = $meta['driver'] ?? 'unknown';

    // Obtener PDO directo para consultar columnas reales con PRAGMA
    $pdo = \RapidBase\Core\DB::pdo($connectionId);
    
    // Build schema_tables consultando columnas reales desde la BD
    $rawTables = $map['tables'] ?? [];
    $schemaTables = [];
    foreach ($rawTables as $tableName => $tableMeta) {
        $cols = [];
        
        // Consultar columnas reales usando PRAGMA table_info para SQLite
        if ($driver === 'sqlite') {
            try {
                $stmt = $pdo->query("PRAGMA table_info('$tableName')");
                $colInfo = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($colInfo as $col) {
                    $cols[] = [
                        'name'     => $col['name'],
                        'type'     => strtolower($col['type'] ?? 'text'),
                        'nullable' => !(bool)$col['notnull'],
                        'primary'  => (bool)$col['pk'],
                    ];
                }
            } catch (\Exception $e) {
                // Fallback to schema_map if PRAGMA fails
                foreach ($tableMeta['columns'] ?? [] as $colName => $colMeta) {
                    $cols[] = [
                        'name'     => $colName,
                        'type'     => strtolower($colMeta['type'] ?? 'text'),
                        'nullable' => (bool)($colMeta['nullable'] ?? true),
                        'primary'  => (bool)($colMeta['primary'] ?? false),
                    ];
                }
            }
        } else {
            // Para MySQL/PostgreSQL usar schema_map por ahora
            foreach ($tableMeta['columns'] ?? [] as $colName => $colMeta) {
                $cols[] = [
                    'name'     => $colName,
                    'type'     => strtolower($colMeta['type'] ?? 'text'),
                    'nullable' => (bool)($colMeta['nullable'] ?? true),
                    'primary'  => (bool)($colMeta['primary'] ?? false),
                ];
            }
        }
        
        $schemaTables[] = ['name' => $tableName, 'columns' => $cols];
    }

    jsonResponse([
        'success'      => true,
        'database'     => $realDbName,
        'driver'       => $driver,
        'tables'       => $tables,
        'schema_tables'=> $schemaTables,
        'views'        => []
    ]);
    break;
        case 'table_relations':
            $connectionKey = $_REQUEST['connectionId'] ?? '';
            $tableName = $_REQUEST['table'] ?? '';
            if (!$connectionKey || !$tableName) { jsonResponse(['from' => [], 'to' => []]); break; }
            activateConnection($connectionKey);
            $map = SchemaMap::getMap();
            $rels = $map['relationships'] ?? [];
            jsonResponse([
                'from' => array_keys($rels['from'][$tableName] ?? []),
                'to'   => array_keys($rels['to'][$tableName] ?? []),
            ]);
            break;

        case 'auto_query':
            $connectionKey = $_POST['connectionId'] ?? '';
            $tablesJson = $_POST['tables'] ?? '';
            if (!$connectionKey || !$tablesJson) errorResponse('connectionId and tables required');
            $tables = json_decode($tablesJson, true);
            if (count($tables) < 1) errorResponse('At least one table required');
            activateConnection($connectionKey);
            $sql = X::con($connectionKey)->toSQL(Q::from($tables)->select());
            jsonResponse(['sql' => $sql]);
            break;

        case 'schema_graph':
            $connectionKey = $_REQUEST['connectionId'] ?? '';
            if (!$connectionKey) { jsonResponse([]); break; }
            activateConnection($connectionKey);
            $map = SchemaMap::getMap();
            $tables = $map['tables'] ?? [];
            $rels   = $map['relationships']['from'] ?? [];
            $nodes = [];
            $edges = [];
            foreach ($tables as $tableName => $info) {
                $nodes[] = ['id' => $tableName, 'label' => $tableName, 'title' => $tableName];
            }
            foreach ($rels as $source => $targets) {
                foreach ($targets as $target => $rel) {
                    $edges[] = [
                        'from'  => $source,
                        'to'    => $target,
                        'label' => $rel['local_key'] . ' → ' . $rel['foreign_key'],
                        'arrows'=> 'to',
                        'title' => $rel['type'] ?? ''
                    ];
                }
            }
            jsonResponse(['nodes' => $nodes, 'edges' => $edges]);
            break;

        // ==================== GRID (CON BÚSQUEDA Y CACHÉ) ====================
        case 'grid_data':
            $connectionKey = $_REQUEST['connectionId'] ?? '';
            $table = $_REQUEST['table'] ?? '';
            $page = max(1, (int)($_REQUEST['page'] ?? 1));
            $limit = max(1, min((int)($_REQUEST['limit'] ?? 10), 1000));
            $sort = $_REQUEST['sort'] ?? [];
            $filter = $_REQUEST['filter'] ?? null;
            $search = $_REQUEST['search'] ?? '';
            $cacheTtl = isset($_REQUEST['cache_ttl']) ? (int)$_REQUEST['cache_ttl'] : 0;
            if ($cacheTtl < 0) $cacheTtl = 0;
            if ($cacheTtl > MAX_CACHE_TTL) $cacheTtl = MAX_CACHE_TTL;

            if (!$connectionKey || !$table) errorResponse('Required parameters: connectionId and table');

            // Decodificar tabla (puede ser JSON para múltiples tablas)
            $decoded = json_decode($table, true);
            if (is_array($decoded)) {
                $tables = $decoded;
                $aliases = [];
            } else {
                $tables = [$table];
                $aliases = [];
            }

            $conditions = [];
            if ($filter) {
                $d = is_string($filter) ? json_decode($filter, true) : $filter;
                if (is_array($d)) $conditions = $d;
            }

            // Búsqueda con Search
            if (!empty($search)) {
                if (count($tables) === 1) {
                    $searchObj = Search::on($tables[0])->like($search);
                } else {
                    $searchObj = Search::onTables($tables, $aliases)->like($search);
                }
                $searchConditions = $searchObj->get();
                if (!empty($searchConditions)) {
                    if (empty($conditions)) {
                        $conditions = $searchConditions;
                    } else {
                        $conditions = ['&' => [$conditions, $searchConditions]];
                    }
                }
            }

            activateConnection($connectionKey);
            $x = X::con($connectionKey)->from($tables, $conditions);
            if ($cacheTtl > 0) {
                $x->cached($cacheTtl);
            }

            // Forzar estrategia según driver
            $driver = SchemaMap::getMap()['driver'] ?? 'sqlite';
            if ($driver === 'mysql') {
                $x->totalStrategy('window');
            } else {
                $x->totalStrategy('separate');
            }

            // Paginación: empaquetar page y limit en un array como espera Q::page
            $pagination = [$page, $limit];
            $gridData = $x->grid('*', $pagination, $sort, 300);

            $gridData['cached'] = $cacheTtl > 0;
            $gridData['cache_ttl'] = $cacheTtl;
            jsonResponse($gridData);
            break;

        // ==================== ENDPOINTS ADICIONALES ====================
        case 'grid_sql':
            $connectionKey = $_REQUEST['connectionId'] ?? '';
            $table = $_REQUEST['table'] ?? '';
            $page = max(1, (int)($_REQUEST['page'] ?? 1));
            $limit = max(1, min((int)($_REQUEST['limit'] ?? 10), 1000));
            $sort = $_REQUEST['sort'] ?? null;
            if (!$connectionKey || !$table) { jsonResponse(['sql' => '']); break; }
            activateConnection($connectionKey);
            $sql = X::con($connectionKey)->toSQL(
                Q::from($table)->select('*', Q::page($page, $limit), $sort)
            );
            jsonResponse(['sql' => $sql]);
            break;

        case 'execute_query':
            $connectionKey = $_POST['connectionId'] ?? '';
            $sql = $_POST['sql'] ?? '';
            $cacheTtl = isset($_POST['cache_ttl']) ? (int)$_POST['cache_ttl'] : 0;
            if ($cacheTtl < 0) $cacheTtl = 0;
            if ($cacheTtl > MAX_CACHE_TTL) $cacheTtl = MAX_CACHE_TTL;

            if (!$connectionKey || !$sql) errorResponse('connectionId and sql required');

            $isSelect = preg_match('/^\s*SELECT/i', trim($sql));
            if ($isSelect && $cacheTtl > 0) {
                $cacheKey = 'sql_' . md5($sql . '_' . $connectionKey);
                $cached = CacheService::get($cacheKey);
                if ($cached !== null) {
                    jsonResponse(['source' => 'cache'] + $cached);
                }
            }

            activateConnection($connectionKey);
            $res = X::con($connectionKey)->raw($sql);
            $response = $res->jsonSerialize();
            if ($isSelect && $cacheTtl > 0) {
                CacheService::set($cacheKey, $response, $cacheTtl);
                $response['source'] = 'database';
                $response['cache_ttl'] = $cacheTtl;
            } else {
                $response['source'] = 'database';
            }
            jsonResponse($response);
            break;

        case 'related_tables':
            $connectionKey = $_REQUEST['connectionId'] ?? '';
            $tablesJson = $_REQUEST['tables'] ?? '[]';
            if (!$connectionKey) { jsonResponse(['to' => [], 'from' => []]); break; }
            activateConnection($connectionKey);
            $tables = json_decode($tablesJson, true) ?: [];
            $map = SchemaMap::getMap();
            $relsFrom = $map['relationships']['from'] ?? [];
            $relsTo   = $map['relationships']['to']   ?? [];

            $toList = [];
            $fromList = [];
            foreach ($tables as $t) {
                foreach ($relsFrom[$t] ?? [] as $target => $rel) {
                    if (!in_array($target, $tables)) $toList[$target] = true;
                }
                foreach ($relsTo[$t] ?? [] as $target => $rel) {
                    if (!in_array($target, $tables)) $fromList[$target] = true;
                }
            }
            jsonResponse([
                'to'   => array_keys($toList),
                'from' => array_keys($fromList)
            ]);
            break;

        case 'get_connection_info':
            $connectionKey = $_REQUEST['connectionId'] ?? '';
            if (!$connectionKey) errorResponse('connectionId required');

            // Conexión guardada (MySQL / PostgreSQL)
            if (str_starts_with($connectionKey, 'saved_')) {
                $id = (int)substr($connectionKey, 6);
                $connRow = X::con('main')->from('connections', ['id' => $id])->first();
                if (!$connRow) errorResponse('Connection not found');
                jsonResponse([
                    'connection_id' => $connectionKey,
                    'name'          => $connRow['name'],
                    'driver'        => $connRow['driver'],
                    'host'          => $connRow['host'],
                    'port'          => $connRow['port'],
                    'database_name' => $connRow['database']
                ]);
            } 
            // Conexión a archivo SQLite
            else {
                if (!isset($_SESSION['connections'][$connectionKey])) errorResponse('Connection not active');
                $dsn = $_SESSION['connections'][$connectionKey]['dsn'];
                $database = basename(substr($dsn, 7)); // extrae el nombre del archivo
                jsonResponse([
                    'connection_id' => $connectionKey,
                    'name'          => $database,
                    'driver'        => 'sqlite',
                    'host'          => null,
                    'port'          => null,
                    'database_name' => $database
                ]);
            }
            break;

        // ==================== CACHÉ ====================
        case 'invalidate_cache':
            $connectionKey = $_REQUEST['connectionId'] ?? '';
            $table = $_REQUEST['table'] ?? '';
            if (!$connectionKey || !$table) errorResponse('connectionId and table required');
            activateConnection($connectionKey);
            CacheService::clearByPrefix("db_select_{$table}_");
            CountCache::invalidate($table);
            jsonResponse(['success' => true, 'message' => "Cache invalidated for table '$table'"]);
            break;

        case 'clear_all_cache':
            if (!isset($_REQUEST['key']) || $_REQUEST['key'] !== CACHE_CLEAR_KEY) errorResponse('Unauthorized', 401);
            CacheService::clear();
            jsonResponse(['success' => true, 'message' => 'All cache cleared']);
            break;

        default:
            errorResponse("Invalid action: $action", 400);
    }
} catch (Throwable $e) {
    errorResponse($e->getMessage(), 500, $e);
}