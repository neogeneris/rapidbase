#!/usr/bin/env php
<?php

/**
 * TDD Runner CLI para RapidBase
 * 
 * Uso:
 *   php tdd-runner.php X                                    - Diagnóstico básico de X
 *   php tdd-runner.php X --tests /path/to/tests             - Genera/esquema o ejecuta tests
 *   php tdd-runner.php X --tests /path --generate           - Genera esqueleto XTest.php
 *   php tdd-runner.php /path/to/XTest.php                   - Ejecuta test específico
 * 
 * Opciones:
 *   --tests <dir>    Directorio donde están/generar los tests
 *   --generate       Generar esqueleto de test si no existe
 *   --verbose, -v    Salida detallada
 *   --help, -h       Mostrar ayuda
 */

require_once __DIR__ . '/TddRunner.php';

use RapidBase\Tdd\TddRunner;

// Parsear argumentos
$args = $argv;
array_shift($args); // Remover nombre del script

if (empty($args)) {
    showHelp();
    exit(1);
}

$options = [
    'class' => null,
    'tests_dir' => null,
    'generate' => false,
    'verbose' => false,
    'help' => false
];

$positionalArgs = [];

for ($i = 0; $i < count($args); $i++) {
    $arg = $args[$i];
    
    if ($arg === '--help' || $arg === '-h') {
        showHelp();
        exit(0);
    } elseif ($arg === '--verbose' || $arg === '-v') {
        $options['verbose'] = true;
    } elseif ($arg === '--generate') {
        $options['generate'] = true;
    } elseif ($arg === '--tests') {
        if (!isset($args[$i + 1])) {
            echo "Error: --tests requiere un directorio\n";
            exit(1);
        }
        $options['tests_dir'] = $args[++$i];
    } elseif (!str_starts_with($arg, '--')) {
        $positionalArgs[] = $arg;
    }
}

// Determinar modo de operación
$runner = new TddRunner();

if (!empty($positionalArgs)) {
    $target = $positionalArgs[0];
    
    // ¿Es un archivo de test?
    if (file_exists($target) && str_ends_with($target, '.php')) {
        // Ejecutar test específico
        echo "\nEjecutando test: $target\n";
        $results = $runner->runTestClass($target, $options['verbose']);
        $runner->printReport($results);
        exit($results['fail'] > 0 ? 1 : 0);
    }
    
    // Es un nombre de clase
    $className = $target;
    
    // Buscar el archivo de la clase
    $classPath = findClassFile($className);
    
    if (!$classPath) {
        echo "Error: No se encontró la clase '$className'\n";
        echo "Sugerencia: Asegúrate de que la clase esté en src/RapidBase/ o proporciona la ruta completa\n";
        exit(1);
    }
    
    // Extraer solo el nombre de la clase del path si es necesario
    if (str_contains($className, '/')) {
        $className = basename($className, '.php');
    }
    
    // Modo diagnóstico (sin --tests)
    if (!$options['tests_dir']) {
        echo "\nRealizando diagnóstico de la clase: $className\n";
        $diagnosis = $runner->diagnose($className, $classPath);
        $runner->printDiagnosis($diagnosis);
        
        // Sugerir generación si no hay tests
        echo "\nNo se especificó directorio de tests.\n";
        echo "Para generar esqueleto: php tdd-runner.php $className --tests /ruta/a/tests --generate\n";
        exit(0);
    }
    
    // Con --tests
    $testsDir = rtrim($options['tests_dir'], '/\\');
    $testFilePath = $testsDir . '/' . $className . 'Test.php';
    
    if ($options['generate'] || !file_exists($testFilePath)) {
        // Generar esqueleto
        echo "\nGenerando esqueleto de test para $className en $testsDir\n";
        
        try {
            $generatedPath = $runner->generateTestSkeleton($className, $classPath, $testsDir);
            echo "✓ Test generado: $generatedPath\n";
            echo "\nAhora puedes:\n";
            echo "  1. Editar $generatedPath y completar los tests\n";
            echo "  2. Ejecutar: php tdd-runner.php $generatedPath\n";
            exit(0);
        } catch (\Exception $e) {
            echo "Error al generar test: " . $e->getMessage() . "\n";
            exit(1);
        }
    } else {
        // Ejecutar tests existentes
        echo "\nEjecutando tests desde: $testFilePath\n";
        $results = $runner->runTestClass($testFilePath, $options['verbose']);
        $runner->printReport($results);
        exit($results['fail'] > 0 ? 1 : 0);
    }
} else {
    showHelp();
    exit(1);
}

/**
 * Busca el archivo de una clase en los directorios estándar
 */
function findClassFile(string $className): ?string {
    // Base path siempre es /workspace desde este script
    $basePath = dirname(__DIR__, 3);
    
    // Si parece una ruta, verificar directamente
    if (str_contains($className, '/') || str_contains($className, '\\')) {
        if (file_exists($className)) {
            return $className;
        }
        if (file_exists($basePath . '/' . $className)) {
            return $basePath . '/' . $className;
        }
    }
    
    // Intentar en src/RapidBase con rutas comunes
    $pathsToTry = [
        $basePath . '/src/RapidBase/Core/' . $className . '.php',
        $basePath . '/src/RapidBase/Api/' . $className . '.php',
        $basePath . '/src/RapidBase/Models/' . $className . '.php',
        $basePath . '/src/RapidBase/Endpoints/' . $className . '.php',
        $basePath . '/src/RapidBase/Tdd/' . $className . '.php',
    ];
    
    foreach ($pathsToTry as $path) {
        if (file_exists($path)) {
            return $path;
        }
    }
    
    // Buscar recursivamente en src/RapidBase
    $srcPath = $basePath . '/src/RapidBase';
    if (is_dir($srcPath)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcPath)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getBasename('.php') === $className) {
                return $file->getPathname();
            }
        }
    }
    
    return null;
}

/**
 * Muestra ayuda
 */
function showHelp(): void {
    echo "\n";
    echo "======================================================================\n";
    echo "              RAPIDBASE TDD RUNNER                                  \n";
    echo "======================================================================\n";
    echo "\n";
    echo "Uso:\n";
    echo "  php tdd-runner.php <clase|archivo> [opciones]\n";
    echo "\n";
    echo "Ejemplos:\n";
    echo "  php tdd-runner.php X                                    # Diagnóstico de X\n";
    echo "  php tdd-runner.php X --tests ./tests/Unit/Core/X        # Tests de X\n";
    echo "  php tdd-runner.php X --tests ./tests --generate         # Generar esqueleto\n";
    echo "  php tdd-runner.php ./tests/Unit/Core/X/XTest.php        # Ejecutar test\n";
    echo "\n";
    echo "Opciones:\n";
    echo "  --tests <dir>    Directorio donde están/generar los tests\n";
    echo "  --generate       Forzar generación de esqueleto de test\n";
    echo "  --verbose, -v    Salida detallada\n";
    echo "  --help, -h       Mostrar esta ayuda\n";
    echo "\n";
    echo "Características:\n";
    echo "  - Diagnóstico automático si no hay tests\n";
    echo "  - Generación 1:1 (método -> testMétodo)\n";
    echo "  - Verificación de sintaxis, namespace e interfaces\n";
    echo "  - Sin runners especializados\n";
    echo "\n";
    echo "======================================================================\n";
    echo "\n";
}
