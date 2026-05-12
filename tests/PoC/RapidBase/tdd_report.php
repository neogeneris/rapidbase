<?php
/**
 * TDD Report Generator - Genera reportes visuales y estadísticas de pruebas
 * 
 * Uso: php tdd_report.php [--json] [--limit N]
 */

declare(strict_types=1);

require_once __DIR__ . '/Tdd/Runner.php';

use RapidBase\Tdd\Runner;

class TddReport {
    private Runner $runner;
    private \PDO $db;

    public function __construct() {
        $this->runner = new Runner(__DIR__ . '/rapidbase_tdd.sqlite');
        $this->db = $this->getReflectionProperty($this->runner, 'db');
    }

    private function getReflectionProperty($object, string $property) {
        $reflection = new ReflectionClass($object);
        $prop = $reflection->getProperty($property);
        $prop->setAccessible(true);
        return $prop->getValue($object);
    }

    public function generate(bool $json = false, int $limit = 50): void {
        $stats = $this->getStats($limit);
        
        if ($json) {
            echo json_encode($stats, JSON_PRETTY_PRINT) . PHP_EOL;
            return;
        }

        $this->renderConsole($stats, $limit);
    }

    private function getStats(int $limit): array {
        // Totales generales
        $totalStmt = $this->db->query("SELECT COUNT(*) FROM test_history");
        $total = (int)$totalStmt->fetchColumn();

        $passStmt = $this->db->query("SELECT COUNT(*) FROM test_history WHERE status = 'PASS'");
        $pass = (int)$passStmt->fetchColumn();

        $failStmt = $this->db->query("SELECT COUNT(*) FROM test_history WHERE status = 'FAIL'");
        $fail = (int)$failStmt->fetchColumn();

        $passRate = $total > 0 ? round(($pass / $total) * 100, 2) : 0;

        // Últimos resultados
        $historyStmt = $this->db->prepare(
            "SELECT test_identifier, status, error_message, execution_time, created_at 
             FROM test_history 
             ORDER BY id DESC 
             LIMIT ?"
        );
        $historyStmt->execute([$limit]);
        $history = $historyStmt->fetchAll(\PDO::FETCH_ASSOC);

        // Tests fallidos actuales (última ejecución de cada test)
        $failingStmt = $this->db->query(
            "SELECT h1.test_identifier, h1.error_message, h1.execution_time, h1.created_at
             FROM test_history h1
             INNER JOIN (
                 SELECT test_identifier, MAX(id) as max_id
                 FROM test_history
                 GROUP BY test_identifier
             ) h2 ON h1.id = h2.max_id
             WHERE h1.status = 'FAIL'"
        );
        $failingTests = $failingStmt->fetchAll(\PDO::FETCH_ASSOC);

        // Top 5 más lentos (promedio por test)
        $slowStmt = $this->db->query(
            "SELECT test_identifier, AVG(execution_time) as avg_time, COUNT(*) as runs
             FROM test_history
             GROUP BY test_identifier
             ORDER BY avg_time DESC
             LIMIT 5"
        );
        $slowest = $slowStmt->fetchAll(\PDO::FETCH_ASSOC);

        // Tendencia (últimas 10 ejecuciones agrupadas)
        $trendStmt = $this->db->query(
            "SELECT status, COUNT(*) as count
             FROM (
                 SELECT status FROM test_history ORDER BY id DESC LIMIT 10
             ) recent
             GROUP BY status"
        );
        $trend = $trendStmt->fetchAll(\PDO::FETCH_KEY_PAIR);

        // Estado del sistema
        $dbSize = filesize(__DIR__ . '/rapidbase_tdd.sqlite');
        $statsFile = __DIR__ . '/../autoloader_stats.dat';
        $statsExists = file_exists($statsFile);
        $statsSize = $statsExists ? filesize($statsFile) : 0;

        return [
            'summary' => [
                'total_tests' => $total,
                'passed' => $pass,
                'failed' => $fail,
                'pass_rate' => $passRate,
                'unique_tests' => count(array_unique(array_column($history, 'test_identifier')))
            ],
            'failing_tests' => $failingTests,
            'slowest_tests' => $slowest,
            'trend' => $trend,
            'recent_history' => $history,
            'system' => [
                'db_size_bytes' => $dbSize,
                'stats_file_exists' => $statsExists,
                'stats_file_size' => $statsSize
            ]
        ];
    }

