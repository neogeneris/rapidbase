<?php
/**
 * Prueba 10: Limpieza y eliminación de recursos de prueba
 * Elimina la conexión de prueba y limpia archivos temporales
 */

$apiUrl = 'http://localhost:8000/api.php';
$stateFile = __DIR__ . '/.test_state.json';
$cookieFile = __DIR__ . '/.test_cookies.txt';

echo "=== Prueba 10: Limpieza de recursos ===\n";

if (!file_exists($stateFile)) {
    echo "INFO: No hay estado de pruebas anteriores, solo limpiando archivos temporales.\n";
} else {
    $state = json_decode(file_get_contents($stateFile), true);
    
    // Eliminar conexión de prueba si existe
    if (isset($state['test_connection_id'])) {
        $connectionId = $state['test_connection_id'];
        $numericId = substr($connectionId, 6); // quitar 'saved_'
        
        echo "Eliminando conexión de prueba ID: $numericId\n";
        
        $ch = curl_init($apiUrl . '?action=remove_connection&id=' . $numericId);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            echo "PASS: Conexión eliminada\n";
        } else {
            echo "WARN: No se pudo eliminar la conexión (HTTP $httpCode)\n";
        }
    }
    
    // Eliminar archivo de base de datos de prueba
    if (isset($state['test_db_path']) && file_exists($state['test_db_path'])) {
        echo "Eliminando DB de prueba: " . $state['test_db_path'] . "\n";
        unlink($state['test_db_path']);
        echo "PASS: DB eliminada\n";
    }
}

// Limpiar archivos temporales
if (file_exists($cookieFile)) {
    unlink($cookieFile);
    echo "INFO: Cookie file eliminado\n";
}

if (file_exists($stateFile)) {
    unlink($stateFile);
    echo "INFO: Estado de pruebas eliminado\n";
}

echo "\n=== Prueba 10 completada - Limpieza finalizada ===\n";
exit(0);
