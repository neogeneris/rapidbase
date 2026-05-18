<?php

declare(strict_types=1);

namespace RapidBase\Tdd;

use PDO;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use Throwable;

/**
 * CoreRunner - Ejecutor de pruebas unitarias para clases Core (X, Gateway, Q).
 * Soporta multi-ambiente, datasets en línea, fixtures SQL y reportes HTML.
 * 
 * Flujo moderno:
 * - El runner invoca el método del test UNA sola vez
 * - EnvironmentBuilder itera los drivers y registra resultados
 * - Se extrae el código del closure, no del método contenedor
 */
class CoreRunner
{
    private string $targetClass;
    private string $testsDir;
    private array $results = [];
    private string $currentDriver = 'sqlite';
    private array $drivers = ['sqlite'];
    private bool $stopOnFirst = false;
    private bool $verbose = false;
    private ?string $htmlReportPath = null;
    private int $assertionCount = 0;
    private array $connections = [];
    
    private const SUPPORTED_DRIVERS = ['sqlite', 'mysql', 'pgsql'];

    public function __construct(string $targetClass, string $testsDir)
    {
        $this->targetClass = $targetClass;
        $this->testsDir = rtrim($testsDir, '/\\');
        
        if (!is_dir($this->testsDir)) {
            mkdir($this->testsDir, 0755, true);
        }
    }

    public function setDrivers(array $drivers): self
    {
        $this->drivers = array_intersect($drivers, self::SUPPORTED_DRIVERS);
        if (empty($this->drivers)) {
            $this->drivers = ['sqlite'];
        }
        return $this;
    }

    public function stopOnFirst(bool $stop = true): self
    {
        $this->stopOnFirst = $stop;
        return $this;
    }

    public function verbose(bool $v = true): self
    {
        $this->verbose = $v;
        return $this;
    }

    public function generateHtmlReport(?string $path = null): self
    {
        $this->htmlReportPath = $path ?? $this->testsDir . '/report-tdd.html';
        return $this;
    }

    /**
     * Permite que EnvironmentBuilder registre resultados detallados.
     */
    public function recordResult(array $result): void
    {
        $this->results[] = $result;
    }

    public function getActiveDrivers(): array
    {
        return $this->drivers;
    }

    public function isVerbose(): bool
    {
        return $this->verbose;
    }

    public function shouldStopOnFirst(): bool
    {
        return $this->stopOnFirst;
    }

    public function incrementAssertionCount(): void
    {
        $this->assertionCount++;
    }

    public function getAssertionCount(): int
    {
        return $this->assertionCount;
    }

    public function getConnection(string $driver = 'sqlite'): PDO
    {
        $connectionId = "{$driver}_{$this->targetClass}";
        
        if (isset($this->connections[$connectionId])) {
            return $this->connections[$connectionId];
        }

        $pdo = match ($driver) {
            'sqlite' => new PDO('sqlite::memory:', null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]),
            'mysql' => new PDO(
                getenv('TDD_MYSQL_DSN') ?: 'mysql:host=localhost;dbname=test_db;charset=utf8mb4',
                getenv('TDD_MYSQL_USER') ?: 'root',
                getenv('TDD_MYSQL_PASS') ?: '',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            ),
            'pgsql' => new PDO(
                getenv('TDD_PGSQL_DSN') ?: 'pgsql:host=localhost;dbname=test_db',
                getenv('TDD_PGSQL_USER') ?: 'postgres',
                getenv('TDD_PGSQL_PASS') ?: '',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            ),
            default => throw new \InvalidArgumentException("Driver '$driver' not supported")
        };

