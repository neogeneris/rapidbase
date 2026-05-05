<?php
/**
 * RapidBase API – Query Browser y Grid Dinámico
 *
 * Gestiona conexiones, genera consultas con JOINs automáticos y
 * sirve datos para el grid (incluyendo nombres de columna reales).
 */

session_start();
require_once 'config.php';          // Define constantes (CONNECTIONS_DB, DATA_PATH, etc.)
require_once __DIR__ . '/../../vendor/autoload.php';

use RapidBase\Core\Conn;
use RapidBase\Core\Executor;
use RapidBase\Core\Gateway;
use RapidBase\Core\SchemaMap;
use RapidBase\Core\SQL\ConditionMatrix;
use RapidBase\Core\SQL\Q;
use RapidBase\Meta\Discovery\DiscoveryFactory;
use RapidBase\Meta\Discovery\FeatureDetector;
use \PDO;

header('Content-Type: application/json; charset=utf-8');

$action = $_REQUEST['action'] ?? '';

// ── Inicializar la base de datos interna de conexiones ───────────────
function initConnectionsDB()
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
// Conexión interna (siempre activa) para la tabla de conexiones
Conn::setup("sqlite:" . CONNECTIONS_DB, '', '', 'main');

// ── Helpers ──────────────────────────────────────────────────────────

