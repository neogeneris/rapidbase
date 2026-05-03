<?php
/**
 * PostgreSQL Performance Comparison: RapidBase vs PDO
 * 
 * Compara el rendimiento de RapidBase contra PDO nativo
 * para determinar el overhead (multiplicador X) de RapidBase
 */

require_once __DIR__ . "/../../../vendor/autoload.php";
require_once __DIR__ . '/config.php';

use RapidBase\Core\DB;
use RapidBase\Core\Conn;

class RapidBaseVsPDOComparison {
    
    private array $results = [];
    private \PDO $pdo;
    
    public function run(): void {
        echo "╔══════════════════════════════════════════════════════════════╗\n";
        echo "║     RAPIDBASE vs PDO - PERFORMANCE COMPARISON TEST          ║\n";
        echo "╚══════════════════════════════════════════════════════════════╝\n\n";
        
        try {
            // Usar configuración centralizada
            PGConfig::printInfo();
            
            // Conectar a PostgreSQL usando config
            PGConfig::setupRapidBase();
            $this->pdo = PGConfig::getPDO();
            echo "✓ Conectado a PostgreSQL\n\n";
            
            // Limpiar y preparar tablas
            if (PGConfig::CLEANUP_BEFORE_TESTS) {
                $this->cleanup();
            }
            $this->createTables();
            
            // Ejecutar comparativas
            $this->compareInsertSingle();
            $this->compareInsertBatch();
            $this->compareSelectSimple();
            $this->compareSelectWhere();
            $this->compareSelectJoin();
            $this->compareUpdate();
            $this->compareDelete();
            $this->compareCount();
            $this->compareTransaction();
            
            // Mostrar resumen
            $this->printSummary();
            
        } catch (Exception $e) {
            echo "✗ Error: " . $e->getMessage() . "\n";
            echo $e->getTraceAsString() . "\n";
        } finally {
            $this->cleanup();
            Conn::close('main');
        }
    }
    
    private function cleanup(): void {
        try {
            $this->pdo->exec("DROP TABLE IF EXISTS test_records CASCADE");
            $this->pdo->exec("DROP TABLE IF EXISTS related_data CASCADE");
        } catch (Exception $e) {
            // Ignorar errores
        }
    }
    
