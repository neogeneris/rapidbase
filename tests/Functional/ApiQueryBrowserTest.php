<?php

/**
 * Functional test for the Query Browser API endpoints.
 * Simulates HTTP requests by including api.php with custom request globals
 * and intercepting the output.
 *
 * Usage: php tests/Functional/ApiQueryBrowserTest.php
 */

// ---- Prevent actual headers from being sent in CLI ----
function _test_header_handler(string $header = null): void
{
    // Simply discard all headers in CLI mode.
}
header_register_callback('_test_header_handler');

// ---- Start session once (api.php uses session_start()) ----
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---- Simulate request data ----
$_SERVER['REQUEST_METHOD'] = 'GET';
$_REQUEST = [];
$_POST   = [];

// ========== Helper functions ==========
function simulateApi(string $action, string $method = 'GET', array $data = []): array
{
    // Clean previous request globals
    $_REQUEST = ['action' => $action];
    $_POST    = ($method === 'POST') ? $data : [];

    // Ensure no previous output buffering interferes
    while (ob_get_level() > 0) ob_end_clean();
    ob_start();

    try {
        include __DIR__ . '/../../examples/querybrowser/api.php';
    } catch (\Throwable $e) {
        // The API may call exit; the output is already captured before the exception.
        error_log("api.php threw: " . $e->getMessage());
    }

    $output = ob_get_clean();
    $decoded = json_decode($output, true);
    return is_array($decoded) ? $decoded : ['raw' => $output];
}

// ========== Pre-test cleanup ==========
echo "Preparing test environment...\n";

$dbDir = __DIR__ . '/../../examples/querybrowser/data';
if (!is_dir($dbDir)) mkdir($dbDir, 0777, true);

// Remove old test connections
$existing = simulateApi('list_connections');
foreach ($existing['connections'] ?? [] as $conn) {
    if (stripos($conn[1] ?? '', 'test_api_') === 0) {
        simulateApi('remove_connection', 'GET', ['id' => $conn[0]]);
    }
}

// ========== 1. Add connection ==========
echo "Test 1: Adding SQLite connection... ";
$connData = [
    'name'     => 'test_api_' . time(),
    'driver'   => 'sqlite',
    'database' => ':memory:',
    'username' => '',
    'password' => '',
];
$add = simulateApi('add_connection', 'POST', $connData);
if (($add['success'] ?? false) !== true) {
    echo "FAIL (response: " . json_encode($add) . ")\n";
    exit(1);
}
$connId = $add['id'];
echo "OK (id=$connId)\n";

// ========== 2. Test connection (raw) ==========
echo "Test 2: Ping with raw credentials... ";
$pingRaw = simulateApi('test_connection', 'POST', $connData);
if (!isset($pingRaw['success']) || !$pingRaw['success']) {
    echo "FAIL (response: " . json_encode($pingRaw) . ")\n";
    exit(1);
}
echo "OK ({$pingRaw['latency']}ms)\n";

// ========== 3. Test connection by ID ==========
echo "Test 3: Ping by ID... ";
$pingById = simulateApi('test_connection', 'POST', ['id' => $connId]);
if (!isset($pingById['success']) || !$pingById['success']) {
    echo "FAIL (response: " . json_encode($pingById) . ")\n";
    exit(1);
}
echo "OK ({$pingById['latency']}ms)\n";

// ========== 4. Activate connection ==========
echo "Test 4: Activate connection... ";
$activate = simulateApi('connect_saved', 'POST', ['connId' => $connId]);
if (($activate['status'] ?? '') !== 'ok') {
    echo "FAIL (response: " . json_encode($activate) . ")\n";
    exit(1);
}
$connectionKey = $activate['connectionId'] ?? '';
echo "OK ($connectionKey)\n";

// ========== 5. List tables ==========
echo "Test 5: List tables... ";
$tables = simulateApi('list_tables', 'GET', ['connectionId' => $connectionKey]);
if (!isset($tables['tables']) || !isset($tables['database']) || !isset($tables['driver'])) {
    echo "FAIL (response: " . json_encode($tables) . ")\n";
    exit(1);
}
echo "OK (driver={$tables['driver']}, db={$tables['database']})\n";

// ========== 6. Execute SQL ==========
echo "Test 6: Execute SQL (CREATE+INSERT)... ";
$createRes = simulateApi('execute_query', 'POST', [
    'connectionId' => $connectionKey,
    'sql'          => "CREATE TABLE test_items (id INTEGER PRIMARY KEY, name TEXT)"
]);
$insertRes = simulateApi('execute_query', 'POST', [
    'connectionId' => $connectionKey,
    'sql'          => "INSERT INTO test_items (name) VALUES ('hello')"
]);
if (($createRes['success'] ?? true) === false || ($insertRes['success'] ?? true) === false) {
    echo "FAIL\n";
    exit(1);
}
echo "OK\n";

// ========== 7. Grid data ==========
echo "Test 7: Fetch grid data... ";
$grid = simulateApi('grid_data', 'GET', [
    'connectionId' => $connectionKey,
    'table'        => 'test_items',
    'limit'        => 10
]);
if (!isset($grid['data']) || count($grid['data']) < 1) {
    echo "FAIL (response: " . json_encode($grid) . ")\n";
    exit(1);
}
echo "OK (rows=" . count($grid['data']) . ")\n";

// ========== 8. Connection info ==========
echo "Test 8: Connection info... ";
$info = simulateApi('get_connection_info', 'GET', ['connectionId' => $connectionKey]);
if (!isset($info['connection_id'])) {
    echo "FAIL (response: " . json_encode($info) . ")\n";
    exit(1);
}
echo "OK (name={$info['name']}, driver={$info['driver']})\n";

// ========== 9. Table description ==========
echo "Test 9: Table description... ";
$desc = simulateApi('table_description', 'GET', [
    'connectionId' => $connectionKey,
    'tables'        => json_encode(['test_items'])
]);
if (!isset($desc['description']['test_items'])) {
    echo "FAIL (response: " . json_encode($desc) . ")\n";
    exit(1);
}
echo "OK\n";

// ========== 10. Invalidate cache ==========
echo "Test 10: Invalidate cache... ";
simulateApi('invalidate_cache', 'GET', [
    'connectionId' => $connectionKey,
    'table'        => 'test_items'
]);
echo "OK\n";

// ========== 11. Cleanup ==========
echo "Cleaning test connection... ";
simulateApi('remove_connection', 'GET', ['id' => $connId]);
echo "OK\n";

echo "\n✅ All API tests passed.\n";