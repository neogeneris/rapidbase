<?php
/**
 * MySQL Performance Test for RapidBase
 * 
 * Pruebas de rendimiento progresivas:
 * 1. Consultas simples (INSERT, SELECT básico)
 * 2. Consultas con WHERE y ORDER BY
 * 3. JOINs entre tablas
 * 4. Agregaciones y GROUP BY
 * 5. Subconsultas y CTEs
 * 6. Operaciones masivas (bulk operations)
 */

namespace Tests\Performance\MySQL;

// Carga manual de dependencias de RapidBase y configuración
require_once __DIR__ . "/../../../vendor/autoload.php";
require_once __DIR__ . "/config.php";

use RapidBase\Core\DB;
use RapidBase\Core\Schema;
use RapidBase\Core\Conn;
use RapidBase\Core\Cache\CacheService;
use PDO;

class MySQLPerformanceTest {
    
    private array $results = [];
    private \PDO $pdo;
    
    public function run(): void {
        echo "=== MySQL Performance Test ===\n\n";
        
        try {
            // Conectar a MySQL usando configuración centralizada
            MySQLConfig::setupRapidBase();
            $this->pdo = MySQLConfig::getPDO();
            echo "✓ Conectado a MySQL (Host: " . MySQLConfig::DB_HOST . ":" . MySQLConfig::DB_PORT . ")\n\n";
            
            // Limpiar tablas si existen
            $this->cleanup();
            
            // Ejecutar pruebas progresivas
            $this->testSimpleInserts();
            $this->testSimpleSelects();
            $this->testWhereAndOrderBy();
            $this->testJoins();
            $this->testAggregations();
            $this->testSubqueriesAndCTEs();
            $this->testBulkOperations();
            $this->testComplexQueries();
            
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
        MySQLConfig::cleanup();
    }
    
    private function createTables(): void {
        // Tabla customers
        $this->pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS customers (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255) NOT NULL,
                city VARCHAR(100),
                credit_limit DECIMAL(10, 2),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
        
        // Tabla categories
        $this->pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS categories (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                description TEXT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
        
        // Tabla products
        $this->pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS products (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                category_id INTEGER,
                price DECIMAL(10, 2),
                stock INTEGER,
                metadata JSON,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (category_id) REFERENCES categories(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
        
        // Tabla orders
        $this->pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS orders (
                id INT AUTO_INCREMENT PRIMARY KEY,
                customer_id INTEGER,
                status VARCHAR(50),
                total DECIMAL(12, 2),
                order_date TIMESTAMP,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (customer_id) REFERENCES customers(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
        
        // Tabla order_items
        $this->pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS order_items (
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
    }
    
    private function seedData(int $customerCount, int $categoryCount, int $productCount, int $orderCount): void {
        echo "  → Generando datos de prueba...\n";
        
        $startTime = microtime(true);
        
        // Insertar categorías
        $categories = [];
        for ($i = 1; $i <= $categoryCount; $i++) {
            $categories[] = [
                'name' => "Category $i",
                'description' => "Description for category $i"
            ];
        }
        DB::table('categories')->insert($categories);
        
        // Insertar clientes
        $cities = ['New York', 'Los Angeles', 'Chicago', 'Houston', 'Phoenix', 'Philadelphia', 'San Antonio', 'San Diego'];
        $customers = [];
        for ($i = 1; $i <= $customerCount; $i++) {
            $customers[] = [
                'name' => "Customer $i",
                'email' => "customer$i@example.com",
                'city' => $cities[array_rand($cities)],
                'credit_limit' => rand(1000, 50000) / 100,
                'created_at' => date('Y-m-d H:i:s', strtotime("-" . rand(1, 365) . " days"))
            ];
        }
        DB::table('customers')->insert($customers);
        
        // Insertar productos
        $products = [];
        for ($i = 1; $i <= $productCount; $i++) {
            $products[] = [
                'name' => "Product $i",
                'category_id' => rand(1, $categoryCount),
                'price' => rand(10, 10000) / 100,
                'stock' => rand(0, 1000),
                'metadata' => json_encode(['brand' => "Brand " . rand(1, 10), 'rating' => rand(1, 5) / 10]),
                'created_at' => date('Y-m-d H:i:s', strtotime("-" . rand(1, 365) . " days"))
            ];
        }
        DB::table('products')->insert($products);
        
        // Insertar órdenes
        $statuses = ['pending', 'processing', 'shipped', 'delivered', 'cancelled'];
        $orders = [];
        for ($i = 1; $i <= $orderCount; $i++) {
            $orders[] = [
                'customer_id' => rand(1, $customerCount),
                'status' => $statuses[array_rand($statuses)],
                'total' => rand(100, 100000) / 100,
                'order_date' => date('Y-m-d H:i:s', strtotime("-" . rand(1, 365) . " days")),
                'created_at' => date('Y-m-d H:i:s', strtotime("-" . rand(1, 365) . " days"))
            ];
        }
        DB::table('orders')->insert($orders);
        
        // Insertar order_items (aproximadamente 3 items por orden)
        $orderItems = [];
        for ($i = 1; $i <= $orderCount * 3; $i++) {
            $productId = rand(1, $productCount);
            $quantity = rand(1, 10);
            $unitPrice = rand(10, 10000) / 100;
            $orderItems[] = [
                'order_id' => (($i - 1) % $orderCount) + 1,
                'product_id' => $productId,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'subtotal' => $quantity * $unitPrice
            ];
        }
        DB::table('order_items')->insert($orderItems);
        
        $endTime = microtime(true);
        $duration = round(($endTime - $startTime) * 1000, 2);
        echo "  ✓ Datos generados en {$duration}ms\n";
        echo "    - Clientes: $customerCount\n";
        echo "    - Categorías: $categoryCount\n";
        echo "    - Productos: $productCount\n";
        echo "    - Órdenes: $orderCount\n";
        echo "    - Items de orden: " . ($orderCount * 3) . "\n\n";
    }
    
    private function measure(string $name, callable $callback, int $iterations = 1): array {
        $times = [];
        
        for ($i = 0; $i < $iterations; $i++) {
            $startTime = microtime(true);
            $result = $callback();
            $endTime = microtime(true);
            $times[] = ($endTime - $startTime) * 1000; // Convertir a ms
        }
        
        $avg = array_sum($times) / count($times);
        $min = min($times);
        $max = max($times);
        
        return [
            'name' => $name,
            'avg' => round($avg, 3),
            'min' => round($min, 3),
            'max' => round($max, 3),
            'iterations' => $iterations,
            'result' => $result ?? null
        ];
    }
    
    private function testSimpleInserts(): void {
        echo "--- Prueba 1: INSERTs Simples ---\n";
        $this->createTables();
        
        // Insert individual
        $result = $this->measure('INSERT individual', function() {
            return DB::insert('customers', [
                'name' => 'Test Customer',
                'email' => 'test@example.com',
                'city' => 'Test City',
                'credit_limit' => 1000.00
            ]);
        });
        $this->results[] = $result;
        echo "  {$result['name']}: {$result['avg']}ms\n";
        
        // Insert multiple (100 registros)
        $batchData = [];
        for ($i = 0; $i < 100; $i++) {
            $batchData[] = [
                'name' => "Batch Customer $i",
                'email' => "batch$i@example.com",
                'city' => 'Batch City',
                'credit_limit' => 500.00
            ];
        }
        
        $result = $this->measure('INSERT batch (100 registros)', function() use ($batchData) {
            $ids = [];
            foreach ($batchData as $row) {
                $ids[] = DB::insert('customers', $row);
            }
            return count($ids);
        });
        $this->results[] = $result;
        echo "  {$result['name']}: {$result['avg']}ms (" . round(100 / ($result['avg'] / 1000), 0) . " regs/seg)\n\n";
    }
    
    private function testSimpleSelects(): void {
        echo "--- Prueba 2: SELECTs Simples ---\n";
        
        // SELECT * sin WHERE
        $result = $this->measure('SELECT * (sin WHERE, 1000 regs)', function() {
            return count(DB::many('SELECT * FROM customers LIMIT 1000'));
        }, 5);
        $this->results[] = $result;
        echo "  {$result['name']}: {$result['avg']}ms ({$result['result']} registros)\n";
        
        // SELECT con columnas específicas
        $result = $this->measure('SELECT columnas específicas', function() {
            return count(DB::many('SELECT id, name, email FROM customers LIMIT 1000'));
        }, 5);
        $this->results[] = $result;
        echo "  {$result['name']}: {$result['avg']}ms\n";
        
        // SELECT COUNT
        $result = $this->measure('SELECT COUNT(*)', function() {
            return DB::value('SELECT COUNT(*) FROM customers');
        }, 10);
        $this->results[] = $result;
        echo "  {$result['name']}: {$result['avg']}ms\n\n";
    }
    
    private function testWhereAndOrderBy(): void {
        echo "--- Prueba 3: WHERE y ORDER BY ---\n";
        
        // WHERE simple
        $result = $this->measure('WHERE simple (city = ?)', function() {
            return count(DB::many('SELECT * FROM customers WHERE city = ?', ['New York']));
        }, 5);
        $this->results[] = $result;
        echo "  {$result['name']}: {$result['avg']}ms ({$result['result']} registros)\n";
        
        // WHERE múltiple
        $result = $this->measure('WHERE múltiple (AND)', function() {
            return count(DB::many('SELECT * FROM customers WHERE credit_limit > ? AND city = ?', [100, 'New York']));
        }, 5);
        $this->results[] = $result;
        echo "  {$result['name']}: {$result['avg']}ms ({$result['result']} registros)\n";
        
        // ORDER BY
        $result = $this->measure('ORDER BY DESC', function() {
            return count(DB::many('SELECT * FROM customers ORDER BY credit_limit DESC LIMIT 100'));
        }, 5);
        $this->results[] = $result;
        echo "  {$result['name']}: {$result['avg']}ms\n";
        
        // WHERE + ORDER BY + LIMIT
        $result = $this->measure('WHERE + ORDER BY + LIMIT', function() {
            return DB::many('SELECT * FROM customers WHERE credit_limit > ? ORDER BY credit_limit DESC LIMIT 50', [50]);
        }, 5);
        $this->results[] = $result;
        echo "  {$result['name']}: {$result['avg']}ms\n\n";
    }
    
    private function testJoins(): void {
        echo "--- Prueba 4: JOINs ---\n";
        
        // INNER JOIN simple
        $result = $this->measure('INNER JOIN (2 tablas)', function() {
            return count(DB::many('SELECT p.name, c.name as category_name, p.price FROM products p JOIN categories c ON p.category_id = c.id LIMIT 500'));
        }, 5);
        $this->results[] = $result;
        echo "  {$result['name']}: {$result['avg']}ms ({$result['result']} registros)\n";
        
        // JOIN múltiple
        $result = $this->measure('JOIN múltiple (3 tablas)', function() {
            return count(DB::many('SELECT o.id as order_id, p.name, oi.quantity, oi.subtotal FROM order_items oi JOIN orders o ON oi.order_id = o.id JOIN products p ON oi.product_id = p.id LIMIT 500'));
        }, 5);
        $this->results[] = $result;
        echo "  {$result['name']}: {$result['avg']}ms ({$result['result']} registros)\n";
        
        // JOIN con WHERE
        $result = $this->measure('JOIN + WHERE', function() {
            return count(DB::many("SELECT o.*, c.name as customer_name FROM orders o JOIN customers c ON o.customer_id = c.id WHERE o.status = ? AND c.city = ? LIMIT 200", ['delivered', 'New York']));
        }, 5);
        $this->results[] = $result;
        echo "  {$result['name']}: {$result['avg']}ms ({$result['result']} registros)\n\n";
    }
    
    private function testAggregations(): void {
        echo "--- Prueba 5: Agregaciones ---\n";
        
        // COUNT con GROUP BY
        $result = $this->measure('COUNT + GROUP BY', function() {
            return DB::many('SELECT status, COUNT(*) as count FROM orders GROUP BY status');
        }, 5);
        $this->results[] = $result;
        echo "  {$result['name']}: {$result['avg']}ms\n";
        
        // SUM con GROUP BY
        $result = $this->measure('SUM + GROUP BY', function() {
            return DB::many('SELECT product_id, SUM(subtotal) as total_sales FROM order_items GROUP BY product_id ORDER BY total_sales DESC LIMIT 20');
        }, 5);
        $this->results[] = $result;
        echo "  {$result['name']}: {$result['avg']}ms\n";
        
        // AVG, MIN, MAX
        $result = $this->measure('AVG, MIN, MAX', function() {
            return DB::query('SELECT AVG(price) as avg_price, MIN(price) as min_price, MAX(price) as max_price FROM products')->fetch(\PDO::FETCH_ASSOC);
        }, 5);
        $this->results[] = $result;
        echo "  {$result['name']}: {$result['avg']}ms\n";
        
        // HAVING clause
        $result = $this->measure('GROUP BY + HAVING', function() {
            return count(DB::many('SELECT product_id, SUM(quantity) as total_qty FROM order_items GROUP BY product_id HAVING SUM(quantity) > 10 LIMIT 50'));
        }, 5);
        $this->results[] = $result;
        echo "  {$result['name']}: {$result['avg']}ms ({$result['result']} registros)\n\n";
    }
    
    private function testSubqueriesAndCTEs(): void {
        echo "--- Prueba 6: Subconsultas y CTEs ---\n";
        
        // Subquery en WHERE
        $result = $this->measure('Subquery en WHERE', function() {
            return count(DB::many("SELECT * FROM products WHERE category_id IN (SELECT id FROM categories WHERE name LIKE '%Category 1%') LIMIT 200"));
        }, 5);
        $this->results[] = $result;
        echo "  {$result['name']}: {$result['avg']}ms ({$result['result']} registros)\n";
        
        // CTE (WITH clause)
        $result = $this->measure('CTE (WITH clause)', function() {
            return DB::many('WITH product_sales AS (SELECT product_id, SUM(subtotal) as product_total FROM order_items GROUP BY product_id) SELECT p.name, ps.product_total FROM product_sales ps JOIN products p ON ps.product_id = p.id ORDER BY ps.product_total DESC LIMIT 20');
        }, 5);
        $this->results[] = $result;
        echo "  {$result['name']}: {$result['avg']}ms\n";
        
        // Subquery correlacionada
        $result = $this->measure('Subquery correlacionada', function() {
            return count(DB::many('SELECT c.*, (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.id) as order_count FROM customers c LIMIT 100'));
        }, 5);
        $this->results[] = $result;
        echo "  {$result['name']}: {$result['avg']}ms ({$result['result']} registros)\n\n";
    }
    
    private function testBulkOperations(): void {
        echo "--- Prueba 7: Operaciones Masivas ---\n";
        
        // Bulk UPDATE
        $result = $this->measure('Bulk UPDATE (1000 registros)', function() {
            DB::exec('UPDATE products SET stock = stock + 10 WHERE stock > 0');
            return DB::getAffectedRows();
        });
        $this->results[] = $result;
        echo "  {$result['name']}: {$result['avg']}ms ({$result['result']} registros actualizados)\n";
        
        // Bulk DELETE
        $result = $this->measure('Bulk DELETE (registros antiguos)', function() {
            DB::exec("DELETE FROM customers WHERE created_at < DATE_SUB(NOW(), INTERVAL 200 DAY)");
            return DB::getAffectedRows();
        });
        $this->results[] = $result;
        echo "  {$result['name']}: {$result['avg']}ms ({$result['result']} registros eliminados)\n";
        
        // UPSERT (ON DUPLICATE KEY UPDATE)
        $result = $this->measure('UPSERT (ON DUPLICATE KEY)', function() {
            DB::exec("INSERT INTO categories (id, name, description) VALUES (1, 'Updated Category 1', 'Updated description'), (2, 'Updated Category 2', 'Updated description') ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description)");
            return DB::getAffectedRows();
        }, 5);
        $this->results[] = $result;
        echo "  {$result['name']}: {$result['avg']}ms\n\n";
    }
    
    private function testComplexQueries(): void {
        echo "--- Prueba 8: Consultas Complejas ---\n";
        
        // Query compleja con múltiples JOINs, agregaciones y filtros
        $result = $this->measure('Query compleja (reporte de ventas)', function() {
            return DB::many("SELECT 
                c.name as category_name,
                cust.city,
                COUNT(DISTINCT o.id) as order_count,
                SUM(oi.quantity) as total_quantity,
                SUM(oi.subtotal) as total_revenue,
                AVG(oi.subtotal) as avg_item_value
            FROM orders o
            JOIN customers cust ON o.customer_id = cust.id
            JOIN order_items oi ON o.id = oi.order_id
            JOIN products p ON oi.product_id = p.id
            JOIN categories c ON p.category_id = c.id
            WHERE o.status = 'delivered'
            AND o.order_date >= DATE_SUB(NOW(), INTERVAL 180 DAY)
            GROUP BY c.name, cust.city
            HAVING SUM(oi.subtotal) > 100
            ORDER BY total_revenue DESC
            LIMIT 50");
        }, 5);
        $this->results[] = $result;
        echo "  {$result['name']}: {$result['avg']}ms\n";
        
        // Window functions (disponible en MySQL 8.0+)
        $result = $this->measure('Window Function (RANK)', function() {
            return count(DB::many('SELECT name, price, category_id, RANK() OVER (PARTITION BY category_id ORDER BY price DESC) as price_rank FROM products LIMIT 100'));
        }, 5);
        $this->results[] = $result;
        echo "  {$result['name']}: {$result['avg']}ms ({$result['result']} registros)\n\n";
    }
    
    private function printSummary(): void {
        echo "\n";
        echo "╔══════════════════════════════════════════════════════════════╗\n";
        echo "║                    PERFORMANCE SUMMARY                       ║\n";
        echo "╠══════════════════════════════════════════════════════════════╣\n";
        echo "║ Operation                              │ Avg (ms) │ Type    ║\n";
        echo "╠────────────────────────────────────────┼──────────┼─────────╣\n";
        
        foreach ($this->results as $result) {
            $type = $this->getResultType($result['name']);
            $name = str_pad(substr($result['name'], 0, 38), 38);
            $avg = str_pad($result['avg'], 8);
            echo "║ $name │ $avg │ $type ║\n";
        }
        
        echo "╚══════════════════════════════════════════════════════════════╝\n\n";
        
        // Estadísticas generales
        $avgTimes = array_column($this->results, 'avg');
        $overallAvg = array_sum($avgTimes) / count($avgTimes);
        $fastest = min($avgTimes);
        $slowest = max($avgTimes);
        
        echo "📊 Estadísticas Generales:\n";
        echo "   - Total de pruebas: " . count($this->results) . "\n";
        echo "   - Promedio general: " . round($overallAvg, 3) . "ms\n";
        echo "   - Más rápida: " . round($fastest, 3) . "ms\n";
        echo "   - Más lenta: " . round($slowest, 3) . "ms\n";
        echo "   - Desviación: " . round($slowest / $fastest, 2) . "x\n\n";
    }
    
    private function getResultType(string $name): string {
        if (strpos($name, 'INSERT') !== false) return 'WRITE';
        if (strpos($name, 'UPDATE') !== false) return 'WRITE';
        if (strpos($name, 'DELETE') !== false) return 'WRITE';
        if (strpos($name, 'UPSERT') !== false) return 'WRITE';
        return 'READ';
    }
}

// Ejecutar tests
$test = new MySQLPerformanceTest();
$test->run();