    private function createTables(): void {
        $this->pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS test_records (
                id SERIAL PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255),
                value INTEGER,
                category VARCHAR(50),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        SQL);
    }
    
    private function measurePDO(string $name, callable $callback): array {
        return $this->measure('PDO', $name, $callback);
    }
    
    private function measureRapidBase(string $name, callable $callback): array {
        return $this->measure('RapidBase', $name, $callback);
    }
    
    private function measure(string $driver, string $name, callable $callback): array {
        $times = [];
        $result = null;
        
        for ($i = 0; $i < 5; $i++) {
            $startTime = microtime(true);
            $result = $callback();
            $endTime = microtime(true);
            $times[] = ($endTime - $startTime) * 1000;
        }
        
        $avg = array_sum($times) / count($times);
        
        return [
            'driver' => $driver,
            'name' => $name,
            'avg' => round($avg, 3),
            'min' => round(min($times), 3),
            'max' => round(max($times), 3),
            'result' => $result
        ];
    }
    
    private function printComparison(string $testName, array $pdoResult, array $rbResult): void {
        $multiplier = $rbResult['avg'] > 0 ? round($rbResult['avg'] / $pdoResult['avg'], 2) : 0;
        
        echo "┌─────────────────────────────────────────────────────────────┐\n";
        echo "│ TEST: $testName\n";
        echo "├─────────────────────────────────────────────────────────────┤\n";
        printf("│ %-12s │ %8s ms │ %8s ms │ %8s ms │\n", 
            'PDO', 
            $pdoResult['avg'],
            $pdoResult['min'],
            $pdoResult['max']
        );
        printf("│ %-12s │ %8s ms │ %8s ms │ %8s ms │\n", 
            'RapidBase', 
            $rbResult['avg'],
            $rbResult['min'],
            $rbResult['max']
        );
        echo "├─────────────────────────────────────────────────────────────┤\n";
        
        if ($multiplier >= 1) {
            printf("│ → RapidBase es %.2fx más lento que PDO\n", $multiplier);
        } else {
            printf("│ → RapidBase es %.2fx más rápido que PDO\n", 1 / $multiplier);
        }
        echo "└─────────────────────────────────────────────────────────────┘\n\n";
        
        $this->results[] = [
            'test' => $testName,
            'pdo_avg' => $pdoResult['avg'],
            'rb_avg' => $rbResult['avg'],
            'multiplier' => $multiplier
        ];
    }
    
    private function compareInsertSingle(): void {
        echo "=== Prueba 1: INSERT Individual ===\n";
        
        // PDO
        $pdoResult = $this->measurePDO('INSERT individual', function() {
            $stmt = $this->pdo->prepare("INSERT INTO test_records (name, email, value, category) VALUES (?, ?, ?, ?)");
            $stmt->execute(['John Doe', 'john@example.com', 100, 'A']);
            return $this->pdo->lastInsertId();
        });
        
        // RapidBase
        $rbResult = $this->measureRapidBase('INSERT individual', function() {
            return DB::insert('test_records', [
                'name' => 'John Doe',
                'email' => 'john@example.com',
                'value' => 100,
                'category' => 'A'
            ]);
        });
        
        $this->printComparison('INSERT Individual', $pdoResult, $rbResult);
    }
    
    private function compareInsertBatch(): void {
        echo "=== Prueba 2: INSERT Batch (100 registros) ===\n";
        
        $batchData = [];
        for ($i = 0; $i < 100; $i++) {
            $batchData[] = [
                'name' => "User $i",
                'email' => "user$i@example.com",
                'value' => rand(1, 1000),
                'category' => chr(65 + ($i % 5))
            ];
        }
        
        // PDO con transacción
        $pdoResult = $this->measurePDO('INSERT batch', function() use ($batchData) {
            $this->pdo->beginTransaction();
            $stmt = $this->pdo->prepare("INSERT INTO test_records (name, email, value, category) VALUES (?, ?, ?, ?)");
            foreach ($batchData as $row) {
                $stmt->execute([$row['name'], $row['email'], $row['value'], $row['category']]);
            }
            $this->pdo->commit();
            return 100;
        });
        
        // RapidBase con insert múltiple
        $rbResult = $this->measureRapidBase('INSERT batch', function() use ($batchData) {
            $ids = [];
            foreach ($batchData as $row) {
                $ids[] = DB::insert('test_records', $row);
            }
            return count($ids);
        });
        
        $this->printComparison('INSERT Batch (100)', $pdoResult, $rbResult);
    }
    
    private function compareSelectSimple(): void {
        echo "=== Prueba 3: SELECT Simple (sin WHERE) ===\n";
        
        // PDO
        $pdoResult = $this->measurePDO('SELECT simple', function() {
            $stmt = $this->pdo->query("SELECT * FROM test_records LIMIT 500");
            return count($stmt->fetchAll(\PDO::FETCH_ASSOC));
        });
        
        // RapidBase
        $rbResult = $this->measureRapidBase('SELECT simple', function() {
            return count(DB::many('SELECT * FROM test_records LIMIT 500'));
        });
        
        $this->printComparison('SELECT Simple', $pdoResult, $rbResult);
    }
    
    private function compareSelectWhere(): void {
        echo "=== Prueba 4: SELECT con WHERE ===\n";
        
        // PDO
        $pdoResult = $this->measurePDO('SELECT WHERE', function() {
            $stmt = $this->pdo->prepare("SELECT * FROM test_records WHERE category = ? AND value > ?");
            $stmt->execute(['A', 50]);
            return count($stmt->fetchAll(\PDO::FETCH_ASSOC));
        });
        
        // RapidBase
        $rbResult = $this->measureRapidBase('SELECT WHERE', function() {
            return count(DB::many('SELECT * FROM test_records WHERE category = ? AND value > ?', ['A', 50]));
        });
        
        $this->printComparison('SELECT con WHERE', $pdoResult, $rbResult);
    }
    
    private function compareSelectJoin(): void {
        echo "=== Prueba 5: SELECT con JOIN ===\n";
        
        // Crear segunda tabla para JOIN
        $this->pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS categories_test (
                id VARCHAR(10) PRIMARY KEY,
                name VARCHAR(100)
            )
        SQL);
        
        $this->pdo->exec("INSERT INTO categories_test VALUES ('A', 'Category A'), ('B', 'Category B'), ('C', 'Category C')");
        
        // PDO
        $pdoResult = $this->measurePDO('SELECT JOIN', function() {
            $stmt = $this->pdo->query("SELECT r.*, c.name as category_name FROM test_records r LEFT JOIN categories_test c ON r.category = c.id LIMIT 500");
            return count($stmt->fetchAll(\PDO::FETCH_ASSOC));
        });
        
        // RapidBase
        $rbResult = $this->measureRapidBase('SELECT JOIN', function() {
            return count(DB::many("SELECT r.*, c.name as category_name FROM test_records r LEFT JOIN categories_test c ON r.category = c.id LIMIT 500"));
        });
        
        $this->printComparison('SELECT con JOIN', $pdoResult, $rbResult);
        
        // Limpiar tabla temporal
        $this->pdo->exec("DROP TABLE IF EXISTS categories_test");
    }
    
    private function compareUpdate(): void {
        echo "=== Prueba 6: UPDATE ===\n";
        
        // PDO
        $pdoResult = $this->measurePDO('UPDATE', function() {
            $stmt = $this->pdo->prepare("UPDATE test_records SET value = ? WHERE category = ?");
            $stmt->execute([999, 'A']);
            return $stmt->rowCount();
        });
        
        // RapidBase
        $rbResult = $this->measureRapidBase('UPDATE', function() {
            return DB::update('test_records', ['value' => 999], ['category' => 'A']);
        });
        
        $this->printComparison('UPDATE', $pdoResult, $rbResult);
    }
    
    private function compareDelete(): void {
        echo "=== Prueba 7: DELETE ===\n";
        
        // Insertar datos para eliminar
        for ($i = 0; $i < 50; $i++) {
            DB::insert('test_records', ['name' => "ToDelete $i", 'email' => "del$i@test.com", 'value' => 1, 'category' => 'DEL']);
        }
        
        // PDO
        $pdoResult = $this->measurePDO('DELETE', function() {
            $stmt = $this->pdo->prepare("DELETE FROM test_records WHERE category = ?");
            $stmt->execute(['DEL']);
            return $stmt->rowCount();
        });
        
        // RapidBase
        $rbResult = $this->measureRapidBase('DELETE', function() {
            return DB::delete('test_records', ['category' => 'DEL']);
        });
        
        $this->printComparison('DELETE', $pdoResult, $rbResult);
    }
    
    private function compareCount(): void {
        echo "=== Prueba 8: COUNT ===\n";
        
        // PDO
        $pdoResult = $this->measurePDO('COUNT', function() {
            $stmt = $this->pdo->query("SELECT COUNT(*) FROM test_records");
            return $stmt->fetchColumn();
        });
        
        // RapidBase
        $rbResult = $this->measureRapidBase('COUNT', function() {
            return DB::value('SELECT COUNT(*) FROM test_records');
        });
        
        $this->printComparison('COUNT', $pdoResult, $rbResult);
    }
    
    private function compareTransaction(): void {
        echo "=== Prueba 9: Transacción (10 INSERTs) ===\n";
        
        // PDO
        $pdoResult = $this->measurePDO('Transacción', function() {
            $this->pdo->beginTransaction();
            $stmt = $this->pdo->prepare("INSERT INTO test_records (name, email, value, category) VALUES (?, ?, ?, ?)");
            for ($i = 0; $i < 10; $i++) {
                $stmt->execute(["Trans $i", "trans$i@test.com", $i, 'T']);
            }
            $this->pdo->commit();
            return 10;
        });
        
        // RapidBase
        $rbResult = $this->measureRapidBase('Transacción', function() {
            DB::transaction(function() {
                for ($i = 0; $i < 10; $i++) {
                    DB::insert('test_records', [
                        'name' => "Trans $i",
                        'email' => "trans$i@test.com",
                        'value' => $i,
                        'category' => 'T'
                    ]);
                }
            });
            return 10;
        });
        
        $this->printComparison('Transacción (10 INSERTs)', $pdoResult, $rbResult);
    }
    
    private function printSummary(): void {
        echo "\n";
        echo "╔══════════════════════════════════════════════════════════════════════════╗\n";
        echo "║                         PERFORMANCE SUMMARY                              ║\n";
        echo "╠══════════════════════════════════════════════════════════════════════════╣\n";
        echo "║ Test                          │ PDO (ms) │ RB (ms)  │ Multiplicador (X) ║\n";
        echo "╠───────────────────────────────┼──────────┼──────────┼───────────────────╣\n";
        
        $totalMultiplier = 0;
        foreach ($this->results as $result) {
            printf("║ %-29s │ %8.3f │ %8.3f │ %8.2fx          ║\n",
                substr($result['test'], 0, 29),
                $result['pdo_avg'],
                $result['rb_avg'],
                $result['multiplier']
            );
            $totalMultiplier += $result['multiplier'];
        }
        
        $avgMultiplier = count($this->results) > 0 ? $totalMultiplier / count($this->results) : 0;
        
        echo "╠───────────────────────────────┴──────────┴──────────┴───────────────────╣\n";
        printf("║ PROMEDIO GENERAL: %.2fx (RapidBase es %s que PDO)                       ║\n", 
            $avgMultiplier,
            $avgMultiplier > 1 ? 'más lento' : 'más rápido'
        );
        echo "╚══════════════════════════════════════════════════════════════════════════╝\n\n";
        
        echo "📊 INTERPRETACIÓN:\n";
        echo "   - 1.0x = Mismo rendimiento\n";
        echo "   - >1.0x = RapidBase es más lento (overhead)\n";
        echo "   - <1.0x = RapidBase es más rápido\n";
        echo "   - El overhead incluye: parsing, validación, cache, abstracción\n\n";
    }
}

// Ejecutar test
$test = new RapidBaseVsPDOComparison();
$test->run();
