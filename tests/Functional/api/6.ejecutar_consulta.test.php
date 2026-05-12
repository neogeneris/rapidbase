<?php
/**
 * Prueba 6: Ejecutar consulta SQL (execute_query)
 * Ejecuta una consulta SELECT en la tabla de prueba
 */

$apiUrl = 'http://localhost:8000/api.php';
$stateFile = __DIR__ . '/.test_state.json';
$cookieFile = __DIR__ . '/.test_cookie.txt';

echo "=== Prueba 6: Ejecutar consulta SQL ===\n";

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

$testTable = $tables[0]; // Usar la primera tabla (debería ser 'usuarios')
echo "Usando tabla: $testTable\n";
echo "Conexión: $connectionId\n";

// Test: execute_query
$queryData = [
    'connectionId' => $connectionId,
    'sql' => "SELECT * FROM $testTable",
    'limit' => 100,
    'offset' => 0
];

$ch = curl_init($apiUrl . '?action=execute_query');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($queryData));
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
    echo "FAIL: execute_query falló: " . json_encode($data) . "\n";
    exit(1);
}

if (!isset($data['rows']) || !is_array($data['rows'])) {
    echo "FAIL: No se devolvieron rows: " . json_encode($data) . "\n";
    exit(1);
}

echo "PASS: Consulta ejecutada exitosamente\n";
echo "Filas retornadas: " . count($data['rows']) . "\n";
echo "Columnas: " . implode(', ', array_keys($data['rows'][0] ?? [])) . "\n";

if (count($data['rows']) > 0) {
    echo "Primera fila: " . json_encode($data['rows'][0], JSON_PRETTY_PRINT) . "\n";
}

// Actualizar estado
$state['step'] = 6;
$state['query_executed'] = true;
file_put_contents($stateFile, json_encode($state));

echo "\n=== Prueba 6 completada exitosamente ===\n";
exit(0);