/** Genera la estructura completa del esquema (relaciones + features) */
function getSchemaMapArray(PDO $pdo, string $connectionId): array
{
    $driverName = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $discovery = DiscoveryFactory::create($pdo);

    // Nombre de la base de datos si aplica
    $databaseName = null;
    if ($driverName === 'mysql') {
        $databaseName = $pdo->query("SELECT DATABASE()")->fetchColumn();
    } elseif ($driverName === 'pgsql') {
        $databaseName = $pdo->query("SELECT current_database()")->fetchColumn();
    }

    // Tablas
    if ($driverName === 'pgsql') {
        $allTables = $discovery->getTables($databaseName);
    } elseif ($driverName === 'sqlsrv') {
        $stmt = $pdo->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE='BASE TABLE' AND TABLE_SCHEMA='dbo'");
        $allTables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $allTables = $discovery->getTables($databaseName);
    }

    $tablesMetadata = [];
    foreach ($allTables as $table) {
        $tablesMetadata[$table] = $discovery->discoverColumns($table, $databaseName);
    }

    // Relaciones y features
    $relationships = $discovery->discoverRelationships($databaseName);
    $detector = new FeatureDetector($pdo);
    $features = $detector->detect();

    // Checksum (para SQLite)
    $signature = '';
    if ($driverName === 'sqlite') {
        $schemas = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
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

/** Construye un DSN a partir de los datos de conexión */
function buildDSN(array $conn): string
{
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

/** Prueba de conectividad simple */
function testConnection(array $conn): bool
{
    try {
        $pdo = new PDO(buildDSN($conn), $conn['username'] ?? '', $conn['password'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->query("SELECT 1");
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Configura el entorno para una conexión guardada en $_SESSION.
 * Debe llamarse antes de cualquier consulta que use Q, Gateway o DB::grid.
 */
function activateSessionConnection(string $connectionKey): array
{
    if (!isset($_SESSION['connections'][$connectionKey])) {
        throw new Exception("Conexión no encontrada o expirada");
    }
    $connInfo = $_SESSION['connections'][$connectionKey];

    // Registrar en el pool de conexiones y activarla
    $user   = $connInfo['connInfo']['username'] ?? $connInfo['user'] ?? '';
    $pass   = $connInfo['connInfo']['password'] ?? $connInfo['pass'] ?? '';
    Conn::setup($connInfo['dsn'], $user, $pass, $connectionKey);
    Conn::select($connectionKey);

    // Cargar esquema y driver
    $map = $connInfo['map'];
    SchemaMap::setMap($map, $connectionKey);
    SchemaMap::setDefaultConnection($connectionKey);
    ConditionMatrix::setDriver($map['driver']);

    return $connInfo;
}

// ── Router principal ─────────────────────────────────────────────────
try {
    switch ($action) {

        // ─── Gestión de conexiones guardadas (CRUD básico) ──────────────
        case 'list_connections':
            $rows = Gateway::select('*', 'connections', [], [], [], [], null, false, PDO::FETCH_ASSOC);
            echo json_encode(['connections' => $rows['data']]);
            break;

        case 'test_connection':
            $data = json_decode(file_get_contents('php://input'), true);
            echo json_encode(['success' => testConnection($data)]);
            break;

        case 'add_connection':
            $data = json_decode(file_get_contents('php://input'), true);
            if (empty($data['name']) || empty($data['driver']) || empty($data['database'])) {
                throw new Exception('Faltan datos obligatorios (name, driver, database)');
            }
            if (!testConnection($data)) {
                throw new Exception('No se pudo conectar a la base de datos. Verifica los datos.');
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

        // ─── Conexión a bases de datos (archivos SQLite o guardadas) ────
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
                $map = getSchemaMapArray($pdo, $connectionKey);
                $_SESSION['connections'][$connectionKey] = [
                    'dsn'  => "sqlite:$fullPath",
                    'map'  => $map,
                    'user' => '',
                    'pass' => '',
                ];
            }
            activateSessionConnection($connectionKey);   // ← ya sincroniza todo
            echo json_encode(['status' => 'ok', 'connectionId' => $connectionKey]);
            break;

        case 'connect_saved':
            $connId = $_POST['connId'] ?? 0;
            $connRow = Gateway::one('connections', ['id' => $connId], '*', null, true);
            if (!$connRow) throw new Exception('Conexión no encontrada');
            $connectionKey = "saved_{$connId}";
            if (!isset($_SESSION['connections'][$connectionKey])) {
                $dsn = buildDSN($connRow);
                $pdo = new PDO($dsn, $connRow['username'], $connRow['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $map = getSchemaMapArray($pdo, $connectionKey);
                $_SESSION['connections'][$connectionKey] = [
                    'dsn'     => $dsn,
                    'map'     => $map,
                    'connInfo'=> $connRow,
                    'user'    => $connRow['username'] ?? '',
                    'pass'    => $connRow['password'] ?? '',
                ];
            }
            activateSessionConnection($connectionKey);
            echo json_encode(['status' => 'ok', 'connectionId' => $connectionKey]);
            break;

        // ─── Explorador de esquema ─────────────────────────────────────
        case 'list_tables':
            $connectionKey = $_REQUEST['connectionId'] ?? '';
            activateSessionConnection($connectionKey);   // asegura entorno
            $map = $_SESSION['connections'][$connectionKey]['map'];
            $tables = array_keys($map['tables']);
            $quotedTables = array_map(fn($t) => ConditionMatrix::quote($t), $tables);
            echo json_encode(['tables' => $tables, 'quotedTables' => $quotedTables, 'views' => []]);
            break;

        // ─── Generación automática de JOINs (drop‑table‑zone) ──────────
        case 'auto_query':
            $connectionKey = $_POST['connectionId'] ?? '';
            $tablesJson    = $_POST['tables'] ?? '';
            if (!$connectionKey || !$tablesJson) throw new Exception('connectionId and tables required');
            $tables = json_decode($tablesJson, true);
            if (count($tables) < 1) throw new Exception('At least one table required');

            activateSessionConnection($connectionKey);

            // Usar Q para generar SQL con JOINs automáticos
            $compiled = Q::from($tables)->select('*');
            $sql = $compiled->getSql();
            echo json_encode(['sql' => $sql]);
            break;

        // ─── Ejecución manual de SQL ───────────────────────────────────
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

        // ─── Grid dinámico (usa DB::grid con FETCH_NUM + proyección) ────
        case 'grid_data':
			$connectionKey = $_REQUEST['connectionId'] ?? $_REQUEST['connection_id'] ?? '';
			$table  = $_REQUEST['table']  ?? '';
			$offset = (int)($_REQUEST['offset'] ?? 0);
			$limit  = (int)($_REQUEST['limit']  ?? 10);
			$sort   = $_REQUEST['sort']   ?? null;
			$filter = $_REQUEST['filter'] ?? null;

			if (!$connectionKey || !$table) {
				http_response_code(400);
				echo json_encode(['error' => 'Parámetros requeridos: connectionId y table']);
				break;
			}

			// Activar conexión, esquema y driver
			activateSessionConnection($connectionKey);

			// Preparar condiciones de filtro (puede venir como JSON)
			$conditions = [];
			if ($filter) {
				$decoded = is_string($filter) ? json_decode($filter, true) : $filter;
				if (is_array($decoded)) {
					$conditions = $decoded;
				}
			}

			// Calcular página a partir de offset/limit
			$page = ($limit > 0) ? (int)floor($offset / $limit) + 1 : 1;

			// Usar DB::grid directamente (¡usa FETCH_NUM y devuelve cols!)
			$response = DB::grid($table, $conditions, $page, $sort);

			// Respuesta compatible con el frontend
			echo json_encode([
				'data'         => $response->data,
				'total'        => $response->total,
				'columns'      => $response->metadata['columns'] ?? [],
				'titles'       => $response->metadata['titles']  ?? [],
				'limit'        => $response->state['per_page'],
				'page'         => $response->state['page'],
				'last_page'    => $response->state['last_page'],
			]);
		break;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}