        $this->connections[$connectionId] = $pdo;
        return $pdo;
    }

    public function closeConnections(): void
    {
        $this->connections = [];
    }

    public function insertDataset(array $data, string $table = 'test_data', ?string $driver = null): void
    {
        $driver = $driver ?? $this->currentDriver;
        $db = $this->getConnection($driver);

        if (empty($data)) {
            return;
        }

        $isAssociative = array_keys($data) !== range(0, count($data) - 1);
        $records = $isAssociative ? [$data] : $data;

        foreach ($records as $record) {
            $columns = array_keys($record);
            $placeholders = array_map(fn($col) => ":$col", $columns);
            
            $sql = sprintf(
                "INSERT INTO %s (%s) VALUES (%s)",
                $table,
                implode(', ', $columns),
                implode(', ', $placeholders)
            );

            $stmt = $db->prepare($sql);
            $stmt->execute($record);
        }
    }

    public function loadFixture(string $filePath, ?string $driver = null): void
    {
        $driver = $driver ?? $this->currentDriver;
        $db = $this->getConnection($driver);

        if (!file_exists($filePath)) {
            throw new \RuntimeException("Fixture file not found: $filePath");
        }

        $sql = file_get_contents($filePath);
        
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            fn($s) => !empty($s) && !str_starts_with(trim($s), '--')
        );

        foreach ($statements as $statement) {
            $db->exec($statement);
        }
    }

    private function hr(int $size = 70, string $char = '_'): void
    {
        echo str_repeat($char, $size) . "\n";
    }

    /**
     * Muestra un bloque de falla en consola.
     */
    public function printFailureBlock(string $displayName, Throwable $e): void
    {
        echo "\n";
        $this->hr(70, '-');
        echo "  FAILURE DETECTED\n";
        $this->hr(70, '-');
        echo "  Test: {$displayName}\n";
        echo "  Error: {$e->getMessage()}\n";
        echo "  File: {$e->getFile()} (Line {$e->getLine()})\n";
        
        $this->showCodeSnippet($e->getFile(), $e->getLine(), 'ERROR LOCATION');
        
        echo "\n  TIP: Fix code and run again.\n";
        $this->hr(70, '=');
        echo "\n";
    }

    /**
     * Muestra fragmento de código preservando UTF-8.
     */
    private function showCodeSnippet(string $file, int $lineNumber, string $label): void
    {
        if (!file_exists($file)) return;

        $lines = file($file);
        $start = max(0, $lineNumber - 5);
        $end = min(count($lines), $lineNumber + 4);
        
        echo "\n  ------------------------------------------------------------------\n";
        echo "  {$label}:\n";
        echo "  ------------------------------------------------------------------\n";
        
        for ($i = $start; $i < $end; $i++) {
            $num = $i + 1;
            $content = rtrim($lines[$i]);
            $marker = ($num == $lineNumber) ? ' >>> ' : '     ';
            // Preservar UTF-8 (tildes, eñes), solo remover caracteres de control
            $content = preg_replace('/[\x00-\x1F\x7F]/', '', $content);
            echo "  {$marker}Line {$num}: {$content}\n";
        }
        echo "  ------------------------------------------------------------------\n";
    }

    /**
     * Extrae el código fuente de un closure usando Reflexión.
     */
    private function extractCallbackCode(\Closure $callback): string
    {
        try {
            $ref = new ReflectionFunction($callback);
            $file = $ref->getFileName();
            $start = $ref->getStartLine();
            $end = $ref->getEndLine();
            
            $lines = file($file);
            $snippet = implode("", array_slice($lines, $start - 1, $end - $start + 1));
            
            return trim($snippet);
            
        } catch (Throwable $e) {
            return '// Could not extract callback code';
        }
    }

    private function escapeHtml(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Ejecuta todas las pruebas encontradas en la clase de test.
     * Ahora invoca cada método UNA sola vez, delegando la iteración de drivers a EnvironmentBuilder.
     */
    public function run(): bool
    {
        $testClass = $this->targetClass . 'Test';
        
        if (!class_exists($testClass)) {
            echo "ERROR: Test class '$testClass' not found in {$this->testsDir}\n";
            return false;
        }

        $reflection = new ReflectionClass($testClass);
        $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

        $totalMethodsEvaluated = 0;

        echo "\n";
        $this->hr(70, '=');
        echo "              RAPIDBASE TDD TEST REPORT                   \n";
        $this->hr(70, '=');

        foreach ($methods as $method) {
            if (str_starts_with($method->getName(), 'test')) {
                $totalMethodsEvaluated++;
                
                try {
                    $instance = new $testClass();
                    
                    // Inyectar contexto del runner
                    if (method_exists($instance, 'setRunnerContext')) {
                        $instance->setRunnerContext($this);
                    }

                    if (method_exists($instance, 'setUp')) {
                        $instance->setUp();
                    }

                    // Invocar UNA SOLA VEZ - EnvironmentBuilder itera los drivers internamente
                    $method->invoke($instance);

                    if (method_exists($instance, 'tearDown')) {
                        $instance->tearDown();
                    }

                } catch (StopTestExecutionException $e) {
                    // Captura el freno de mano del modo --first
                    goto end_report;
                    
                } catch (Throwable $e) {
                    // Captura fallas catastróficas del setUp o aserciones fuera de la estructura fluida
                    $testName = $method->getName();
                    $this->recordResult([
                        'name' => "{$testName} (Catastrophic)",
                        'method' => $testName,
                        'description' => 'Direct assertion failure',
                        'status' => 'FAILURE',
                        'duration' => 0,
                        'driver' => $this->drivers[0] ?? 'none',
                        'message' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    
                    $this->printFailureBlock($testName, $e);
                    
                    if ($this->stopOnFirst) {
                        goto end_report;
                    }
                }
            }
        }

        end_report:

        // Métricas calculadas desde resultados reales registrados
        $successCount = count(array_filter($this->results, fn($r) => $r['status'] === 'SUCCESS'));
        $failureCount = count($this->results) - $successCount;
        $totalTestsRun = count($this->results);

        echo "\n";
        $this->hr(70, '=');
        echo "  Métodos Evaluados: {$totalMethodsEvaluated}   Pruebas Totales: {$totalTestsRun}   Success: {$successCount}   Failure: {$failureCount}\n";
        $this->hr(70, '_');
        
        if ($failureCount === 0) {
            echo "  All tests passed successfully!\n";
        } else {
            echo "  {$failureCount} test(s) failed.\n";
        }
        $this->hr(70, '=');

        if ($this->htmlReportPath) {
            $this->saveHtmlReport();
            echo "\n  HTML Report generated: {$this->htmlReportPath}\n";
        }

        return $failureCount === 0;
    }

    private function saveHtmlReport(): void
    {
        $html = $this->buildHtmlContent();
        file_put_contents($this->htmlReportPath, $html);
    }

    private function buildHtmlContent(): string
    {
        $successCount = count(array_filter($this->results, fn($r) => $r['status'] === 'SUCCESS'));
        $failCount = count($this->results) - $successCount;
        $totalCount = count($this->results);
        
        $date = date('Y-m-d H:i:s');
        $classShort = substr($this->targetClass, strrpos($this->targetClass, '\\') + 1);

        $cards = '';
        foreach ($this->results as $res) {
            $statusClass = strtolower($res['status']);
            
            // Extraer código del closure si está disponible
            $codeSnippet = isset($res['callback']) && $res['callback'] instanceof \Closure
                ? $this->extractCallbackCode($res['callback'])
                : '// No code snippet available';
            
            $message = $res['message'] ? '❌ ' . $this->escapeHtml($res['message']) : '✅ Assertion passed';
            
            $cards .= <<<HTML
            <div class="test-card {$statusClass}">
                <div class="test-header">
                    <div>
                        <div class="test-title">{$this->escapeHtml($res['name'])}</div>
                        <div class="test-meta">
                            <span class="env-tag">{$this->escapeHtml($res['driver'])}</span>
                            <span>{$res['duration']}ms</span>
                        </div>
                    </div>
                    <span class="badge {$statusClass}">{$res['status']}</span>
                </div>
                <div class="code-block">
                    <code>{$this->escapeHtml($codeSnippet)}</code>
                </div>
                <div class="result-box">
                    {$message}
                </div>
            </div>
            HTML;
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>RapidBase TDD Report - {$classShort}</title>
    <style>
        :root { --success: #22c55e; --failure: #ef4444; --bg: #f8fafc; --code-bg: #1e293b; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: var(--bg); padding: 2rem; color: #334155; }
        .header { text-align: center; margin-bottom: 2rem; }
        .stats { display: flex; gap: 1rem; justify-content: center; margin-top: 1rem; }
        .badge { padding: 0.5rem 1rem; border-radius: 99px; font-weight: bold; color: white; }
        .badge.success { background: var(--success); }
        .badge.failure { background: var(--failure); }
        
        .test-card { background: white; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); margin-bottom: 1.5rem; overflow: hidden; border-left: 4px solid var(--success); }
        .test-card.failure { border-left-color: var(--failure); }
        
        .test-header { padding: 1rem; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; }
        .test-title { font-weight: 600; font-size: 1.1rem; }
        .test-meta { font-size: 0.85rem; color: #64748b; display: flex; gap: 0.5rem; align-items: center; }
        .env-tag { background: #e0f2fe; color: #0369a1; padding: 0.2rem 0.6rem; border-radius: 4px; font-size: 0.75rem; }
        
        .code-block { background: var(--code-bg); color: #e2e8f0; padding: 1rem; overflow-x: auto; font-family: 'Consolas', 'Monaco', monospace; font-size: 0.9rem; line-height: 1.5; margin: 0; }
        .code-block code { display: block; white-space: pre; }
        
        .result-box { padding: 1rem; background: #f0fdf4; border-top: 1px solid #bbf7d0; font-size: 0.9rem; }
        .failure .result-box { background: #fef2f2; border-top-color: #fecaca; color: #991b1b; }
    </style>
</head>
<body>
    <div class="header">
        <h1>RapidBase TDD Report</h1>
        <h2>Class: {$this->escapeHtml($this->targetClass)}</h2>
        <div class="stats">
            <span class="badge success">SUCCESS: {$successCount}</span>
            <span class="badge failure">FAILURE: {$failCount}</span>
            <span class="badge" style="background:#64748b">TOTAL: {$totalCount}</span>
        </div>
        <p style="margin-top:1rem; color:#64748b">Generated: {$date}</p>
    </div>

    {$cards}

</body>
</html>
HTML;
    }
}
