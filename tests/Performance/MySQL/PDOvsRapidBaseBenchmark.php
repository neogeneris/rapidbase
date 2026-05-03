<?php
/**
 * MySQL Performance Benchmark: PDO Puro vs RapidBase
 * 
 * Prueba comparativa de rendimiento entre PDO nativo (baseline 1x) y RapidBase.
 * Mide operaciones CRUD básicas y consultas complejas en MySQL/MariaDB.
 * 
 * @author RapidBase Team
 * @version 1.0
 */

require_once __DIR__ . "/../../../vendor/autoload.php";
require_once __DIR__ . "/config.php";

use RapidBase\Core\DB;
use RapidBase\Core\Conn;

class MySQLPerformanceTest {
    
    private array $results = [];
    private \PDO $pdo;
    
    // Configuración de pruebas
    private const ITERATIONS_SIMPLE = 100;
    private const ITERATIONS_COMPLEX = 50;
    private const TEST_DATA_COUNT = 1000;
    
    public function run(): void {
        echo "╔══════════════════════════════════════════════════════════════╗\n";
        echo "║     MySQL Performance: PDO Puro vs RapidBase                 ║\n";
        echo "║     PDO Nativo = 1.00x (Baseline)                            ║\n";
        echo "╚══════════════════════════════════════════════════════════════╝\n\n";
        
        try {
            // Conectar y configurar
            MySQLConfig::setupRapidBase();
            $this->pdo = MySQLConfig::getPDO();
            echo "✓ Conectado a MySQL (Host: " . MySQLConfig::DB_HOST . ":" . MySQLConfig::DB_PORT . ")\n\n";
            
            // Preparar entorno
            $this->setupTables();
            $this->seedData();
            
            // Ejecutar benchmarks
            echo "🔥 Ejecutando benchmarks...\n\n";
            
            $this->benchmarkSimpleSelect();
            $this->benchmarkSelectWithWhere();
            $this->benchmarkSelectOrderByLimit();
            $this->benchmarkJoinQuery();
            $this->benchmarkInsertSingle();
            $this->benchmarkInsertBatch();
            $this->benchmarkUpdate();
            $this->benchmarkDelete();
            $this->benchmarkAggregation();
            $this->benchmarkComplexQuery();
            
            // Mostrar resultados
            $this->printResults();
            
        } catch (Exception $e) {
            echo "✗ Error: " . $e->getMessage() . "\n";
            echo $e->getTraceAsString() . "\n";
        } finally {
            $this->cleanup();
            Conn::close('main');
        }
    }
    
