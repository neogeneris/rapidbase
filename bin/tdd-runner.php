<?php

declare(strict_types=1);

/**
 * TDD Runner - Framework de pruebas unitarias para RapidBase
 * 
 * Uso:
 *   php tdd-runner.php <clase|archivo> [--tests <directorio>] [--generate] [-v]
 * 
 * Ejemplos:
 *   php tdd-runner.php RapidBase\Core\X
 *   php tdd-runner.php X.php --tests /workspace/tests/Unit/Core/X2
 *   php tdd-runner.php RapidBase\Core\X --tests /workspace/tests/Unit/Core/X2 --generate
 */

// Configuración inicial
error_reporting(E_ALL);
ini_set('display_errors', '1');

class TDDRunner
{
    private string $target;
    private ?string $testsDir = null;
    private bool $generate = false;
    private bool $verbose = false;
    private bool $first = false;
    private ?string $className = null;
    private ?string $filePath = null;
    private string $dbPath;
    
    public function __construct(array $argv)
    {
        // Parsear argumentos
        array_shift($argv); // Eliminar nombre del script
        
        if (empty($argv)) {
            $this->showHelp();
            exit(1);
        }
        
        $this->target = array_shift($argv);
        
        while (!empty($argv)) {
            $arg = array_shift($argv);
            
            switch ($arg) {
                case '--tests':
                    $this->testsDir = array_shift($argv);
                    break;
                case '--generate':
                case '--first':
                    $this->first = true;
                    break;
                    $this->generate = true;
                    break;
                case '-v':
                case '--verbose':
                    $this->verbose = true;
                    break;
                case '--help':
                case '-h':
                    $this->showHelp();
                    exit(0);
                default:
                    echo "Argumento desconocido: $arg\n";
                    exit(1);
            }
        }
        
        // Base de datos para historial y mapeo
        $this->dbPath = dirname(__DIR__) . '/rapidbase_core_tdd.sqlite';
        $this->initDatabase();
    }
    
