<?php

namespace RapidBase\Tdd;

use ReflectionClass;
use ReflectionMethod;
use PDO;

/**
 * Runner TDD unificado y simple para RapidBase
 * 
 * Uso:
 *   php tdd-runner.php X                          - Diagnóstico básico de X
 *   php tdd-runner.php X --tests /path/to/tests   - Genera/esquema de tests o ejecuta
 *   php tdd-runner.php X --generate               - Genera esqueleto XTest.php
 * 
 * Características:
 * - Sin runners especializados
 * - Diagnóstico automático si no hay tests
 * - Generación de esqueletos 1:1 (método -> testMétodo)
 * - Verificación básica de sintaxis, namespace, interfaces
 */
class TddRunner {
    private PDO $db;
    private array $results = [];
    private bool $stopOnFirstFail = false;
    private string $basePath;
    
    public function __construct(
        string $dbPath = 'rapidbase_tdd.sqlite',
        string $basePath = null
    ) {
        // Si no se proporciona basePath, intentar deducirlo
        if ($basePath === null) {
            // Intentar encontrar el root del proyecto buscando bin/RapidBase.php
            $possiblePaths = [
                dirname(__DIR__, 3),  // Desde src/RapidBase/Tdd
                getcwd(),
            ];
            
            foreach ($possiblePaths as $path) {
                if (file_exists($path . '/bin/RapidBase.php')) {
                    $this->basePath = rtrim($path, '/\\');
                    break;
                }
            }
            
            if (!isset($this->basePath)) {
                $this->basePath = dirname(__DIR__, 3);
            }
        } else {
            $this->basePath = rtrim($basePath, '/\\');
        }
        
        // Cargar RapidBase bundle SOLO si no se ha cargado el Autoloader desde src
        // Esto evita errores de redeclaración de clases
        if (!class_exists('RapidBase\\Autoloader\\Autoloader', false)) {
            $bundlePath = $this->basePath . '/bin/RapidBase.php';
            if (file_exists($bundlePath)) {
                require_once $bundlePath;
            } elseif (file_exists($this->basePath . '/lib/RapidBase.php')) {
                require_once $this->basePath . '/lib/RapidBase.php';
            }
        }
        
        // Inicializar DB de historial
        $this->db = new \PDO("sqlite:$dbPath");
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->initDb();
    }

