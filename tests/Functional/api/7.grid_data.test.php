<?php
/**
 * Prueba 7: Grid data con paginación y filtros
 * Prueba la funcionalidad grid_data con parámetros de paginación
 */

$apiUrl = 'http://localhost:8000/api.php';
$stateFile = __DIR__ . '/.test_state.json';
$cookieFile = __DIR__ . '/.test_cookie.txt';

echo "=== Prueba 7: Grid data con paginación ===\n";

if (!file_exists($stateFile)) {
    echo "FAIL: No hay estado de pruebas anteriores.\n";
    exit(1);
}

$state = json_decode(file_get_contents($stateFile), true);
if (!isset($state['session_connection_id'])) {
    echo "FAIL: No hay session_connection_id en el estado.\n";
    exit(1);
}

$connectionId = $state['session_connection_id'];
$tables = $state['tables'] ?? [];

if (empty($tables)) {
    echo "FAIL: No hay tablas en el estado.\n";
    exit(1);
}

$testTable = $tables[0];
echo "Usando tabla: $testTable\n";

// Test: grid_data
$gridData = [
    'connectionId' => $connectionId,
    'tables' => [$testTable],
    'limit' => 10,
    'offset' => 0,
    'orderBy' => [],
    'filters' => []
];

$ch = curl_init($apiUrl . '?action=grid_data');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($gridData));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
if (file_exists($cookieFile)) {
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
}
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
    echo "FAIL: grid_data falló: " . json_encode($data) . "\n";
    exit(1);
}

if (!isset($data['rows']) || !is_array($data['rows'])) {
    echo "FAIL: No se devolvieron rows: " . json_encode($data) . "\n";
    exit(1);
}

echo "PASS: Grid data obtenido exitosamente\n";
echo "Filas retornadas: " . count($data['rows']) . "\n";
echo "Total (si está disponible): " . ($data['total'] ?? 'N/A') . "\n";

// Actualizar estado
$state['step'] = 7;
$state['grid_tested'] = true;
file_put_contents($stateFile, json_encode($state));

echo "\n=== Prueba 7 completada exitosamente ===\n";
exit(0);
