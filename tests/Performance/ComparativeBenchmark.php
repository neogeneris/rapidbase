<?php
/**
 * Benchmark Comparativo: RapidBase vs PDO Nativo
 * 
 * Compara el rendimiento de operaciones CRUD básicas y consultas con JOINs.
 * PDO Nativo representa la velocidad base (1x).
 */

$basePath = __DIR__ . "/../../src/RapidBase/Core";
require_once "$basePath/SQL.php";
require_once "$basePath/Conn.php";
require_once "$basePath/Executor.php";
require_once "$basePath/Gateway.php";
require_once "$basePath/DBInterface.php";
require_once "$basePath/DB.php";

use RapidBase\Core\DB;
use RapidBase\Core\SQL;
use RapidBase\Core\Gateway;
use RapidBase\Core\Conn;

// Configurar SQLite para el benchmark
$dsn = 'sqlite:' . __DIR__ . '/../../tests/data/test_benchmark.sqlite';

// Asegurar que el directorio de datos existe
if (!is_dir(dirname($dsn))) {
    mkdir(dirname($dsn), 0777, true);
}

// Configurar RapidBase
SQL::setDriver('sqlite');
Conn::setup($dsn, '', '', 'main');

class ComparativeBenchmark
{
    private array $results = [];
    
    public function run(): void
    {
        echo "🔥 Benchmark Comparativo: RapidBase vs PDO Nativo\n";
        echo "PDO Nativo = 1.00x (Baseline)\n";
        echo str_repeat("=", 80) . "\n\n";
        
        $this->setupData();
        
        // Tests de rendimiento
        $this->testSimpleSelect();
        $this->testSelectWithWhere();
        $this->testJoinQuery();
        $this->testInsert();
        $this->testUpdate();
        
        $this->printResults();
    }
    
    private function setupData(): void
    {
        echo "📦 Preparando datos de prueba...\n";
        
        Conn::get()->exec("DROP TABLE IF EXISTS products");
        Conn::get()->exec("DROP TABLE IF EXISTS categories");
        
        Conn::get()->exec("
            CREATE TABLE categories (
                id INTEGER PRIMARY KEY,
                name TEXT,
                description TEXT
            )
        ");
        
        Conn::get()->exec("
            CREATE TABLE products (
                id INTEGER PRIMARY KEY,
                name TEXT,
                price REAL,
                category_id INTEGER,
                stock INTEGER
            )
        ");
        
        // Insertar datos en RapidBase
        for ($i = 1; $i <= 10; $i++) {
            Gateway::action('insert', 'categories', [
                'id' => $i,
                'name' => "Category $i",
                'description' => "Description for category $i"
            ]);
        }
        
        for ($i = 1; $i <= 100; $i++) {
            Gateway::action('insert', 'products', [
                'id' => $i,
                'name' => "Product $i",
                'price' => rand(10, 1000) / 10,
                'category_id' => ($i % 10) + 1,
                'stock' => rand(0, 100)
            ]);
        }
        
        echo "✅ 100 productos y 10 categorías creados\n\n";
    }
    
    private function testSimpleSelect(): void
    {
        $iterations = 100;
        
        // PDO Nativo
        $start = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $stmt = Conn::get()->query("SELECT * FROM products LIMIT 10");
            $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        $pdoTime = (microtime(true) - $start) / $iterations;
        
        // RapidBase
        $start = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            Gateway::select('*', 'products', [], [], [], [], 1, 10);
        }
        $rapidBaseTime = (microtime(true) - $start) / $iterations;
        
        $this->results['Simple SELECT (10 rows)'] = [
            'PDO' => $pdoTime,
            'RapidBase' => $rapidBaseTime
        ];
        
        echo "✅ Simple SELECT completado\n";
    }
    
    private function testSelectWithWhere(): void
    {
        $iterations = 100;
        
        // PDO Nativo
        $start = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $stmt = Conn::get()->prepare("SELECT * FROM products WHERE price > ? AND stock > ?");
            $stmt->execute([50, 10]);
            $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        $pdoTime = (microtime(true) - $start) / $iterations;
        
        // RapidBase
        $start = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            Gateway::select('*', 'products', [
                'price' => ['>' => 50],
                'stock' => ['>' => 10]
            ]);
        }
        $rapidBaseTime = (microtime(true) - $start) / $iterations;
        
        $this->results['SELECT with WHERE'] = [
            'PDO' => $pdoTime,
            'RapidBase' => $rapidBaseTime
        ];
        
        echo "✅ SELECT with WHERE completado\n";
    }
    
