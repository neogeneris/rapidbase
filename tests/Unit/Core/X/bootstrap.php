<?php

// Cargar el autoloader de Composer (si existe) o cargar RapidBase manualmente
$autoloadPath = __DIR__ . '/../../../vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
} else {
    // Cargar RapidBase.php desde el directorio de ejemplos
    require_once __DIR__ . '/../../../../examples/querybrowser/RapidBase.php';
}

// Inicializar la base de datos interna de conexiones (si se necesita)
// En muchos tests no se usa, pero aquí la dejamos opcional.
if (defined('CONNECTIONS_DB') === false) {
    define('CONNECTIONS_DB', __DIR__ . '/../../../../examples/querybrowser/data/connections.sqlite');
}