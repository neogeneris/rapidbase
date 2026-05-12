<?php
/**
 * Prueba 1: Conectar y verificar entorno
 * Verifica que el API esté accesible y el entorno esté configurado correctamente
 */

$apiUrl = 'http://localhost:8000/api.php';
$stateFile = __DIR__ . '/.test_state.json';
$cookieFile = __DIR__ . '/.test_cookies.txt';
$errors = [];

// Test 1: Verificar que el API responde
echo "=== Prueba 1: Conectar y verificar entorno ===\n";

// Limpiar cookies anteriores
if (file_exists($cookieFile)) {
    unlink($cookieFile);
}

$ch = curl_init($apiUrl . '?action=list_databases');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    $errors[] = "Error de conexión: $curlError";
    echo "FAIL: No se pudo conectar al API ($curlError)\n";
    exit(1);
}

if ($httpCode !== 200) {
    $errors[] = "HTTP code incorrecto: $httpCode";
    echo "FAIL: HTTP code $httpCode esperado 200\n";
    exit(1);
}

$data = json_decode($response, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    $errors[] = "JSON inválido: " . json_last_error_msg();
    echo "FAIL: Respuesta no es JSON válido\n";
    exit(1);
}

if (!isset($data['databases'])) {
    $errors[] = "Respuesta inesperada: " . json_encode($data);
    echo "FAIL: Respuesta no contiene 'databases'\n";
    exit(1);
}

echo "PASS: API conectado correctamente\n";
echo "Databases encontradas: " . count($data['databases']) . "\n";

// Guardar estado para siguientes pruebas
file_put_contents(__DIR__ . '/.test_state.json', json_encode([
    'step' => 1,
    'timestamp' => time(),
    'errors' => $errors
]));

echo "\n=== Prueba 1 completada exitosamente ===\n";
exit(0);
