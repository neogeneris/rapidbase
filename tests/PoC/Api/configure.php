<?php
/**
 * Configuración de Infraestructura para PoC
 * Ubicación: /tests/PoC/Api/configure.php
 */

namespace Core;
$rootDir = dirname(__DIR__, 3);
require_once $rootDir . '/src/Core/Conn.php';

// 2. Inicialización Automática para la PoC
// Aquí pones tus credenciales de desarrollo
Conn::setup(
    'mysql:host=localhost;dbname=veon_motorsports;charset=utf8mb4', 
    'root', 
    '', 
    'main'
);