    private function initDb() {
        $this->db->exec("CREATE TABLE IF NOT EXISTS tdd_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            test_identifier TEXT,
            class_name TEXT,
            method_name TEXT,
            status TEXT,
            error_message TEXT,
            execution_time REAL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_tdd_identifier 
                        ON tdd_history(test_identifier)");
    }

    private function logToHistory(string $testId, string $className, string $methodName, string $status, ?string $error, float $time): void {
        $stmt = $this->db->prepare("INSERT INTO tdd_history (test_identifier, class_name, method_name, status, error_message, execution_time) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$testId, $className, $methodName, $status, $error, $time]);
    }

    /**
     * Línea horizontal separadora
     */
    private function hr(int $size = 70, string $char = '_'): void {
        echo str_repeat($char, $size) . "\n";
    }

    /**
     * Diagnóstico básico de una clase sin necesidad de tests
     */
    public function diagnose(string $className, ?string $classPath = null): array {
        $diagnosis = [
            'class' => $className,
            'file_exists' => false,
            'syntax_ok' => false,
            'loadable' => false,
            'namespace_ok' => false,
            'expected_namespace' => '',
            'actual_namespace' => '',
            'interfaces' => [],
            'interface_issues' => [],
            'methods' => [],
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
            $diagnosis['suggestions'][] = "Archivo de clase no encontrado: $classPath";
            return $diagnosis;
        }

        // 3. Intentar cargar la clase
        if ($diagnosis['syntax_ok']) {
            // Construir FQN esperado basado en el path
            $relativePath = str_replace([$classPath, '.php'], '', $classPath);
            $relativePath = str_replace([$this->basePath . '/', '.php'], '', $classPath);
            $parts = explode('/', $relativePath);
            $expectedNamespace = implode('\\', array_slice($parts, 0, -1));
            $expectedClassName = end($parts);
            $fqnClass = $expectedNamespace . '\\' . $expectedClassName;
            
            $diagnosis['expected_namespace'] = $expectedNamespace;
            
            // Cargar bundle primero si es Core (esto puede cargar la clase)
            $bundleLoaded = false;
            if (str_contains($classPath, '/Core/')) {
                $bundlePaths = [
                    $this->basePath . '/bin/RapidBase.php',
                    $this->basePath . '/lib/RapidBase.php',
                ];
                foreach ($bundlePaths as $bundlePath) {
                    if (file_exists($bundlePath)) {
                        require_once $bundlePath;
                        $bundleLoaded = true;
                        break;
                    }
                }
            }
            
            // NO cargar el archivo de la clase si el bundle ya la cargó
            // El bundle define clases inline que no pueden ser recargadas
            if (!$bundleLoaded) {
                require_once $classPath;
            }
            
            // Intentar obtener la clase cargada (puede estar en bundle con FQN correcto)
            $loadedClass = null;
            if (class_exists($fqnClass, false)) {
                $loadedClass = $fqnClass;
                $diagnosis['loadable'] = true;
            } else {
                $diagnosis['suggestions'][] = "No se pudo cargar la clase. El bundle puede haberla registrado con otro nombre.";
            }
            
            if ($loadedClass) {
                $reflection = new ReflectionClass($loadedClass);
                $diagnosis['actual_namespace'] = $reflection->getNamespaceName();
                
                // Verificar correspondencia namespace/path
                if ($reflection->getNamespaceName() !== $expectedNamespace) {
                    $diagnosis['suggestions'][] = "El namespace '{$reflection->getNamespaceName()}' no coincide con la estructura de directorios esperada '$expectedNamespace'";
                } else {
                    $diagnosis['namespace_ok'] = true;
                }
                
                // 4. Obtener métodos públicos
                $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
                foreach ($methods as $method) {
                    if ($method->class === $loadedClass && !str_starts_with($method->name, '__')) {
                        $diagnosis['methods'][] = $method->getName();
                    }
                }
                
                // 5. Verificar interfaces implementadas
                $interfaces = $reflection->getInterfaceNames();
                foreach ($interfaces as $interface) {
                    if (!interface_exists($interface)) {
                        $diagnosis['interface_issues'][] = "Interfaz no encontrada: $interface";
                    } else {
                        $diagnosis['interfaces'][] = $interface;
                    }
                }
            }
        }

        return $diagnosis;
    }

    /**
     * Genera esqueleto de test 1:1 para una clase
     */
    public function generateTestSkeleton(string $className, string $classPath, string $testOutputDir): string {
        // Asegurar que el directorio existe
        if (!is_dir($testOutputDir)) {
            mkdir($testOutputDir, 0755, true);
        }
        
        // Diagnosticar primero
        $diagnosis = $this->diagnose($className, $classPath);
        
        if (!$diagnosis['loadable']) {
            throw new \Exception("No se puede generar el test: la clase no se pudo cargar");
        }
        
        $reflection = new ReflectionClass($diagnosis['expected_namespace'] . '\\' . $className);
        $methods = $diagnosis['methods'];
        
        // Construir contenido del archivo de test
        $testClassName = $className . 'Test';
        $testNamespace = str_replace(['/', '\\'], '\\', trim(str_replace($this->basePath, '', $testOutputDir), '/\\'));
        $testNamespace = trim($testNamespace, '\\');
        
        if (empty($testNamespace)) {
            $testNamespace = 'Tests';
        }
        
        $fullClassName = $diagnosis['expected_namespace'] . '\\' . $className;
        
        $content = "<?php\n\n";
        $content .= "namespace $testNamespace;\n\n";
        $content .= "use {$fullClassName};\n";
        $content .= "use RapidBase\\Tdd\\TestCase;\n\n";
        $content .= "/**\n";
        $content .= " * Tests auto-generados para $className\n";
        $content .= " * Cada método público tiene su correspondiente test\n";
        $content .= " */\n";
        $content .= "class {$testClassName} extends TestCase {\n\n";
        
        foreach ($methods as $method) {
            $content .= "    /**\n";
            $content .= "     * Test para {$method}()\n";
            $content .= "     */\n";
            $content .= "    public function test{$method}(): void {\n";
            $content .= "        \$instance = new {$className}();\n";
            $content .= "        \n";
            $content .= "        // TODO: Configurar parámetros y aserciones\n";
            $content .= "        \$result = \$instance->{$method}();\n";
            $content .= "        \n";
            $content .= "        \$this->assertNotNull(\$result);\n";
            $content .= "    }\n\n";
        }
        
        $content .= "}\n";
        
        // Escribir archivo
        $testFilePath = $testOutputDir . '/' . $testClassName . '.php';
        file_put_contents($testFilePath, $content);
        
        return $testFilePath;
    }

    /**
     * Ejecuta tests desde un archivo de test específico
     */
    public function runTestClass(string $testFilePath, bool $verbose = false): array {
        if (!file_exists($testFilePath)) {
            return ['error' => "Archivo de test no encontrado: $testFilePath"];
        }
        
        require_once $testFilePath;
        
        $className = pathinfo($testFilePath, PATHINFO_FILENAME);
        $namespace = $this->extractNamespace($testFilePath);
        $fqnClass = $namespace ? "$namespace\\$className" : $className;
        
        if (!class_exists($fqnClass)) {
            return ['error' => "Clase de test no encontrada: $fqnClass"];
        }
        
        $instance = new $fqnClass();
        $reflection = new ReflectionClass($instance);
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
        
        $this->results = [
            'total' => 0,
            'pass' => 0,
            'fail' => 0,
            'tests' => []
        ];
        
        foreach ($methods as $method) {
            if (!str_starts_with($method->name, 'test')) {
                continue;
            }
            
            $testId = "{$className}::{$method->name}";
            $this->results['total']++;
            
            if ($verbose) {
                echo "Running: $testId ... ";
            }
            
            $result = $this->runTest($instance, $method->name, $testId);
            
            if ($result['status'] === 'PASS') {
                $this->results['pass']++;
                if ($verbose) echo "✓ PASS\n";
            } else {
                $this->results['fail']++;
                if ($verbose) echo "✗ FAIL: {$result['error']}\n";
                
                if ($this->stopOnFirstFail) {
                    return $this->results;
                }
            }
            
            $this->results['tests'][] = [
                'id' => $testId,
                'class' => $className,
                'method' => $method->name,
                'status' => $result['status'],
                'error' => $result['error'] ?? null,
                'duration' => $result['duration'] ?? 0
            ];
        }
        
        return $this->results;
    }

    /**
     * Extrae el namespace de un archivo PHP
     */
    private function extractNamespace(string $filePath): string {
        $content = file_get_contents($filePath);
        if (preg_match('/namespace\s+([^;]+);/', $content, $matches)) {
            return trim($matches[1]);
        }
        return '';
    }

    /**
     * Ejecuta un método de prueba
     */
    private function runTest($instance, string $methodName, string $testId): array {
        $start = microtime(true);
        
        try {
            // Setup si existe
            if (method_exists($instance, 'setUp')) {
                $instance->setUp();
            }
            
            // Ejecutar método de prueba
            $method = new ReflectionMethod($instance, $methodName);
            $method->invoke($instance);
            
            // Teardown si existe
            if (method_exists($instance, 'tearDown')) {
                $instance->tearDown();
            }
            
            $duration = microtime(true) - $start;
            $this->logToHistory($testId, $instance::class, $methodName, 'PASS', null, $duration);
            
            return [
                'status' => 'PASS',
                'duration' => $duration
            ];
        } catch (\Throwable $e) {
            $duration = microtime(true) - $start;
            $this->logToHistory($testId, $instance::class, $methodName, 'FAIL', $e->getMessage(), $duration);
            
            return [
                'status' => 'FAIL',
                'error' => $e->getMessage(),
                'duration' => $duration
            ];
        }
    }

    /**
     * Imprime diagnóstico en consola
     */
    public function printDiagnosis(array $diagnosis): void {
        echo "\n";
        $this->hr(70, '=');
        echo "           RAPIDBASE TDD - DIAGNÓSTICO DE CLASE             \n";
        $this->hr(70, '=');
        echo "  Clase: {$diagnosis['class']}\n";
        $this->hr(70);
        
        echo "  Archivo: " . ($diagnosis['file_exists'] ? "✓ Existe" : "✗ No encontrado") . "\n";
        echo "  Sintaxis: " . ($diagnosis['syntax_ok'] ? "✓ OK" : "✗ Error") . "\n";
        echo "  Cargable: " . ($diagnosis['loadable'] ? "✓ Sí" : "✗ No") . "\n";
        echo "  Namespace: " . ($diagnosis['namespace_ok'] ? "✓ Coincide" : "✗ No coincide") . "\n";
        
        if ($diagnosis['expected_namespace']) {
            echo "    Esperado: {$diagnosis['expected_namespace']}\n";
        }
        if ($diagnosis['actual_namespace']) {
            echo "    Actual: {$diagnosis['actual_namespace']}\n";
        }
        
        if (!empty($diagnosis['interfaces'])) {
            echo "  Interfaces: " . implode(', ', $diagnosis['interfaces']) . "\n";
        }
        
        if (!empty($diagnosis['interface_issues'])) {
            echo "  Problemas de interfaces:\n";
            foreach ($diagnosis['interface_issues'] as $issue) {
                echo "    - $issue\n";
            }
        }
        
        if (!empty($diagnosis['methods'])) {
            echo "  Métodos públicos (" . count($diagnosis['methods']) . "):\n";
            foreach ($diagnosis['methods'] as $method) {
                echo "    - $method()\n";
            }
        }
        
        if (!empty($diagnosis['suggestions'])) {
            echo "\n  Sugerencias:\n";
            foreach ($diagnosis['suggestions'] as $suggestion) {
                echo "    ⚠ $suggestion\n";
            }
        }
        
        echo "\n";
        $this->hr(70);
        echo "  Para generar tests: php tdd-runner.php {$diagnosis['class']} --tests /ruta/a/tests\n";
        $this->hr(70, '=');
        echo "\n";
    }

    /**
     * Imprime reporte de tests
     */
    public function printReport(array $results): void {
        if (isset($results['error'])) {
            echo "\nError: {$results['error']}\n";
            return;
        }
        
        echo "\n";
        $this->hr(70, '=');
        echo "              RAPIDBASE TDD TEST REPORT                   \n";
        $this->hr(70, '=');
        printf("  Total: %-4d  Success: %-4d  Failure: %-4d                  \n", 
               $results['total'], $results['pass'], $results['fail']);
        $this->hr(70);
        
        foreach ($results['tests'] as $test) {
            $status = $test['status'] === 'PASS' ? '[SUCCESS]' : '[FAILURE]';
            $errorInfo = $test['error'] ? " - {$test['error']}" : '';
            printf("  %-8s %s::%s%s\n", $status, $test['class'], $test['method'], $errorInfo);
        }
        
        $this->hr(70, '=');
        echo "\n";
    }
}

?>