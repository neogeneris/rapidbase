<?php
/**
 * RapidBase API - Query Browser and Dynamic Grid
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
use RapidBase\Meta\SchemaMapper;
use RapidBase\Meta\Discovery\DiscoveryFactory;
use RapidBase\Meta\Discovery\FeatureDetector;

use Throwable;
use Exception;

header('Content-Type: application/json; charset=utf-8');

$action = $_REQUEST['action'] ?? '';

// ---------------------------------------------------------------------------
// Initialize internal connections database (SQLite)
// ---------------------------------------------------------------------------
function initConnectionsDB(): void
{
    $dbFile = CONNECTIONS_DB;
    $dir = dirname($dbFile);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    if (!file_exists($dbFile)) {
        $pdo = new PDO("sqlite:$dbFile");
        $pdo->exec("
            CREATE TABLE connections (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                driver TEXT NOT NULL,
                host TEXT,
                port INTEGER,
                database TEXT,
                username TEXT,
                password TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }
}

initConnectionsDB();
Conn::setup("sqlite:" . CONNECTIONS_DB, '', '', 'main');

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function buildDSN(array $conn): string
{
    switch ($conn['driver']) {
        case 'sqlite':
            return "sqlite:{$conn['database']}";
        case 'mysql':
            $port = $conn['port'] ?? 3306;
            return "mysql:host={$conn['host']};port={$port};dbname={$conn['database']};charset=utf8mb4";
        case 'pgsql':
            $port = $conn['port'] ?? 5432;
            return "pgsql:host={$conn['host']};port={$port};dbname={$conn['database']}";
        default:
            throw new Exception("Unsupported driver: {$conn['driver']}");
    }
}

function testConnection(array $conn): bool
{
    try {
        $pdo = new PDO(
            buildDSN($conn),
            $conn['username'] ?? '',
            $conn['password'] ?? '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $pdo->query("SELECT 1");
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function getSchemaMapArrayFallback(PDO $pdo, string $connectionId, ?string $databaseName, ?string $schema): array
{
    $driverName = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
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
        $schemas = $pdo->query(
            "SELECT sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
        )->fetchAll(PDO::FETCH_COLUMN);
        $signature = md5(implode("\n", $schemas));
    }

    return [
        'connection'    => $connectionId,
        'driver'        => $driverName,
        'checksum'      => $signature,
        'generated_at'  => date('Y-m-d H:i:s'),
        'features'      => $features,
        'relationships' => $relationships,
        'tables'        => $tablesMetadata,
    ];
}

function activateSessionConnection(string $connectionKey): array
{
    if (!isset($_SESSION['connections'][$connectionKey])) {
        throw new Exception("Connection not found or expired");
    }
    $connInfo = $_SESSION['connections'][$connectionKey];

    $user = $connInfo['user'] ?? '';
    $pass = $connInfo['pass'] ?? '';
    Conn::setup($connInfo['dsn'], $user, $pass, $connectionKey);
    Conn::select($connectionKey);

    $map = $connInfo['map'];
    SchemaMap::setMap($map, $connectionKey);
    SchemaMap::setDefaultConnection($connectionKey);
    ConditionMatrix::setDriver($map['driver']);

    return $connInfo;
}

// ---------------------------------------------------------------------------
// Main Router
// ---------------------------------------------------------------------------
try {
    switch ($action) {

        case 'list_connections':
            $rows = Gateway::select('*', 'connections');
            echo json_encode(['connections' => $rows['data']]);
            break;

        case 'test_connection':
            $data = json_decode(file_get_contents('php://input'), true);
            echo json_encode(['success' => testConnection($data)]);
            break;

        case 'add_connection':
            $data = json_decode(file_get_contents('php://input'), true);
            if (empty($data['name']) || empty($data['driver']) || empty($data['database'])) {
                throw new Exception('Missing required fields (name, driver, database)');
            }
            if (!testConnection($data)) {
                throw new Exception('Could not connect to database. Verify the data.');
            }
            $insertData = [
                'name'     => $data['name'],
                'driver'   => $data['driver'],
                'host'     => $data['host'] ?? null,
                'port'     => $data['port'] ?? null,
                'database' => $data['database'],
                'username' => $data['username'] ?? null,
                'password' => $data['password'] ?? null,
            ];
            $id = Gateway::insert('connections', $insertData);
            echo json_encode(['success' => true, 'id' => $id]);
            break;

        case 'remove_connection':
            $id = $_REQUEST['id'] ?? 0;
            if ($id) {
                Gateway::delete('connections', ['id' => $id]);
            }
            echo json_encode(['success' => true]);
            break;

        case 'list_databases':
            if (!is_dir(DATA_PATH)) mkdir(DATA_PATH, 0777, true);
            $files = glob(DATA_PATH . '/*.sqlite');
            $databases = array_map('basename', $files);
            echo json_encode(['databases' => $databases]);
            break;

        case 'connect':
            $dbFile = $_POST['db'] ?? '';
            if (empty($dbFile)) throw new Exception('Database name required');
            $fullPath = DATA_PATH . '/' . basename($dbFile);
            if (!file_exists($fullPath)) throw new Exception('Database file not found');
            $connectionKey = md5($fullPath);
            if (!isset($_SESSION['connections'][$connectionKey])) {
                $pdo = new PDO("sqlite:$fullPath", '', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $connRow = ['driver' => 'sqlite', 'database' => $fullPath];
                $map = getSchemaMapArrayFallback($pdo, $connectionKey, $fullPath, null);
                $_SESSION['connections'][$connectionKey] = [
                    'dsn'  => "sqlite:$fullPath",
                    'map'  => $map,
                    'user' => '',
                    'pass' => '',
                ];
            }
            activateSessionConnection($connectionKey);
            echo json_encode(['status' => 'ok', 'connectionId' => $connectionKey]);
            break;

        case 'connect_saved':
            $connId = $_POST['connId'] ?? 0;
            $connRow = Gateway::one('connections', ['id' => $connId], '*', null, true);
            if (!$connRow) throw new Exception('Connection not found');
            $connectionKey = "saved_{$connId}";
            if (!isset($_SESSION['connections'][$connectionKey])) {
                $dsn = buildDSN($connRow);
                $pdo = new PDO($dsn, $connRow['username'], $connRow['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $databaseName = $connRow['database'] ?? null;
                $schema = ($connRow['driver'] === 'pgsql') ? 'public' : null;
                $map = getSchemaMapArrayFallback($pdo, $connectionKey, $databaseName, $schema);
                $_SESSION['connections'][$connectionKey] = [
                    'dsn'  => $dsn,
                    'map'  => $map,
                    'user' => $connRow['username'] ?? '',
                    'pass' => $connRow['password'] ?? '',
                ];
            }
            activateSessionConnection($connectionKey);
            echo json_encode(['status' => 'ok', 'connectionId' => $connectionKey]);
            break;

        case 'list_tables':
            $connectionKey = $_REQUEST['connectionId'] ?? '';
            activateSessionConnection($connectionKey);
            $map = SchemaMap::getMap($connectionKey);
            $tables = array_keys($map['tables'] ?? []);
            $quotedTables = array_map(fn($t) => ConditionMatrix::quote($t), $tables);
            echo json_encode(['tables' => $tables, 'quotedTables' => $quotedTables, 'views' => []]);
            break;

        case 'auto_query':
            $connectionKey = $_POST['connectionId'] ?? '';
            $tablesJson    = $_POST['tables'] ?? '';
            if (!$connectionKey || !$tablesJson) throw new Exception('connectionId and tables required');
            $tables = json_decode($tablesJson, true);
            if (count($tables) < 1) throw new Exception('At least one table required');
            activateSessionConnection($connectionKey);
            $compiled = Q::from($tables)->select('*');
            echo json_encode(['sql' => $compiled->getSql()]);
            break;

        case 'execute_query':
            $connectionKey = $_POST['connectionId'] ?? '';
            $sql = $_POST['sql'] ?? '';
            if (!$connectionKey || !$sql) throw new Exception('connectionId and sql required');
            activateSessionConnection($connectionKey);
            $stmt = Conn::get()->prepare($sql);
            $stmt->execute();
            if (stripos(trim($sql), 'select') === 0) {
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $columns = $rows ? array_keys($rows[0]) : [];
                echo json_encode(['columns' => $columns, 'rows' => $rows]);
            } else {
                echo json_encode(['affected' => $stmt->rowCount()]);
            }
            break;

        case 'grid_data':
            $connectionKey = $_REQUEST['connectionId'] ?? $_REQUEST['connection_id'] ?? '';
            $table  = $_REQUEST['table']  ?? '';
            $page   = (int)($_REQUEST['page']   ?? 1);
            $limit  = (int)($_REQUEST['limit']  ?? 10);
            $sort   = $_REQUEST['sort']   ?? null;
            $filter = $_REQUEST['filter'] ?? null;

            if (!$connectionKey || !$table) {
                http_response_code(400);
                echo json_encode(['error' => 'Required parameters: connectionId and table']);
                break;
            }

            $page  = max(1, $page);
            $limit = max(1, min($limit, 1000));

            activateSessionConnection($connectionKey);

            $conditions = [];
            if ($filter) {
                $decoded = is_string($filter) ? json_decode($filter, true) : $filter;
                if (is_array($decoded)) $conditions = $decoded;
            }

            $response = DB::grid($table, $conditions, [$page, $limit], $sort);

            echo json_encode([
                'data'      => $response->data,
                'total'     => $response->total,
                'columns'   => $response->metadata['columns'] ?? [],
                'titles'    => $response->metadata['titles']  ?? [],
                'limit'     => $response->state['per_page'],
                'page'      => $response->state['page'],
                'last_page' => $response->state['last_page'],
            ]);
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