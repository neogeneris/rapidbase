<?php
require_once 'config.php';

use RapidBase\Core\Conn;
use RapidBase\Core\Executor;
use RapidBase\Core\Gateway;
use RapidBase\Core\SchemaMap;
use RapidBase\Core\SQL\ConditionMatrix;
use RapidBase\Core\SQL\Q;
use RapidBase\Meta\Discovery\DiscoveryFactory;
use RapidBase\Meta\Discovery\FeatureDetector;

header('Content-Type: application/json');

$action = $_REQUEST['action'] ?? '';

// Inicializar la base de datos interna de conexiones si no existe
function initConnectionsDB() {
    $dbFile = CONNECTIONS_DB;
    // Asegurar que el directorio existe
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

// Configurar la conexión 'main' para la base de datos interna
initConnectionsDB();
Conn::setup("sqlite:" . CONNECTIONS_DB, '', '', 'main');

// Helper para generar mapa bajo demanda
function getSchemaMapArray(PDO $pdo, string $connectionId): array {
    $driverName = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $discovery = DiscoveryFactory::create($pdo);
    
    // Obtener el nombre de la base de datos actual si es necesario
    $databaseName = null;
    if ($driverName === 'mysql') {
        $stmt = $pdo->query("SELECT DATABASE() as current_db");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $databaseName = $row['current_db'] ?? '';
    } elseif ($driverName === 'pgsql') {
        $stmt = $pdo->query("SELECT current_database() as current_db");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $databaseName = $row['current_db'] ?? '';
    }
    
    if ($driverName === 'pgsql') {
        $allTables = $discovery->getTables($databaseName);
    } elseif ($driverName === 'sqlsrv') {
        $stmt = $pdo->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE' AND TABLE_SCHEMA = 'dbo'");
        $allTables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $allTables = $discovery->getTables($databaseName);
    }
    
    $tablesMetadata = [];
    foreach ($allTables as $table) {
        $tablesMetadata[$table] = $discovery->discoverColumns($table, $databaseName);
    }
    
    $relationships = $discovery->discoverRelationships($databaseName);
    $detector = new FeatureDetector($pdo);
    $features = $detector->detect();
    
    if ($driverName === 'sqlite') {
        $stmt = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
        $schemas = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $signature = md5(implode("\n", $schemas));
    } else {
        $signature = '';
    }
    
    return [
        'connection'    => $connectionId,
        'driver'        => $driverName,
        'checksum'      => $signature,
        'generated_at'  => date('Y-m-d H:i:s'),
        'features'      => $features,
        'relationships' => $relationships,
        'tables'        => $tablesMetadata
    ];
}

function buildDSN(array $conn): string {
    switch ($conn['driver']) {
        case 'sqlite':
            return "sqlite:{$conn['database']}";
        case 'mysql':
            return "mysql:host={$conn['host']};port={$conn['port']};dbname={$conn['database']};charset=utf8mb4";
        case 'pgsql':
            return "pgsql:host={$conn['host']};port={$conn['port']};dbname={$conn['database']}";
        default:
            throw new Exception("Driver no soportado: {$conn['driver']}");
    }
}

function testConnection(array $conn): bool {
    try {
        $dsn = buildDSN($conn);
        $pdo = new PDO($dsn, $conn['username'], $conn['password']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->query("SELECT 1");
        return true;
    } catch (Exception $e) {
        return false;
    }
}

try {
    switch ($action) {
        case 'list_connections':
            $rows = Gateway::select('*', 'connections', [], [], [], [], null, false, PDO::FETCH_ASSOC);
            echo json_encode(['connections' => $rows['data']]);
            break;

        case 'test_connection':
            $data = json_decode(file_get_contents('php://input'), true);
            $success = testConnection($data);
            echo json_encode(['success' => $success]);
            break;

        case 'add_connection':
            $data = json_decode(file_get_contents('php://input'), true);
            if (empty($data['name']) || empty($data['driver'])) {
                throw new Exception('Faltan datos obligatorios (name, driver)');
            }
            // Para SQLite se requiere database, para servidores también
            if (empty($data['database'])) {
                throw new Exception('El campo database es obligatorio');
            }
            if (!testConnection($data)) {
                throw new Exception('No se pudo conectar a la base de datos. Verifica los datos.');
            }
            $insertData = [
                'name' => $data['name'],
                'driver' => $data['driver'],
                'host' => $data['host'] ?? null,
                'port' => $data['port'] ?? null,
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
            $dataPath = DATA_PATH;
            if (!is_dir($dataPath)) mkdir($dataPath, 0777, true);
            $files = glob($dataPath . '/*.sqlite');
            $databases = array_map('basename', $files);
            echo json_encode(['databases' => $databases]);
            break;

        case 'connect':
            $dbFile = $_POST['db'] ?? '';
            if (empty($dbFile)) throw new Exception('Database name required');
            $fullPath = DATA_PATH . '/' . basename($dbFile);
            if (!file_exists($fullPath)) throw new Exception('Database file not found');
            $connectionId = md5($fullPath);
            if (!isset($_SESSION['connections'][$connectionId])) {
                $pdo = new PDO("sqlite:$fullPath");
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $map = getSchemaMapArray($pdo, $connectionId);
                $_SESSION['connections'][$connectionId] = [
                    'dsn' => "sqlite:$fullPath",
                    'map' => $map
                ];
            }
            $map = $_SESSION['connections'][$connectionId]['map'];
            SchemaMap::setMap($map, $connectionId);
            SchemaMap::setDefaultConnection($connectionId);
            ConditionMatrix::setDriver($map['driver']);
            echo json_encode(['status' => 'ok', 'connectionId' => $connectionId]);
            break;

        case 'connect_saved':
            $connId = $_POST['connId'] ?? 0;
            $connRow = Gateway::one('connections', ['id' => $connId], '*', null, true);
            if (!$connRow) throw new Exception('Conexión no encontrada');
            $dsn = buildDSN($connRow);
            $pdo = new PDO($dsn, $connRow['username'], $connRow['password']);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $connectionKey = "saved_{$connId}";
            if (!isset($_SESSION['connections'][$connectionKey])) {
                $map = getSchemaMapArray($pdo, $connectionKey);
                $_SESSION['connections'][$connectionKey] = [
                    'dsn' => $dsn,
                    'map' => $map,
                    'connInfo' => $connRow,
                    'databaseName' => $connRow['database'] ?? null
                ];
            }
            $map = $_SESSION['connections'][$connectionKey]['map'];
            SchemaMap::setMap($map, $connectionKey);
            SchemaMap::setDefaultConnection($connectionKey);
            ConditionMatrix::setDriver($map['driver']);
            echo json_encode(['status' => 'ok', 'connectionId' => $connectionKey]);
            break;

        case 'list_tables':
            $connectionId = $_REQUEST['connectionId'] ?? '';
            if (!$connectionId || !isset($_SESSION['connections'][$connectionId])) {
                throw new Exception('Invalid connectionId');
            }
            $map = $_SESSION['connections'][$connectionId]['map'];
            $tables = array_keys($map['tables']);
            // Configurar ConditionMatrix para generar nombres de tablas con quotes correctos
            ConditionMatrix::setDriver($map['driver']);
            $quotedTables = array_map(fn($t) => ConditionMatrix::quote($t), $tables);
            echo json_encode(['tables' => $tables, 'quotedTables' => $quotedTables, 'views' => []]);
            break;

        case 'auto_query':
            $connectionId = $_POST['connectionId'] ?? '';
            $tablesJson = $_POST['tables'] ?? '';
            if (!$connectionId || !$tablesJson) throw new Exception('connectionId and tables required');
            $tables = json_decode($tablesJson, true);
            if (count($tables) < 2) throw new Exception('At least two tables required');
            $connInfo = $_SESSION['connections'][$connectionId];
            $map = $connInfo['map'];
            SchemaMap::setMap($map, $connectionId);
            SchemaMap::setDefaultConnection($connectionId);
            ConditionMatrix::setDriver($map['driver']);
            
            // Si es MySQL y tenemos un nombre de base de datos específico, asegurarnos de usarla
            $driverName = $map['driver'] ?? '';
            if ($driverName === 'mysql' && !empty($connInfo['databaseName'])) {
                // La base de datos ya está en el DSN, pero por si acaso
                // las relaciones se generaron correctamente con esa DB
            }
            
            $main = $tables[0];
            $from = ConditionMatrix::quote($main);
            $rels = $map['relationships']['from'] ?? [];
            for ($i = 1; $i < count($tables); $i++) {
                $next = $tables[$i];
                if (isset($rels[$main][$next])) {
                    $rel = $rels[$main][$next];
                    $from .= " LEFT JOIN " . ConditionMatrix::quote($next) .
                             " ON " . ConditionMatrix::quote($main) . "." . ConditionMatrix::quote($rel['local_key']) .
                             " = " . ConditionMatrix::quote($next) . "." . ConditionMatrix::quote($rel['foreign_key']);
                } else {
                    $fallbackCol = rtrim($main, 's') . '_id';
                    $from .= " LEFT JOIN " . ConditionMatrix::quote($next) .
                             " ON " . ConditionMatrix::quote($main) . ".id = " . ConditionMatrix::quote($next) . "." . ConditionMatrix::quote($fallbackCol);
                }
            }
            $sql = "SELECT * FROM $from LIMIT 100";
            echo json_encode(['sql' => $sql]);
            break;

        case 'execute_query':
            $connectionId = $_POST['connectionId'] ?? '';
            $sql = $_POST['sql'] ?? '';
            if (!$connectionId || !$sql) throw new Exception('connectionId and sql required');
            $connInfo = $_SESSION['connections'][$connectionId];
            
            // Configurar SchemaMap y ConditionMatrix para esta conexión
            $map = $connInfo['map'];
            SchemaMap::setMap($map, $connectionId);
            SchemaMap::setDefaultConnection($connectionId);
            ConditionMatrix::setDriver($map['driver']);
            
            $user = $connInfo['connInfo']['username'] ?? null;
            $pass = $connInfo['connInfo']['password'] ?? null;
            $pdo = new PDO($connInfo['dsn'], $user, $pass);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Si es MySQL o PostgreSQL y tenemos un nombre de base de datos específico, seleccionarla
            $driverName = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driverName === 'mysql' && !empty($connInfo['databaseName'])) {
                $pdo->exec("USE `{$connInfo['databaseName']}`");
            } elseif ($driverName === 'pgsql' && !empty($connInfo['databaseName'])) {
                // En PostgreSQL la DB ya está en el DSN, pero por si acaso
                // no es necesario hacer nada adicional aquí
            }
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            if (stripos(trim($sql), 'select') === 0) {
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $columns = $rows ? array_keys($rows[0]) : [];
                echo json_encode(['columns' => $columns, 'rows' => $rows]);
            } else {
                $affected = $stmt->rowCount();
                echo json_encode(['affected' => $affected]);
            }
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => "Invalid action: $action"]);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}