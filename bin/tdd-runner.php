#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * RapidBase TDD Runner
 * 
 * Uso:
 *  php bin/tdd-runner.php <Clase|Archivo> --tests <Directorio> [Opciones]
 * 
 * Ejemplos:
 *  php bin/tdd-runner.php RapidBase\\Core\\X --tests tests/Unit/Core/X2
 *  php bin/tdd-runner.php X.php --tests tests/Unit/Core/X2 --generate
 *  php bin/tdd-runner.php RapidBase\\Core\\X --tests tests/Unit/Core/X2 --first
 *  php bin/tdd-runner.php RapidBase\\Core\\X --tests tests/Unit/Core/X2 --html
 *  php bin/tdd-runner.php RapidBase\\Core\\X --tests tests/Unit/Core/X2 --drivers sqlite,mysql
 */

// Determinar ruta base del proyecto (asumiendo que este script está en bin/)
$baseDir = dirname(__DIR__);
if (!file_exists($baseDir . '/src/RapidBase/Tdd/CoreRunner.php')) {
    // Ajuste si se ejecuta desde otra ubicación relativa
    $baseDir = getcwd();
}

// Autoloader simple para el framework TDD si no existe uno global
if (file_exists($baseDir . '/vendor/autoload.php')) {
    require_once $baseDir . '/vendor/autoload.php';
} else {
    // Fallback manual para clases críticas del framework si no hay vendor
    spl_autoload_register(function ($class) use ($baseDir) {
        $prefix = 'RapidBase\\';
        $baseDir = rtrim($baseDir, '/') . '/src/';
        
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }
        
        $relativeClass = substr($class, $len);
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
        
        if (file_exists($file)) {
            require $file;
        }
    });
}

use RapidBase\Tdd\CoreRunner;

// --- Parseo de argumentos ---
$args = $argv;
array_shift($args); // Eliminar nombre del script

$target = null;
$testsDir = null;
$options = [
    'generate' => false,
    'first' => false,
    'verbose' => false,
    'html' => false,
    'help' => false,
    'drivers' => ['sqlite'], // Default
    'config' => [] // Configuración específica de drivers
];

$i = 0;
while ($i < count($args)) {
    $arg = $args[$i];
    
    if ($arg === '--tests' && isset($args[$i + 1])) {
        $testsDir = $args[$i + 1];
        $i += 2;
        continue;
    }
    
    if ($arg === '--config' && isset($args[$i + 1])) {
        // Ejemplo: --config mysql:host=localhost;dbname=test;user=root;pass=secret
        parse_str(str_replace([';', '='], ['&', '=>'], $args[$i + 1]), $parsedConfig);
        $options['config'] = array_merge($options['config'], $parsedConfig);
        $i += 2;
        continue;
    }
    
    if (str_starts_with($arg, '--')) {
        $key = substr($arg, 2);
        if (isset($options[$key])) {
            $options[$key] = true;
        }
        if ($key === 'drivers' && isset($args[$i + 1])) {
            // Ejemplo: --drivers sqlite,mysql
            $options['drivers'] = explode(',', $args[$i + 1]);
            $i++;
        }
        $i++;
        continue;
    }
    
    if ($target === null) {
        $target = $arg;
    }
    $i++;
}

// Help
if ($options['help'] || $target === null) {
    echo "RapidBase TDD Runner\n\n";
    echo "Usage:\n";
    echo "  php bin/tdd-runner.php <Class|File> --tests <Dir> [Options]\n\n";
    echo "Options:\n";
    echo "  --tests <dir>       Directorio donde están/generar las pruebas (Obligatorio)\n";
    echo "  --generate          Generar esqueleto de pruebas si no existen\n";
    echo "  --first             Detener en la primera falla (TDD Mode)\n";
    echo "  --verbose           Mostrar detalle de cada prueba\n";
    echo "  --html              Generar reporte HTML al finalizar\n";
    echo "  --drivers <d1,d2>   Drivers a usar (ej: sqlite,mysql,pgsql)\n";
    echo "  --config <str>      Configuración adicional (ej: mysql:host=localhost;dbname=test)\n";
    echo "  --help              Mostrar esta ayuda\n";
    echo "\nExamples:\n";
    echo "  php bin/tdd-runner.php RapidBase\\\\Core\\\\X --tests tests/Unit/Core/X\n";
    echo "  php bin/tdd-runner.php X --tests tests/Unit/Core/X --drivers sqlite,mysql --html\n";
    echo "  php bin/tdd-runner.php X --tests tests/Unit/Core/X --first --verbose\n";
    exit(0);
}

if ($testsDir === null) {
    echo "ERROR: Missing required option --tests <directory>\n";
    echo "Use --help for usage.\n";
    exit(1);
}

// --- Resolución del Target (Clase o Archivo) ---
$className = null;
$fileLocation = null;