    private function setupTables(): void {
        echo "📦 Preparando tablas de prueba...\n";
        
        $this->pdo->exec("DROP TABLE IF EXISTS order_items");
        $this->pdo->exec("DROP TABLE IF EXISTS orders");
        $this->pdo->exec("DROP TABLE IF EXISTS products");
        $this->pdo->exec("DROP TABLE IF EXISTS categories");
        $this->pdo->exec("DROP TABLE IF EXISTS customers");
        
        // Tabla customers
        $this->pdo->exec(<<<SQL
            CREATE TABLE customers (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255) NOT NULL,
                city VARCHAR(100),
                credit_limit DECIMAL(10, 2),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
        
        // Tabla categories
        $this->pdo->exec(<<<SQL
            CREATE TABLE categories (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                description TEXT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
        
        // Tabla products
        $this->pdo->exec(<<<SQL
            CREATE TABLE products (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                category_id INTEGER,
                price DECIMAL(10, 2),
                stock INTEGER,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (category_id) REFERENCES categories(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
        
        // Tabla orders
        $this->pdo->exec(<<<SQL
            CREATE TABLE orders (
                id INT AUTO_INCREMENT PRIMARY KEY,
                customer_id INTEGER,
                status VARCHAR(50),
                total DECIMAL(12, 2),
                order_date TIMESTAMP,
                FOREIGN KEY (customer_id) REFERENCES customers(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
        
        // Tabla order_items
        $this->pdo->exec(<<<SQL
            CREATE TABLE order_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                order_id INTEGER,
                product_id INTEGER,
                quantity INTEGER,
                unit_price DECIMAL(10, 2),
                subtotal DECIMAL(12, 2),
                FOREIGN KEY (order_id) REFERENCES orders(id),
                FOREIGN KEY (product_id) REFERENCES products(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
        
        echo "✓ Tablas creadas\n\n";
    }
    
    private function seedData(): void {
        echo "📊 Generando datos de prueba (" . self::TEST_DATA_COUNT . " registros)...\n";
        
        $startTime = microtime(true);
        
        // Insertar categorías
        $categories = [];
        for ($i = 1; $i <= 50; $i++) {
            $categories[] = [
                'name' => "Category $i",
                'description' => "Description for category $i"
            ];
        }
        foreach ($categories as $cat) {
            DB::insert('categories', $cat);
        }
        
        // Insertar clientes
        $cities = ['New York', 'Los Angeles', 'Chicago', 'Houston', 'Phoenix'];
        for ($i = 1; $i <= self::TEST_DATA_COUNT; $i++) {
            DB::insert('customers', [
                'name' => "Customer $i",
                'email' => "customer$i@example.com",
                'city' => $cities[array_rand($cities)],
                'credit_limit' => rand(1000, 50000) / 100,
                'created_at' => date('Y-m-d H:i:s', strtotime("-" . rand(1, 365) . " days"))
            ]);
        }
        
        // Insertar productos
        for ($i = 1; $i <= self::TEST_DATA_COUNT; $i++) {
            DB::insert('products', [
                'name' => "Product $i",
                'category_id' => rand(1, 50),
                'price' => rand(10, 10000) / 100,
                'stock' => rand(0, 1000),
                'created_at' => date('Y-m-d H:i:s', strtotime("-" . rand(1, 365) . " days"))
            ]);
        }
        
        // Insertar órdenes
        $statuses = ['pending', 'processing', 'shipped', 'delivered', 'cancelled'];
        for ($i = 1; $i <= self::TEST_DATA_COUNT; $i++) {
            DB::insert('orders', [
                'customer_id' => rand(1, self::TEST_DATA_COUNT),
                'status' => $statuses[array_rand($statuses)],
                'total' => rand(100, 100000) / 100,
                'order_date' => date('Y-m-d H:i:s', strtotime("-" . rand(1, 365) . " days"))
            ]);
        }
        
        // Insertar order_items (3 items por orden)
        for ($i = 1; $i <= self::TEST_DATA_COUNT * 3; $i++) {
            $productId = rand(1, self::TEST_DATA_COUNT);
            $quantity = rand(1, 10);
            $unitPrice = rand(10, 10000) / 100;
            DB::insert('order_items', [
                'order_id' => (($i - 1) % self::TEST_DATA_COUNT) + 1,
                'product_id' => $productId,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'subtotal' => $quantity * $unitPrice
            ]);
        }
        
        $endTime = microtime(true);
        $duration = round(($endTime - $startTime) * 1000, 2);
        echo "✓ Datos generados en {$duration}ms\n\n";
    }
    
    private function cleanup(): void {
        MySQLConfig::cleanup();
    }
    
    /**
     * Medir tiempo de ejecución de una función
     */
    private function measure(callable $callback, int $iterations = 1): float {
        $times = [];
        
        for ($i = 0; $i < $iterations; $i++) {
            $startTime = microtime(true);
            $callback();
            $endTime = microtime(true);
            $times[] = ($endTime - $startTime) * 1000; // ms
        }
        
        return array_sum($times) / count($times);
    }
    
    private function benchmarkSimpleSelect(): void {
        echo "  → Benchmark: Simple SELECT (100 rows)...\n";
        
        // PDO Puro
        $pdoTime = $this->measure(function() {
            $stmt = $this->pdo->query("SELECT * FROM customers LIMIT 100");
            $stmt->fetchAll(PDO::FETCH_ASSOC);
        }, self::ITERATIONS_SIMPLE);
        
        // RapidBase
        $rbTime = $this->measure(function() {
            DB::many("SELECT * FROM customers LIMIT 100");
        }, self::ITERATIONS_SIMPLE);
        
        $this->results['Simple SELECT (100 rows)'] = ['PDO' => $pdoTime, 'RapidBase' => $rbTime];
    }
    
    private function benchmarkSelectWithWhere(): void {
        echo "  → Benchmark: SELECT with WHERE...\n";
        
        // PDO Puro
        $pdoTime = $this->measure(function() {
            $stmt = $this->pdo->prepare("SELECT * FROM customers WHERE city = ? AND credit_limit > ?");
            $stmt->execute(['New York', 100]);
            $stmt->fetchAll(PDO::FETCH_ASSOC);
        }, self::ITERATIONS_SIMPLE);
        
        // RapidBase
        $rbTime = $this->measure(function() {
            DB::many("SELECT * FROM customers WHERE city = ? AND credit_limit > ?", ['New York', 100]);
        }, self::ITERATIONS_SIMPLE);
        
        $this->results['SELECT with WHERE'] = ['PDO' => $pdoTime, 'RapidBase' => $rbTime];
    }
    
    private function benchmarkSelectOrderByLimit(): void {
        echo "  → Benchmark: SELECT ORDER BY LIMIT...\n";
        
        // PDO Puro
        $pdoTime = $this->measure(function() {
            $stmt = $this->pdo->query("SELECT * FROM products ORDER BY price DESC LIMIT 50");
            $stmt->fetchAll(PDO::FETCH_ASSOC);
        }, self::ITERATIONS_SIMPLE);
        
        // RapidBase
        $rbTime = $this->measure(function() {
            DB::many("SELECT * FROM products ORDER BY price DESC LIMIT 50");
        }, self::ITERATIONS_SIMPLE);
        
        $this->results['SELECT ORDER BY LIMIT'] = ['PDO' => $pdoTime, 'RapidBase' => $rbTime];
    }
    
    private function benchmarkJoinQuery(): void {
        echo "  → Benchmark: JOIN Query (2 tables)...\n";
        
        // PDO Puro
        $pdoTime = $this->measure(function() {
            $stmt = $this->pdo->prepare("
                SELECT p.name, p.price, c.name as category_name 
                FROM products p 
                INNER JOIN categories c ON p.category_id = c.id 
                WHERE p.price > ? 
                LIMIT 100
            ");
            $stmt->execute([50]);
            $stmt->fetchAll(PDO::FETCH_ASSOC);
        }, self::ITERATIONS_COMPLEX);
        
        // RapidBase
        $rbTime = $this->measure(function() {
            DB::many("
                SELECT p.name, p.price, c.name as category_name 
                FROM products p 
                INNER JOIN categories c ON p.category_id = c.id 
                WHERE p.price > ? 
                LIMIT 100
            ", [50]);
        }, self::ITERATIONS_COMPLEX);
        
        $this->results['JOIN Query (2 tables)'] = ['PDO' => $pdoTime, 'RapidBase' => $rbTime];
    }
    
    private function benchmarkInsertSingle(): void {
        echo "  → Benchmark: INSERT Single Row...\n";
        
        $testId = uniqid('test_');
        
        // PDO Puro
        $pdoTime = $this->measure(function() use ($testId) {
            static $counter = 0;
            $counter++;
            $stmt = $this->pdo->prepare("INSERT INTO customers (name, email, city, credit_limit) VALUES (?, ?, ?, ?)");
            $stmt->execute(["$testId $counter", "$testId$counter@test.com", 'Test City', 1000.00]);
            $this->pdo->exec("DELETE FROM customers WHERE name LIKE '$testId%'");
        }, self::ITERATIONS_SIMPLE);
        
        // RapidBase
        $rbTime = $this->measure(function() use ($testId) {
            static $counter = 0;
            $counter++;
            DB::insert('customers', [
                'name' => "$testId $counter",
                'email' => "$testId$counter@test.com",
                'city' => 'Test City',
                'credit_limit' => 1000.00
            ]);
            DB::exec("DELETE FROM customers WHERE name LIKE '$testId%'");
        }, self::ITERATIONS_SIMPLE);
        
        $this->results['INSERT Single Row'] = ['PDO' => $pdoTime, 'RapidBase' => $rbTime];
    }
    
    private function benchmarkInsertBatch(): void {
        echo "  → Benchmark: INSERT Batch (50 rows)...\n";
        
        $testId = uniqid('batch_');
        
        // PDO Puro
        $pdoTime = $this->measure(function() use ($testId) {
            $this->pdo->beginTransaction();
            for ($i = 0; $i < 50; $i++) {
                $stmt = $this->pdo->prepare("INSERT INTO customers (name, email, city, credit_limit) VALUES (?, ?, ?, ?)");
                $stmt->execute(["$testId $i", "$testId$i@test.com", 'Batch City', 500.00]);
            }
            $this->pdo->commit();
            $this->pdo->exec("DELETE FROM customers WHERE name LIKE '$testId%'");
        }, self::ITERATIONS_COMPLEX);
        
        // RapidBase
        $rbTime = $this->measure(function() use ($testId) {
            for ($i = 0; $i < 50; $i++) {
                DB::insert('customers', [
                    'name' => "$testId $i",
                    'email' => "$testId$i@test.com",
                    'city' => 'Batch City',
                    'credit_limit' => 500.00
                ]);
            }
            DB::exec("DELETE FROM customers WHERE name LIKE '$testId%'");
        }, self::ITERATIONS_COMPLEX);
        
        $this->results['INSERT Batch (50 rows)'] = ['PDO' => $pdoTime, 'RapidBase' => $rbTime];
    }
    
    private function benchmarkUpdate(): void {
        echo "  → Benchmark: UPDATE Single Row...\n";
        
        // PDO Puro
        $pdoTime = $this->measure(function() {
            $stmt = $this->pdo->prepare("UPDATE products SET price = ?, stock = ? WHERE id = 1");
            $stmt->execute([99.99, 100]);
        }, self::ITERATIONS_SIMPLE);
        
        // RapidBase
        $rbTime = $this->measure(function() {
            DB::update('products', ['price' => 99.99, 'stock' => 100], ['id' => 1]);
        }, self::ITERATIONS_SIMPLE);
        
        $this->results['UPDATE Single Row'] = ['PDO' => $pdoTime, 'RapidBase' => $rbTime];
    }
    
    private function benchmarkDelete(): void {
        echo "  → Benchmark: DELETE with WHERE...\n";
        
        // Primero insertamos datos de prueba
        for ($i = 0; $i < 10; $i++) {
            DB::insert('customers', [
                'name' => "ToDelete $i",
                'email' => "delete$i@test.com",
                'city' => 'Delete City',
                'credit_limit' => 100.00
            ]);
        }
        
        // PDO Puro
        $pdoTime = $this->measure(function() {
            $stmt = $this->pdo->prepare("DELETE FROM customers WHERE name LIKE ?");
            $stmt->execute(['ToDelete%']);
            // Re-insertar para siguiente iteración
            for ($i = 0; $i < 10; $i++) {
                $stmt = $this->pdo->prepare("INSERT INTO customers (name, email, city, credit_limit) VALUES (?, ?, ?, ?)");
                $stmt->execute(["ToDelete $i", "delete$i@test.com", 'Delete City', 100.00]);
            }
        }, self::ITERATIONS_SIMPLE);
        
        // RapidBase
        $rbTime = $this->measure(function() {
            DB::exec("DELETE FROM customers WHERE name LIKE ?", ['ToDelete%']);
            // Re-insertar para siguiente iteración
            for ($i = 0; $i < 10; $i++) {
                DB::insert('customers', [
                    'name' => "ToDelete $i",
                    'email' => "delete$i@test.com",
                    'city' => 'Delete City',
                    'credit_limit' => 100.00
                ]);
            }
        }, self::ITERATIONS_SIMPLE);
        
        // Limpiar datos de prueba
        DB::exec("DELETE FROM customers WHERE name LIKE 'ToDelete%'");
        
        $this->results['DELETE with WHERE'] = ['PDO' => $pdoTime, 'RapidBase' => $rbTime];
    }
    
    private function benchmarkAggregation(): void {
        echo "  → Benchmark: Aggregation (GROUP BY)...\n";
        
        // PDO Puro
        $pdoTime = $this->measure(function() {
            $stmt = $this->pdo->query("
                SELECT status, COUNT(*) as count, SUM(total) as total_sum 
                FROM orders 
                GROUP BY status
            ");
            $stmt->fetchAll(PDO::FETCH_ASSOC);
        }, self::ITERATIONS_COMPLEX);
        
        // RapidBase
        $rbTime = $this->measure(function() {
            DB::many("
                SELECT status, COUNT(*) as count, SUM(total) as total_sum 
                FROM orders 
                GROUP BY status
            ");
        }, self::ITERATIONS_COMPLEX);
        
        $this->results['Aggregation (GROUP BY)'] = ['PDO' => $pdoTime, 'RapidBase' => $rbTime];
    }
    
    private function benchmarkComplexQuery(): void {
        echo "  → Benchmark: Complex Query (3 JOINs + aggregation)...\n";
        
        // PDO Puro
        $pdoTime = $this->measure(function() {
            $stmt = $this->pdo->prepare("
                SELECT 
                    c.name as customer_name,
                    c.city,
                    COUNT(DISTINCT o.id) as order_count,
                    SUM(oi.quantity) as total_quantity,
                    SUM(oi.subtotal) as total_revenue
                FROM orders o
                JOIN customers c ON o.customer_id = c.id
                JOIN order_items oi ON o.id = oi.order_id
                WHERE o.status = 'delivered'
                GROUP BY c.name, c.city
                HAVING SUM(oi.subtotal) > 100
                ORDER BY total_revenue DESC
                LIMIT 50
            ");
            $stmt->execute();
            $stmt->fetchAll(PDO::FETCH_ASSOC);
        }, self::ITERATIONS_COMPLEX);
        
        // RapidBase
        $rbTime = $this->measure(function() {
            DB::many("
                SELECT 
                    c.name as customer_name,
                    c.city,
                    COUNT(DISTINCT o.id) as order_count,
                    SUM(oi.quantity) as total_quantity,
                    SUM(oi.subtotal) as total_revenue
                FROM orders o
                JOIN customers c ON o.customer_id = c.id
                JOIN order_items oi ON o.id = oi.order_id
                WHERE o.status = 'delivered'
                GROUP BY c.name, c.city
                HAVING SUM(oi.subtotal) > 100
                ORDER BY total_revenue DESC
                LIMIT 50
            ");
        }, self::ITERATIONS_COMPLEX);
        
        $this->results['Complex Query (3 JOINs + agg)'] = ['PDO' => $pdoTime, 'RapidBase' => $rbTime];
    }
    
    private function printResults(): void {
        echo "\n";
        echo "╔══════════════════════════════════════════════════════════════════════════╗\n";
        echo "║                         PERFORMANCE RESULTS                              ║\n";
        echo "╠══════════════════════════════════════════════════════════════════════════╣\n";
        echo "║  Test                              │ PDO (ms)  │ RB (ms)   │ Factor     ║\n";
        echo "╠────────────────────────────────────┼───────────┼───────────┼────────────╣\n";
        
        $factors = [];
        
        foreach ($this->results as $testName => $times) {
            $pdoMs = $times['PDO'];
            $rbMs = $times['RapidBase'];
            $factor = $rbMs / $pdoMs;
            $factors[] = $factor;
            
            $name = str_pad(substr($testName, 0, 36), 36);
            $pdoStr = str_pad(number_format($pdoMs, 4), 9);
            $rbStr = str_pad(number_format($rbMs, 4), 9);
            
            if ($factor <= 1.1) {
                $factorStr = sprintf("%8.2fx ✓", $factor);
            } elseif ($factor <= 1.5) {
                $factorStr = sprintf("%8.2fx ⚠", $factor);
            } else {
                $factorStr = sprintf("%8.2fx ✗", $factor);
            }
            
            echo "║  $name │ $pdoStr │ $rbStr │ $factorStr ║\n";
        }
        
        echo "╚══════════════════════════════════════════════════════════════════════════╝\n\n";
        
        // Estadísticas generales
        $avgFactor = array_sum($factors) / count($factors);
        $minFactor = min($factors);
        $maxFactor = max($factors);
        
        echo "📊 Summary Statistics:\n";
        echo "   - Total tests: " . count($this->results) . "\n";
        echo "   - Average factor: " . number_format($avgFactor, 2) . "x\n";
        echo "   - Best case: " . number_format($minFactor, 2) . "x\n";
        echo "   - Worst case: " . number_format($maxFactor, 2) . "x\n";
        echo "   - Overhead promedio: " . number_format(($avgFactor - 1) * 100, 2) . "%\n\n";
        
        // Interpretación
        echo "💡 Interpretación:\n";
        echo "   - Factor 1.0x-1.1x: overhead mínimo (excelente)\n";
        echo "   - Factor 1.1x-1.5x: overhead aceptable (bueno)\n";
        echo "   - Factor >1.5x: overhead significativo (mejorable)\n";
        echo "   - PDO = 1.0x es el baseline teórico mínimo\n\n";
        
        echo "✅ Benchmark completado exitosamente\n";
    }
}

// Ejecutar test
$test = new MySQLPerformanceTest();
$test->run();
