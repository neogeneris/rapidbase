<?php

namespace RapidBase\Tdd;

use ReflectionClass;
use ReflectionMethod;
use PDO;

/**
 * Runner especializado para pruebas unitarias de clases Core (X, Gateway, Q)
 * 
 * Uso:
 *   php core-runner.php --all              Ejecuta todas las pruebas
 *   php core-runner.php --first            Ejecuta hasta el primer fallo
 *   php core-runner.php --class X          Ejecuta solo pruebas de la clase X
 *   php core-runner.php --stats            Muestra estadísticas
 */
class CoreRunner {
    private PDO $db;
    private string $basePath;
    private string $testPath;
    private array $results = [];
    private bool $stopOnFirstFail = false;
    private string $currentConnectionId = 'core_test';

    public function __construct(
        string $dbPath = 'rapidbase_core_tdd.sqlite',
        string $basePath = __DIR__ . '/../..'
    ) {
        $this->basePath = rtrim($basePath, '/\\');
        $this->testPath = $this->basePath . '/tests/Unit/Core';
        
        // Inicializar base de datos de historial
        $this->db = new \PDO("sqlite:$dbPath");
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->initDb();
    }

    private function initDb() {
        $this->db->exec("CREATE TABLE IF NOT EXISTS core_test_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            test_identifier TEXT,
            class_name TEXT,
            method_name TEXT,
            status TEXT,
            error_message TEXT,
            execution_time REAL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_core_test_identifier 
                        ON core_test_history(test_identifier)");
    }

    /**
     * Registra un resultado de prueba en el historial
     */
    private function logToHistory(string $testId, string $className, string $methodName, string $status, ?string $error, float $time): void {
        $stmt = $this->db->prepare("INSERT INTO core_test_history (test_identifier, class_name, method_name, status, error_message, execution_time) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$testId, $className, $methodName, $status, $error, $time]);
    }

    /**
     * Escanea la carpeta de tests Core y devuelve todos los archivos de prueba
     */
    public function scanCoreTests(): array {
        $tests = [];
        
        if (!is_dir($this->testPath)) {
            return $tests;
        }

        // Buscar subdirectorios (X, Gateway, Q, etc.)
        $subdirs = glob($this->testPath . '/*', GLOB_ONLYDIR);
        
        foreach ($subdirs as $subdir) {
            $category = basename($subdir);
            
            // Buscar archivos *Test.php en el subdirectorio y sus subdirectorios
            $files = glob($subdir . '/*Test.php');
            $recursiveFiles = glob($subdir . '/*/*Test.php');
            $allFiles = array_merge($files, $recursiveFiles);
            
            foreach ($allFiles as $file) {
                $relativePath = str_replace($subdir . '/', '', $file);
                $parts = explode('/', $relativePath);
                
                // Si hay subdirectorios, usarlos como prefijo para el nombre del test
                if (count($parts) > 1) {
                    // Ejemplo: NewTests/XTest.php -> NewTests_XTest
                    $subdir_name = implode('_', array_slice($parts, 0, -1));
                    $testName = $subdir_name . '_' . basename($file, 'Test.php');
                } else {
                    $testName = basename($file, 'Test.php');
                }
                
                $tests[] = [
                    'file' => $file,
                    'category' => $category,
                    'name' => $testName,
                    'class' => $category . '\\' . str_replace('/', '\\', $relativePath)
                ];
            }
        }

        return $tests;
    }

    /**
     * Obtiene métodos de prueba (test*) de una clase de test
     */
    public function getTestMethods(string $className): array {
        $reflection = new ReflectionClass($className);
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
        $testMethods = [];

        foreach ($methods as $method) {
            // Solo métodos que comienzan con 'test'
            if (str_starts_with($method->name, 'test')) {
                $testMethods[] = $method->getName();
            }
        }

        return $testMethods;
    }

    /**
     * Ejecuta un método de prueba específico
     */
    public function runTest($instance, string $methodName, string $testId): array {
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
     * Ejecuta TODAS las pruebas Core
     */
    public function runAll(bool $verbose = false): array {
        $this->results = [
            'total' => 0,
            'pass' => 0,
            'fail' => 0,
            'tests' => []
        ];

        $tests = $this->scanCoreTests();
        
        foreach ($tests as $testInfo) {
            require_once $testInfo['file'];
            
            $className = 'RapidBase\\Tests\\' . $testInfo['class'];
            
            if (!class_exists($className)) {
                if ($verbose) {
                    echo "[SKIP] Class $className not found\n";
                }
                continue;
            }
            
            $instance = new $className();
            $methods = $this->getTestMethods($className);
            
            foreach ($methods as $method) {
                $testId = "{$testInfo['category']}::{$testInfo['name']}::{$method}";
                $this->results['total']++;
                
                if ($verbose) {
                    echo "Running: $testId ... ";
                }
                
                $result = $this->runTest($instance, $method, $testId);
                
                if ($result['status'] === 'PASS') {
                    $this->results['pass']++;
                    if ($verbose) echo "✓ PASS\n";
                } else {
                    $this->results['fail']++;
                    if ($verbose) echo "✗ FAIL: {$result['error']}\n";
                    
                    if ($this->stopOnFirstFail) {
                        if ($verbose) echo "\nStopping on first failure.\n";
                        return $this->results;
                    }
                }
                
                $this->results['tests'][] = [
                    'id' => $testId,
                    'category' => $testInfo['category'],
                    'class' => $testInfo['name'],
                    'method' => $method,
                    'status' => $result['status'],
                    'error' => $result['error'] ?? null,
                    'duration' => $result['duration'] ?? 0
                ];
            }
        }

        return $this->results;
    }

    /**
     * Ejecuta pruebas de una categoría específica (X, Gateway, Q)
     */
    public function runCategory(string $category, bool $verbose = false): array {
        $this->results = [
            'total' => 0,
            'pass' => 0,
            'fail' => 0,
            'tests' => []
        ];

        $tests = $this->scanCoreTests();
        
        foreach ($tests as $testInfo) {
            if ($testInfo['category'] !== $category) {
                continue;
            }
            
            require_once $testInfo['file'];
            
            $className = 'RapidBase\\Tests\\' . $testInfo['class'];
            
            if (!class_exists($className)) {
                continue;
            }
            
            $instance = new $className();
            $methods = $this->getTestMethods($className);
            
            foreach ($methods as $method) {
                $testId = "{$testInfo['category']}::{$testInfo['name']}::{$method}";
                $this->results['total']++;
                
                if ($verbose) {
                    echo "Running: $testId ... ";
                }
                
                $result = $this->runTest($instance, $method, $testId);
                
                if ($result['status'] === 'PASS') {
                    $this->results['pass']++;
                    if ($verbose) echo "✓ PASS\n";
                } else {
                    $this->results['fail']++;
                    if ($verbose) echo "✗ FAIL: {$result['error']}\n";
                }
                
                $this->results['tests'][] = [
                    'id' => $testId,
                    'category' => $testInfo['category'],
                    'class' => $testInfo['name'],
                    'method' => $method,
                    'status' => $result['status'],
                    'error' => $result['error'] ?? null,
                    'duration' => $result['duration'] ?? 0
                ];
            }
        }

        return $this->results;
    }

    /**
     * Ejecuta solo las pruebas que fallaron anteriormente
     */
    public function runFailingOnly(bool $verbose = false): array {
        $failingTests = $this->getFailingTests();
        
        if (empty($failingTests)) {
            echo "No failing tests found. All tests passing!\n";
            return ['total' => 0, 'pass' => 0, 'fail' => 0, 'tests' => []];
        }

        $this->results = [
            'total' => 0,
            'pass' => 0,
            'fail' => 0,
            'tests' => []
        ];

        foreach ($failingTests as $testId) {
            $parts = explode('::', $testId);
            if (count($parts) !== 3) {
                continue;
            }
            
            [$category, $className, $method] = $parts;
            $fqnClass = "RapidBase\\Tests\\$category\\$className";
            
            if (!class_exists($fqnClass)) {
                continue;
            }
            
            require_once $this->testPath . "/$category/{$className}Test.php";
            
            $instance = new $fqnClass();
            
            if (method_exists($instance, 'setUp')) {
                $instance->setUp();
            }
            
            $this->results['total']++;
            
            if ($verbose) {
                echo "Retrying: $testId ... ";
            }
            
            $result = $this->runTest($instance, $method, $testId);
            
            if ($result['status'] === 'PASS') {
                $this->results['pass']++;
                if ($verbose) echo "✓ PASS (Fixed!)\n";
            } else {
                $this->results['fail']++;
                if ($verbose) echo "✗ FAIL: {$result['error']}\n";
            }
            
            $this->results['tests'][] = [
                'id' => $testId,
                'category' => $category,
                'class' => $className,
                'method' => $method,
                'status' => $result['status'],
                'error' => $result['error'] ?? null,
                'duration' => $result['duration'] ?? 0
            ];
        }

        return $this->results;
    }

    /**
     * Obtiene lista de tests que fallaron en su última ejecución
     */
    public function getFailingTests(): array {
        $sql = "SELECT test_identifier FROM core_test_history h1 
                WHERE id = (SELECT MAX(id) FROM core_test_history h2 
                           WHERE h1.test_identifier = h2.test_identifier)
                AND status = 'FAIL'";
        return $this->db->query($sql)->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Obtiene historial completo de pruebas
     */
    public function getHistory(int $limit = 100): array {
        $sql = "SELECT * FROM core_test_history 
                ORDER BY created_at DESC 
                LIMIT :limit";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['limit' => $limit]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Obtiene estadísticas de pruebas
     */
    public function getStats(): array {
        $sql = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'PASS' THEN 1 ELSE 0 END) as pass,
                    SUM(CASE WHEN status = 'FAIL' THEN 1 ELSE 0 END) as fail,
                    AVG(execution_time) as avg_time
                FROM core_test_history h1
                WHERE id = (SELECT MAX(id) FROM core_test_history h2 
                           WHERE h1.test_identifier = h2.test_identifier)";
        
        $stmt = $this->db->query($sql);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    /**
     * Imprime una línea horizontal separadora
     */
    private function hr(int $size = 70, string $char = '_'): void {
        echo str_repeat($char, $size) . "\n";
    }

    /**
     * Muestra reporte en consola
     */
    public function printReport(array $results): void {
        echo "\n";
        $this->hr(70, '=');
        echo "           RAPIDBASE CORE TDD TEST REPORT                 \n";
        $this->hr(70, '=');
        printf("  Total: %-4d  Success: %-4d  Failure: %-4d                  \n", 
               $results['total'], $results['pass'], $results['fail']);
        $this->hr(70);
        
        foreach ($results['tests'] as $test) {
            $status = $test['status'] === 'PASS' ? '[SUCCESS]' : '[FAILURE]';
            $errorInfo = '';
            if ($test['status'] === 'FAIL' && !empty($test['error'])) {
                $errorInfo = ' - ' . substr($test['error'], 0, 40);
            }

            printf("  %s %s::%s::%s%s\n",
                   $status,
                   $test['category'],
                   $test['class'],
                   $test['method'],
                   $errorInfo);
        }

        $this->hr(70, '=');
        echo "\n";
    }

    /**
     * Configura si debe detenerse en el primer fallo
     */
    public function setStopOnFirstFail(bool $stop): void {
        $this->stopOnFirstFail = $stop;
    }
    
    /**
     * Obtiene el ID de conexión actual para pruebas
     */
    public function getConnectionId(): string {
        return $this->currentConnectionId;
    }
    
    /**
     * Establece el ID de conexión para pruebas
     */
    public function setConnectionId(string $connectionId): void {
        $this->currentConnectionId = $connectionId;
    }
}