// 1. Si es un archivo existente
if (file_exists($target)) {
    $fileLocation = realpath($target);
    // Intentar extraer el nombre de la clase principal del archivo
    $content = file_get_contents($fileLocation);
    if (preg_match('/namespace\s+([^;]+);/', $content, $nsMatches)) {
        $namespace = trim($nsMatches[1]);
        if (preg_match('/class\s+(\w+)/', $content, $classMatches)) {
            $className = $namespace . '\\' . $classMatches[1];
        }
    }
    if (!$className) {
        // Fallback: asumir nombre de archivo como clase
        $className = pathinfo($target, PATHINFO_FILENAME);
    }
} else {
    // 2. Asumir que es un FQCN (Fully Qualified Class Name)
    $className = $target;
    // Intentar verificar si existe vía autoloader o búsqueda básica
    if (!class_exists($className, false) && !interface_exists($className, false)) {
        // Podríamos intentar buscar recursivamente si fallara, pero por ahora asumimos que el autoloader lo resolverá al instanciar
        // O lanzar warning si no se encuentra inmediatamente
        // Para diagnóstico temprano:
        $possiblePath = str_replace('\\', '/', $className) . '.php';
        $found = false;
        // Búsqueda simple en src/
        if (file_exists($baseDir . '/src/' . $possiblePath)) {
            $fileLocation = $baseDir . '/src/' . $possiblePath;
            $found = true;
        }
        
        if (!$found) {
             echo "WARNING: Class '$className' not immediately loaded. Will attempt to load during test generation/execution.\n";
        }
    }
}

echo "Target: $className\n";
if ($fileLocation) echo "Location: $fileLocation\n";
echo "Tests Dir: $testsDir\n";
echo "Drivers: " . implode(', ', $options['drivers']) . "\n\n";

