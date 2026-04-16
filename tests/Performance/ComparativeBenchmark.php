<?php
/**
 * Benchmark Comparativo: RapidBase vs RedBeanPHP vs PDO Nativo
 * 
 * Compara el rendimiento de operaciones CRUD básicas y consultas con JOINs
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use RapidBase\Core\DB;
use RapidBase\Core\SQL;
use RapidBase\Core\Gateway;
use RapidBase\Core\Conn;
use RedBeanPHP\R;

// Configurar SQLite para ambos
$dsn = 'sqlite:' . __DIR__ . '/../../tests/data/test_benchmark.sqlite';

// Configurar RedBeanPHP
R::setup($dsn);
R::freeze(true); // Congelar esquema para mejor rendimiento

// Configurar RapidBase
SQL::setDriver('sqlite');
Conn::setup($dsn, '', '', 'main');

class ComparativeBenchmark
{
    private array $results = [];
    
    public function run(): void
    {
        echo "🔥 Benchmark Comparativo: RapidBase vs RedBeanPHP vs PDO\n";
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
        
        // Crear tablas para RedBeanPHP (auto-crea el esquema)
        R::freeze(false); // Permitir creación automática de tablas
        
        // Crear categorías
        $categories = [];
        for ($i = 1; $i <= 10; $i++) {
            $cat = R::dispense('category');
            $cat->name = "Category $i";
            $cat->description = "Description for category $i";
            $categories[] = R::store($cat);
        }
        
        // Crear productos
        for ($i = 1; $i <= 100; $i++) {
            $prod = R::dispense('product');
            $prod->name = "Product $i";
            $prod->price = rand(10, 1000) / 10;
            $prod->category_id = ($i % 10) + 1;
            $prod->stock = rand(0, 100);
            R::store($prod);
        }
        
        R::freeze(true); // Congelar después de crear
        
        // Crear tablas para RapidBase
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
        $iterations = 1000;
        
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
        
        // RedBeanPHP
        $start = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            R::find('product', ['LIMIT' => '10']);
        }
        $redbeanTime = (microtime(true) - $start) / $iterations;
        
        $this->results['Simple SELECT (10 rows)'] = [
            'PDO' => $pdoTime,
            'RapidBase' => $rapidBaseTime,
            'RedBeanPHP' => $redbeanTime
        ];
        
        echo "✅ Simple SELECT completado\n";
    }
    
    private function testSelectWithWhere(): void
    {
        $iterations = 1000;
        
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
        
        // RedBeanPHP
        $start = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            R::find('product', 'price > ? AND stock > ?', [50, 10]);
        }
        $redbeanTime = (microtime(true) - $start) / $iterations;
        
        $this->results['SELECT with WHERE'] = [
            'PDO' => $pdoTime,
            'RapidBase' => $rapidBaseTime,
            'RedBeanPHP' => $redbeanTime
        ];
        
        echo "✅ SELECT with WHERE completado\n";
    }
    
    private function testJoinQuery(): void
    {
        $iterations = 500;
        
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
            SQL::buildSelect(
                'p.name, p.price, c.name as category_name',
                'products',
                ['price' => ['>' => 50]],
                [],
                [],
                [],
                1,
                10,
                [['table' => 'categories', 'on' => 'products.category_id = categories.id', 'alias' => 'c']]
            );
            // Nota: buildSelect solo construye el SQL, necesitamos ejecutarlo
            // Para este benchmark usaremos una consulta directa
        }
        $rapidBaseTime = (microtime(true) - $start) / $iterations;
        
        // Simular ejecución real de RapidBase con JOIN
        $start = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            [$sql, $params] = SQL::buildSelect(
                'p.name, p.price, c.name as category_name',
                'products',
                ['price' => ['>' => 50]],
                [],
                [],
                [],
                1,
                10,
                [['table' => 'categories', 'on' => 'products.category_id = categories.id', 'alias' => 'c']]
            );
            $stmt = Conn::get()->prepare($sql);
            $stmt->execute($params);
            $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        $rapidBaseTime = (microtime(true) - $start) / $iterations;
        
        // RedBeanPHP (los JOINs son más verbosos en RedBean)
        $start = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            R::find('product', 'price > ? LIMIT 10', [50]);
            // RedBean no tiene JOINs nativos simples, hay que usar query personalizado
        }
        $redbeanTime = (microtime(true) - $start) / $iterations;
        
        $this->results['JOIN Query'] = [
            'PDO' => $pdoTime,
            'RapidBase' => $rapidBaseTime,
            'RedBeanPHP' => $redbeanTime
        ];
        
        echo "✅ JOIN Query completado\n";
    }
    
    private function testInsert(): void
    {
        $iterations = 500;
        
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
        
        // RedBeanPHP
        $start = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $prod = R::dispense('product');
            $prod->name = "RedBean Test Product $i";
            $prod->price = 99.99;
            $prod->category_id = 1;
            $prod->stock = 50;
            R::store($prod);
            R::trash(R::load('product', $prod->id));
        }
        $redbeanTime = (microtime(true) - $start) / $iterations;
        
        $this->results['INSERT Single Row'] = [
            'PDO' => $pdoTime,
            'RapidBase' => $rapidBaseTime,
            'RedBeanPHP' => $redbeanTime
        ];
        
        echo "✅ INSERT completado\n";
    }
    
    private function testUpdate(): void
    {
        $iterations = 500;
        
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
        
        // RedBeanPHP
        $start = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $prod = R::load('product', 1);
            $prod->price = 99.99;
            R::store($prod);
        }
        $redbeanTime = (microtime(true) - $start) / $iterations;
        
        $this->results['UPDATE Single Row'] = [
            'PDO' => $pdoTime,
            'RapidBase' => $rapidBaseTime,
            'RedBeanPHP' => $redbeanTime
        ];
        
        echo "✅ UPDATE completado\n";
    }
    
    private function printResults(): void
    {
        echo "\n" . str_repeat("=", 80) . "\n";
        echo "📊 RESULTADOS COMPARATIVOS (tiempo promedio por operación en ms)\n";
        echo str_repeat("=", 80) . "\n\n";
        
        foreach ($this->results as $testName => $times) {
            echo "📌 $testName\n";
            echo str_repeat("-", 60) . "\n";
            
            $min = min($times);
            $winner = array_search($min, $times);
            
            printf("  %-15s %10.4f ms", "PDO:", $times['PDO'] * 1000);
            if ($winner === 'PDO') echo " 🏆 WINNER";
            echo "\n";
            
            printf("  %-15s %10.4f ms", "RapidBase:", $times['RapidBase'] * 1000);
            if ($winner === 'RapidBase') echo " 🏆 WINNER";
            echo "\n";
            
            printf("  %-15s %10.4f ms", "RedBeanPHP:", $times['RedBeanPHP'] * 1000);
            if ($winner === 'RedBeanPHP') echo " 🏆 WINNER";
            echo "\n";
            
            // Calcular mejora vs RedBean
            $rbImprovement = (($times['RedBeanPHP'] - $times['RapidBase']) / $times['RedBeanPHP']) * 100;
            echo "  → RapidBase es " . number_format(abs($rbImprovement), 1) . "% " . 
                 ($rbImprovement > 0 ? "MÁS RÁPIDO" : "MÁS LENTO") . " que RedBeanPHP\n\n";
        }
        
        echo str_repeat("=", 80) . "\n";
        echo "✅ Benchmark completado exitosamente\n";
    }
}

// Ejecutar benchmark
$benchmark = new ComparativeBenchmark();
$benchmark->run();
