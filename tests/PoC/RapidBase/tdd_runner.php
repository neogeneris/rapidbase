#!/usr/bin/env php
<?php
/**
 * CLI Runner para el framework TDD de RapidBase
 * 
 * Uso:
 *   php tdd_runner.php --all              Ejecuta todas las pruebas
 *   php tdd_runner.php --first            Ejecuta hasta el primer fallo
 *   php tdd_runner.php --failing          Re-ejecuta solo las que fallaron
 *   php tdd_runner.php --stats            Muestra estadísticas
 *   php tdd_runner.php --history [limit]  Muestra historial
 *   php tdd_runner.php --scan             Escanea y lista endpoints disponibles
 */

require_once __DIR__ . '/Api/ApiContext.php';
require_once __DIR__ . '/Api/BaseEndpoint.php';
require_once __DIR__ . '/Tdd/Runner.php';

// Cargar endpoints existentes
foreach (glob(__DIR__ . '/Endpoints/*.php') as $file) {
    require_once $file;
}

$runner = new \RapidBase\Tdd\Runner(
    dbPath: 'rapidbase_tdd.sqlite',
    basePath: __DIR__
);

$args = $argv;
array_shift($args); // Remover nombre del script

if (empty($args)) {
    printHelp();
    exit(0);
}

$mode = $args[0] ?? '--all';
$verbose = in_array('-v', $args) || in_array('--verbose', $args);

// Verificar si el primer argumento es un nombre de endpoint (ej: ConnectionManager)
$endpoints = $runner->scanEndpoints();
$endpointNames = array_column($endpoints, 'name');

if (in_array($mode, $endpointNames)) {
    // Ejecutar solo las pruebas de este endpoint
    echo "Running tests for endpoint: {$mode}...\n\n";
    $results = $runner->runEndpoint($mode, verbose: $verbose);
    $runner->printReport($results);
    
    if ($results['fail'] > 0) {
        exit(1);
    }
    exit(0);
}

switch ($mode) {
    case '--all':
        echo "Running ALL tests...\n\n";
        $results = $runner->runAll(verbose: $verbose);
        $runner->printReport($results);
        
        if ($results['fail'] > 0) {
            exit(1);
        }
        break;
        
    case '--first':
        echo "Running tests (stop on first fail)...\n\n";
        $runner->setStopOnFirstFail(true);
        $results = $runner->runAll(verbose: true);
        $runner->printReport($results);
        
        if ($results['fail'] > 0) {
            exit(1);
        }
        break;
        
    case '--failing':
        echo "Re-running FAILED tests only...\n\n";
        $results = $runner->runFailingOnly(verbose: $verbose);
        
        if (empty($results['tests'])) {
            echo "No failing tests to re-run.\n";
        } else {
            $runner->printReport($results);
        }
        
        if ($results['fail'] > 0) {
            exit(1);
        }
        break;
        
    case '--stats':
        $stats = $runner->getStats();
        echo "\n=== TDD Statistics ===\n";
        echo "Total Tests:     {$stats['total']}\n";
        echo "Passing:         {$stats['pass']}\n";
        echo "Failing:         {$stats['fail']}\n";
        echo "Avg Exec Time:   " . round($stats['avg_time'] * 1000, 2) . "ms\n\n";
        break;
        
    case '--history':
        $limit = (int)($args[1] ?? 20);
        $history = $runner->getHistory($limit);
        
        echo "\n=== Recent Test History (last $limit) ===\n";
        printf("%-30s %-10s %-10s %s\n", "TEST", "STATUS", "TIME(ms)", "TIMESTAMP");
        echo str_repeat("-", 80) . "\n";
        
        foreach ($history as $row) {
            $statusColor = $row['status'] === 'PASS' ? "\033[32m" : "\033[31m";
            $reset = "\033[0m";
            $time = round($row['execution_time'] * 1000, 2);
            
            printf(
                "%-30s {$statusColor}%-10s{$reset} %-10s %s\n",
                $row['test_identifier'],
                $row['status'],
                $time,
                $row['created_at']
            );
        }
        echo "\n";
        break;
        
    case '--scan':
        echo "Scanning endpoints...\n\n";
        $endpoints = $runner->scanEndpoints();
        
        if (empty($endpoints)) {
            echo "No endpoints found in " . __DIR__ . "/Endpoints\n\n";
            exit(0);
        }
        
        foreach ($endpoints as $endpoint) {
            echo "[ENDPOINT] {$endpoint['name']}\n";
            echo "   File: {$endpoint['file']}\n";
            echo "   Class: {$endpoint['class']}\n";
            
            $methods = $runner->getTestableMethods($endpoint['class']);
            echo "   Methods (" . count($methods) . "):\n";
            
            foreach ($methods as $method) {
                echo "      - $method()\n";
            }
            echo "\n";
        }
        break;
        
    case '--help':
    case '-h':
        printHelp();
        break;
        
    default:
        echo "Unknown command: $mode\n\n";
        printHelp();
        exit(1);
}

function printHelp() {
    echo <<<HELP
    
RapidBase TDD Runner
====================

Usage: php tdd_runner.php [command] [options]

Commands:
  --all           Run all tests (default)
  --first         Run tests, stop at first failure
  --failing       Re-run only previously failed tests
  --stats         Show test statistics
  --history [n]   Show last n test results (default: 20)
  --scan          Scan and list all available endpoints
  --help, -h      Show this help message

Options:
  -v, --verbose   Show detailed output during test execution

Examples:
  php tdd_runner.php --all -v       # Run all tests with verbose output
  php tdd_runner.php --first        # Stop on first failure (TDD mode)
  php tdd_runner.php --failing      # Fix failing tests iteratively
  php tdd_runner.php --scan         # See what endpoints are available

HELP;
    echo "\n";
}