    private function renderConsole(array $stats, int $limit): void {
        $s = $stats['summary'];
        
        echo "\n";
        echo "______________________________________________________________________\n";
        echo "|           RAPIDBASE TDD REPORT DASHBOARD                 |\n";
        echo "|______________________________________________________________|\n";
        
        // Métricas clave
        $statusColor = $s['pass_rate'] >= 90 ? '✓' : ($s['pass_rate'] >= 70 ? '⚠' : '✗');
        printf("|  %-15s %s%% %-30s |\n", "Pass Rate:", number_format($s['pass_rate'], 2), $statusColor);
        printf("|  %-15s %-37s |\n", "Total Tests:", $s['total_tests']);
        printf("|  %-15s %-37s |\n", "Passed:", $s['passed']);
        printf("|  %-15s %-37s |\n", "Failed:", $s['failed']);
        printf("|  %-15s %-37s |\n", "Unique Tests:", $s['unique_tests']);
        
        echo "|______________________________________________________________|\n";
        echo "|  TREND (Last 10 executions)                              |\n";
        echo "|______________________________________________________________|\n";
        
        $passTrend = $stats['trend']['PASS'] ?? 0;
        $failTrend = $stats['trend']['FAIL'] ?? 0;
        $barLength = 40;
        $passBar = (int)($passTrend / max(1, $passTrend + $failTrend) * $barLength);
        $failBar = $barLength - $passBar;
        
        echo "|  PASS: " . str_repeat('█', max(0, $passBar)) . str_repeat('░', max(0, $barLength - $passBar)) . " ($passTrend)\n";
        echo "|  FAIL: " . str_repeat('█', max(0, $failBar)) . str_repeat('░', max(0, $barLength - $failBar)) . " ($failTrend)\n";
        
        echo "|______________________________________________________________|\n";
        echo "|  RECENT HISTORY (Last {$limit} entries)                  |\n";
        echo "|______________________________________________________________|\n";
        
        foreach (array_slice($stats['recent_history'], 0, 10) as $entry) {
            $icon = $entry['status'] === 'PASS' ? '✓' : '✗';
            $color = $entry['status'] === 'PASS' ? '' : ' [ERROR: ' . substr($entry['error_message'], 0, 30) . ']';
            $time = number_format($entry['execution_time'] * 1000, 2);
            printf("|  %s %-25s %sms%-20s |\n", 
                $icon, 
                substr($entry['test_identifier'], 0, 25),
                $time,
                substr($color, 0, 20)
            );
        }
        
        if (!empty($stats['slowest_tests'])) {
            echo "|______________________________________________________________|\n";
            echo "|  TOP 5 SLOWEST TESTS (avg time)                          |\n";
            echo "|______________________________________________________________|\n";
            
            foreach ($stats['slowest_tests'] as $i => $test) {
                $time = number_format($test['avg_time'] * 1000, 2);
                printf("|  %d. %-30s %sms (%d runs)              |\n", 
                    $i + 1,
                    substr($test['test_identifier'], 0, 30),
                    $time,
                    $test['runs']
                );
            }
        }
        
        echo "|______________________________________________________________|\n";
        echo "|  SYSTEM STATUS                                           |\n";
        echo "|______________________________________________________________|\n";
        printf("|  DB Size: %-10s KB                                     |\n", number_format($stats['system']['db_size_bytes'] / 1024, 2));
        $statsStatus = $stats['system']['stats_file_exists'] ? '✓ Found' : '✗ Not found';
        printf("|  Autoloader Stats: %-27s |\n", $statsStatus);
        
        echo "|______________________________________________________________|\n";
        echo "\n";
    }
}

// Parsear argumentos
$json = in_array('--json', $argv);
$limit = 50;

if (($index = array_search('--limit', $argv)) !== false && isset($argv[$index + 1])) {
    $limit = (int)$argv[$index + 1];
}

try {
    $report = new TddReport();
    $report->generate($json, $limit);
} catch (\Exception $e) {
    fwrite(STDERR, "Error generating report: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