    private function testJoinQuery(): void
    {
        $iterations = 50;
        
        // PDO Nativo
        $start = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $stmt = Conn::get()->prepare("
                SELECT p.name, p.price, c.name as category_name
                FROM products p
                INNER JOIN categories c ON p.category_id = c.id
                WHERE p.price > ?
                LIMIT 10
            ");
            $stmt->execute([50]);
            $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        $pdoTime = (microtime(true) - $start) / $iterations;
        
        // RapidBase
        $start = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            SQL::reset();
            [$sql, $params] = SQL::buildSelect(
                'p.name, p.price, c.name as category_name',
                [
                    'products AS p', 
                    ['categories' => ['local_key' => 'category_id', 'foreign_key' => 'id', 'as' => 'c']]
                ],
                ['p.price' => ['>' => 50]],
                [],
                [],
                [],
                1,
                10
            );
            $stmt = Conn::get()->prepare($sql);
            $stmt->execute($params);
            $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        $rapidBaseTime = (microtime(true) - $start) / $iterations;
        
        $this->results['JOIN Query (SQL Builder + Exec)'] = [
            'PDO' => $pdoTime,
            'RapidBase' => $rapidBaseTime
        ];
        
        echo "✅ JOIN Query completado\n";
    }
    
    private function testInsert(): void
    {
        $iterations = 50;
        
        // PDO Nativo
        $start = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $stmt = Conn::get()->prepare("INSERT INTO products (name, price, category_id, stock) VALUES (?, ?, ?, ?)");
            $stmt->execute(["Test Product $i", 99.99, 1, 50]);
            Conn::get()->exec("DELETE FROM products WHERE name = 'Test Product $i'");
        }
        $pdoTime = (microtime(true) - $start) / $iterations;
        
        // RapidBase
        $start = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            Gateway::insert('products', [
                'name' => "RB Test Product $i",
                'price' => 99.99,
                'category_id' => 1,
                'stock' => 50
            ]);
            Conn::get()->exec("DELETE FROM products WHERE name = 'RB Test Product $i'");
        }
        $rapidBaseTime = (microtime(true) - $start) / $iterations;
        
        $this->results['INSERT Single Row'] = [
            'PDO' => $pdoTime,
            'RapidBase' => $rapidBaseTime
        ];
        
        echo "✅ INSERT completado\n";
    }
    
    private function testUpdate(): void
    {
        $iterations = 50;
        
        // PDO Nativo
        $start = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $stmt = Conn::get()->prepare("UPDATE products SET price = ? WHERE id = 1");
            $stmt->execute([99.99]);
        }
        $pdoTime = (microtime(true) - $start) / $iterations;
        
        // RapidBase
        $start = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            Gateway::update('products', ['price' => 99.99], ['id' => 1]);
        }
        $rapidBaseTime = (microtime(true) - $start) / $iterations;
        
        $this->results['UPDATE Single Row'] = [
            'PDO' => $pdoTime,
            'RapidBase' => $rapidBaseTime
        ];
        
        echo "✅ UPDATE completado\n";
    }
    
    private function printResults(): void
    {
        echo "\n" . str_repeat("=", 80) . "\n";
        echo "📊 RESULTADOS COMPARATIVOS (PDO Nativo = 1.0x)\n";
        echo str_repeat("=", 80) . "\n\n";
        
        printf("  %-35s | %-12s | %-12s | %-10s\n", "Prueba", "PDO (ms)", "RapidBase (ms)", "Factor");
        echo str_repeat("-", 80) . "\n";
        
        foreach ($this->results as $testName => $times) {
            $pdoMs = $times['PDO'] * 1000;
            $rbMs = $times['RapidBase'] * 1000;
            $factor = $rbMs / $pdoMs;
            
            printf("  %-35s | %12.4f | %12.4f | %9.2fx\n", 
                $testName, 
                $pdoMs, 
                $rbMs,
                $factor
            );
        }
        
        echo str_repeat("=", 80) . "\n";
        echo "✅ Benchmark completado exitosamente\n";
    }
}

// Ejecutar benchmark
$benchmark = new ComparativeBenchmark();
$benchmark->run();
