#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * RapidBase TDD Runner
 * 
 * Uso:
 *  php bin/tdd-runner.php <Clase|Archivo> [Opciones]
 * 
 * Ejemplos:
 *  php bin/tdd-runner.php RapidBase\Core\X --tests tests/Unit/Core/X2 --generate
 *  php bin/tdd-runner.php X.php --generate
 *  php bin/tdd-runner.php RapidBase\Core\X --first
 *  php bin/tdd-runner.php RapidBase\Core\X --html
 *  php bin/tdd-runner.php src/RapidBase/Core/X.php
 */

$baseDir = dirname(__DIR__);
if (!file_exists($baseDir . '/src/RapidBase/Tdd/CoreRunner.php')) {
    $baseDir = getcwd();
}

if (file_exists($baseDir . '/vendor/autoload.php')) {
    require_once $baseDir . '/vendor/autoload.php';
} else {
    spl_autoload_register(function ($class) use ($baseDir) {
        $prefix = 'RapidBase\\';
        $baseDir = rtrim($baseDir, '/') . '/src/';
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) return;
        $relativeClass = substr($class, $len);
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
        if (file_exists($file)) require $file;
    });
}

use RapidBase\Tdd\CoreRunner;
use RapidBase\Tdd\TestCase;

$dbFile = $baseDir . '/rapidbase_core_tdd.sqlite';

