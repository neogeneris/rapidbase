<?php
/**
 * RapidBase API - Query Browser and Dynamic Grid
 * Demo del framework RapidBase
 */

session_start();
require_once __DIR__ . '/RapidBase.php';
require_once 'config.php';

ini_set('display_errors', 0);
error_reporting(0);

use RapidBase\Core\Conn;
use RapidBase\Core\Gateway;
use RapidBase\Core\DB;
use RapidBase\Core\SchemaMap;
use RapidBase\Core\SQL\ConditionMatrix;
use RapidBase\Core\SQL\Q;
use RapidBase\Meta\Discovery\DiscoveryFactory;
use RapidBase\Meta\Discovery\FeatureDetector;

use Throwable;
use Exception;

header('Content-Type: application/json; charset=utf-8');

$action = $_REQUEST['action'] ?? '';

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
DB::setup("sqlite:" . CONNECTIONS_DB, '', '', 'main');

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

function activateSessionConnection(string $connectionKey): void
{
    if (!isset($_SESSION['connections'][$connectionKey])) {
        throw new Exception("Connection not found or expired");
    }
    $c = $_SESSION['connections'][$connectionKey];
    DB::setup($c['dsn'], $c['user'] ?? '', $c['pass'] ?? '', $connectionKey);
    SchemaMap::setMap($c['map'], $connectionKey);
    SchemaMap::setDefaultConnection($connectionKey);
    ConditionMatrix::setDriver($c['map']['driver']);
}

