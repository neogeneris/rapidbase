<?php

declare(strict_types=1);

namespace RapidBase\Tdd;

use PDO;
use ReflectionClass;
use ReflectionMethod;
use ReflectionFunction;
use Throwable;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Runner Unificado de RapidBase TDD
 * 
 * Características:
 * - Diagnóstico de clases sin necesidad de tests (sintaxis, namespace, herencia, interfaces)
 * - Integración con Autoloader para carga de dependencias
 * - Base de datos SQLite para historial de ejecución y estadísticas
 * - Diagnóstico especializado para Models y Endpoints
 * - Generación de skeletons de tests (--generate)
 * - Soporte para rutas de tests (--tests)
 * - Ejecución de tests unitarios con múltiples drivers
 * 
 * Uso:
 *   php bin/tdd-runner.php <Class|File> [--tests <Dir>] [--generate] [Options]
 */
class Runner
{
    private ?PDO $db = null;
    private string $dbPath;
    private string $basePath;
    private array $results = [];
    private array $runtimeResults = [];
    private bool $stopOnFirstFail = false;
    private bool $verbose = false;
    private ?string $htmlReportPath = null;
    private array $configuredDrivers = ['sqlite'];
    private ?\RapidBase\Autoloader\Autoloader $autoloader = null;
    
    // Paths especializados
    private string $endpointsPath;
    private string $modelsPath;
    private string $testsPath;

    public function __construct(
        string $dbPath = 'rapidbase_tdd.sqlite',
        string $basePath = null
    ) {
        // Deducir basePath si no se proporciona
        if ($basePath === null) {
            $basePath = getcwd();
            while ($basePath !== dirname($basePath) && !file_exists($basePath . '/bin/RapidBase.php')) {
                $basePath = dirname($basePath);
            }
        }
        
        $this->basePath = rtrim($basePath, '/\\');
        $this->dbPath = $dbPath;
        $this->endpointsPath = $this->basePath . '/src/RapidBase/Api';
        $this->modelsPath = $this->basePath . '/src/RapidBase/ORM/ActiveRecord';
        $this->testsPath = $this->basePath . '/tests';
        
        // Inicializar Autoloader
        $this->initAutoloader();
        
        // Cargar bundle de RapidBase si existe
        $this->loadRapidBaseBundle();
        
        // Inicializar conexión por defecto para ORM
        $this->initDefaultConnection();
        
        // Inicializar base de datos
        $this->initDb();
    }
    
    /**
     * Inicializa el Autoloader de RapidBase
     */
    private function initAutoloader(): void
    {
        $autoloaderPath = $this->basePath . '/src/RapidBase/Autoloader/Autoloader.php';
        if (file_exists($autoloaderPath)) {
            require_once $autoloaderPath;
            $this->autoloader = \RapidBase\Autoloader\Autoloader::getInstance($this->basePath . '/src')
                ->enableDebug(false)
                ->register();
        }
    }
    
    /**
     * Carga el bundle principal de RapidBase
     */
    private function loadRapidBaseBundle(): void
    {
        // Verificar si ya está cargado (el bundle define muchas clases incluyendo Autoloader)
        // Si el Autoloader ya existe, significa que cargamos desde src y no debemos cargar el bundle
        if (class_exists('RapidBase\Autoloader\Autoloader', false)) {
            return;
        }
        
        $bundlePaths = [
            $this->basePath . '/bin/RapidBase.php',
            $this->basePath . '/lib/RapidBase.php',
        ];
        
        foreach ($bundlePaths as $path) {
            if (file_exists($path)) {
                require_once $path;
                break;
            }
        }
    }
    
    /**
     * Inicializa conexión SQLite por defecto para operaciones ORM
     */
    private function initDefaultConnection(): void
    {
        $pocDbPath = $this->basePath . '/rapidbase_poc.sqlite';
        
        try {
            if (class_exists('\RapidBase\Core\Conn')) {
                \RapidBase\Core\Conn::setup('sqlite:' . $pocDbPath, '', '', 'default');
            }
        } catch (Throwable $e) {
            // Si ya existe la conexión, continuar
        }
    }
    