function initDb(string $dbFile): PDO
{
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("CREATE TABLE IF NOT EXISTS class_test_mapping (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        class_name TEXT UNIQUE NOT NULL,
        test_dir TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS test_results (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        category TEXT, class TEXT, method TEXT, status TEXT,
        error TEXT, duration REAL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    return $db;
}

function findTestDir(PDO $db, string $className): ?string
{
    $stmt = $db->prepare('SELECT test_dir FROM class_test_mapping WHERE class_name = ?');
    $stmt->execute([$className]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ? $result['test_dir'] : null;
}

function registerTestDir(PDO $db, string $className, string $testDir): void
{
    $stmt = $db->prepare("INSERT INTO class_test_mapping (class_name, test_dir, updated_at) 
        VALUES (:class, :dir, DATETIME('now'))
        ON CONFLICT(class_name) DO UPDATE SET test_dir = :dir, updated_at = DATETIME('now')");
    $stmt->execute([':class' => $className, ':dir' => $testDir]);
}

$args = $argv;
array_shift($args);

$target = null;
$testsDir = null;
$options = ['generate' => false, 'first' => false, 'verbose' => false, 'html' => false, 'help' => false, 'drivers' => ['sqlite']];

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
        if (isset($options[$key])) $options[$key] = true;
        if ($key === 'drivers' && isset($args[$i + 1])) {
            $options['drivers'] = explode(',', $args[$i + 1]);
            $i++;
        }
        $i++;
        continue;
    }
    if ($target === null) $target = $arg;
    $i++;
}

if ($options['help'] || $target === null) {
    echo "RapidBase TDD Runner\n\n";
    echo "Usage:\n  php bin/tdd-runner.php <Class|File> [Options]\n\n";
    echo "Options:\n";
    echo "  --tests <dir>     Directorio de pruebas (opcional si ya registrado)\n";
    echo "  --generate        Generar esqueleto de pruebas\n";
    echo "  --first           Detener en primera falla\n";
    echo "  --verbose         Mostrar detalle\n";
    echo "  --html            Generar reporte HTML\n";
    echo "  --drivers <d1,d2> Drivers (ej: sqlite,mysql)\n";
    echo "  --help            Esta ayuda\n\n";
    echo "Examples:\n";
    echo "  php bin/tdd-runner.php X.php --generate\n";
    echo "  php bin/tdd-runner.php RapidBase\\Core\\X --drivers sqlite,mysql --html\n";
    echo "  php bin/tdd-runner.php src/RapidBase/Core/X.php\n";
    exit(0);
}

try {
    $db = initDb($dbFile);
} catch (Throwable $e) {
    echo "ERROR: Database init failed: " . $e->getMessage() . "\n";
    exit(1);
}

$className = null;
$fileLocation = null;

echo "Resolving target: $target\n";

if (file_exists($target)) {
    $fileLocation = realpath($target);
    echo "  → File found: $fileLocation\n";
    $content = file_get_contents($fileLocation);
    if (preg_match('/namespace\s+([^;]+);/', $content, $nsMatches)) {
        $namespace = trim($nsMatches[1]);
        if (preg_match('/class\s+(\w+)/', $content, $classMatches)) {
            $className = $namespace . '\\' . $classMatches[1];
            echo "  → Class detected: $className\n";
        }
    }
    if (!$className) {
        $className = pathinfo($target, PATHINFO_FILENAME);
        echo "  → Fallback: $className\n";
    }
} else {
    $className = $target;
    if (!str_contains($className, '\\')) {
        $paths = [
            $baseDir . '/src/RapidBase/Core/' . $className . '.php',
            $baseDir . '/src/' . $className . '.php',
            getcwd() . '/' . $className . '.php',
        ];
        foreach ($paths as $path) {
            if (file_exists($path)) {
                $fileLocation = realpath($path);
                echo "  → Found by name: $fileLocation\n";
                $content = file_get_contents($fileLocation);
                if (preg_match('/namespace\s+([^;]+);/', $content, $nsMatches)) {
                    $className = trim($nsMatches[1]) . '\\' . $className;
                    echo "  → Resolved: $className\n";
                }
                break;
            }
        }
    }
    if (!class_exists($className, false)) {
        $path = str_replace('\\', '/', $className) . '.php';
        if (file_exists($baseDir . '/src/' . $path)) {
            $fileLocation = $baseDir . '/src/' . $path;
            echo "  → Found via autoloader: $fileLocation\n";
        }
    }
}

if (!$className) {
    echo "ERROR: Cannot resolve class from '$target'\n";
    exit(1);
}

if ($testsDir === null) {
    $registeredDir = findTestDir($db, $className);
    if ($registeredDir) {
        $testsDir = $registeredDir;
        echo "  → Tests dir from DB: $testsDir\n";
    }
}

if ($testsDir === null && !$options['generate']) {
    echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "  ℹ️  NO TESTS FOUND FOR: $className\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    echo "  Class located successfully, but no test suite is associated.\n\n";
    echo "  Generate tests with:\n";
    echo "    php bin/tdd-runner.php $target --generate\n\n";
    echo "  Or specify directory:\n";
    echo "    php bin/tdd-runner.php $target --tests tests/Unit/Core/" . basename(str_replace('\\', '/', $className)) . " --generate\n\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    exit(0);
}

if ($testsDir === null && $options['generate']) {
    $shortName = basename(str_replace('\\', '/', $className));
    $testsDir = $baseDir . '/tests/Unit/Core/' . $shortName;
    echo "  → Default test dir: $testsDir\n";
}

echo "\nTarget: $className\n";
if ($fileLocation) echo "Location: $fileLocation\n";
echo "Tests Dir: $testsDir\n";
echo "Drivers: " . implode(', ', $options['drivers']) . "\n\n";

if ($options['generate']) {
    try {
        $reflection = new ReflectionClass($className);
        $testClassName = $reflection->getShortName() . 'Test';
        $testFilePath = $testsDir . '/' . $testClassName . '.php';
        
        if (file_exists($testFilePath)) {
            echo "WARNING: Test file exists: $testFilePath\n";
            exit(0);
        }

        echo "Generating skeleton for $className in $testFilePath...\n";
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
        $shortName = $reflection->getShortName();
        $namespace = substr($className, 0, strrpos($className, '\\'));
        
        $code = "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$namespace};\n\nuse RapidBase\\Tdd\\TestCase;\n\n";
        $code .= "/**\n * Auto-generated Test Suite for {$shortName}\n */\n";
        $code .= "class {$testClassName} extends TestCase\n{\n";

        foreach ($methods as $method) {
            if ($method->isConstructor() || str_starts_with($method->getName(), '__')) continue;
            $methodName = $method->getName();
            $testMethodName = 'test' . ucfirst(preg_replace('/[^a-zA-Z0-9_]/', '_', $methodName));
            $code .= "    public function {$testMethodName}(): void\n";
            $code .= "    {\n";
            $code .= "        \$this->env()->test('should verify {$methodName}', function(\$db) {\n";
            $code .= "            \$this->assertTrue(true);\n";
            $code .= "        });\n";
            $code .= "    }\n\n";
        }
        $code .= "}\n";

        if (!is_dir($testsDir)) mkdir($testsDir, 0755, true);
        file_put_contents($testFilePath, $code);
        
        // Registrar en BD
        registerTestDir($db, $className, $testsDir);
        
        echo "SUCCESS: Skeleton generated at $testFilePath\n";
        echo "Registered in database. Next time just run:\n";
        echo "  php bin/tdd-runner.php $className\n";
    } catch (Throwable $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
        exit(1);
    }
    exit(0);
}

// Ejecución
$shortName = basename(str_replace('\\', '/', $className));
$testFile = $testsDir . '/' . $shortName . 'Test.php';
if (file_exists($testFile)) require_once $testFile;

if (!class_exists($className . 'Test')) {
    echo "ERROR: Test class '{$className}Test' not found.\n";
    echo "Generate it: php bin/tdd-runner.php $className --generate\n";
    exit(1);
}

try {
    $runner = new CoreRunner($className, $testsDir);
    $runner->setDrivers($options['drivers']);
    $runner->stopOnFirst($options['first']);
    $runner->verbose($options['verbose']);
    if ($options['html']) $runner->generateHtmlReport();
    $success = $runner->run();
    exit($success ? 0 : 1);
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
