<?php
/**
 * RapidBase API – Query Browser (optimizado con X::cached)
 * Incluye todos los endpoints originales + mejoras de caché.
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
use RapidBase\Meta\Discovery\DiscoveryFactory;
use RapidBase\Meta\Discovery\FeatureDetector;

use Throwable;
use Exception;

header('Content-Type: application/json; charset=utf-8');

// Configuración (puede venir de config.php)
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
        $pdo->exec("CREATE TABLE connections (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE, driver TEXT NOT NULL, host TEXT, port INTEGER, database TEXT, username TEXT, password TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
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

function testConnection(array $conn): bool
{
    try {
        $pdo = new PDO(buildDSN($conn), $conn['username'] ?? '', $conn['password'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->query("SELECT 1");
        return true;
    } catch (Exception $e) {
        return false;
    }
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
        // ------------------------------------------------------------------
        // ENDPOINTS ORIGINALES (MANTENIDOS)
        // ------------------------------------------------------------------
        case 'list_connections':
            $res = X::con('main')->from('connections')->select();
            jsonResponse(['connections' => $res->data]);
            break;

        case 'test_connection':
            $data = json_decode(file_get_contents('php://input'), true);
            jsonResponse(['success' => testConnection($data)]);
            break;

        case 'add_connection':
            $data = json_decode(file_get_contents('php://input'), true);
            if (empty($data['name']) || empty($data['driver']) || empty($data['database'])) {
                errorResponse('Missing required fields');
            }
            if (!testConnection($data)) errorResponse('Could not connect to database.');
            $res = X::con('main')->from('connections')->insert([
                'name' => $data['name'], 'driver' => $data['driver'],
                'host' => $data['host'] ?? null, 'port' => $data['port'] ?? null,
                'database' => $data['database'], 'username' => $data['username'] ?? null,
                'password' => $data['password'] ?? null,
            ]);
            jsonResponse(['success' => true, 'id' => $res->lastId]);
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

        case 'list_tables':
            activateConnection($_REQUEST['connectionId'] ?? '');
            $tables = array_keys(SchemaMap::getMap()['tables'] ?? []);
            jsonResponse(['tables' => $tables, 'views' => []]);
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

        // MEJORADO: grid_data con caché configurable
        case 'grid_data':
            $connectionKey = $_REQUEST['connectionId'] ?? '';
            $table = $_REQUEST['table'] ?? '';
            $page = max(1, (int)($_REQUEST['page'] ?? 1));
            $limit = max(1, min((int)($_REQUEST['limit'] ?? 10), 1000));
            $sort = $_REQUEST['sort'] ?? [];
            $filter = $_REQUEST['filter'] ?? null;
            $cacheTtl = isset($_REQUEST['cache_ttl']) ? (int)$_REQUEST['cache_ttl'] : 0;
            if ($cacheTtl < 0) $cacheTtl = 0;
            if ($cacheTtl > MAX_CACHE_TTL) $cacheTtl = MAX_CACHE_TTL;

            if (!$connectionKey || !$table) errorResponse('Required parameters: connectionId and table');

            $decoded = json_decode($table, true);
            if (is_array($decoded)) $table = $decoded;

            $conditions = [];
            if ($filter) {
                $d = is_string($filter) ? json_decode($filter, true) : $filter;
                if (is_array($d)) $conditions = $d;
            }

            activateConnection($connectionKey);
            $x = X::con($connectionKey)->from($table, $conditions);
            if ($cacheTtl > 0) {
                $x->cached($cacheTtl);
            }
            $gridData = $x->grid('*', $page, $limit, $sort, 300);
            $gridData['cached'] = $cacheTtl > 0;
            $gridData['cache_ttl'] = $cacheTtl;
            jsonResponse($gridData);
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

        // NUEVO: invalidar caché de una tabla específica
        case 'invalidate_cache':
            $connectionKey = $_REQUEST['connectionId'] ?? '';
            $table = $_REQUEST['table'] ?? '';
            if (!$connectionKey || !$table) errorResponse('connectionId and table required');
            activateConnection($connectionKey);
            CacheService::clearByPrefix("db_select_{$table}_");
            CountCache::invalidate($table);
            jsonResponse(['success' => true, 'message' => "Cache invalidated for table '$table'"]);
            break;

        // OPCIONAL: limpiar toda la caché (protegido por clave)
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