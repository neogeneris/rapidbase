<?php
/**
 * Prueba 3: Agregar nueva conexión SQLite
 * Crea una conexión de prueba y la verifica
 */

$apiUrl = 'http://localhost:8000/api.php';
$testDbName = 'test_' . time() . '.sqlite';
$testDbPath = __DIR__ . '/../../data/' . $testDbName;

echo "=== Prueba 3: Agregar nueva conexión SQLite ===\n";

// Crear DB de prueba
if (!is_dir(__DIR__ . '/../../data')) {
    mkdir(__DIR__ . '/../../data', 0777, true);
}

$pdo = new PDO("sqlite:$testDbPath");
$pdo->exec("CREATE TABLE usuarios (id INTEGER PRIMARY KEY, nombre TEXT, email TEXT)");
$pdo->exec("INSERT INTO usuarios (nombre, email) VALUES ('Test User', 'test@example.com')");
echo "DB de prueba creada: $testDbPath\n";

// Test: add_connection
$connectionData = [
    'name' => 'Test Connection ' . time(),
    'driver' => 'sqlite',
    'database' => $testDbPath,
    'description' => 'Conexión de prueba automática',
    'status' => 'dev'
];

$ch = curl_init($apiUrl . '?action=add_connection');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($connectionData));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    echo "FAIL: Error de conexión: $curlError\n";
    exit(1);
}

if ($httpCode !== 200) {
    echo "FAIL: HTTP code $httpCode esperado 200\n";
    echo "Respuesta: $response\n";
    exit(1);
}

$data = json_decode($response, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo "FAIL: Respuesta no es JSON válido\n";
    exit(1);
}

if (!isset($data['success']) || !$data['success']) {
    echo "FAIL: No se pudo agregar conexión: " . json_encode($data) . "\n";
    exit(1);
}

if (!isset($data['id'])) {
    echo "FAIL: Respuesta no contiene ID: " . json_encode($data) . "\n";
    exit(1);
}

echo "PASS: Conexión agregada correctamente\n";
echo "ID de conexión: " . $data['id'] . "\n";

// Actualizar estado
$state = file_exists(__DIR__ . '/.test_state.json') 
    ? json_decode(file_get_contents(__DIR__ . '/.test_state.json'), true) 
    : [];
$state['step'] = 3;
$state['test_connection_id'] = $data['id'];
$state['test_db_path'] = $testDbPath;
$state['test_db_name'] = $testDbName;
file_put_contents(__DIR__ . '/.test_state.json', json_encode($state));

echo "\n=== Prueba 3 completada exitosamente ===\n";
exit(0);
