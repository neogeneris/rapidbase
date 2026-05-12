<?php

namespace RapidBase\Tdd;

use ReflectionClass;
use ReflectionMethod;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use PDO;

class Runner {
    private PDO $db;
    private string $endpointsPath;
    private array $results = [];
    private bool $stopOnFirstFail = false;

    public function __construct(
        string $dbPath = 'rapidbase_tdd.sqlite',
        string $endpointsPath = __DIR__ . '/../Endpoints'
    ) {
        $this->db = new \PDO("sqlite:$dbPath");
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->endpointsPath = $endpointsPath;
        $this->initDb();
    }

    private function initDb() {
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
        
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_test_identifier 
                        ON test_history(test_identifier)");
    }

    /**
     * Registra un resultado de prueba en el historial
     */
    private function logToHistory(string $testId, string $className, string $methodName, string $status, ?string $error, float $time): void {
        $stmt = $this->db->prepare("INSERT INTO test_history (test_identifier, class_name, method_name, status, error_message, execution_time) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$testId, $className, $methodName, $status, $error, $time]);
    }

    /**
     * Escanea la carpeta de Endpoints y devuelve todas las clases encontradas
     */
    public function scanEndpoints(): array {
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
                
                // Extraer nombre de clase del archivo
                $className = $file->getBasename('.php');
                $fqnClass = "RapidBase\\Endpoints\\$className";
                
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
     * Obtiene todos los métodos testeables de una clase
     */
    public function getTestableMethods(string $className): array {
        $reflection = new ReflectionClass($className);
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
        $testable = [];

        foreach ($methods as $method) {
            // Ignorar métodos heredados de BaseEndpoint o mágicos
            if ($method->class === 'RapidBase\Api\BaseEndpoint' || 
                str_starts_with($method->name, '__')) {
                continue;
            }
            
            $testable[] = $method->getName();
        }

        return $testable;
    }

    /**
     * Ejecuta un método específico de una instancia
     */
    public function runMethod($instance, string $methodName, string $testId): array {
        $start = microtime(true);
        
        try {
            // Setup si existe
            if (method_exists($instance, 'setUp')) {
                $instance->setUp();
            }
            
            // Obtener parámetros requeridos
            $reflection = new ReflectionClass($instance);
            $method = $reflection->getMethod($methodName);
            $params = $this->generateDefaultParams($method);
            
            // Ejecutar método
            $result = $method->invokeArgs($instance, $params);
            
            $duration = microtime(true) - $start;
            $this->logToHistory($testId, $instance::class, $methodName, 'PASS', null, $duration);
            
            return [
                'status' => 'PASS',
                'result' => $result,
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
     * Genera parámetros por defecto basados en los tipos de la firma del método
     */
    private function generateDefaultParams(ReflectionMethod $method): array {
        $params = [];
        
        foreach ($method->getParameters() as $param) {
            if ($param->isOptional()) {
                $params[] = $param->getDefaultValue();
            } else {
                // Valores por defecto inteligentes según tipo
                $type = $param->hasType() ? $param->getType()->getName() : 'mixed';
                $params[] = match($type) {
                    'int' => 1,
                    'string' => 'test',
                    'bool' => true,
                    'float' => 1.0,
                    'array' => [],
                    default => null
                };
            }
        }
        
        return $params;
    }

    /**
     * Ejecuta TODAS las pruebas de todos los endpoints
     */
    public function runAll(bool $verbose = false): array {
        $this->results = [
            'total' => 0,
            'pass' => 0,
            'fail' => 0,
            'tests' => []
        ];

        $endpoints = $this->scanEndpoints();
        
        foreach ($endpoints as $endpoint) {
            $instance = new $endpoint['class']();
            
            // Inyectar contexto mock si es necesario
            if (method_exists($instance, 'setContext')) {
                $context = new \RapidBase\Api\ApiContext(
                    params: [],
                    session: ['user_id' => 1],
                    auth: ['role' => 'admin']
                );
                $instance->setContext($context);
            }
            
            $methods = $this->getTestableMethods($endpoint['class']);
            
            foreach ($methods as $method) {
                $testId = "{$endpoint['name']}::{$method}";
                $this->results['total']++;
                
                if ($verbose) {
                    echo "Running: $testId ... ";
                }
                
                $result = $this->runMethod($instance, $method, $testId);
                
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
                    'class' => $endpoint['name'],
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
            [$className, $method] = explode('::', $testId);
            $fqnClass = "RapidBase\\Endpoints\\$className";
            
            if (!class_exists($fqnClass)) {
                continue;
            }
            
            $instance = new $fqnClass();
            
            if (method_exists($instance, 'setContext')) {
                $context = new \RapidBase\Api\ApiContext(
                    params: [],
                    session: ['user_id' => 1],
                    auth: ['role' => 'admin']
                );
                $instance->setContext($context);
            }
            
            $this->results['total']++;
            
            if ($verbose) {
                echo "Retrying: $testId ... ";
            }
            
            $result = $this->runMethod($instance, $method, $testId);
            
            if ($result['status'] === 'PASS') {
                $this->results['pass']++;
                if ($verbose) echo "✓ PASS (Fixed!)\n";
            } else {
                $this->results['fail']++;
                if ($verbose) echo "✗ FAIL: {$result['error']}\n";
            }
            
            $this->results['tests'][] = [
                'id' => $testId,
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
        $sql = "SELECT test_identifier FROM test_history h1 
                WHERE id = (SELECT MAX(id) FROM test_history h2 
                           WHERE h1.test_identifier = h2.test_identifier)
                AND status = 'FAIL'";
        return $this->db->query($sql)->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Obtiene historial completo de pruebas
     */
    public function getHistory(int $limit = 100): array {
        $sql = "SELECT * FROM test_history 
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
                FROM test_history h1
                WHERE id = (SELECT MAX(id) FROM test_history h2 
                           WHERE h1.test_identifier = h2.test_identifier)";
        
        $stmt = $this->db->query($sql);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    /**
     * Muestra reporte en consola
     */
    public function printReport(array $results): void {
        echo "\n";
        echo "╔══════════════════════════════════════════════════════════╗\n";
        echo "║              RAPIDBASE TDD TEST REPORT                   ║\n";
        echo "╠══════════════════════════════════════════════════════════╣\n";
        printf("║  Total: %-4d  Pass: %-4d  Fail: %-4d                  ║\n", 
               $results['total'], $results['pass'], $results['fail']);
        echo "╠══════════════════════════════════════════════════════════╣\n";
        
        foreach ($results['tests'] as $test) {
            $icon = $test['status'] === 'PASS' ? '✓' : '✗';
            $colorCode = $test['status'] === 'PASS' ? "\033[32m" : "\033[31m";
            $reset = "\033[0m";
            
            printf("║  %s%s %s::%s%-40s%s ║\n", 
                   $colorCode, 
                   $icon, 
                   $test['class'], 
                   $test['method'], 
                   $test['status'] === 'FAIL' ? ': ' . substr($test['error'], 0, 25) : '',
                   $reset);
        }
        
        echo "╚══════════════════════════════════════════════════════════╝\n\n";
    }

    /**
     * Configura si debe detenerse en el primer fallo
     */
    public function setStopOnFirstFail(bool $stop): void {
        $this->stopOnFirstFail = $stop;
    }
}
