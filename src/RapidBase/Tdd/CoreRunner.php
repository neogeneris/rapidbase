<?php

declare(strict_types=1);

namespace RapidBase\Tdd;

use PDO;
use ReflectionClass;
use ReflectionFunction;
use Throwable;

class CoreRunner
{
    private $dbPath;
    private $baseDir;
    private $db = null;
    private array $runtimeResults = [];
    private array $configuredDrivers = ['sqlite'];
    private bool $stopOnFirstFail = false;
    private bool $verbose = false;
    private $htmlReportPath = null;

    public function __construct(string $dbPath, string $baseDir)
    {
        $this->dbPath = $dbPath;
        $this->baseDir = $baseDir;
        $this->initDb();
    }

    private function initDb(): void
    {
        $this->db = new PDO('sqlite:' . $this->dbPath);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->db->exec("CREATE TABLE IF NOT EXISTS test_results (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            category TEXT, class TEXT, method TEXT, driver TEXT,
            status TEXT, error TEXT, duration REAL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $this->db->exec("CREATE TABLE IF NOT EXISTS class_test_mapping (
            class_name TEXT PRIMARY KEY,
            test_directory TEXT,
            last_updated DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    }

    public function registerTestClass(string $className, string $testDir): void
    {
        if (!$this->db) return;
        $stmt = $this->db->prepare("INSERT OR REPLACE INTO class_test_mapping (class_name, test_directory, last_updated) VALUES (:class, :dir, DATETIME('now'))");
        $stmt->execute([':class' => $className, ':dir' => $testDir]);
    }

    public function getTestDirectoryForClass(string $className): ?string
    {
        if (!$this->db) return null;
        $stmt = $this->db->prepare("SELECT test_directory FROM class_test_mapping WHERE class_name = :class");
        $stmt->execute([':class' => $className]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        return $res ? $res['test_directory'] : null;
    }

    public function setDrivers(array $drivers): void { $this->configuredDrivers = $drivers; }
    public function getDrivers(): array { return $this->configuredDrivers; }
    public function stopOnFirst(bool $stop): void { $this->stopOnFirstFail = $stop; }
    public function shouldStopOnFirstFail(): bool { return $this->stopOnFirstFail; }
    public function verbose(bool $v = true): void { $this->verbose = $v; }
    public function isVerbose(): bool { return $this->verbose; }
    public function generateHtmlReport(?string $path = null): void { $this->htmlReportPath = $path ?? $this->baseDir . '/report-tdd.html'; }

    public function recordRuntimeResult(array $result): void
    {
        $this->runtimeResults[] = $result;
        try {
            $stmt = $this->db->prepare("INSERT INTO test_results (category, class, method, driver, status, error, duration) VALUES (:cat, :cls, :met, :drv, :stat, :err, :dur)");
            $stmt->execute([
                ':cat' => $result['category'] ?? 'Unit',
                ':cls' => $result['class'],
                ':met' => $result['method'],
                ':drv' => $result['driver'],
                ':stat' => $result['status'],
                ':err' => $result['error'] ?? '',
                ':dur' => $result['duration'] ?? 0
            ]);
        } catch (Throwable $t) {}
    }

    public function printImmediateFailure(string $displayName, Throwable $e): void
    {
        echo "\n" . str_repeat('-', 70) . "\n  FAILURE DETECTED\n" . str_repeat('-', 70) . "\n";
        echo "  Test: {$displayName}\n  Error: {$e->getMessage()}\n  File: {$e->getFile()} (Line {$e->getLine()})\n";
        $this->showCodeSnippet($e->getFile(), $e->getLine(), 'ERROR LOCATION');
        echo str_repeat('=', 70) . "\n\n";
    }

    private function showCodeSnippet(string $file, int $lineNumber, string $label): void
    {
        if (!file_exists($file)) return;
        $lines = file($file);
        $start = max(0, $lineNumber - 5);
        $end = min(count($lines), $lineNumber + 4);
        echo "\n  " . str_repeat('-', 60) . "\n  {$label}:\n  " . str_repeat('-', 60) . "\n";
        for ($i = $start; $i < $end; $i++) {
            $num = $i + 1;
            $content = rtrim($lines[$i]);
            $marker = ($num == $lineNumber) ? ' >>> ' : '     ';
            $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $content);
            echo "  {$marker}Line {$num}: {$content}\n";
        }
        echo "  " . str_repeat('-', 60) . "\n";
    }

    public function runTargetClass(string $targetClass): bool
    {
        $testClass = $targetClass . 'Test';
        if (!class_exists($testClass) && class_exists($targetClass) && str_ends_with($targetClass, 'Test')) {
            $testClass = $targetClass;
        }

        if (!class_exists($testClass)) {
            echo "ERROR: Test class '$testClass' not found.\n";
            return false;
        }

        $reflection = new ReflectionClass($testClass);
        $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);
        
        try {
            $instance = $reflection->newInstance();
        } catch (Throwable $e) {
            echo "ERROR: Cannot instantiate test class: " . $e->getMessage() . "\n";
            return false;
        }

        if (method_exists($instance, 'setRunnerContext')) $instance->setRunnerContext($this);

        echo "\n" . str_repeat('=', 70) . "\n              RAPIDBASE TDD TEST REPORT                    \n" . str_repeat('=', 70) . "\n";

        foreach ($methods as $method) {
            if (str_starts_with($method->getName(), 'test')) {
                try {
                    if (method_exists($instance, 'setUp')) $instance->setUp();
                    $method->invoke($instance);
                    if (method_exists($instance, 'tearDown')) $instance->tearDown();
                } catch (StopSuiteExecutionException $e) {
                    goto end_report;
                } catch (Throwable $e) {
                    $this->recordRuntimeResult([
                        'category' => 'Unit', 'class' => $testClass, 'method' => $method->getName(),
                        'driver' => $this->configuredDrivers[0] ?? 'none', 'status' => 'FAIL',
                        'duration' => 0, 'error' => $e->getMessage()
                    ]);
                    $this->printImmediateFailure($method->getName() . ' (Catastrophic)', $e);
                    if ($this->stopOnFirstFail) goto end_report;
                }
            }
        }

        end_report:
        return $this->printFinalConsoleSummary();
    }

    private function printFinalConsoleSummary(): bool
    {
        $total = count($this->runtimeResults);
        $passes = count(array_filter($this->runtimeResults, fn($r) => $r['status'] === 'PASS'));
        $fails = $total - $passes;

        echo "\n" . str_repeat('=', 70) . "\n";
        printf("  Total Environments: %-4d  Passed: %-4d  Failed: %-4d\n", $total, $passes, $fails);
        echo str_repeat('-', 70) . "\n";
        
        if ($fails === 0) echo "  All tests passed successfully!\n";
        else echo "  {$fails} test(s) failed.\n";

        foreach ($this->runtimeResults as $res) {
            $statusLabel = $res['status'] === 'PASS' ? '[SUCCESS]' : '[FAILURE]';
            $short = (new ReflectionClass($res['class']))->getShortName();
            printf("  %s %s::%s (%s) [%sms]\n", $statusLabel, $short, $res['method'], $res['driver'], $res['duration']);
        }
        echo str_repeat('=', 70) . "\n";

        if ($this->htmlReportPath) {
            $this->saveHtmlReport();
            echo "\n  HTML Report generated: {$this->htmlReportPath}\n";
        }
        return $fails === 0;
    }

    private function saveHtmlReport(): void
    {
        if (!$this->htmlReportPath) return;
        file_put_contents($this->htmlReportPath, $this->buildHtmlContent());
    }

    private function buildHtmlContent(): string
    {
        $successCount = count(array_filter($this->runtimeResults, fn($r) => $r['status'] === 'PASS'));
        $failCount = count($this->runtimeResults) - $successCount;
        $totalCount = count($this->runtimeResults);
        $date = date('Y-m-d H:i:s');
        $classShort = !empty($this->runtimeResults) ? (new ReflectionClass($this->runtimeResults[0]['class']))->getShortName() : 'Unknown';

        $cards = '';
        foreach ($this->runtimeResults as $res) {
            $statusClass = strtolower($res['status']);
            $codeSnippet = $this->extractTestCode($res);
            $msg = $res['error'] ? '❌ ' . htmlspecialchars($res['error']) : '✅ Assertion passed';
            
            $cards .= '<div class="test-card ' . $statusClass . '">';
            $cards .= '<div class="test-header"><div><div class="test-title">' . htmlspecialchars($res['method']) . '</div>';
            $cards .= '<div class="test-meta"><span class="env-tag">' . $res['driver'] . '</span><span>' . $res['duration'] . 'ms</span></div></div>';
            $cards .= '<span class="badge ' . $statusClass . '">' . $res['status'] . '</span></div>';
            $cards .= '<div class="code-block"><code>' . htmlspecialchars($codeSnippet) . '</code></div>';
            $cards .= '<div class="result-box">' . $msg . '</div></div>';
        }

        return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>RapidBase TDD Report - ' . $classShort . '</title>';
        // ... (Estilos CSS simplificados para brevedad, usa el anterior si necesitas el diseño completo)
        return $this->getFullHtml($classShort, $date, $successCount, $failCount, $totalCount, $cards);
    }

    private function getFullHtml($class, $date, $s, $f, $t, $cards) {
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>TDD Report - $class</title>
<style>body{font-family:sans-serif;background:#f8fafc;padding:2rem}.test-card{background:white;margin:1rem;padding:1rem;border-left:4px solid green}.test-card.failure{border-left-color:red}.code-block{background:#1e293b;color:#fff;padding:1rem;overflow:auto}</style>
</head><body><h1>Report: $class</h1><p>Date: $date</p><p>Passed: $s | Failed: $f | Total: $t</p>$cards</body></html>
HTML;
    }

    private function extractTestCode(array $res): string
    {
        if (isset($res['callback']) && $res['callback'] instanceof \Closure) {
            try {
                $ref = new ReflectionFunction($res['callback']);
                $lines = file($ref->getFileName());
                return trim(implode("", array_slice($lines, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1)));
            } catch (Throwable $e) { return '// Extract failed'; }
        }
        return '// No snippet';
    }
}