    private function initDatabase(): void
    {
        $db = new SQLite3($this->dbPath);
        
        // Tabla para mapeo de clases
        $db->exec("
            CREATE TABLE IF NOT EXISTS class_map (
                class_name TEXT PRIMARY KEY,
                file_path TEXT NOT NULL,
                last_checked DATETIME DEFAULT CURRENT_TIMESTAMP,
                namespace_valid INTEGER DEFAULT 1
            )
        ");
        
        // Tabla para historial de tests
        $db->exec("
            CREATE TABLE IF NOT EXISTS test_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                class_name TEXT NOT NULL,
                test_method TEXT,
                status TEXT NOT NULL,
                message TEXT,
                executed_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        $db->close();
    }
    
    private function showHelp(): void
    {
        echo self::hr(70, '=') . "\n";
        echo "           RAPIDBASE TDD RUNNER\n";
        echo self::hr(70, '=') . "\n";
        echo "\n";
        echo "Uso:\n";
        echo "  php tdd-runner.php <clase|archivo> [opciones]\n";
        echo "\n";
        echo "Ejemplos:\n";
        echo "  php tdd-runner.php RapidBase\\Core\\X\n";
        echo "  php tdd-runner.php X.php --tests /workspace/tests/Unit/Core/X2\n";
        echo "  php tdd-runner.php RapidBase\\Core\\X --tests /workspace/tests/Unit/Core/X2 --generate\n";
        echo "\n";
        echo "Opciones:\n";
        echo "  --tests <dir>   Directorio donde están/generarán los tests\n";
        echo "  --generate      Generar esqueleto de tests si no existen\n";
        echo "  -v, --verbose   Mostrar salida detallada\n";
        echo "  --help, -h      Mostrar esta ayuda\n";
        echo "\n";
        echo self::hr(70, '_') . "\n";
    }
    
    private static function hr(int $size = 70, string $char = '_'): string
    {
        return str_repeat($char, $size);
    }
    
    public function run(): void
    {
        echo self::hr(70, '=') . "\n";
        echo "         RAPIDBASE TDD RUNNER\n";
        echo self::hr(70, '=') . "\n\n";
        
        // Paso 1: Resolver el objetivo (clase o archivo)
        echo "[1/4] Resolviendo objetivo: {$this->target}\n";
        $this->resolveTarget();
        
        if (!$this->filePath || !$this->className) {
            echo "ERROR: No se pudo resolver el objetivo\n";
            exit(1);
        }
        
        echo "  Clase: {$this->className}\n";
        echo "  Archivo: {$this->filePath}\n\n";
        
        // Paso 2: Diagnóstico básico
        echo "[2/4] Ejecutando diagnóstico básico...\n";
        $diagnostics = $this->runDiagnostics();
        
        if (!$diagnostics['success']) {
            echo "ERROR: Diagnóstico falló\n";
            foreach ($diagnostics['errors'] as $error) {
                echo "  - $error\n";
            }
            if (!$this->generate) {
                echo "\nSugerencia: Usa --generate para crear esqueleto de tests\n";
                exit(1);
            }
        } else {
            echo "  Sintaxis: OK\n";
            echo "  Namespace: " . ($diagnostics['namespace_valid'] ? 'OK' : 'Warning (no coincide con ruta)') . "\n";
            echo "  Interfaces: " . ($diagnostics['interfaces_valid'] ? 'OK' : 'Faltan interfaces') . "\n\n";
        }
        
        // Paso 3: Verificar/generar tests
        echo "[3/4] Verificando tests...\n";
        $testFile = $this->getTestFilePath();
        $testClass = $this->className . 'Test';
        
        if (!file_exists($testFile)) {
            echo "  Tests no encontrados en: $testFile\n";
            
            if ($this->generate) {
                echo "  Generando esqueleto de tests...\n";
                $this->generateTestSkeleton($testFile, $testClass);
                echo "  Test generado exitosamente\n";
            } else {
                echo "\nNo existen tests para {$this->className}\n";
                echo "Usa --generate para crear un esqueleto básico\n";
                echo "O especifica --tests <directorio> si están en otra ubicación\n";
                exit(0);
            }
        } else {
            echo "  Tests encontrados: $testFile\n";
        }
        
        echo "\n";
        
        // Paso 4: Ejecutar tests
        echo "[4/4] Ejecutando tests...\n";
        echo self::hr(70, '_') . "\n";
        
        $this->executeTests($testFile, $testClass);
    }
    
    private function resolveTarget(): void
    {
        // Caso A: Es un archivo existente
        if (file_exists($this->target)) {
            $this->filePath = realpath($this->target);
            $this->className = $this->extractClassNameFromFile($this->filePath);
            $this->saveClassMap();
            return;
        }
        
        // Caso B: Es un FQCN (nombre de clase completo)
        // Intentar con autoloader si existe
        if (class_exists($this->target, false)) {
            // Ya está cargada (ej: por RapidBase.php)
            $reflection = new ReflectionClass($this->target);
            $this->filePath = $reflection->getFileName();
            $this->className = $this->target;
            $this->saveClassMap();
            return;
        }
        
        // Buscar en directorios comunes
        $searchPaths = [
            dirname(__DIR__) . '/src',
            dirname(__DIR__),
        ];
        
        // Convertir FQCN a ruta potencial
        $potentialPath = str_replace('\\', DIRECTORY_SEPARATOR, $this->target) . '.php';
        
        foreach ($searchPaths as $path) {
            $fullPath = $path . DIRECTORY_SEPARATOR . $potentialPath;
            if (file_exists($fullPath)) {
                $this->filePath = realpath($fullPath);
                $this->className = $this->target;
                $this->saveClassMap();
                return;
            }
        }
        
        // Búsqueda recursiva como último recurso
        foreach ($searchPaths as $path) {
            $found = $this->recursiveSearch($path, $this->target);
            if ($found) {
                $this->filePath = $found;
                $this->className = $this->target;
                $this->saveClassMap();
                return;
            }
        }
        
        // No encontrada
        echo "ERROR: No se encontró la clase/archivo: {$this->target}\n";
        
        // Sugerencia basada en namespace
        if (str_contains($this->target, '\\')) {
            $parts = explode('\\', $this->target);
            $suggestedPath = dirname(__DIR__) . '/src/' . implode(DIRECTORY_SEPARATOR, $parts) . '.php';
            echo "Sugerencia: ¿El archivo está en $suggestedPath?\n";
        }
        
        exit(1);
    }
    
    private function extractClassNameFromFile(string $filePath): ?string
    {
        $content = file_get_contents($filePath);
        
        // Extraer namespace
        preg_match('/namespace\s+([^;]+);/', $content, $nsMatches);
        $namespace = trim($nsMatches[1] ?? '');
        
        // Extraer nombre de clase
        preg_match('/class\s+(\w+)/', $content, $classMatches);
        $className = $classMatches[1] ?? null;
        
        if (!$className) {
            // Podría ser una interfaz o trait
            preg_match('/(?:interface|trait)\s+(\w+)/', $content, $otherMatches);
            $className = $otherMatches[1] ?? null;
        }
        
        if (!$className) {
            return null;
        }
        
        return $namespace ? $namespace . '\\' . $className : $className;
    }
    
    private function recursiveSearch(string $dir, string $className): ?string
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $content = file_get_contents($file->getPathname());
                
                // Verificar si contiene la clase
                $pattern = '/\b(?:class|interface|trait)\s+' . preg_quote(end(explode('\\', $className)), '/') . '\b/i';
                if (preg_match($pattern, $content)) {
                    // Verificar namespace también
                    preg_match('/namespace\s+([^;]+);/', $content, $nsMatches);
                    $fileNamespace = trim($nsMatches[1] ?? '');
                    
                    $expectedNamespace = implode('\\', array_slice(explode('\\', $className), 0, -1));
                    
                    if ($fileNamespace === $expectedNamespace || empty($expectedNamespace)) {
                        return $file->getPathname();
                    }
                }
            }
        }
        