try {
    switch ($action) {

        case 'list_connections':
            echo json_encode(['connections' => Gateway::select('*', 'connections')['data']]);
            break;

        case 'test_connection':
            $data = json_decode(file_get_contents('php://input'), true);
            echo json_encode(['success' => testConnection($data)]);
            break;

        case 'add_connection':
            $data = json_decode(file_get_contents('php://input'), true);
            if (empty($data['name']) || empty($data['driver']) || empty($data['database'])) {
                throw new Exception('Missing required fields');
            }
            if (!testConnection($data)) throw new Exception('Could not connect to database.');
            $id = Gateway::insert('connections', [
                'name' => $data['name'], 'driver' => $data['driver'],
                'host' => $data['host'] ?? null, 'port' => $data['port'] ?? null,
                'database' => $data['database'], 'username' => $data['username'] ?? null,
                'password' => $data['password'] ?? null,
            ]);
            echo json_encode(['success' => true, 'id' => $id]);
            break;

        case 'remove_connection':
            $id = $_REQUEST['id'] ?? 0;
            if ($id) Gateway::delete('connections', ['id' => $id]);
            echo json_encode(['success' => true]);
            break;

        case 'list_databases':
            if (!is_dir(DATA_PATH)) mkdir(DATA_PATH, 0777, true);
            echo json_encode(['databases' => array_map('basename', glob(DATA_PATH . '/*.sqlite'))]);
            break;

        case 'connect':
            $dbFile = $_POST['db'] ?? '';
            if (empty($dbFile)) throw new Exception('Database name required');
            $fullPath = DATA_PATH . '/' . basename($dbFile);
            if (!file_exists($fullPath)) throw new Exception('Database file not found');
            $connectionKey = md5($fullPath);
            if (!isset($_SESSION['connections'][$connectionKey])) {
                $map = discoverSchema("sqlite:$fullPath", '', '', $connectionKey, ['driver' => 'sqlite', 'database' => $fullPath]);
                $_SESSION['connections'][$connectionKey] = ['dsn' => "sqlite:$fullPath", 'map' => $map, 'user' => '', 'pass' => ''];
            }
            activateSessionConnection($connectionKey);
            echo json_encode(['status' => 'ok', 'connectionId' => $connectionKey]);
            break;

        case 'connect_saved':
            $connId = $_POST['connId'] ?? 0;
            $connRow = Gateway::one('connections', ['id' => $connId], '*', null, true);
            if (!$connRow) throw new Exception('Connection not found');
            $connectionKey = "saved_{$connId}";
            $dsn = buildDSN($connRow);
            $map = discoverSchema($dsn, $connRow['username'] ?? '', $connRow['password'] ?? '', $connectionKey, $connRow);
            $_SESSION['connections'][$connectionKey] = [
                'dsn' => $dsn, 'map' => $map,
                'user' => $connRow['username'] ?? '', 'pass' => $connRow['password'] ?? '',
            ];
            activateSessionConnection($connectionKey);
            echo json_encode(['status' => 'ok', 'connectionId' => $connectionKey]);
            break;

        case 'list_tables':
            activateSessionConnection($_REQUEST['connectionId'] ?? '');
            $tables = array_keys(SchemaMap::getMap()['tables'] ?? []);
            echo json_encode(['tables' => $tables, 'views' => []]);
            break;

        case 'table_relations':
            $connectionKey = $_REQUEST['connectionId'] ?? '';
            $tableName = $_REQUEST['table'] ?? '';
            if (!$connectionKey || !$tableName) { echo json_encode(['from' => [], 'to' => []]); break; }
            activateSessionConnection($connectionKey);
            $map = SchemaMap::getMap();
            $rels = $map['relationships'] ?? [];
            echo json_encode([
                'from' => array_keys($rels['from'][$tableName] ?? []),
                'to'   => array_keys($rels['to'][$tableName] ?? []),
            ]);
            break;

        case 'db_status':
            $connectionKey = $_REQUEST['connectionId'] ?? '';
            activateSessionConnection($connectionKey);
            echo json_encode(['status' => DB::status()]);
            break;

        case 'auto_query':
            $connectionKey = $_POST['connectionId'] ?? '';
            $tablesJson = $_POST['tables'] ?? '';
            if (!$connectionKey || !$tablesJson) throw new Exception('connectionId and tables required');
            activateSessionConnection($connectionKey);
            $tables = json_decode($tablesJson, true);
            if (count($tables) < 1) throw new Exception('At least one table required');
            echo json_encode(['sql' => Q::from($tables)->select('*')->getSql()]);
            break;

        case 'execute_query':
            $connectionKey = $_POST['connectionId'] ?? '';
            $sql = $_POST['sql'] ?? '';
            if (!$connectionKey || !$sql) throw new Exception('connectionId and sql required');
            activateSessionConnection($connectionKey);
            $start = microtime(true);
            $upper = strtoupper(trim($sql));
            $isSelect = str_starts_with($upper, 'SELECT') || str_starts_with($upper, 'DESCRIBE')
                || str_starts_with($upper, 'SHOW') || str_starts_with($upper, 'EXPLAIN')
                || str_starts_with($upper, 'PRAGMA');
            if ($isSelect) {
                $stmt = DB::query($sql);
                $rows = $stmt->fetchAll(PDO::FETCH_NUM);
                $columns = [];
                for ($i = 0; $i < $stmt->columnCount(); $i++) {
                    $meta = $stmt->getColumnMeta($i);
                    $columns[] = $meta['name'] ?? "col_$i";
                }
                echo json_encode(['columns' => $columns, 'rows' => $rows, 'elapsed' => round(microtime(true) - $start, 4)]);
            } else {
                $result = DB::exec($sql);
                echo json_encode(['affected' => $result['count'] ?? 0, 'elapsed' => round(microtime(true) - $start, 4)]);
            }
            break;

        case 'grid_sql':
            $connectionKey = $_REQUEST['connectionId'] ?? '';
            $table = $_REQUEST['table'] ?? '';
            $page = max(1, (int)($_REQUEST['page'] ?? 1));
            $limit = max(1, min((int)($_REQUEST['limit'] ?? 10), 1000));
            $sort = $_REQUEST['sort'] ?? null;

            if (!$connectionKey || !$table) { echo json_encode(['sql' => '']); break; }
            activateSessionConnection($connectionKey);

            try {
                $q = Q::from($table, []);
                $compiled = $q->select('*', Q::page($page, $limit), $sort ? (is_string($sort) ? [$sort] : (is_array($sort) ? $sort : [])) : []);
                echo json_encode(['sql' => $compiled->getSql()]);
            } catch (\Exception $e) {
                echo json_encode(['sql' => '']);
            }
            break;

        case 'grid_data':
			
            $connectionKey = $_REQUEST['connectionId'] ?? '';
            $table = $_REQUEST['table'] ?? '';
            $page = max(1, (int)($_REQUEST['page'] ?? 1));
            $limit = max(1, min((int)($_REQUEST['limit'] ?? 10), 1000));
            $sort = $_REQUEST['sort'] ?? null;
            $filter = $_REQUEST['filter'] ?? null;

            if (!$connectionKey || !$table) {
                http_response_code(400);
                echo json_encode(['error' => 'Required parameters: connectionId and table']);
                break;
            }

            activateSessionConnection($connectionKey);

            $decoded = json_decode($table, true);
            if (is_array($decoded)) $table = $decoded;

            $conditions = [];
            if ($filter) {
                $d = is_string($filter) ? json_decode($filter, true) : $filter;
                if (is_array($d)) $conditions = $d;
            }
            try{
            $response = DB::grid($table, $conditions, [$page, $limit], $sort);
            $status = DB::status();
             
            echo json_encode([
                'data'      => $response->data,
                'total'     => $response->total,
                'columns'   => $response->metadata['columns'] ?? [],
                'titles'    => $response->metadata['titles']  ?? [],
                'limit'     => $limit,
                'page'      => $page,
                'last_page' => $response->state['last_page'],
                'debug'     => [
                     'sql'      => $status['sql'] ?? '',
                    'duration' => $status['duration'] ?? 0,
                ],
            ]);
			
			}catch(Exception $e){
				echo json_encode(['error' => $e->getMessage()]);
				
			} 
            break;
			
			case 'test_join':
    $connectionKey = $_REQUEST['connectionId'] ?? '';
    $tablesJson = $_REQUEST['tables'] ?? '[]';
    if (!$connectionKey || !$tablesJson) {
        echo json_encode(['error' => 'connectionId and tables required']);
        break;
    }
    activateSessionConnection($connectionKey);
    $tables = json_decode($tablesJson, true);
    try {
        $compiled = Q::from($tables)->select('*');
        echo json_encode(['sql' => $compiled->getSql()]);
    } catch (\Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    break;
	
case 'related_tables':
    $connectionKey = $_REQUEST['connectionId'] ?? '';
    $tablesJson = $_REQUEST['tables'] ?? '[]';
    if (!$connectionKey) { echo json_encode(['to' => [], 'from' => []]); break; }
    activateSessionConnection($connectionKey);
    $tables = json_decode($tablesJson, true) ?: [];
    $map = SchemaMap::getMap();
    $relsFrom = $map['relationships']['from'] ?? []; // $tabla -> a quién apunto (belongsTo)
    $relsTo   = $map['relationships']['to']   ?? []; // $tabla -> quién me apunta (hasMany)

    $toList = [];   // belongsTo: tablas a las que apuntan las seleccionadas
    $fromList = []; // hasMany: tablas que apuntan a las seleccionadas

    foreach ($tables as $t) {
        // BelongsTo: la tabla actual tiene FK hacia estas
        foreach ($relsFrom[$t] ?? [] as $target => $rel) {
            if (!in_array($target, $tables)) $toList[$target] = true;
        }
        // HasMany: otras tablas tienen FK hacia la actual
        foreach ($relsTo[$t] ?? [] as $target => $rel) {
            if (!in_array($target, $tables)) $fromList[$target] = true;
        }
    }

    echo json_encode([
        'to'   => array_keys($toList),   // belongsTo
        'from' => array_keys($fromList)  // hasMany
    ]);
    break;			
		case 'debug_schema':
    $connectionKey = $_REQUEST['connectionId'] ?? '';
    activateSessionConnection($connectionKey);
    $map = SchemaMap::getMap();
    echo json_encode([
        'tables'        => array_keys($map['tables'] ?? []),
        'from'          => $map['relationships']['from'] ?? [],
        'to'            => $map['relationships']['to'] ?? [],
    ]);
    break;	
	
	case 'schema_graph':
    $connectionKey = $_REQUEST['connectionId'] ?? '';
    if (!$connectionKey) { echo json_encode([]); break; }
    activateSessionConnection($connectionKey);
    $map = SchemaMap::getMap();
    $tables = $map['tables'] ?? [];
    $rels   = $map['relationships']['from'] ?? [];
    
    $nodes = [];
    $edges = [];
    $nodeIndex = [];
    
    foreach ($tables as $tableName => $info) {
        $nodes[] = [
            'id'    => $tableName,
            'label' => $tableName,
            'title' => $tableName
        ];
        $nodeIndex[$tableName] = true;
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
    
    echo json_encode(['nodes' => $nodes, 'edges' => $edges]);
    break;

        default:
            http_response_code(400);
            echo json_encode(['error' => "Invalid action: $action"]);
    }
} catch (Throwable $e) {
    http_response_code(500);
    $response = ['error' => $e->getMessage()];
    if (getenv('APP_ENV') !== 'production') {
        $response['file'] = $e->getFile();
        $response['line'] = $e->getLine();
    }
    echo json_encode($response);
}