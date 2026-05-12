<?php
/**
 * Prueba 4: Probar conexión (test_connection)
 * Verifica que se pueda probar una conexión guardada
 */

$apiUrl = 'http://localhost:8000/api.php';
$stateFile = __DIR__ . '/.test_state.json';

echo "=== Prueba 4: Probar conexión ===\n";

if (!file_exists($stateFile)) {
    echo "FAIL: No hay estado de pruebas anteriores. Ejecuta primero las pruebas 1-3.\n";
    exit(1);
}

$state = json_decode(file_get_contents($stateFile), true);
if (!isset($state['test_connection_id'])) {
    echo "FAIL: No hay ID de conexión de prueba en el estado.\n";
    exit(1);
}

$connectionId = $state['test_connection_id'];
echo "Usando conexión ID: $connectionId\n";

// Test: test_connection
$ch = curl_init($apiUrl . '?action=test_connection');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['id' => $connectionId]));
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

if (!isset($data['success'])) {
    echo "FAIL: Respuesta no contiene 'success': " . json_encode($data) . "\n";
    exit(1);
}

if (!$data['success']) {
    echo "FAIL: La conexión falló: " . ($data['error'] ?? 'Error desconocido') . "\n";
    exit(1);
}

echo "PASS: Conexión probada exitosamente\n";
echo "Latency: " . ($data['latency'] ?? 'N/A') . " ms\n";

// Actualizar estado
$state['step'] = 4;
$state['connection_tested'] = true;
file_put_contents($stateFile, json_encode($state));

echo "\n=== Prueba 4 completada exitosamente ===\n";
exit(0);
