#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * RapidBase TDD Runner CLI
 * 
 * Usage:
 *  php bin/tdd-runner.php <Class|File> [--tests <Dir>] [Options]
 */

$baseDir = dirname(__DIR__);
if (!file_exists($baseDir . '/src/RapidBase/Tdd/CoreRunner.php')) {
    $baseDir = getcwd();
}

// Load Autoloader ONLY from src (avoid bundled RapidBase.php to prevent redeclaration errors)
if (file_exists($baseDir . '/vendor/autoload.php')) {
    require_once $baseDir . '/vendor/autoload.php';
} elseif (file_exists($baseDir . '/src/RapidBase/Autoloader/Autoloader.php')) {
    require_once $baseDir . '/src/RapidBase/Autoloader/Autoloader.php';
    \RapidBase\Autoloader\Autoloader::getInstance($baseDir . '/src')
        ->enableDebug(false)
        ->enableCache(true)
        ->register();
} else {
    spl_autoload_register(function ($class) use ($baseDir) {
        $prefix = 'RapidBase\\';
        $srcDir = rtrim($baseDir, '/') . '/src/';
        if (strncmp($prefix, $class, strlen($prefix)) === 0) {
            $file = $srcDir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (file_exists($file)) require $file;
        }
    });
}

use RapidBase\Tdd\Runner;

// --- Argument Parsing ---
$args = $argv;
array_shift($args);

$target = null;
$testsDir = null;
$options = [
    'generate' => false,
    'first' => false,
    'verbose' => false,
    'html' => false,
    'help' => false,
    'drivers' => ['sqlite']
];

