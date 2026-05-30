#!/usr/bin/env php
<?php
/**
 * Endpoint Runner for RapidBase
 * 
 * Ejecuta Endpoints de RapidBase directamente desde la línea de comandos,
 * simulando una petición HTTP sin necesidad de un servidor web.
 * 
 * Uso básico:
 *   php bin/endpoint-runner.php --ep=SystemInfo --action=catalog
 *   php bin/endpoint-runner.php --ep=Users --action=list --db=mydb
 *   php bin/endpoint-runner.php --ep=Orders --action=create --data='{"id":1}'
 *   php bin/endpoint-runner.php --ep=SystemInfo --action=catalog --dir=/path/to/api
 */

// Configuración inicial
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Determinar la raíz del proyecto
$rootDir = dirname(__DIR__);
chdir($rootDir);

// Opciones por defecto
$options = [
    'ep' => null,
    'action' => null,
    'db' => null,        // Nombre de la conexión o ruta al config
    'data' => null,      // JSON string
    'config' => null,    // Ruta al archivo de configuración de RB
    'dir' => null,       // Directorio del API (donde está index.php y Router.php)
    'help' => false,
    'verbose' => false
];

// Parseo simple de argumentos
$args = $argv;
array_shift($args); // Quitar nombre del script

// Parámetros adicionales (se convertirán en parámetros GET/POST)
$extraParams = [];

foreach ($args as $arg) {
    if (strpos($arg, '--') === 0) {
        $parts = explode('=', substr($arg, 2), 2);
        $key = $parts[0];
        // Si hay un valor después del '=', usarlo; si no, es un flag booleano
        $value = isset($parts[1]) ? $parts[1] : true;
        
        if ($key === 'help' || $key === 'h') $options['help'] = true;
        elseif ($key === 'verbose' || $key === 'v') $options['verbose'] = true;
        elseif ($key === 'dir' || $key === 'd') $options['dir'] = $value;
        elseif (array_key_exists($key, $options)) $options[$key] = $value;
        else {
            // Parámetro adicional (ej. --limit=10 se convierte en $_GET['limit']='10')
            $extraParams[$key] = $value;
        }
    }
}

// Mostrar ayuda
if ($options['help']) {
    echo "\n=== RapidBase Endpoint Runner ===\n\n";
    echo "Ejecuta endpoints directamente sin servidor HTTP.\n\n";
    echo "Uso:\n";
    echo "  php bin/endpoint-runner.php --ep=<Endpoint> --action=<Accion> [opciones]\n\n";
    echo "Obligatorio:\n";
    echo "  --ep        Nombre del Endpoint (ej. SystemInfo, Users)\n";
    echo "  --action    Acción a ejecutar (ej. catalog, list, create)\n\n";
    echo "Opciones:\n";
    echo "  --dir       Directorio del API (donde están index.php y Router.php).\n";
    echo "              Si se omite, busca en examples/querybrowser/api/v1/\n";
    echo "  --db        Nombre de la conexión (ej. default, mysql) o ruta al config DB.\n";
    echo "              Si se omite, usa la configuración por defecto de RapidBase.\n";
    echo "  --data      String JSON con los datos de entrada (body de la petición).\n";
    echo "  --config    Ruta al archivo de configuración de RapidBase (opcional).\n";
    echo "  --verbose   Muestra información detallada de ejecución.\n";
    echo "  --<param>   Cualquier otro parámetro se pasa como argumento al endpoint.\n";
    echo "              Ej: --limit=10 --offset=0\n\n";
    echo "Ejemplos:\n";
    echo "  # Ejecutar SystemInfo con la DB por defecto\n";
    echo "  php bin/endpoint-runner.php --ep=SystemInfo --action=catalog\n\n";
    echo "  # Ejecutar Listado con parámetros adicionales\n";
    echo "  php bin/endpoint-runner.php --ep=Grid --action=list --table=users --limit=10\n\n";
    echo "  # Usar un directorio específico del API\n";
    echo "  php bin/endpoint-runner.php --ep=SystemInfo --action=catalog --dir=/path/to/api\n\n";
    echo "  # Crear registro enviando JSON desde clipboard\n";
    echo "  php bin/endpoint-runner.php --ep=Orders --action=create --data='{\"customer\":\"John\"}'\n\n";
    exit(0);
}

// Validaciones básicas
if (!$options['ep'] || !$options['action']) {
    echo "Error: Debes especificar --ep y --action.\n";
    echo "Usa --help para más información.\n";
    exit(1);
}

// Determinar directorio del API
$apiDir = $options['dir'] ?? __DIR__ . '/../examples/querybrowser/api/v1';

if (!is_dir($apiDir)) {
    echo "Error: Directorio del API no encontrado: $apiDir\n";
    echo "Usa --dir para especificar la ruta correcta.\n";
    exit(1);
}

if ($options['verbose']) {
    echo "[INFO] Usando directorio API: $apiDir\n";
}

// Cargar RapidBase desde el API o tests/lib
$bundlePath = $apiDir . '/lib/RapidBase.php';
$testBundlePath = __DIR__ . '/../tests/lib/RapidBase.php';
$srcPath = __DIR__ . '/../src/RapidBase.php';

