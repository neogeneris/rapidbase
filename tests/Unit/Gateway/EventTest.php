<?php
namespace Tests\Unit\Gateway;

// Incluir dependencias del núcleo
require_once __DIR__ . '/../../../src/Core/SQL.php';
require_once __DIR__ . '/../../../src/Core/Conn.php';
require_once __DIR__ . '/../../../src/Core/Executor.php';
require_once __DIR__ . '/../../../src/Core/Gateway.php';
require_once __DIR__ . '/../../../src/Core/Event.php';
// Incluir el sistema de caché real (ya existe en tu proyecto)
require_once __DIR__ . '/../../../src/Core/Cache/CacheService.php';

use Core\Conn;
use Core\Gateway;
use Core\Event;

echo "--- Probando Sistema de Eventos de RapidBase ---\n";

// Configurar eventos
Event::listen('db.success', function($data) {
    echo "\033[32m[EVENT: OK]\033[0m {$data['type']} en {$data['table']} - {$data['duration']}ms\n";
    echo "    SQL: {$data['sql']}\n";
});

Event::listen('db.error', function($data) {
    echo "\033[31m[EVENT: ERROR]\033[0m {$data['type']} en {$data['table']} - {$data['error']}\n";
    echo "    SQL: {$data['sql']}\n";
});

// Setup DB (SQLite en memoria)
Conn::setup("sqlite::memory:", "", "", "main");
Conn::get()->exec("CREATE TABLE sensores (id INTEGER PRIMARY KEY, tipo TEXT)");

echo "\nEjecutando acciones...\n";

// Acciones exitosas
Gateway::action('insert', 'sensores', ['tipo' => 'Temperatura Neumático']);
Gateway::action('insert', 'sensores', ['tipo' => 'Presión Aceite']);
Gateway::action('update', 'sensores', ['tipo' => 'Temperatura Llanta'], ['id' => 1]);
Gateway::action('delete', 'sensores', ['id' => 2]);

// Forzar error: método 'select' no es acción
try {
    Gateway::action('select', 'sensores', ['id' => 1]);
} catch (\Exception $e) {
    echo "\033[33m[INFO] Excepción capturada (esperada): " . $e->getMessage() . "\033[0m\n";
}

echo "\nPrueba de eventos finalizada.\n";