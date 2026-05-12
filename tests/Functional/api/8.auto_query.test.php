<?php
/**
 * Prueba 8: Auto Query Builder
 * Prueba la generación automática de consultas SQL
 */

$apiUrl = 'http://localhost:8000/api.php';
$stateFile = __DIR__ . '/.test_state.json';
$cookieFile = __DIR__ . '/.test_cookie.txt';

echo "=== Prueba 8: Auto Query Builder ===\n";

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

// Test: auto_query
$queryData = [
    'connectionId' => $connectionId,
    'tables' => json_encode([$testTable])
];

$ch = curl_init($apiUrl . '?action=auto_query');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($queryData));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
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

if (!isset($data['sql'])) {
    echo "FAIL: No se devolvió SQL: " . json_encode($data) . "\n";
    exit(1);
}

echo "PASS: Auto query generado exitosamente\n";
echo "SQL generado: " . $data['sql'] . "\n";

// Actualizar estado
$state['step'] = 8;
$state['auto_query_tested'] = true;
file_put_contents($stateFile, json_encode($state));

echo "\n=== Prueba 8 completada exitosamente ===\n";
exit(0);