$i = 0;
while ($i < count($args)) {
    $arg = $args[$i];
    if ($arg === '--tests' && isset($args[$i + 1])) {
        $testsDir = $args[$i + 1];
        $i += 2;
        continue;
    }
    if (str_starts_with($arg, '--')) {
        $key = substr($arg, 2);
        if (isset($options[$key])) {
            $options[$key] = true;
        }
        if ($key === 'drivers' && isset($args[$i + 1])) {
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

if ($options['help'] || $target === null) {
    echo "RapidBase TDD Runner\n\n";
    echo "Usage:\n  php bin/tdd-runner.php <Class|File> [--tests <Dir>] [Options]\n\n";
    echo "Options:\n";
    echo "  --tests <dir>   Test directory (optional if registered)\n";
    echo "  --generate      Generate test skeleton\n";
    echo "  --first         Stop on first failure\n";
    echo "  --verbose       Show details\n";
    echo "  --html          Generate HTML report\n";
    echo "  --drivers <d1,d2> Drivers (e.g., sqlite,mysql)\n";
    echo "  --help          Show help\n";
    exit(0);
}

// --- Target Resolution ---
$className = null;
$fileLocation = null;
$isTestFile = false;

if (file_exists($target) && str_ends_with($target, 'Test.php')) {
    $isTestFile = true;
    $fileLocation = realpath($target);
    $testsDir = dirname($fileLocation);
    $content = file_get_contents($fileLocation);
    if (preg_match('/namespace\s+([^;]+);/', $content, $nsMatches)) {
        $namespace = trim($nsMatches[1]);
        if (preg_match('/class\s+(\w+)/', $content, $classMatches)) {
            $className = $namespace . '\\' . $classMatches[1];
        }
    }
    if (!$className) $className = pathinfo($target, PATHINFO_FILENAME);
    echo "Mode: Direct Test File Execution\n";
} elseif (is_dir($target)) {
    // Es un directorio, buscar todos los archivos *Test.php recursivamente
    echo "Mode: Directory Scan for Tests\n";
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($target)
    );
    $testFiles = [];
    foreach ($iterator as $file) {
        if ($file->isFile() && strpos($file->getFilename(), 'Test.php') !== false) {
            $testFiles[] = $file->getPathname();
        }
    }
    
    if (empty($testFiles)) {
        echo "No test files found in directory: $target\n";
        exit(1);
    }
    
    echo "Found " . count($testFiles) . " test file(s).\n\n";
    
    $totalTests = 0;
    $totalPassed = 0;
    $totalFailed = 0;
    
    foreach ($testFiles as $testFile) {
        echo str_repeat("-", 60) . "\n";
        // Ejecutar cada archivo individualmente re-invocando la lógica o procesando aquí
        // Para simplificar, procesamos la clase directamente aquí
        $content = file_get_contents($testFile);
        $className = null;
        if (preg_match('/namespace\s+([^;]+);/', $content, $nsMatches)) {
            $namespace = trim($nsMatches[1]);
            if (preg_match('/class\s+(\w+)/', $content, $classMatches)) {
                $className = $namespace . '\\' . $classMatches[1];
            }
        }
        if (!$className) continue;
        
        // Instanciar y correr tests (simplificado para el escaneo)
        require_once $testFile;
        if (class_exists($className)) {
            $instance = new $className();
            if (method_exists($instance, 'setRunner')) {
               // Si usa runner externo
            }
            
            // Ejecutar métodos test*
            $methods = array_filter(get_class_methods($instance), function($m) {
                return strpos($m, 'test') === 0;
            });
            
            foreach ($methods as $method) {
                $totalTests++;
                try {
                    if (method_exists($instance, 'setUp')) $instance->setUp();
                    $instance->$method();
                    echo "."; // Pass
                    $totalPassed++;
                    if (method_exists($instance, 'tearDown')) $instance->tearDown();
                } catch (Throwable $e) {
                    echo "F"; // Fail
                    $totalFailed++;
                    echo "\n  FAIL: $className::$method - " . $e->getMessage() . "\n";
                    if (method_exists($instance, 'tearDown')) $instance->tearDown();
                }
            }
            echo "\n";
        }
    }
    
    echo "\n" . str_repeat("=", 40) . "\n";
    echo "Total: $totalTests | Passed: $totalPassed | Failed: $totalFailed\n";
    exit($totalFailed > 0 ? 1 : 0);

} else {
    if (file_exists($target)) {
        $fileLocation = realpath($target);
        $content = file_get_contents($fileLocation);
        if (preg_match('/namespace\s+([^;]+);/', $content, $nsMatches)) {
            $namespace = trim($nsMatches[1]);
            if (preg_match('/class\s+(\w+)/', $content, $classMatches)) {
                $className = $namespace . '\\' . $classMatches[1];
            }
        }
        if (!$className) $className = pathinfo($target, PATHINFO_FILENAME);
        echo "Mode: Source File Resolution\n";
    } else {
        $className = $target;
        echo "Mode: Class Name Resolution\n";
        if (!class_exists($className, false)) {
            $possiblePath = str_replace('\\', '/', $className) . '.php';
            if (file_exists($baseDir . '/src/' . $possiblePath)) {
                $fileLocation = $baseDir . '/src/' . $possiblePath;
            }
        } else {
            $ref = new ReflectionClass($className);
            $fileLocation = $ref->getFileName();
        }
    }
}

echo "Target Class: $className\n";
if ($testsDir) echo "Tests Directory: $testsDir\n";
echo "Drivers: " . implode(', ', $options['drivers']) . "\n\n";

// --- Init Runner ---
$dbPath = $baseDir . '/rapidbase_core_tdd.sqlite';
try {
    $runner = new Runner($dbPath, $baseDir);
    $runner->setDrivers($options['drivers']);
    $runner->stopOnFirst($options['first']);
    $runner->verbose($options['verbose']);
    if ($options['html']) $runner->generateHtmlReport();
} catch (Throwable $e) {
    echo "ERROR initializing runner: " . $e->getMessage() . "\n";
    exit(1);
}

// --- Generation Mode ---
if ($options['generate']) {
    if (!$className || !$testsDir) {
        echo "ERROR: Class name and --tests directory required for generation.\n";
        exit(1);
    }

    $reflection = new ReflectionClass($className);
    $shortName = $reflection->getShortName();
    $testClassName = $shortName . 'Test';
    $testFilePath = $testsDir . '/' . $testClassName . '.php';
    
    if (file_exists($testFilePath)) {
        echo "WARNING: Test file already exists: $testFilePath\n";
        exit(0);
    }

    echo "Generating skeleton for $className in $testFilePath...\n";
    $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
    $namespace = substr($className, 0, strrpos($className, '\\'));
    
    $code = "<?php\n\ndeclare(strict_types=1);\n\nnamespace $namespace;\n\nuse RapidBase\\Tdd\\TestCase;\n\n";
    $code .= "/**\n * Auto-generated Test Suite for $shortName\n */\n";
    $code .= "class $testClassName extends TestCase\n{\n";

    foreach ($methods as $method) {
        if ($method->isConstructor() || str_starts_with($method->getName(), '__')) continue;
        $methodName = $method->getName();
        $testMethodName = 'test' . ucfirst(preg_replace('/[^a-zA-Z0-9_]/', '_', $methodName));
        $code .= "    public function {$testMethodName}(): void\n    {\n";
        $code .= "        \$this->env()->test('verify {$methodName} behavior', function(\$test) {\n";
        $code .= "            // TODO: Implement test logic\n            \$test->assertTrue(true);\n        });\n    }\n\n";
    }
    $code .= "}\n";

    if (!is_dir($testsDir)) mkdir($testsDir, 0755, true);
    file_put_contents($testFilePath, $code);
    $runner->registerTestClass($className, $testsDir);
    echo "SUCCESS: Skeleton generated and registered.\n";
    exit(0);
}

// --- Execution Mode ---
if ($isTestFile) {
    if (!class_exists($className)) require_once $fileLocation;
} else {
    $testClassName = $className . 'Test';
    $knownDir = $runner->getTestDirectoryForClass($className);
    if ($knownDir) $testsDir = $knownDir;
    
    if ($testsDir) {
        $potentialFile = $testsDir . '/' . (new ReflectionClass($className))->getShortName() . 'Test.php';
        if (file_exists($potentialFile)) require_once $potentialFile;
    }
    
    if (!class_exists($testClassName)) {
        echo "ERROR: Test class '$testClassName' not found.\n";
        echo "Generate it with: php bin/tdd-runner.php $className --tests <dir> --generate\n";
        exit(1);
    }
}

echo "Starting Test Execution...\n" . str_repeat('-', 70) . "\n";
try {
    $success = $runner->runTargetClass($className);
    exit($success ? 0 : 1);
} catch (Throwable $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
    exit(1);
}