<?php
/**
 * Bootstrap para pruebas de Core
 * 
 * Este archivo permite cargar RapidBase de dos formas:
 * 1. Usando el bundle RapidBase.php desde tests/lib/ (por defecto)
 * 2. Usando el autoloader inteligente del proyecto (si se define USE_AUTOLOADER)
 */

$baseDir = dirname(__DIR__, 2);
$libPath = $baseDir . '/lib/RapidBase.php';
$autoloadPath = $baseDir . '/../../vendor/autoload.php';
$srcAutoloader = $baseDir . '/../../src/RapidBase/Autoloader/Autoloader.php';

// Determinar modo de carga
if (getenv('USE_AUTOLOADER') === 'true' && file_exists($srcAutoloader)) {
    // Modo autoloader: usar código fuente directamente
    require_once $srcAutoloader;
    \RapidBase\Autoloader\Autoloader::getInstance($baseDir . '/../../src')
        ->enableDebug(false)
        ->enableCache(true)
        ->register();
} elseif (file_exists($libPath)) {
    // Modo bundle: usar RapidBase.php empaquetado
    require_once $libPath;
} elseif (file_exists($autoloadPath)) {
    // Fallback: autoloader de Composer
    require_once $autoloadPath;
} else {
    throw new RuntimeException('No se pudo cargar RapidBase. Verifique que exista tests/lib/RapidBase.php o vendor/autoload.php');
}

// Configuración común para pruebas
if (!defined('RAPIDBASE_TEST_MODE')) {
    define('RAPIDBASE_TEST_MODE', true);
}

// Ruta para caché temporal de pruebas
if (!defined('RAPIDBASE_TEST_CACHE_PATH')) {
    define('RAPIDBASE_TEST_CACHE_PATH', $baseDir . '/tmp/cache');
    if (!is_dir(RAPIDBASE_TEST_CACHE_PATH)) {
        mkdir(RAPIDBASE_TEST_CACHE_PATH, 0777, true);
    }
}

// Inicializar caché si es necesario
if (class_exists('\RapidBase\Core\Cache\CacheService')) {
    \RapidBase\Core\Cache\CacheService::init(RAPIDBASE_TEST_CACHE_PATH);
}
