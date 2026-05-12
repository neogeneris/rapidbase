<?php
/**
 * Prueba 5: Conectar a base de datos y listar tablas
 * Activa una conexión y obtiene el esquema de tablas
 */

$apiUrl = 'http://localhost:8000/api.php';
$stateFile = __DIR__ . '/.test_state.json';
$cookieFile = __DIR__ . '/.test_cookies.txt';

echo "=== Prueba 5: Conectar y listar tablas ===\n";

if (!file_exists($stateFile)) {
    echo "FAIL: No hay estado de pruebas anteriores. Ejecuta primero las pruebas 1-4.\n";
    exit(1);
}

$state = json_decode(file_get_contents($stateFile), true);
if (!isset($state['test_connection_id'])) {
    echo "FAIL: No hay ID de conexión de prueba en el estado.\n";
    exit(1);
}

$connectionId = $state['test_connection_id'];
$numericId = is_numeric($connectionId) ? $connectionId : intval(substr($connectionId, 6)); // quitar 'saved_' si existe
echo "Usando conexión ID numérico: $numericId\n";

// Paso 1: connect_saved para activar la conexión
$ch = curl_init($apiUrl . '?action=connect_saved');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['connId' => $numericId]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    echo "FAIL: Error en connect_saved: $curlError\n";
    exit(1);
}

if ($httpCode !== 200) {
    echo "FAIL: HTTP code $httpCode en connect_saved\n";
    echo "Respuesta: $response\n";
    exit(1);
}

$data = json_decode($response, true);
if (!isset($data['status']) || $data['status'] !== 'ok') {
    echo "FAIL: connect_saved falló: " . json_encode($data) . "\n";
    exit(1);
}

$sessionConnectionId = $data['connectionId'] ?? '';
echo "PASS: Conexión activada (session ID: $sessionConnectionId)\n";

// Guardar cookie de sesión para siguientes requests
$cookieFile = __DIR__ . '/.test_cookie.txt';
preg_match_all('/Set-Cookie:\s*([^;]*)/mi', $response, $matches);
file_put_contents($cookieFile, implode("\n", $matches[0]));

// Paso 2: list_tables
$ch = curl_init($apiUrl . '?action=list_tables&connectionId=' . urlencode($sessionConnectionId));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    echo "FAIL: Error en list_tables: $curlError\n";
    exit(1);
}

if ($httpCode !== 200) {
    echo "FAIL: HTTP code $httpCode en list_tables\n";
    echo "Respuesta: $response\n";
    exit(1);
}

$data = json_decode($response, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo "FAIL: Respuesta no es JSON válido en list_tables\n";
    exit(1);
}

if (!isset($data['success']) || !$data['success']) {
    echo "FAIL: list_tables falló: " . json_encode($data) . "\n";
    exit(1);
}

if (!isset($data['tables']) || !is_array($data['tables'])) {
    echo "FAIL: list_tables no devolvió array de tablas: " . json_encode($data) . "\n";
    exit(1);
}

echo "PASS: Tablas listadas correctamente\n";
echo "Tablas encontradas: " . implode(', ', $data['tables']) . "\n";
echo "Driver: " . ($data['driver'] ?? 'N/A') . "\n";
echo "Database: " . ($data['database'] ?? 'N/A') . "\n";

// Actualizar estado
$state['step'] = 5;
$state['session_connection_id'] = $sessionConnectionId;
$state['tables'] = $data['tables'];
file_put_contents($stateFile, json_encode($state));

echo "\n=== Prueba 5 completada exitosamente ===\n";
exit(0);
