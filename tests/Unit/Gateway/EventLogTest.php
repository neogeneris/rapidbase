<?php
namespace Tests\Unit\Gateway;

require_once __DIR__ . '/../../../src/RapidBase/Core/SQL.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/Conn.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/Executor.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/Gateway.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/Event.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/Cache/CacheService.php';

use RapidBase\Core\Conn;
use RapidBase\Core\Gateway;
use RapidBase\Core\Event;

// Crear directorio de logs si no existe
$logDir = __DIR__ . '/../../tmp/log';
if (!is_dir($logDir)) {
    mkdir($logDir, 0777, true);
}
$logFile = $logDir . '/action.log';
// Limpiar archivo anterior
if (file_exists($logFile)) {
    unlink($logFile);
}

// Configurar eventos para escribir en el archivo de log
Event::listen('db.success', function($data) use ($logFile) {
    $entry = json_encode([
        'event' => 'db.success',
        'type' => $data['type'] ?? null,
        'table' => $data['table'] ?? null,
        'duration' => $data['duration'] ?? null,
        'sql' => $data['sql'] ?? null,
        'timestamp' => microtime(true)
    ]) . PHP_EOL;
    file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
});

Event::listen('db.error', function($data) use ($logFile) {
    $entry = json_encode([
        'event' => 'db.error',
        'type' => $data['type'] ?? null,
        'table' => $data['table'] ?? null,
        'error' => $data['error'] ?? null,
        'sql' => $data['sql'] ?? null,
        'timestamp' => microtime(true)
    ]) . PHP_EOL;
    file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
});

// También escuchamos db.log (opcional)
Event::listen('db.log', function($data) use ($logFile) {
    $entry = json_encode([
        'event' => 'db.log',
        'type' => $data['type'] ?? null,
        'table' => $data['table'] ?? null,
        'success' => $data['success'],
        'duration' => $data['duration'] ?? null,
        'timestamp' => microtime(true)
    ]) . PHP_EOL;
    file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
});

// Setup DB
Conn::setup("sqlite::memory:", "", "", "main");
Conn::get()->exec("CREATE TABLE test_log (id INTEGER PRIMARY KEY, nombre TEXT)");

echo "--- Prueba de registro de eventos en archivo ---\n";

// Realizar acciones que disparen eventos
Gateway::action('insert', 'test_log', ['nombre' => 'Evento 1']);
Gateway::action('insert', 'test_log', ['nombre' => 'Evento 2']);
Gateway::action('update', 'test_log', ['nombre' => 'Actualizado'], ['id' => 1]);
Gateway::action('delete', 'test_log', ['id' => 2]);

// Forzar error
try {
    Gateway::action('select', 'test_log', ['id' => 1]);
} catch (\Exception $e) {
    // El evento db.error ya se disparó
}

// Leer el archivo de log y mostrar su contenido
echo "\nContenido del archivo $logFile:\n";
echo str_repeat('-', 60) . "\n";
if (file_exists($logFile)) {
    echo file_get_contents($logFile);
} else {
    echo "No se pudo crear el archivo de log.\n";
}
echo str_repeat('-', 60) . "\n";

// Verificar que se hayan escrito al menos 5 líneas (por los 4 éxitos + 1 error)
$lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$count = count($lines);
if ($count >= 5) {
    echo "\033[32m[OK]\033[0m Se registraron $count eventos en el log.\n";
} else {
    echo "\033[31m[FAIL]\033[0m Solo se registraron $count eventos (se esperaban al menos 5).\n";
}