// --- Modo Generación ---
if ($options['generate']) {
    if (!$className) {
        echo "ERROR: Cannot generate tests without a resolved class name.\n";
        exit(1);
    }
    
    try {
        $reflection = new ReflectionClass($className);
        $testClassName = $reflection->getShortName() . 'Test';
        $testFilePath = $testsDir . '/' . $testClassName . '.php';
        
        if (file_exists($testFilePath)) {
            echo "WARNING: Test file already exists: $testFilePath\n";
            echo "Skipping generation. Use --first to run existing tests.\n";
            exit(0);
        }

        echo "Generating skeleton for $className in $testFilePath...\n";

        // Obtener métodos públicos
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
        
        $shortName = $reflection->getShortName();
        $namespace = substr($className, 0, strrpos($className, '\\'));
        
        $code = "<?php\n\n";
        $code .= "namespace {$namespace};\n\n";
        
        $code .= "use RapidBase\\Tdd\\CoreRunner;\n";
        $code .= "use RapidBase\\Tdd\\EnvironmentBuilder;\n";
        $code .= "use PDO;\n\n";
        
        $code .= "/**\n * Auto-generated Test Suite for {$shortName}\n */\n";
        $code .= "class {$testClassName}\n{\n";
        $code .= "    public CoreRunner \$runner;\n";
        $code .= "    public string \$currentDriver = 'sqlite';\n";
        $code .= "    private ?PDO \$db = null;\n\n";
        
        $code .= "    /**\n     * Inyecta el contexto del runner\n     */\n";
        $code .= "    public function setRunnerContext(CoreRunner \$runner): void\n";
        $code .= "    {\n";
        $code .= "        \$this->runner = \$runner;\n";
        $code .= "    }\n\n";

        $code .= "    /**\n     * Obtiene conexión a la base de datos\n     */\n";
        $code .= "    protected function db(): PDO\n";
        $code .= "    {\n";
        $code .= "        if (\$this->db === null) {\n";
        $code .= "            \$this->db = \$this->runner->getConnection(\$this->currentDriver);\n";
        $code .= "        }\n";
        $code .= "        return \$this->db;\n";
        $code .= "    }\n\n";
        
        $code .= "    /**\n     * Carga un fixture SQL\n     */\n";
        $code .= "    protected function loadFixture(string \$file): void\n";
        $code .= "    {\n";
        $code .= "        \$this->runner->loadFixture(\$file, \$this->currentDriver);\n";
        $code .= "    }\n\n";

        $code .= "    /**\n     * Inserta un dataset en una tabla\n     */\n";
        $code .= "    protected function dataset(array \$data, string \$table = 'test_data'): void\n";
        $code .= "    {\n";
        $code .= "        \$this->runner->insertDataset(\$data, \$table, \$this->currentDriver);\n";
        $code .= "    }\n\n";

        $code .= "    /**\n     * Factory para entorno multi-db\n     */\n";
        $code .= "    protected function env(string ...\$drivers): EnvironmentBuilder\n";
        $code .= "    {\n";
        $code .= "        // Si no se especifican drivers, usar los activos del runner\n";
        $code .= "        \$selectedDrivers = empty(\$drivers) ? \$this->runner->getActiveDrivers() : \$drivers;\n";
        $code .= "        return new EnvironmentBuilder(\$selectedDrivers, \$this, \$this->runner);\n";
        $code .= "    }\n\n";

        $code .= "    /**\n     * Helper para aserciones simples\n     */\n";
        $code .= "    protected function assertTrue(bool \$condition, string \$msg = ''): void\n";
        $code .= "    {\n";
        $code .= "        \$this->runner->incrementAssertionCount();\n";
        $code .= "        if (!\$condition) throw new \\Exception(\$msg ?: 'Expected true');\n";
        $code .= "    }\n";
        $code .= "    protected function assertFalse(bool \$condition, string \$msg = ''): void\n";
        $code .= "    {\n";
        $code .= "        \$this->runner->incrementAssertionCount();\n";
        $code .= "        if (\$condition) throw new \\Exception(\$msg ?: 'Expected false');\n";
        $code .= "    }\n";
        $code .= "    protected function assertEquals(mixed \$expected, mixed \$actual, string \$msg = ''): void\n";
        $code .= "    {\n";
        $code .= "        \$this->runner->incrementAssertionCount();\n";
        $code .= "        if (\$expected !== \$actual) throw new \\Exception(\$msg ?: \"Expected \" . var_export(\$expected, true) . \" but got \" . var_export(\$actual, true));\n";
        $code .= "    }\n";
        $code .= "    protected function assertCount(int \$count, array|int|string \$data, string \$msg = ''): void\n";
        $code .= "    {\n";
        $code .= "        \$this->runner->incrementAssertionCount();\n";
        $code .= "        \$actual = is_array(\$data) ? count(\$data) : 0;\n";
        $code .= "        if (\$actual !== \$count) throw new \\Exception(\$msg ?: \"Expected count \$count but got \$actual\");\n";
        $code .= "    }\n";
        $code .= "    protected function assertNull(mixed \$value, string \$msg = ''): void\n";
        $code .= "    {\n";
        $code .= "        \$this->runner->incrementAssertionCount();\n";
        $code .= "        if (\$value !== null) throw new \\Exception(\$msg ?: 'Expected null');\n";
        $code .= "    }\n";
        $code .= "    protected function assertNotNull(mixed \$value, string \$msg = ''): void\n";
        $code .= "    {\n";
        $code .= "        \$this->runner->incrementAssertionCount();\n";
        $code .= "        if (\$value === null) throw new \\Exception(\$msg ?: 'Expected not null');\n";
        $code .= "    }\n";
        $code .= "    protected function fail(string \$msg = 'Test failed'): void\n";
        $code .= "    {\n";
        $code .= "        \$this->runner->incrementAssertionCount();\n";
        $code .= "        throw new \\Exception(\$msg);\n";
        $code .= "    }\n\n";

        foreach ($methods as $method) {
            if ($method->isConstructor()) {
                $methodName = '__construct';
            } else {
                $methodName = $method->getName();
            }
            // Sanitizar nombre para método de test
            $testMethodName = 'test' . ucfirst($methodName);
            // Evitar caracteres inválidos si el método original tiene raros (poco probable en PHP)
            $testMethodName = preg_replace('/[^a-zA-Z0-9_]/', '_', $testMethodName);

            $code .= "    /**\n     * Test for {$methodName}\n     */\n";
            $code .= "    public function {$testMethodName}(): void\n";
            $code .= "    {\n";
            $code .= "        \$this->env()->test('{$methodName} behavior', function(?PDO \$db) {\n";
            $code .= "            // TODO: Implement test logic for {$methodName}\n";
            $code .= "            // Example:\n";
            $code .= "            // \$obj = new {$shortName}();\n";
            $code .= "            // \$this->assertTrue(true, '{$methodName} should work');\n";
            $code .= "            \$this->assertTrue(true);\n";
            $code .= "        });\n";
            $code .= "    }\n\n";
        }

        $code .= "}\n";

        if (!is_dir($testsDir)) {
            mkdir($testsDir, 0755, true);
        }
        
        file_put_contents($testFilePath, $code);
        echo "SUCCESS: Skeleton generated at $testFilePath\n";
        echo "Run 'php bin/tdd-runner.php $className --tests $testsDir --first' to start TDD.\n";
    } catch (Throwable $e) {
        echo "ERROR: Could not generate test skeleton: " . $e->getMessage() . "\n";
        exit(1);
    }
    exit(0);
}

// --- Modo Ejecución ---
// Intentar cargar el archivo de test si existe
$shortName = basename(str_replace('\\', '/', $className));
$testFile = $testsDir . '/' . $shortName . 'Test.php';
if (file_exists($testFile)) {
    require_once $testFile;
}

if (!class_exists($className . 'Test')) {
    echo "ERROR: Test class '{$className}Test' not found.\n";
    echo "Try generating it first: php bin/tdd-runner.php $className --tests $testsDir --generate\n";
    exit(1);
}

try {
    $runner = new CoreRunner($className, $testsDir);
    $runner->setDrivers($options['drivers']);
    $runner->stopOnFirst($options['first']);
    $runner->verbose($options['verbose']);

    if ($options['html']) {
        $runner->generateHtmlReport();
    }

    $success = $runner->run();

    exit($success ? 0 : 1);
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . " (Line " . $e->getLine() . ")\n";
    exit(1);
}