if (file_exists($bundlePath)) {
    require_once $bundlePath;
    if ($options['verbose']) echo "[INFO] Usando Bundle: $bundlePath\n";
} elseif (file_exists($testBundlePath)) {
    require_once $testBundlePath;
    if ($options['verbose']) echo "[INFO] Usando Bundle: $testBundlePath\n";
} elseif (file_exists($srcPath)) {
    // Si usamos el src, necesitamos el autoloader inteligente
    if (!class_exists('RapidBase\RapidBase')) {
        echo "[WARNING] Bundle no encontrado. Intentando cargar desde src/...\n";
        echo "Error: No se encontró RapidBase.php. Ejecuta 'php bin/build.php' primero.\n";
        exit(1);
    }
} else {
    echo "Error: No se encontró la librería RapidBase. Ejecuta 'php bin/build.php'.\n";
    exit(1);
}

try {
    // 1. Configurar entorno (simular variables superglobales)
    $_GET['ep'] = $options['ep'];
    $_GET['action'] = $options['action'];
    
    // Agregar parámetros adicionales
    foreach ($extraParams as $key => $value) {
        $_GET[$key] = $value;
    }
    
    // Preparar datos de entrada
    $inputData = [];
    if ($options['data']) {
        $decoded = json_decode($options['data'], true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $inputData = $decoded;
            $_POST = $inputData;
            $_REQUEST = array_merge($_GET, $_POST);
            if (!isset($GLOBALS['HTTP_RAW_POST_DATA'])) {
                $GLOBALS['HTTP_RAW_POST_DATA'] = $options['data'];
            }
        } else {
            echo "[WARNING] El argumento --data no es un JSON válido.\n";
        }
    } else {
        $_REQUEST = $_GET;
    }

    // 2. Configurar Base de Datos (Si se especificó)
    if ($options['db']) {
        if ($options['verbose']) echo "[INFO] Configurando conexión DB: {$options['db']}\n";
        
        $configLoaded = false;
        $possibleConfigs = [
            $apiDir . "/../config/db_{$options['db']}.php",
            $apiDir . "/../config/database.php",
            __DIR__ . "/../tests/config/db_{$options['db']}.php",
            __DIR__ . "/../tests/config/database.php",
            __DIR__ . "/../config/db_{$options['db']}.php",
            __DIR__ . "/../config/database.php",
        ];

        // Si el argumento parece una ruta (contiene / o .php)
        if (strpos($options['db'], '/') !== false || strpos($options['db'], '.php') !== false) {
            if (file_exists($options['db'])) {
                $dbConfig = require $options['db'];
                if (function_exists('RapidBase\\setDbConfig')) {
                    RapidBase\setDbConfig($dbConfig);
                }
                $configLoaded = true;
                if ($options['verbose']) echo "[INFO] Config cargada desde: {$options['db']}\n";
            } else {
                throw new Exception("Archivo de configuración DB no encontrado: {$options['db']}");
            }
        } else {
            // Es un nombre lógico, buscar en archivos de config
            foreach ($possibleConfigs as $cfgFile) {
                if (file_exists($cfgFile)) {
                    if ($options['verbose']) echo "[DEBUG] Probando config: $cfgFile\n";
                    $dbConfig = require $cfgFile;
                    
                    // Si el config devuelve un array de conexiones, buscar la clave
                    if (is_array($dbConfig)) {
                        if (isset($dbConfig[$options['db']])) {
                            if (function_exists('RapidBase\setDbConfig')) {
                                RapidBase\setDbConfig($dbConfig[$options['db']]);
                            }
                            $configLoaded = true;
                            if ($options['verbose']) echo "[INFO] Conexión '{$options['db']}' cargada desde $cfgFile\n";
                            break;
                        } elseif (isset($dbConfig['default'])) {
                            if (function_exists('RapidBase\setDbConfig')) {
                                RapidBase\setDbConfig($dbConfig['default']);
                            }
                            $configLoaded = true;
                            if ($options['verbose']) echo "[INFO] Usando conexión 'default' desde $cfgFile\n";
                            break;
                        }
                    } else {
                        // El archivo retorna directamente la config de una sola DB
                        if (function_exists('RapidBase\setDbConfig')) {
                            RapidBase\setDbConfig($dbConfig);
                        }
                        $configLoaded = true;
                        if ($options['verbose']) echo "[INFO] Config cargada desde $cfgFile\n";
                        break;
                    }
                }
            }
        }

        if (!$configLoaded) {
            echo "[WARNING] No se encontró configuración explícita para '{$options['db']}'. Usando defaults.\n";
        }
    }

    if ($options['verbose']) {
        echo "[DEBUG] Ejecutando Ep: {$options['ep']}, Action: {$options['action']}\n";
        if (!empty($inputData)) {
            echo "[DEBUG] Data: " . json_encode($inputData) . "\n";
        }
        if (!empty($extraParams)) {
            echo "[DEBUG] Extra Params: " . json_encode($extraParams) . "\n";
        }
    }

    // 3. Cargar Router y ejecutar
    $routerPath = $apiDir . '/Router.php';
    if (!file_exists($routerPath)) {
        throw new Exception("Router.php no encontrado en: $routerPath");
    }
    
    require_once $routerPath;
    
    // Iniciar sesión si es necesario
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // 4. Ejecutar
    $startTime = microtime(true);
    
    ob_start();
    $router = new RapidBase\Api\v1\Router();
    $router->handle();
    $output = ob_get_clean();
    
    $endTime = microtime(true);
    $duration = round(($endTime - $startTime) * 1000, 2);

    // 5. Mostrar Resultados
    echo "\n--- Resultado ({$duration}ms) ---\n";
    echo $output;
    echo "--- Fin ---\n\n";

} catch (Exception $e) {
    echo "\n[ERROR] " . $e->getMessage() . "\n";
    if ($options['verbose']) {
        echo $e->getTraceAsString() . "\n";
    }
    exit(1);
}