    /**
     * Inicializa la base de datos de historial de tests
     */
    private function initDb(): void
    {
        try {
            $this->db = new PDO('sqlite:' . $this->dbPath);
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Tabla de resultados de tests
            $this->db->exec("CREATE TABLE IF NOT EXISTS test_results (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                category TEXT, class TEXT, method TEXT, driver TEXT,
                status TEXT, error TEXT, duration REAL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
            
            // Tabla de mapeo clase-test
            $this->db->exec("CREATE TABLE IF NOT EXISTS class_test_mapping (
                class_name TEXT PRIMARY KEY,
                test_directory TEXT,
                last_updated DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
            
            // Tabla de historial general
            $this->db->exec("CREATE TABLE IF NOT EXISTS test_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                test_identifier TEXT,
                class_name TEXT,
                method_name TEXT,
                status TEXT,
                error_message TEXT,
                execution_time REAL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
            
            $this->db->exec("CREATE INDEX IF NOT EXISTS idx_test_identifier ON test_history(test_identifier)");
        } catch (Throwable $e) {
            throw new \RuntimeException("Error initializing TDD database: " . $e->getMessage());
        }
    }
    
    /**
     * Registra un mapeo entre clase y directorio de tests
     */
    public function registerTestClass(string $className, string $testDir): void
    {
        if (!$this->db) return;
        $stmt = $this->db->prepare("INSERT OR REPLACE INTO class_test_mapping (class_name, test_directory, last_updated) VALUES (:class, :dir, DATETIME('now'))");
        $stmt->execute([':class' => $className, ':dir' => $testDir]);
    }
    
    /**
     * Obtiene el directorio de tests registrado para una clase
     */
    public function getTestDirectoryForClass(string $className): ?string
    {
        if (!$this->db) return null;
        $stmt = $this->db->prepare("SELECT test_directory FROM class_test_mapping WHERE class_name = :class");
        $stmt->execute([':class' => $className]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        return $res ? $res['test_directory'] : null;
    }
    
    /**
     * Configura los drivers a utilizar
     */
    public function setDrivers(array $drivers): void 
    { 
        $this->configuredDrivers = $drivers; 
    }
    
    public function getDrivers(): array 
    { 
        return $this->configuredDrivers; 
    }
    
    /**
     * Configura si debe detenerse en el primer fallo
     */
    public function stopOnFirst(bool $stop): void 
    { 
        $this->stopOnFirstFail = $stop; 
    }
    
    public function shouldStopOnFirstFail(): bool 
    { 
        return $this->stopOnFirstFail; 
    }
    
    /**
     * Configura modo verbose
     */
    public function verbose(bool $v = true): void 
    { 
        $this->verbose = $v; 
    }
    
    public function isVerbose(): bool 
    { 
        return $this->verbose; 
    }
    
    /**
     * Configura generación de reporte HTML
     */
    public function generateHtmlReport(?string $path = null): void 
    { 
        $this->htmlReportPath = $path ?? $this->basePath . '/report-tdd.html'; 
    }
    
    /**
     * Registra un resultado de test en runtime y DB
     */
    public function recordRuntimeResult(array $result): void
    {
        $this->runtimeResults[] = $result;
        try {
            $stmt = $this->db->prepare("INSERT INTO test_results (category, class, method, driver, status, error, duration) VALUES (:cat, :cls, :met, :drv, :stat, :err, :dur)");
            $stmt->execute([
                ':cat' => $result['category'] ?? 'Unit',
                ':cls' => $result['class'],
                ':met' => $result['method'],
                ':drv' => $result['driver'],
                ':stat' => $result['status'],
                ':err' => $result['error'] ?? '',
                ':dur' => $result['duration'] ?? 0
            ]);
        } catch (Throwable $t) {}
    }
    
    /**
     * Registra un resultado en el historial general
     */
    private function logToHistory(string $testId, string $className, string $methodName, string $status, ?string $error, float $time): void
    {
        if (!$this->db) return;
        $stmt = $this->db->prepare("INSERT INTO test_history (test_identifier, class_name, method_name, status, error_message, execution_time) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$testId, $className, $methodName, $status, $error, $time]);
    }
    
    // ==================== DIAGNÓSTICO DE CLASES ====================
    
    /**
     * Diagnóstico completo de una clase sin necesidad de tests
     */
    public function diagnose(string $className, ?string $classPath = null): array
    {
        $diagnosis = [
            'class' => $className,
            'file_exists' => false,
            'syntax_ok' => false,
            'loadable' => false,
            'namespace_ok' => false,
            'expected_namespace' => '',
            'actual_namespace' => '',
            'parent_class' => null,
            'parent_exists' => null,
            'interfaces' => [],
            'interface_issues' => [],
            'methods' => [],
            'is_model' => false,
            'is_endpoint' => false,
            'has_tests' => false,
            'suggestions' => []
        ];

        // 1. Verificar si existe el archivo
        if ($classPath && file_exists($classPath)) {
            $diagnosis['file_exists'] = true;
            
            // 2. Verificar sintaxis con php -l
            $output = [];
            $returnCode = 0;
            exec("php -l " . escapeshellarg($classPath) . " 2>&1", $output, $returnCode);
            $diagnosis['syntax_ok'] = ($returnCode === 0);
            
            if (!$diagnosis['syntax_ok']) {
                $diagnosis['suggestions'][] = "Error de sintaxis: " . implode("\n", $output);
            }
        } else {
            // Intentar encontrar la clase vía autoloader
            if ($this->autoloader) {
                $this->autoloader->loadClass($className);
            }
            if (class_exists($className, false)) {
                $ref = new ReflectionClass($className);
                $classPath = $ref->getFileName();
                $diagnosis['file_exists'] = true;
                $diagnosis['syntax_ok'] = true;
            } else {
                $diagnosis['suggestions'][] = "Archivo de clase no encontrado: $className";
                return $diagnosis;
            }
        }

        // 3. Cargar y analizar la clase
        if ($diagnosis['syntax_ok'] && !$diagnosis['loadable']) {
            // Construir FQN esperado basado en el path
            $relativePath = str_replace([$this->basePath . '/', '.php'], '', $classPath);
            $parts = explode('/', $relativePath);
            $expectedNamespace = implode('\\', array_slice($parts, 0, -1));
            $expectedClassName = end($parts);
            
            $diagnosis['expected_namespace'] = $expectedNamespace;
            
            // Cargar la clase
            if (!class_exists($className, false)) {
                require_once $classPath;
            }
            
            if (class_exists($className, false)) {
                $diagnosis['loadable'] = true;
                $reflection = new ReflectionClass($className);
                $diagnosis['actual_namespace'] = $reflection->getNamespaceName();
                
                // Verificar correspondencia namespace/path
                if ($reflection->getNamespaceName() !== $expectedNamespace) {
                    $diagnosis['suggestions'][] = "El namespace '{$reflection->getNamespaceName()}' no coincide con la estructura esperada '$expectedNamespace'";
                } else {
                    $diagnosis['namespace_ok'] = true;
                }
                
                // 4. Obtener información de herencia
                $parentClass = $reflection->getParentClass();
                if ($parentClass) {
                    $diagnosis['parent_class'] = $parentClass->getName();
                    $diagnosis['parent_exists'] = class_exists($parentClass->getName());
                }
                
                // 5. Obtener métodos públicos
                $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
                foreach ($methods as $method) {
                    if ($method->class === $className && !str_starts_with($method->name, '__')) {
                        $diagnosis['methods'][] = $method->getName();
                    }
                }
                
                // 6. Verificar interfaces implementadas
                $interfaces = $reflection->getInterfaceNames();
                foreach ($interfaces as $interface) {
                    if (!interface_exists($interface)) {
                        $diagnosis['interface_issues'][] = "Interfaz no encontrada: $interface";
                    } else {
                        $diagnosis['interfaces'][] = $interface;
                    }
                }
                
                // 7. Detectar tipo especial
                if ($parentClass && $parentClass->getName() === 'RapidBase\ORM\ActiveRecord\Model') {
                    $diagnosis['is_model'] = true;
                }
                if ($parentClass && $parentClass->getName() === 'RapidBase\Api\BaseEndpoint') {
                    $diagnosis['is_endpoint'] = true;
                }
                
                // 8. Verificar si existen tests
                $testClassName = $className . 'Test';
                $diagnosis['has_tests'] = class_exists($testClassName, false);
                if (!$diagnosis['has_tests']) {
                    $testDir = $this->getTestDirectoryForClass($className);
                    if ($testDir) {
                        $testFile = $testDir . '/' . $expectedClassName . 'Test.php';
                        $diagnosis['has_tests'] = file_exists($testFile);
                    }
                }
            }
        }

        return $diagnosis;
    }
    
    /**
     * Diagnóstico especializado para Models
     */
    public function diagnoseModel(string $className): array
    {
        $diagnosis = $this->diagnose($className);
        
        if (!$diagnosis['loadable']) {
            return $diagnosis;
        }
        
        try {
            $reflection = new ReflectionClass($className);
            
            // Check that it inherits from Model
            if (!$reflection->isSubclassOf('RapidBase\ORM\ActiveRecord\Model')) {
                $diagnosis['suggestions'][] = "Class does not inherit from RapidBase\\ORM\ActiveRecord\Model";
                return $diagnosis;
            }
            
            // Obtener tabla configurada
            if ($reflection->hasProperty('table')) {
                $tableProp = $reflection->getProperty('table');
                $tableProp->setAccessible(true);
                $diagnosis['table'] = $tableProp->getValue();
            }
            
            // Obtener primary key
            if ($reflection->hasProperty('primaryKey')) {
                $pkProp = $reflection->getProperty('primaryKey');
                $pkProp->setAccessible(true);
                $diagnosis['primary_key'] = $pkProp->getValue();
            }
            
            // Verificar existencia de tabla en DB
            if (isset($diagnosis['table']) && class_exists('\RapidBase\Core\DB')) {
                try {
                    $exists = \RapidBase\Core\DB::tableExists($diagnosis['table']);
                    $diagnosis['table_exists'] = $exists;
                    if (!$exists) {
                        $diagnosis['suggestions'][] = "La tabla '{$diagnosis['table']}' no existe en la base de datos";
                    }
                } catch (Throwable $e) {
                    $diagnosis['table_exists'] = null;
                }
            }
            
        } catch (Throwable $e) {
            $diagnosis['suggestions'][] = "Error analizando Model: " . $e->getMessage();
        }
        
        return $diagnosis;
    }
    
    /**
     * Diagnóstico especializado para Endpoints
     */
    public function diagnoseEndpoint(string $className): array
    {
        $diagnosis = $this->diagnose($className);
        
        if (!$diagnosis['loadable']) {
            return $diagnosis;
        }
        
        try {
            $reflection = new ReflectionClass($className);
            
            // Check that it inherits from BaseEndpoint
            if (!$reflection->isSubclassOf('RapidBase\Api\BaseEndpoint')) {
                $diagnosis['suggestions'][] = "Class does not inherit from RapidBase\\Api\\BaseEndpoint";
                return $diagnosis;
            }
            
            // Obtener métodos disponibles (excluyendo los heredados)
            $endpointMethods = [];
            $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
            foreach ($methods as $method) {
                if ($method->class === $className && !str_starts_with($method->name, '__')) {
                    $endpointMethods[] = [
                        'name' => $method->getName(),
                        'params' => array_map(fn($p) => [
                            'name' => $p->getName(),
                            'type' => $p->hasType() ? $p->getType()->getName() : 'mixed',
                            'optional' => $p->isOptional()
                        ], $method->getParameters())
                    ];
                }
            }
            $diagnosis['endpoint_methods'] = $endpointMethods;
            
            // Verificar si tiene método setContext
            $diagnosis['has_context_support'] = $reflection->hasMethod('setContext');
            
        } catch (Throwable $e) {
            $diagnosis['suggestions'][] = "Error analizando Endpoint: " . $e->getMessage();
        }
        
        return $diagnosis;
    }
    
    /**
     * Imprime diagnóstico en consola
     */
    public function printDiagnosis(array $diagnosis): void
    {
        echo "\n";
        $this->hr(70, '=');
        echo "           RAPIDBASE TDD - DIAGNÓSTICO DE CLASE             \n";
        $this->hr(70, '=');
        echo "  Class: {$diagnosis['class']}\n";
        $this->hr(70);
        
        echo "  File: " . ($diagnosis['file_exists'] ? "✓ Exists" : "✗ Not found") . "\n";
        echo "  Syntax: " . ($diagnosis['syntax_ok'] ? "✓ OK" : "✗ Error") . "\n";
        echo "  Loadable: " . ($diagnosis['loadable'] ? "✓ Yes" : "✗ No") . "\n";
        echo "  Namespace: " . ($diagnosis['namespace_ok'] ? "✓ Matches" : "✗ Mismatch") . "\n";
        
        if ($diagnosis['expected_namespace']) {
            echo "    Expected: {$diagnosis['expected_namespace']}\n";
        }
        if ($diagnosis['actual_namespace']) {
            echo "    Actual: {$diagnosis['actual_namespace']}\n";
        }
        
        if ($diagnosis['parent_class']) {
            echo "  Hereda de: {$diagnosis['parent_class']} " . 
                 ($diagnosis['parent_exists'] ? "✓" : "✗ (no existe)") . "\n";
        }
        
        if ($diagnosis['is_model']) {
            echo "  Tipo: MODEL (ActiveRecord)\n";
            if (isset($diagnosis['table'])) {
                echo "  Tabla: {$diagnosis['table']}\n";
            }
            if (isset($diagnosis['table_exists'])) {
                echo "  Tabla en DB: " . ($diagnosis['table_exists'] ? "✓ Existe" : "✗ No existe") . "\n";
            }
        }
        
        if ($diagnosis['is_endpoint']) {
            echo "  Tipo: ENDPOINT (API)\n";
            if (isset($diagnosis['has_context_support'])) {
                echo "  Soporte Contexto: " . ($diagnosis['has_context_support'] ? "✓ Sí" : "✗ No") . "\n";
            }
        }
        
        if (!empty($diagnosis['interfaces'])) {
            echo "  Interfaces: " . implode(', ', $diagnosis['interfaces']) . "\n";
        }
        
        if (!empty($diagnosis['interface_issues'])) {
            echo "  Interface issues:\n";
            foreach ($diagnosis['interface_issues'] as $issue) {
                echo "    - $issue\n";
            }
        }
        
        if (!empty($diagnosis['methods'])) {
            echo "  Public methods (" . count($diagnosis['methods']) . "):\n";
            foreach ($diagnosis['methods'] as $method) {
                echo "    - {$method}()\n";
            }
        }
        
        echo "  Existing tests: " . ($diagnosis['has_tests'] ? "✓ Yes" : "✗ No") . "\n";
        
        if (!empty($diagnosis['suggestions'])) {
            echo "\n  Suggestions:\n";
            foreach ($diagnosis['suggestions'] as $suggestion) {
                echo "    ⚠ $suggestion\n";
            }
        }
        
        echo "\n";
        $this->hr(70);
        if (!$diagnosis['has_tests']) {
            echo "  Para generar tests: php bin/tdd-runner.php {$diagnosis['class']} --tests /ruta/a/tests --generate\n";
        }
        $this->hr(70, '=');
        echo "\n";
    }
    
    // ==================== GENERACIÓN DE SKELETONS ====================
    
    /**
     * Genera esqueleto de test para una clase
     */
    public function generateTestSkeleton(string $className, string $testOutputDir): string
    {
        // Asegurar que el directorio existe
        if (!is_dir($testOutputDir)) {
            mkdir($testOutputDir, 0755, true);
        }
        
        // Diagnosticar primero
        $diagnosis = $this->diagnose($className);
        
        if (!$diagnosis['loadable']) {
            throw new \Exception("Cannot generate test: class could not be loaded");
        }
        
        $reflection = new ReflectionClass($className);
        $shortName = $reflection->getShortName();
        $methods = $diagnosis['methods'];
        
        // Construir contenido del archivo de test
        $testClassName = $shortName . 'Test';
        $testNamespace = $reflection->getNamespaceName() . '\\Tests';
        
        $content = "<?php\n\n";
        $content .= "declare(strict_types=1);\n\n";
        $content .= "namespace {$testNamespace};\n\n";
        $content .= "use {$className};\n";
        $content .= "use RapidBase\\Tdd\\TestCase;\n\n";
        $content .= "/**\n";
        $content .= " * Tests auto-generados para {$shortName}\n";
        $content .= " * Cada método público tiene su correspondiente test\n";
        $content .= " * @generated by RapidBase TDD Runner\n";
        $content .= " */\n";
        $content .= "class {$testClassName} extends TestCase\n";
        $content .= "{\n";
        
        if (empty($methods)) {
            $content .= "    // No hay métodos públicos para testear\n";
            $content .= "    public function testClassExists(): void\n";
            $content .= "    {\n";
            $content .= "        \$this->assertTrue(class_exists('{$className}'));\n";
            $content .= "    }\n";
        } else {
            foreach ($methods as $method) {
                $testMethodName = 'test' . ucfirst(preg_replace('/[^a-zA-Z0-9_]/', '_', $method));
                $content .= "\n";
                $content .= "    /**\n";
                $content .= "     * Test para {$method}()\n";
                $content .= "     */\n";
                $content .= "    public function {$testMethodName}(): void\n";
                $content .= "    {\n";
                $content .= "        \$instance = new {$shortName}();\n";
                $content .= "        \n";
                $content .= "        \$this->env()->test('verify {$method} behavior', function(\$test) {\n";
                $content .= "            // TODO: Configurar parámetros y aserciones\n";
                $content .= "            \$result = \$instance->{$method}();\n";
                $content .= "            \$test->assertNotNull(\$result);\n";
                $content .= "        });\n";
                $content .= "    }\n";
            }
        }
        
        $content .= "}\n";
        
        // Escribir archivo
        $testFilePath = $testOutputDir . '/' . $testClassName . '.php';
        file_put_contents($testFilePath, $content);
        
        // Registrar mapeo
        $this->registerTestClass($className, $testOutputDir);
        
        return $testFilePath;
    }
    
    // ==================== EJECUCIÓN DE TESTS ====================
    
    /**
     * Ejecuta tests de una clase específica
     */
    public function runTargetClass(string $targetClass): bool
    {
        $testClass = $targetClass . 'Test';
        
        // Si el target ya es un test, usarlo directamente
        if (!class_exists($testClass) && class_exists($targetClass) && str_ends_with($targetClass, 'Test')) {
            $testClass = $targetClass;
        }
        
        if (!class_exists($testClass)) {
            echo "ERROR: Test class '$testClass' not found.\n";
            return false;
        }
        
        $reflection = new ReflectionClass($testClass);
        $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);
        
        try {
            $instance = $reflection->newInstance();
        } catch (Throwable $e) {
            echo "ERROR: Cannot instantiate test class: " . $e->getMessage() . "\n";
            return false;
        }
        
        if (method_exists($instance, 'setRunnerContext')) {
            $instance->setRunnerContext($this);
        }
        
        echo "\n" . str_repeat('=', 70) . "\n              RAPIDBASE TDD TEST REPORT                    \n" . str_repeat('=', 70) . "\n";
        
        foreach ($methods as $method) {
            if (str_starts_with($method->getName(), 'test')) {
                try {
                    if (method_exists($instance, 'setUp')) $instance->setUp();
                    $method->invoke($instance);
                    if (method_exists($instance, 'tearDown')) $instance->tearDown();
                } catch (StopSuiteExecutionException $e) {
                    goto end_report;
                } catch (Throwable $e) {
                    $this->recordRuntimeResult([
                        'category' => 'Unit', 
                        'class' => $testClass, 
                        'method' => $method->getName(),
                        'driver' => $this->configuredDrivers[0] ?? 'none', 
                        'status' => 'FAIL',
                        'duration' => 0, 
                        'error' => $e->getMessage()
                    ]);
                    $this->printImmediateFailure($method->getName() . ' (Catastrophic)', $e);
                    if ($this->stopOnFirstFail) goto end_report;
                }
            }
        }
        
        end_report:
        return $this->printFinalConsoleSummary();
    }
    
    /**
     * Imprime fallo inmediato
     */
    public function printImmediateFailure(string $displayName, Throwable $e): void
    {
        echo "\n" . str_repeat('-', 70) . "\n  FAILURE DETECTED\n" . str_repeat('-', 70) . "\n";
        echo "  Test: {$displayName}\n  Error: {$e->getMessage()}\n  File: {$e->getFile()} (Line {$e->getLine()})\n";
        $this->showCodeSnippet($e->getFile(), $e->getLine(), 'ERROR LOCATION');
        echo str_repeat('=', 70) . "\n\n";
    }
    
    /**
     * Muestra snippet de código
     */
    private function showCodeSnippet(string $file, int $lineNumber, string $label): void
    {
        if (!file_exists($file)) return;
        $lines = file($file);
        $start = max(0, $lineNumber - 5);
        $end = min(count($lines), $lineNumber + 4);
        echo "\n  " . str_repeat('-', 60) . "\n  {$label}:\n  " . str_repeat('-', 60) . "\n";
        for ($i = $start; $i < $end; $i++) {
            $num = $i + 1;
            $content = rtrim($lines[$i]);
            $marker = ($num == $lineNumber) ? ' >>> ' : '     ';
            $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $content);
            echo "  {$marker}Line {$num}: {$content}\n";
        }
        echo "  " . str_repeat('-', 60) . "\n";
    }
    
    /**
     * Imprime resumen final en consola
     */
    private function printFinalConsoleSummary(): bool
    {
        $total = count($this->runtimeResults);
        $passes = count(array_filter($this->runtimeResults, fn($r) => $r['status'] === 'PASS'));
        $fails = $total - $passes;
        
        echo "\n" . str_repeat('=', 70) . "\n";
        printf("  Total Environments: %-4d  Passed: %-4d  Failed: %-4d\n", $total, $passes, $fails);
        echo str_repeat('-', 70) . "\n";
        
        if ($fails === 0) echo "  All tests passed successfully!\n";
        else echo "  {$fails} test(s) failed.\n";
        
        foreach ($this->runtimeResults as $res) {
            $statusLabel = $res['status'] === 'PASS' ? '[SUCCESS]' : '[FAILURE]';
            $short = (new ReflectionClass($res['class']))->getShortName();
            printf("  %s %s::%s (%s) [%sms]\n", $statusLabel, $short, $res['method'], $res['driver'], $res['duration']);
        }
        echo str_repeat('=', 70) . "\n";
        
        if ($this->htmlReportPath) {
            $this->saveHtmlReport();
            echo "\n  HTML Report generated: {$this->htmlReportPath}\n";
        }
        return $fails === 0;
    }
    
    /**
     * Guarda reporte HTML
     */
    private function saveHtmlReport(): void
    {
        if (!$this->htmlReportPath) return;
        file_put_contents($this->htmlReportPath, $this->buildHtmlContent());
    }
    
    /**
     * Construye contenido HTML del reporte
     */
    private function buildHtmlContent(): string
    {
        $successCount = count(array_filter($this->runtimeResults, fn($r) => $r['status'] === 'PASS'));
        $failCount = count($this->runtimeResults) - $successCount;
        $totalCount = count($this->runtimeResults);
        $date = date('Y-m-d H:i:s');
        $classShort = !empty($this->runtimeResults) ? (new ReflectionClass($this->runtimeResults[0]['class']))->getShortName() : 'Unknown';
        
        $cards = '';
        foreach ($this->runtimeResults as $res) {
            $statusClass = strtolower($res['status']);
            $msg = $res['error'] ? '❌ ' . htmlspecialchars($res['error']) : '✅ Assertion passed';
            
            $cards .= '<div class="test-card ' . $statusClass . '">';
            $cards .= '<div class="test-header"><div><div class="test-title">' . htmlspecialchars($res['method']) . '</div>';
            $cards .= '<div class="test-meta"><span class="env-tag">' . $res['driver'] . '</span><span>' . $res['duration'] . 'ms</span></div></div>';
            $cards .= '<span class="badge ' . $statusClass . '">' . $res['status'] . '</span></div>';
            $cards .= '<div class="result-box">' . $msg . '</div></div>';
        }
        
        return $this->getFullHtml($classShort, $date, $successCount, $failCount, $totalCount, $cards);
    }
    
    /**
     * Obtiene HTML completo del reporte
     */
    private function getFullHtml($class, $date, $s, $f, $t, $cards): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>TDD Report - $class</title>
<style>
body{font-family:sans-serif;background:#f8fafc;padding:2rem}
.test-card{background:white;margin:1rem;padding:1rem;border-left:4px solid #22c55e}
.test-card.failure{border-left-color:#ef4444}
.test-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:0.5rem}
.test-title{font-weight:bold}
.test-meta{font-size:0.85rem;color:#64748b}
.env-tag{background:#e2e8f0;padding:2px 6px;border-radius:3px;margin-right:8px}
.badge{padding:4px 8px;border-radius:4px;font-size:0.85rem;font-weight:bold}
.badge.pass{background:#dcfce7;color:#166534}
.badge.failure{background:#fee2e2;color:#991b1b}
.result-box{background:#f1f5f9;padding:0.75rem;border-radius:4px;margin-top:0.5rem}
</style>
</head>
<body>
<h1>Report: $class</h1>
<p>Date: $date</p>
<p>Passed: $s | Failed: $f | Total: $t</p>
$cards
</body>
</html>
HTML;
    }
    
    // ==================== UTILIDADES ====================
    
    /**
     * Línea horizontal separadora
     */
    private function hr(int $size = 70, string $char = '_'): void
    {
        echo str_repeat($char, $size) . "\n";
    }
    
    /**
     * Escanea Endpoints disponibles
     */
    public function scanEndpoints(): array
    {
        $classes = [];
        
        if (!is_dir($this->endpointsPath)) {
            return $classes;
        }
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->endpointsPath)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                require_once $file->getPathname();
                
                $className = $file->getBasename('.php');
                $fqnClass = "RapidBase\\Api\\$className";
                
                if (class_exists($fqnClass)) {
                    $classes[] = [
                        'file' => $file->getPathname(),
                        'class' => $fqnClass,
                        'name' => $className
                    ];
                }
            }
        }
        
        return $classes;
    }
    
    /**
     * Obtiene historial de tests
     */
    public function getHistory(int $limit = 100): array
    {
        if (!$this->db) return [];
        $sql = "SELECT * FROM test_history ORDER BY created_at DESC LIMIT :limit";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['limit' => $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obtiene estadísticas de tests
     */
    public function getStats(): array
    {
        if (!$this->db) return [];
        $sql = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'PASS' THEN 1 ELSE 0 END) as pass,
                    SUM(CASE WHEN status = 'FAIL' THEN 1 ELSE 0 END) as fail,
                    AVG(execution_time) as avg_time
                FROM test_history h1
                WHERE id = (SELECT MAX(id) FROM test_history h2 
                           WHERE h1.test_identifier = h2.test_identifier)";
        
        $stmt = $this->db->query($sql);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obtiene tests que fallaron
     */
    public function getFailingTests(): array
    {
        if (!$this->db) return [];
        $sql = "SELECT test_identifier FROM test_history h1 
                WHERE id = (SELECT MAX(id) FROM test_history h2 
                           WHERE h1.test_identifier = h2.test_identifier)
                AND status = 'FAIL'";
        return $this->db->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    }
}
