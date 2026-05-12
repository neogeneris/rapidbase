<?php
/**
 * Prueba 2: Listar conexiones guardadas
 * Verifica que se puedan listar las conexiones almacenadas en la DB principal
 */

$apiUrl = 'http://localhost:8000/api.php';

echo "=== Prueba 2: Listar conexiones guardadas ===\n";

// Test: list_connections
$ch = curl_init($apiUrl . '?action=list_connections');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
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
    exit(1);
}

$data = json_decode($response, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo "FAIL: Respuesta no es JSON válido\n";
    exit(1);
}

if (!isset($data['connections'])) {
    echo "FAIL: Respuesta no contiene 'connections': " . json_encode($data) . "\n";
    exit(1);
}

echo "PASS: Conexiones listadas correctamente\n";
echo "Conexiones encontradas: " . count($data['connections']) . "\n";

if (count($data['connections']) > 0) {
    echo "Primera conexión: " . json_encode($data['connections'][0], JSON_PRETTY_PRINT) . "\n";
}

// Actualizar estado
$state = file_exists(__DIR__ . '/.test_state.json') 
    ? json_decode(file_get_contents(__DIR__ . '/.test_state.json'), true) 
    : [];
$state['step'] = 2;
$state['connections'] = $data['connections'];
file_put_contents(__DIR__ . '/.test_state.json', json_encode($state));

echo "\n=== Prueba 2 completada exitosamente ===\n";
exit(0);
