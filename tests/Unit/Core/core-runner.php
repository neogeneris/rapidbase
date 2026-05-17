#!/usr/bin/env php
<?php
/**
 * CLI Runner para pruebas unitarias del Core de RapidBase (X, Gateway, Q)
 * 
 * Uso:
 *   php core-runner.php --all              Ejecuta todas las pruebas
 *   php core-runner.php --first            Ejecuta hasta el primer fallo
 *   php core-runner.php --category X       Ejecuta solo pruebas de la categoría X
 *   php core-runner.php --failing          Re-ejecuta solo las que fallaron
 *   php core-runner.php --stats            Muestra estadísticas
 *   php core-runner.php --history [limit]  Muestra historial
 */

require_once __DIR__ . '/../../../src/RapidBase/Tdd/CoreRunner.php';

$runner = new \RapidBase\Tdd\CoreRunner(
    dbPath: __DIR__ . '/rapidbase_core_tdd.sqlite',
    basePath: __DIR__ . '/../../..'
);

$args = $argv;
array_shift($args); // Remover nombre del script

if (empty($args)) {
    printHelp();
    exit(0);
}

$mode = $args[0] ?? '--all';
$verbose = in_array('-v', $args) || in_array('--verbose', $args);

switch ($mode) {
    case '--all':
        echo "Running ALL Core tests...\n\n";
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
    
    case '--category':
    case '--cat':
        $category = $args[1] ?? null;
        if (!$category) {
            echo "ERROR: Category name required. Usage: php core-runner.php --category X\n";
            exit(1);
        }
        echo "Running tests for category: $category...\n\n";
        $results = $runner->runCategory($category, verbose: $verbose);
        $runner->printReport($results);
        
        if ($results['fail'] > 0) {
            exit(1);
        }
        break;
        
    case '--stats':
        $stats = $runner->getStats();
        echo "\n=== Core TDD Statistics ===\n";
        echo "Total Tests:     {$stats['total']}\n";
        echo "Passing:         {$stats['pass']}\n";
        echo "Failing:         {$stats['fail']}\n";
        echo "Avg Exec Time:   " . round($stats['avg_time'] * 1000, 2) . "ms\n\n";
        break;
        
    case '--history':
        $limit = (int)($args[1] ?? 20);
        $history = $runner->getHistory($limit);
        
        echo "\n=== Recent Test History (last $limit) ===\n";
        printf("%-40s %-10s %-10s %s\n", "TEST", "STATUS", "TIME(ms)", "TIMESTAMP");
        echo str_repeat("-", 90) . "\n";
        
        foreach ($history as $row) {
            $statusColor = $row['status'] === 'PASS' ? "\033[32m" : "\033[31m";
            $reset = "\033[0m";
            $time = round($row['execution_time'] * 1000, 2);
            
            printf(
                "%-40s {$statusColor}%-10s{$reset} %-10s %s\n",
                $row['test_identifier'],
                $row['status'],
                $time,
                $row['created_at']
            );
        }
        echo "\n";
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
    
RapidBase Core TDD Runner
=========================

Usage: php core-runner.php [command] [options]

Commands:
  --all                 Run all tests (default)
  --first               Run tests, stop at first failure
  --failing             Re-run only previously failed tests
  --category, --cat     Run tests for a specific category (X, Gateway, Q)
  --stats               Show test statistics
  --history [n]         Show last n test results (default: 20)
  --help, -h            Show this help message

Options:
  -v, --verbose         Show detailed output during test execution

Examples:
  php core-runner.php --all -v          # Run all tests with verbose output
  php core-runner.php --first           # Stop on first failure (TDD mode)
  php core-runner.php --category X      # Run only X class tests
  php core-runner.php --failing -v      # Fix failing tests iteratively

HELP;
    echo "\n";
}