        return null;
    }
    
    private function saveClassMap(): void
    {
        $db = new SQLite3($this->dbPath);
        
        // Verificar validez del namespace
        $expectedPath = str_replace('\\', DIRECTORY_SEPARATOR, $this->className) . '.php';
        $namespaceValid = str_ends_with(str_replace('\\', '/', $this->filePath), $expectedPath) ? 1 : 0;
        
        $stmt = $db->prepare("
            INSERT OR REPLACE INTO class_map (class_name, file_path, namespace_valid)
            VALUES (:class_name, :file_path, :namespace_valid)
        ");
        $stmt->bindValue(':class_name', $this->className, SQLITE3_TEXT);
        $stmt->bindValue(':file_path', $this->filePath, SQLITE3_TEXT);
        $stmt->bindValue(':namespace_valid', $namespaceValid, SQLITE3_INTEGER);
        $stmt->execute();
        
        $db->close();
    }
    
    private function runDiagnostics(): array
    {
        $result = [
            'success' => true,
            'errors' => [],
            'warnings' => [],
            'namespace_valid' => true,
            'interfaces_valid' => true,
        ];
        
        // 1. Sintaxis
        exec("php -l " . escapeshellarg($this->filePath) . " 2>&1", $output, $returnCode);
        if ($returnCode !== 0) {
            $result['success'] = false;
            $result['errors'][] = implode("\n", $output);
        }
        
        // 2. Validar namespace vs ruta
        $expectedPath = str_replace('\\', DIRECTORY_SEPARATOR, $this->className) . '.php';
        if (!str_ends_with(str_replace('\\', '/', $this->filePath), $expectedPath)) {
            $result['warnings'][] = "El namespace no coincide con la ruta del archivo";
            $result['namespace_valid'] = false;
        }
        
        // 3. Verificar interfaces y clases padre
        try {
            require_once $this->filePath;
            
            if (class_exists($this->className, false)) {
                $reflection = new ReflectionClass($this->className);
                
                // Verificar interfaces
                foreach ($reflection->getInterfaceNames() as $interface) {
                    if (!interface_exists($interface, false)) {
                        $result['success'] = false;
                        $result['errors'][] = "Interfaz no encontrada: $interface";
                        $result['interfaces_valid'] = false;
                    }
                }
                
                // Verificar clase padre
                if ($parent = $reflection->getParentClass()) {
                    if (!class_exists($parent->getName(), false)) {
                        $result['success'] = false;
                        $result['errors'][] = "Clase padre no encontrada: " . $parent->getName();
                    }
                }
            }
        } catch (Throwable $e) {
            $result['success'] = false;
            $result['errors'][] = "Error al cargar clase: " . $e->getMessage();
        }
        
        return $result;
    }
    
    private function getTestFilePath(): string
    {
        if ($this->testsDir) {
            return rtrim($this->testsDir, '/\\') . DIRECTORY_SEPARATOR . 
                   (new ReflectionClass($this->className))->getShortName() . 'Test.php';
        }
        
        // Ruta por defecto
        $shortName = (new ReflectionClass($this->className))->getShortName();
        return dirname(__DIR__) . "/tests/Unit/Core/{$shortName}/{$shortName}Test.php";
    }
    
    private function generateTestSkeleton(string $testFile, string $testClass): void
    {
        // Obtener métodos públicos de la clase original
        require_once $this->filePath;
        
        $reflection = new ReflectionClass($this->className);
        $methods = [];
        
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() === $this->className) {
                $methods[] = $method->getName();
            }
        }
        
        // Nombre corto de la clase (sin namespace)
        $shortClassName = $reflection->getShortName();
        $testClassName = $shortClassName . 'Test';
        
        // Generar código del test
        $code = "<?php\n\n";
        $code .= "declare(strict_types=1);\n\n";
        $code .= "/**\n";
        $code .= " * Tests para {$this->className}\n";
        $code .= " * Generado automáticamente por TDD Runner\n";
        $code .= " */\n";
        $code .= "class {$testClassName}\n";
        $code .= "{\n";
        $code .= "    private ?object \$instance = null;\n\n";
        $code .= "    // Métodos de aserción básicos\n";
        $code .= "    protected function assertTrue(bool \$condition, string \$message = ''): void\n";
        $code .= "    {\n";
        $code .= "        if (!\$condition) {\n";
        $code .= "            throw new \\Exception(\$message ?: 'Assertion failed');\n";
        $code .= "        }\n";
        $code .= "    }\n\n";
        $code .= "    protected function assertFalse(bool \$condition, string \$message = ''): void\n";
        $code .= "    {\n";
        $code .= "        \$this->assertTrue(!\$condition, \$message);\n";
        $code .= "    }\n\n";
        $code .= "    protected function assertEquals(mixed \$expected, mixed \$actual, string \$message = ''): void\n";
        $code .= "    {\n";
        $code .= "        if (\$expected !== \$actual) {\n";
        $code .= "            throw new \\Exception(\$message ?: 'Expected: ' . var_export(\$expected, true) . ', got: ' . var_export(\$actual, true));\n";
        $code .= "        }\n";
        $code .= "    }\n\n";
        $code .= "    protected function assertNull(mixed \$value, string \$message = ''): void\n";
        $code .= "    {\n";
        $code .= "        if (\$value !== null) {\n";
        $code .= "            throw new \\Exception(\$message ?: 'Expected null');\n";
        $code .= "        }\n";
        $code .= "    }\n\n";
        $code .= "    protected function assertNotNull(mixed \$value, string \$message = ''): void\n";
        $code .= "    {\n";
        $code .= "        if (\$value === null) {\n";
        $code .= "            throw new \\Exception(\$message ?: 'Expected not null');\n";
        $code .= "        }\n";
        $code .= "    }\n\n";
        $code .= "    public function setUp(): void\n";
        $code .= "    {\n";
        $code .= "        // Incluir directamente sin depender del autoloader\n";
        $code .= "        require_once '" . addslashes($this->filePath) . "';\n";
        $code .= "        \n";
        $code .= "        // Instanciar la clase (ajustar según constructor)\n";
        $code .= "        try {\n";
        $code .= "            \$reflection = new \\ReflectionClass('{$this->className}');\n";
        $code .= "            \$constructor = \$reflection->getConstructor();\n";
        $code .= "            \n";
        $code .= "            if (\$constructor && \$constructor->getNumberOfRequiredParameters() > 0) {\n";
        $code .= "                // Constructor requiere parámetros - usar mock o instancia manual\n";
        $code .= "                \$this->instance = null;\n";
        $code .= "            } else {\n";
        $code .= "                \$this->instance = new {$this->className}();\n";
        $code .= "            }\n";
        $code .= "        } catch (Throwable \$e) {\n";
        $code .= "            // Error al instanciar\n";
        $code .= "            \$this->instance = null;\n";
        $code .= "        }\n";
        $code .= "    }\n\n";
        
        foreach ($methods as $method) {
            $code .= "    public function test" . ucfirst($method) . "(): void\n";
            $code .= "    {\n";
            $code .= "        // TODO: Implementar prueba para {$method}\n";
            $code .= "        \$this->assertFalse(true, 'TODO: Implementar prueba para ' . $method . '');
";
            $code .= "    }\n\n";
        }
        
        $code .= "}\n";
        
        // Crear directorio si no existe
        $dir = dirname($testFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        file_put_contents($testFile, $code);
    }
    
    private function executeTests(string $testFile, string $testClass): void
    {
        // Incluir el archivo de test
        require_once $testFile;
        
        // Obtener nombre corto de la clase (sin namespace)
        $shortClassName = (new ReflectionClass($this->className))->getShortName() . 'Test';
        
        if (!class_exists($shortClassName)) {
            echo "ERROR: La clase de test {$shortClassName} no existe\n";
            exit(1);
        }
        
        $testInstance = new $shortClassName();
        $reflection = new ReflectionClass($shortClassName);
        
        $total = 0;
        $success = 0;
        $failed = 0;
        
        // Ejecutar setUp si existe
        if (method_exists($testInstance, 'setUp')) {
            try {
                $testInstance->setUp();
            } catch (Throwable $e) {
                echo "WARNING: setUp falló: " . $e->getMessage() . "\n";
            }
        }
        
        // Encontrar todos los métodos de test
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (str_starts_with($method->getName(), 'test')) {
                $total++;
                $methodName = $method->getName();
                
                try {
                    $testInstance->$methodName();
                    echo "[SUCCESS] {$testClass}::{$methodName}\n";
                    $success++;
                    $this->logTestResult($testClass, $methodName, 'SUCCESS', '');
                } catch (Throwable $e) {
                    echo "[FAILURE] {$testClass}::{$methodName}\n";
                    
                    // Mostrar detalle del error
                    echo "       Error: " . $e->getMessage() . "\n";
                    echo "       En: " . $e->getFile() . ":" . $e->getLine() . "\n";
                    
                    // Si --first, mostrar código del método y detener
                    if ($this->first) {
                        echo "\n" . self::hr(70, '=') . "\n";
                        echo "PRIMER FALLO - Deteniendo ejecución\n";
                        echo self::hr(70, '_') . "\n";
                        
                        // Intentar mostrar el código del método donde falló
                        $this->showMethodCode($e->getFile(), $e->getLine());
                        
                        echo "\nSugerencia: Repara el código y vuelve a ejecutar\n";
                        echo self::hr(70, '=') . "\n";
                        exit(1);
                    }
                    
                    $failed++;
                    $this->logTestResult($testClass, $methodName, 'FAILURE', $e->getMessage());
                }
            }
        }
        
        echo self::hr(70, '_') . "\n";
        echo "Total: $total | Exitosos: $success | Fallidos: $failed\n";
        echo self::hr(70, '=') . "\n";
        
        exit($failed > 0 ? 1 : 0);
    }
    
    private function logTestResult(string $className, string $method, string $status, string $message): void
    {
        $db = new SQLite3($this->dbPath);
        
        $stmt = $db->prepare("
            INSERT INTO test_history (class_name, test_method, status, message)
            VALUES (:class_name, :test_method, :status, :message)
        ");
        $stmt->bindValue(':class_name', $className, SQLITE3_TEXT);
        $stmt->bindValue(':test_method', $method, SQLITE3_TEXT);
        $stmt->bindValue(':status', $status, SQLITE3_TEXT);
        $stmt->bindValue(':message', $message, SQLITE3_TEXT);
        $stmt->execute();
        
        $db->close();
    }
    
    private function showMethodCode(string $file, int $line): void
    {
        if (!file_exists($file)) {
            echo "       No se pudo leer el archivo: $file\n";
            return;
        }
        
        $lines = file($file);
        $totalLines = count($lines);
        
        // Encontrar el inicio del método (buscar hacia atrás)
        $startLine = max(0, $line - 1);
        $braceCount = 0;
        $foundFunction = false;
        
        for ($i = $startLine; $i >= 0; $i--) {
            $lineContent = $lines[$i];
            if (preg_match('/function\s+\w+/', $lineContent)) {
                $foundFunction = true;
                $startLine = $i;
                break;
            }
        }
        
        // Si no encontramos función, usar contexto alrededor de la línea
        if (!$foundFunction) {
            $startLine = max(0, $line - 5);
        }
        
        // Mostrar contexto (líneas alrededor del error)
        $contextStart = max(0, $startLine - 2);
        $contextEnd = min($totalLines, $line + 3);
        
        echo "\nCódigo donde ocurrió el error:\n";
        echo self::hr(70, '_') . "\n";
        
        for ($i = $contextStart; $i < $contextEnd; $i++) {
            $marker = ($i == $line - 1) ? ' >>> ' : '     ';
            $lineNum = str_pad((string)($i + 1), 4, ' ', STR_PAD_LEFT);
            echo "{$marker}{$lineNum}| " . rtrim($lines[$i]);
            if ($i == $line - 1) {
                echo "  <-- ERROR";
            }
            echo "\n";
        }
        
        echo self::hr(70, '_') . "\n";
        
        // Si es un archivo de la clase original (X.php), mostrar el método completo
        if (str_contains($file, '/Core/X.php') || str_contains($file, '\\Core\\X.php')) {
            echo "\nMétodo en X.php:\n";
            echo self::hr(70, '_') . "\n";
            $this->showFullMethod($file, $line);
        }
    }
    
    private function showFullMethod(string $file, int $errorLine): void
    {
        if (!file_exists($file)) {
            return;
        }
        
        $lines = file($file);
        $totalLines = count($lines);
        
        // Buscar el inicio del método (hacia atrás desde el error)
        $methodStart = -1;
        $methodEnd = -1;
        
        for ($i = $errorLine - 1; $i >= 0; $i--) {
            if (preg_match('/^(public|private|protected)\s+function\s+(\w+)/', $lines[$i], $matches)) {
                $methodStart = $i;
                break;
            }
        }
        
        if ($methodStart === -1) {
            return;
        }
        
        // Buscar el final del método (contando llaves)
        $braceCount = 0;
        $inMethod = false;
        
        for ($i = $methodStart; $i < $totalLines; $i++) {
            $lineContent = $lines[$i];
            $braceCount += substr_count($lineContent, '{');
            $braceCount -= substr_count($lineContent, '}');
            
            if ($braceCount > 0) {
                $inMethod = true;
            }
            
            if ($inMethod && $braceCount === 0) {
                $methodEnd = $i;
                break;
            }
        }
        
        if ($methodEnd === -1) {
            $methodEnd = min($totalLines, $methodStart + 30);
        }
        
        // Mostrar el método completo
        for ($i = $methodStart; $i <= $methodEnd; $i++) {
            $lineNum = str_pad((string)($i + 1), 4, ' ', STR_PAD_LEFT);
            echo "     {$lineNum}| " . rtrim($lines[$i]) . "\n";
        }
    }
}

// Ejecutar
$runner = new TDDRunner($argv);
$runner->run();
