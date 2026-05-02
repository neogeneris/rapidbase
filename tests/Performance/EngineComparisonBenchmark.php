<?php

/**
 * EngineComparisonBenchmark.php
 * 
 * Prueba de performance comparativa entre diferentes motores SQL que usan PDO.
 * Compara:
 * - Consultas simples (SELECT, INSERT, UPDATE, DELETE)
 * - Consultas con JOINs de 2 a 5 tablas
 * - Operaciones CRUD completas
 * 
 * Motores evaluados:
 * 1. PDO Directo (referencia 1x)
 * 2. SQL.php (motor tradicional)
 * 3. W/Wm.php (bajo acoplamiento)
 * 4. B/F.php (fragmentado original)
 * 5. EB/EF.php (fragmentado completo - SQLEngine)
 * 6. B2/F2.php (JoinManager integrado - SQLEngine2)
 * 7. Q.php (Fluent SQL builder - Core)
 */

declare(strict_types=1);

// Configuración de autoload
require_once __DIR__ . '/../../src/RapidBase/Core/DBInterface.php';
require_once __DIR__ . '/../../src/RapidBase/Core/DB.php';
require_once __DIR__ . '/../../src/RapidBase/Core/SQL.php';
require_once __DIR__ . '/../../src/RapidBase/Core/SchemaMap.php';
require_once __DIR__ . '/../../src/RapidBase/Core/SQL/CompiledQuery.php';
require_once __DIR__ . '/../../src/RapidBase/Core/SQL/ConditionMatrix.php';
require_once __DIR__ . '/../../src/RapidBase/Core/SQL/ConditionParser.php';
require_once __DIR__ . '/../../src/RapidBase/Core/SQL/DeterministicJoin.php';
require_once __DIR__ . '/../../src/RapidBase/Core/SQL/JoinResolver.php';
require_once __DIR__ . '/../../src/RapidBase/Core/SQL/JoinStrategy.php';
require_once __DIR__ . '/../../src/RapidBase/Core/SQL/Q.php';
require_once __DIR__ . '/../../src/RapidBase/Core/SQL/QType.php';
require_once __DIR__ . '/../../src/RapidBase/Core/SQL/SqlCompiler.php';

// PoC SQL engines
require_once __DIR__ . '/../PoC/SQL/FinalizerInterface.php';
require_once __DIR__ . '/../PoC/SQL/W.php';
require_once __DIR__ . '/../PoC/SQL/Wm.php';
require_once __DIR__ . '/../PoC/SQL/B.php';
require_once __DIR__ . '/../PoC/SQL/F.php';

// SQLEngine
require_once __DIR__ . '/../PoC/SQLEngine/EB.php';
require_once __DIR__ . '/../PoC/SQLEngine/EF.php';
require_once __DIR__ . '/../PoC/SQLEngine/JoinManager.php';
require_once __DIR__ . '/../PoC/SQLEngine/JoinManagerAuto.php';
require_once __DIR__ . '/../PoC/SQLEngine/JoinManagerGenetic.php';
require_once __DIR__ . '/../PoC/SQLEngine/Parser.php';

// SQLEngine2
require_once __DIR__ . '/../PoC/SQLEngine2/B2.php';
require_once __DIR__ . '/../PoC/SQLEngine2/F2.php';

use RapidBase\Core\DB;
use RapidBase\Core\SQL;
use RapidBase\Core\W;
use RapidBase\Core\Wm;
use RapidBase\Core\B;
use RapidBase\Core\F;
use RapidBase\SQLEngine\EB;
use RapidBase\SQLEngine\EF;
use RapidBase\SQLEngine2\B2;
use RapidBase\SQLEngine2\F2;
use RapidBase\Core\SQL\Q;

class EngineComparisonBenchmark
{
    private PDO $pdo;
    private int $iterations;
    private array $results = [];
    private bool $executeQueries = false; // Solo generación de SQL por defecto

    public function __construct(int $iterations = 10000, bool $executeQueries = false)
    {
        $this->iterations = $iterations;
        $this->executeQueries = $executeQueries;
        $this->setupDatabase();
    }

    private function setupDatabase(): void
    {
        // Crear conexión SQLite en memoria para pruebas
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Crear tablas para pruebas de JOIN
        $this->pdo->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                name TEXT,
                email TEXT,
                status TEXT,
                country_id INTEGER,
                role_id INTEGER
            )
        ");
        
        $this->pdo->exec("
            CREATE TABLE posts (
                id INTEGER PRIMARY KEY,
                user_id INTEGER,
                title TEXT,
                content TEXT,
                category_id INTEGER,
                created_at TEXT
            )
        ");
        
        $this->pdo->exec("
            CREATE TABLE comments (
                id INTEGER PRIMARY KEY,
                post_id INTEGER,
                user_id INTEGER,
                content TEXT,
                rating INTEGER
            )
        ");
        
        $this->pdo->exec("
            CREATE TABLE categories (
                id INTEGER PRIMARY KEY,
                name TEXT,
                parent_id INTEGER
            )
        ");
        
        $this->pdo->exec("
            CREATE TABLE countries (
                id INTEGER PRIMARY KEY,
                name TEXT,
                code TEXT
            )
        ");
        
        $this->pdo->exec("
            CREATE TABLE roles (
                id INTEGER PRIMARY KEY,
                name TEXT,
                level INTEGER
            )
        ");
        
        $this->pdo->exec("
            CREATE TABLE tags (
                id INTEGER PRIMARY KEY,
                name TEXT
            )
        ");
        
        $this->pdo->exec("
            CREATE TABLE post_tags (
                post_id INTEGER,
                tag_id INTEGER,
                PRIMARY KEY (post_id, tag_id)
            )
        ");
        
        // Insertar datos de prueba
        $this->seedTestData();
    }

    private function seedTestData(): void
    {
        // Insertar países
        $stmt = $this->pdo->prepare("INSERT INTO countries (name, code) VALUES (?, ?)");
        for ($i = 1; $i <= 10; $i++) {
            $stmt->execute(["Country {$i}", "C{$i}"]);
        }
        
        // Insertar roles
        $stmt = $this->pdo->prepare("INSERT INTO roles (name, level) VALUES (?, ?)");
        for ($i = 1; $i <= 5; $i++) {
            $stmt->execute(["Role {$i}", $i]);
        }
        
        // Insertar categorías
        $stmt = $this->pdo->prepare("INSERT INTO categories (name, parent_id) VALUES (?, ?)");
        for ($i = 1; $i <= 20; $i++) {
            $stmt->execute(["Category {$i}", $i > 10 ? $i - 10 : null]);
        }
        
        // Insertar usuarios
        $stmt = $this->pdo->prepare("INSERT INTO users (name, email, status, country_id, role_id) VALUES (?, ?, ?, ?, ?)");
        for ($i = 1; $i <= 100; $i++) {
            $stmt->execute([
                "User {$i}",
                "user{$i}@example.com",
                $i % 3 === 0 ? 'inactive' : 'active',
                ($i % 10) + 1,
                ($i % 5) + 1
            ]);
        }
        
        // Insertar posts
        $stmt = $this->pdo->prepare("INSERT INTO posts (user_id, title, content, category_id, created_at) VALUES (?, ?, ?, ?, ?)");
        for ($i = 1; $i <= 500; $i++) {
            $stmt->execute([
                ($i % 100) + 1,
                "Post Title {$i}",
                "Content for post {$i}",
                ($i % 20) + 1,
                date('Y-m-d H:i:s', strtotime("-{$i} days"))
            ]);
        }
        
        // Insertar comentarios
        $stmt = $this->pdo->prepare("INSERT INTO comments (post_id, user_id, content, rating) VALUES (?, ?, ?, ?)");
        for ($i = 1; $i <= 1000; $i++) {
            $stmt->execute([
                ($i % 500) + 1,
                ($i % 100) + 1,
                "Comment {$i}",
                ($i % 5) + 1
            ]);
        }
        
        // Insertar tags
        $stmt = $this->pdo->prepare("INSERT INTO tags (name) VALUES (?)");
        for ($i = 1; $i <= 50; $i++) {
            $stmt->execute(["Tag {$i}"]);
        }
        
        // Insertar post_tags
        $stmt = $this->pdo->prepare("INSERT INTO post_tags (post_id, tag_id) VALUES (?, ?)");
        for ($i = 1; $i <= 200; $i++) {
            $stmt->execute([
                ($i % 500) + 1,
                ($i % 50) + 1
            ]);
        }
    }

    public function run(): void
    {
        echo "╔══════════════════════════════════════════════════════════════╗\n";
        echo "║         ENGINE COMPARISON BENCHMARK                          ║\n";
        echo "║         Comparativa de Motores SQL con PDO                   ║\n";
        echo "╚══════════════════════════════════════════════════════════════╝\n\n";
        
        echo "Iteraciones: {$this->iterations}\n";
        echo "Modo: " . ($this->executeQueries ? "Ejecución real" : "Solo generación de SQL") . "\n\n";
        
        // Warmup
        echo "🔥 Calentando motores...\n";
        $this->warmup();
        
        // Test 1: Consultas Simples
        echo "\n";
        echo "┌──────────────────────────────────────────────────────────────┐\n";
        echo "│ TEST 1: CONSULTAS SIMPLES                                    │\n";
        echo "└──────────────────────────────────────────────────────────────┘\n";
        $this->testSimpleSelect();
        $this->testSelectWithWhere();
        $this->testSelectWithOrderLimit();
        $this->testCount();
        
        // Test 2: Operaciones CRUD
        echo "\n";
        echo "┌──────────────────────────────────────────────────────────────┐\n";
        echo "│ TEST 2: OPERACIONES CRUD                                     │\n";
        echo "└──────────────────────────────────────────────────────────────┘\n";
        $this->testInsert();
        $this->testUpdate();
        $this->testDelete();
        
        // Test 3: JOINs de 2 a 5 tablas
        echo "\n";
        echo "┌──────────────────────────────────────────────────────────────┐\n";
        echo "│ TEST 3: JOINS DE 2 A 5 TABLAS                                │\n";
        echo "└──────────────────────────────────────────────────────────────┘\n";
        $this->testJoin2Tables();
        $this->testJoin3Tables();
        $this->testJoin4Tables();
        $this->testJoin5Tables();
        
        // Resumen final
        $this->printSummary();
    }

    private function warmup(): void
    {
        for ($i = 0; $i < 100; $i++) {
            // PDO
            $this->pdo->query("SELECT * FROM users WHERE id = 1")->fetchAll();
            
            // SQL.php
            SQL::buildSelect('*', 'users', ['id' => 1]);
            
            // W
            W::from('users', ['id' => 1])->select('*');
            
            // Wm
            Wm::from('users', ['id' => 1])->select('*');
            
            // B+F
            F::fromBuilder(B::from('users', ['id' => 1]))->select();
            
            // EB+EF
            EF::fromBuilder(EB::from('users', ['id' => 1]))->select();
            
            // B2+F2
            F2::fromBuilder(B2::from('users', ['id' => 1]))->select();
            
            // Q
            Q::from('users', ['id' => 1])->select('*');
        }
    }

    private function measure(callable $callback, string $engine): array
    {
        $startMem = memory_get_usage();
        $startTime = microtime(true);
        
        for ($i = 0; $i < $this->iterations; $i++) {
            $callback();
        }
        
        $endTime = microtime(true);
        $endMem = memory_get_usage();
        
        return [
            'time_ms' => ($endTime - $startTime) * 1000,
            'memory_bytes' => $endMem - $startMem
        ];
    }

    private function testSimpleSelect(): void
    {
        echo "\n1.1 SELECT simple (SELECT * FROM users)\n";
        echo str_repeat('-', 70) . "\n";
        
        $results = [];
        
        // PDO
        $results['PDO'] = $this->measure(fn() => 
            $this->pdo->query("SELECT * FROM users LIMIT 10")->fetchAll(),
            'PDO'
        );
        
        // SQL.php
        $results['SQL.php'] = $this->measure(fn() => 
            SQL::buildSelect('*', 'users', [], [], [], [], 0, 10),
            'SQL.php'
        );
        
        // W
        $results['W'] = $this->measure(fn() => 
            W::from('users')->select('*', [], 10),
            'W'
        );
        
        // Wm
        $results['Wm'] = $this->measure(fn() => 
            Wm::from('users')->select('*', [], 10),
            'Wm'
        );
        
        // B+F
        $results['B+F'] = $this->measure(fn() => 
            F::fromBuilder(B::from('users')->limit(10))->select(),
            'B+F'
        );
        
        // EB+EF
        $results['EB+EF'] = $this->measure(fn() => 
            EF::fromBuilder(EB::from('users')->limit(10))->select(),
            'EB+EF'
        );
        
        // B2+F2
        $results['B2+F2'] = $this->measure(fn() => 
            F2::fromBuilder(B2::from('users')->limit(10))->select(),
            'B2+F2'
        );
        
        // Q
        $results['Q'] = $this->measure(fn() => 
            Q::from('users')->select('*', 10),
            'Q'
        );
        
        $this->printResults($results, 'SELECT simple');
        $this->results['simple_select'] = $results;
    }

    private function testSelectWithWhere(): void
    {
        echo "\n1.2 SELECT con WHERE (status = 'active')\n";
        echo str_repeat('-', 70) . "\n";
        
        $results = [];
        
        // PDO
        $results['PDO'] = $this->measure(fn() => {
            $stmt = $this->pdo->prepare("SELECT * FROM users WHERE status = ?");
            $stmt->execute(['active']);
            return $stmt->fetchAll();
        }, 'PDO');
        
        // SQL.php
        $results['SQL.php'] = $this->measure(fn() => 
            SQL::buildSelect('*', 'users', ['status' => 'active']),
            'SQL.php'
        );
        
        // W
        $results['W'] = $this->measure(fn() => 
            W::from('users', ['status' => 'active'])->select('*'),
            'W'
        );
        
        // Wm
        $results['Wm'] = $this->measure(fn() => 
            Wm::from('users', ['status' => 'active'])->select('*'),
            'Wm'
        );
        
        // B+F
        $results['B+F'] = $this->measure(fn() => 
            F::fromBuilder(B::from('users', ['status' => 'active']))->select(),
            'B+F'
        );
        
        // EB+EF
        $results['EB+EF'] = $this->measure(fn() => 
            EF::fromBuilder(EB::from('users', ['status' => 'active']))->select(),
            'EB+EF'
        );
        
        // B2+F2
        $results['B2+F2'] = $this->measure(fn() => 
            F2::fromBuilder(B2::from('users', ['status' => 'active']))->select(),
            'B2+F2'
        );
        
        // Q
        $results['Q'] = $this->measure(fn() => 
            Q::from('users', ['status' => 'active'])->select('*'),
            'Q'
        );
        
        $this->printResults($results, 'SELECT con WHERE');
        $this->results['select_where'] = $results;
    }

    private function testSelectWithOrderLimit(): void
    {
        echo "\n1.3 SELECT con ORDER BY y LIMIT\n";
        echo str_repeat('-', 70) . "\n";
        
        $results = [];
        
        // PDO
        $results['PDO'] = $this->measure(fn() => 
            $this->pdo->query("SELECT * FROM users ORDER BY name LIMIT 20 OFFSET 10")
                ->fetchAll(),
            'PDO'
        );
        
        // SQL.php
        $results['SQL.php'] = $this->measure(fn() => 
            SQL::buildSelect('*', 'users', [], [], [], ['name'], 10, 20),
            'SQL.php'
        );
        
        // W
        $results['W'] = $this->measure(fn() => 
            W::from('users')->select('*', 20, 'name', 10),
            'W'
        );
        
        // Wm
        $results['Wm'] = $this->measure(fn() => 
            Wm::from('users')->select('*', 20, 'name', 10),
            'Wm'
        );
        
        // B+F
        $results['B+F'] = $this->measure(fn() => 
            F::fromBuilder(B::from('users')->orderBy('name')->limit(20, 10))->select(),
            'B+F'
        );
        
        // EB+EF
        $results['EB+EF'] = $this->measure(fn() => 
            EF::fromBuilder(EB::from('users')->orderBy('name')->limit(20, 10))->select(),
            'EB+EF'
        );
        
        // B2+F2
        $results['B2+F2'] = $this->measure(fn() => 
            F2::fromBuilder(B2::from('users')->orderBy('name')->limit(20, 10))->select(),
            'B2+F2'
        );
        
        // Q
        $results['Q'] = $this->measure(fn() => 
            Q::from('users')->select('*', [10, 20], ['name']),
            'Q'
        );
        
        $this->printResults($results, 'SELECT con ORDER BY + LIMIT');
        $this->results['select_order_limit'] = $results;
    }

    private function testCount(): void
    {
        echo "\n1.4 COUNT con WHERE\n";
        echo str_repeat('-', 70) . "\n";
        
        $results = [];
        
        // PDO
        $results['PDO'] = $this->measure(fn() => 
            $this->pdo->prepare("SELECT COUNT(*) FROM users WHERE status = ?")
                ->execute(['active'])
                ->fetchColumn(),
            'PDO'
        );
        
        // SQL.php
        $results['SQL.php'] = $this->measure(fn() => 
            SQL::buildCount('users', ['status' => 'active']),
            'SQL.php'
        );
        
        // W
        $results['W'] = $this->measure(fn() => 
            W::from('users', ['status' => 'active'])->exec('count'),
            'W'
        );
        
        // Wm
        $results['Wm'] = $this->measure(fn() => 
            Wm::from('users', ['status' => 'active'])->exec('count'),
            'Wm'
        );
        
        // B+F
        $results['B+F'] = $this->measure(fn() => 
            F::fromBuilder(B::from('users', ['status' => 'active']))->count(),
            'B+F'
        );
        
        // EB+EF
        $results['EB+EF'] = $this->measure(fn() => 
            EF::fromBuilder(EB::from('users', ['status' => 'active']))->count(),
            'EB+EF'
        );
        
        // B2+F2
        $results['B2+F2'] = $this->measure(fn() => 
            F2::fromBuilder(B2::from('users', ['status' => 'active']))->count(),
            'B2+F2'
        );
        
        // Q
        $results['Q'] = $this->measure(fn() => 
            Q::from('users', ['status' => 'active'])->exec('count'),
            'Q'
        );
        
        $this->printResults($results, 'COUNT con WHERE');
        $this->results['count'] = $results;
    }

    private function testInsert(): void
    {
        echo "\n2.1 INSERT de un registro\n";
        echo str_repeat('-', 70) . "\n";
        
        $results = [];
        
        // PDO
        $results['PDO'] = $this->measure(fn() => 
            $this->pdo->prepare("INSERT INTO users (name, email, status) VALUES (?, ?, ?)")
                ->execute(['Test User', 'test@example.com', 'active']),
            'PDO'
        );
        
        // SQL.php
        $results['SQL.php'] = $this->measure(fn() => 
            SQL::buildInsert('users', ['name' => 'Test User', 'email' => 'test@example.com', 'status' => 'active']),
            'SQL.php'
        );
        
        // W
        $results['W'] = $this->measure(fn() => 
            W::from('users')->exec('insert', ['name' => 'Test User', 'email' => 'test@example.com', 'status' => 'active']),
            'W'
        );
        
        // Wm
        $results['Wm'] = $this->measure(fn() => 
            Wm::from('users')->exec('insert', ['name' => 'Test User', 'email' => 'test@example.com', 'status' => 'active']),
            'Wm'
        );
        
        // B+F
        $results['B+F'] = $this->measure(fn() => 
            F::fromBuilder(B::into('users'))->insert(['name' => 'Test User', 'email' => 'test@example.com', 'status' => 'active']),
            'B+F'
        );
        
        // EB+EF
        $results['EB+EF'] = $this->measure(fn() => 
            EF::fromBuilder(EB::into('users'))->insert(['name' => 'Test User', 'email' => 'test@example.com', 'status' => 'active']),
            'EB+EF'
        );
        
        // B2+F2
        $results['B2+F2'] = $this->measure(fn() => 
            F2::fromBuilder(B2::into('users'))->insert(['name' => 'Test User', 'email' => 'test@example.com', 'status' => 'active']),
            'B2+F2'
        );
        
        // Q
        $results['Q'] = $this->measure(fn() => 
            Q::into('users')->insert(['name' => 'Test User', 'email' => 'test@example.com', 'status' => 'active']),
            'Q'
        );
        
        $this->printResults($results, 'INSERT');
        $this->results['insert'] = $results;
    }

    private function testUpdate(): void
    {
        echo "\n2.2 UPDATE con WHERE\n";
        echo str_repeat('-', 70) . "\n";
        
        $results = [];
        
        // PDO
        $results['PDO'] = $this->measure(fn() => 
            $this->pdo->prepare("UPDATE users SET status = ? WHERE id = ?")
                ->execute(['inactive', 1]),
            'PDO'
        );
        
        // SQL.php
        $results['SQL.php'] = $this->measure(fn() => 
            SQL::buildUpdate('users', ['status' => 'inactive'], ['id' => 1]),
            'SQL.php'
        );
        
        // W
        $results['W'] = $this->measure(fn() => 
            W::from('users', ['id' => 1])->exec('update', ['status' => 'inactive']),
            'W'
        );
        
        // Wm
        $results['Wm'] = $this->measure(fn() => 
            Wm::from('users', ['id' => 1])->exec('update', ['status' => 'inactive']),
            'Wm'
        );
        
        // B+F
        $results['B+F'] = $this->measure(fn() => 
            F::fromBuilder(B::from('users', ['id' => 1]))->update(['status' => 'inactive']),
            'B+F'
        );
        
        // EB+EF
        $results['EB+EF'] = $this->measure(fn() => 
            EF::fromBuilder(EB::from('users', ['id' => 1]))->update(['status' => 'inactive']),
            'EB+EF'
        );
        
        // B2+F2
        $results['B2+F2'] = $this->measure(fn() => 
            F2::fromBuilder(B2::from('users', ['id' => 1]))->update(['status' => 'inactive']),
            'B2+F2'
        );
        
        // Q
        $results['Q'] = $this->measure(fn() => 
            Q::from('users', ['id' => 1])->exec('update', ['status' => 'inactive']),
            'Q'
        );
        
        $this->printResults($results, 'UPDATE');
        $this->results['update'] = $results;
    }

    private function testDelete(): void
    {
        echo "\n2.3 DELETE con WHERE\n";
        echo str_repeat('-', 70) . "\n";
        
        $results = [];
        
        // PDO
        $results['PDO'] = $this->measure(fn() => 
            $this->pdo->prepare("DELETE FROM users WHERE id = ?")
                ->execute([999]),
            'PDO'
        );
        
        // SQL.php
        $results['SQL.php'] = $this->measure(fn() => 
            SQL::buildDelete('users', ['id' => 999]),
            'SQL.php'
        );
        
        // W
        $results['W'] = $this->measure(fn() => 
            W::from('users', ['id' => 999])->exec('delete'),
            'W'
        );
        
        // Wm
        $results['Wm'] = $this->measure(fn() => 
            Wm::from('users', ['id' => 999])->exec('delete'),
            'Wm'
        );
        
        // B+F
        $results['B+F'] = $this->measure(fn() => 
            F::fromBuilder(B::from('users', ['id' => 999]))->delete(),
            'B+F'
        );
        
        // EB+EF
        $results['EB+EF'] = $this->measure(fn() => 
            EF::fromBuilder(EB::from('users', ['id' => 999]))->delete(),
            'EB+EF'
        );
        
        // B2+F2
        $results['B2+F2'] = $this->measure(fn() => 
            F2::fromBuilder(B2::from('users', ['id' => 999]))->delete(),
            'B2+F2'
        );
        
        // Q
        $results['Q'] = $this->measure(fn() => 
            Q::from('users', ['id' => 999])->exec('delete'),
            'Q'
        );
        
        $this->printResults($results, 'DELETE');
        $this->results['delete'] = $results;
    }

    private function testJoin2Tables(): void
    {
        echo "\n3.1 JOIN de 2 tablas (users + posts)\n";
        echo str_repeat('-', 70) . "\n";
        
        $results = [];
        
        // PDO
        $results['PDO'] = $this->measure(fn() => 
            $this->pdo->query("
                SELECT u.*, p.title, p.content 
                FROM users u 
                INNER JOIN posts p ON u.id = p.user_id 
                WHERE u.status = 'active' 
                LIMIT 20
            ")->fetchAll(),
            'PDO'
        );
        
        // W con JOIN
        $results['W'] = $this->measure(fn() => 
            W::from(['users', 'posts'])
                ->select('u.*, p.title, p.content', ['u.status' => 'active'], 20),
            'W'
        );
        
        // Wm con JOIN
        $results['Wm'] = $this->measure(fn() => 
            Wm::from(['users', 'posts'])
                ->select('u.*, p.title, p.content', ['u.status' => 'active'], 20),
            'Wm'
        );
        
        // B+F con JOIN manual
        $results['B+F'] = $this->measure(fn() => 
            F::fromBuilder(
                B::from('users')
                    ->join('INNER JOIN posts p ON u.id = p.user_id')
                    ->where(['u.status' => 'active'])
                    ->limit(20)
            )->select('u.*, p.title, p.content'),
            'B+F'
        );
        
        // EB+EF con JOIN
        $results['EB+EF'] = $this->measure(fn() => 
            EF::fromBuilder(
                EB::from('users')
                    ->join('LEFT', '"posts" AS "p"', '"u"."id" = "p"."user_id"')
                    ->where(['u.status' => 'active'])
                    ->limit(20)
            )->select('u.*, p.title, p.content'),
            'EB+EF'
        );
        
        // B2+F2 con AutoJoin
        $results['B2+F2'] = $this->measure(fn() => 
            F2::fromBuilder(
                B2::from('users as u')
                    ->addAutoJoin('posts as p', ['u' => 'users'])
                    ->where(['u.status' => 'active'])
                    ->limit(20)
            )->select('u.*, p.title, p.content'),
            'B2+F2'
        );
        
        // Q con JOIN
        $results['Q'] = $this->measure(fn() => 
            Q::from('users as u')
                ->select('u.*, p.title, p.content', 20, [], null, [], 
                    ['type' => 'INNER', 'table' => 'posts as p', 'on' => 'u.id = p.user_id'],
                    ['u.status' => 'active']
                ),
            'Q'
        );
        
        $this->printResults($results, 'JOIN 2 tablas');
        $this->results['join_2_tables'] = $results;
    }

    private function testJoin3Tables(): void
    {
        echo "\n3.2 JOIN de 3 tablas (users + posts + comments)\n";
        echo str_repeat('-', 70) . "\n";
        
        $results = [];
        
        // PDO
        $results['PDO'] = $this->measure(fn() => 
            $this->pdo->query("
                SELECT u.name, p.title, c.content as comment
                FROM users u
                INNER JOIN posts p ON u.id = p.user_id
                INNER JOIN comments c ON p.id = c.post_id
                WHERE u.status = 'active'
                LIMIT 20
            ")->fetchAll(),
            'PDO'
        );
        
        // W con 3 tablas
        $results['W'] = $this->measure(fn() => 
            W::from(['users', 'posts', 'comments'])
                ->select('u.name, p.title, c.content as comment', ['u.status' => 'active'], 20),
            'W'
        );
        
        // Wm con 3 tablas
        $results['Wm'] = $this->measure(fn() => 
            Wm::from(['users', 'posts', 'comments'])
                ->select('u.name, p.title, c.content as comment', ['u.status' => 'active'], 20),
            'Wm'
        );
        
        // B+F con JOINs manuales
        $results['B+F'] = $this->measure(fn() => 
            F::fromBuilder(
                B::from('users')
                    ->join('INNER JOIN posts p ON u.id = p.user_id')
                    ->join('INNER JOIN comments c ON p.id = c.post_id')
                    ->where(['u.status' => 'active'])
                    ->limit(20)
            )->select('u.name, p.title, c.content as comment'),
            'B+F'
        );
        
        // EB+EF con múltiples JOINs
        $results['EB+EF'] = $this->measure(fn() => 
            EF::fromBuilder(
                EB::from('users')
                    ->join('INNER', '"posts" AS "p"', '"u"."id" = "p"."user_id"')
                    ->join('INNER', '"comments" AS "c"', '"p"."id" = "c"."post_id"')
                    ->where(['u.status' => 'active'])
                    ->limit(20)
            )->select('u.name, p.title, c.content as comment'),
            'EB+EF'
        );
        
        // B2+F2 con AutoJoin múltiple
        $results['B2+F2'] = $this->measure(fn() => 
            F2::fromBuilder(
                B2::from('users as u')
                    ->addAutoJoin('posts as p', ['u' => 'users'])
                    ->addAutoJoin('comments as c', ['p' => 'posts'])
                    ->where(['u.status' => 'active'])
                    ->limit(20)
            )->select('u.name, p.title, c.content as comment'),
            'B2+F2'
        );
        
        // Q con múltiples JOINs
        $results['Q'] = $this->measure(fn() => 
            Q::from('users as u')
                ->select('u.name, p.title, c.content as comment', 20, [], null, [],
                    [
                        ['type' => 'INNER', 'table' => 'posts as p', 'on' => 'u.id = p.user_id'],
                        ['type' => 'INNER', 'table' => 'comments as c', 'on' => 'p.id = c.post_id']
                    ],
                    ['u.status' => 'active']
                ),
            'Q'
        );
        
        $this->printResults($results, 'JOIN 3 tablas');
        $this->results['join_3_tables'] = $results;
    }

    private function testJoin4Tables(): void
    {
        echo "\n3.3 JOIN de 4 tablas (users + posts + comments + categories)\n";
        echo str_repeat('-', 70) . "\n";
        
        $results = [];
        
        // PDO
        $results['PDO'] = $this->measure(fn() => 
            $this->pdo->query("
                SELECT u.name, p.title, c.content as comment, cat.name as category
                FROM users u
                INNER JOIN posts p ON u.id = p.user_id
                INNER JOIN comments c ON p.id = c.post_id
                INNER JOIN categories cat ON p.category_id = cat.id
                WHERE u.status = 'active' AND c.rating >= 3
                LIMIT 20
            ")->fetchAll(),
            'PDO'
        );
        
        // W con 4 tablas
        $results['W'] = $this->measure(fn() => 
            W::from(['users', 'posts', 'comments', 'categories'])
                ->select('u.name, p.title, c.content as comment, cat.name as category', 
                         ['u.status' => 'active', 'c.rating' => 3], 20),
            'W'
        );
        
        // Wm con 4 tablas
        $results['Wm'] = $this->measure(fn() => 
            Wm::from(['users', 'posts', 'comments', 'categories'])
                ->select('u.name, p.title, c.content as comment, cat.name as category', 
                         ['u.status' => 'active', 'c.rating' => 3], 20),
            'Wm'
        );
        
        // B+F con múltiples JOINs
        $results['B+F'] = $this->measure(fn() => 
            F::fromBuilder(
                B::from('users')
                    ->join('INNER JOIN posts p ON u.id = p.user_id')
                    ->join('INNER JOIN comments c ON p.id = c.post_id')
                    ->join('INNER JOIN categories cat ON p.category_id = cat.id')
                    ->where(['u.status' => 'active'])
                    ->limit(20)
            )->select('u.name, p.title, c.content as comment, cat.name as category'),
            'B+F'
        );
        
        // EB+EF con 4 JOINs
        $results['EB+EF'] = $this->measure(fn() => 
            EF::fromBuilder(
                EB::from('users')
                    ->join('INNER', '"posts" AS "p"', '"u"."id" = "p"."user_id"')
                    ->join('INNER', '"comments" AS "c"', '"p"."id" = "c"."post_id"')
                    ->join('INNER', '"categories" AS "cat"', '"p"."category_id" = "cat"."id"')
                    ->where(['u.status' => 'active', 'c.rating' => 3])
                    ->limit(20)
            )->select('u.name, p.title, c.content as comment, cat.name as category'),
            'EB+EF'
        );
        
        // B2+F2 con AutoJoin en cadena
        $results['B2+F2'] = $this->measure(fn() => 
            F2::fromBuilder(
                B2::from('users as u')
                    ->addAutoJoin('posts as p', ['u' => 'users'])
                    ->addAutoJoin('comments as c', ['p' => 'posts'])
                    ->addAutoJoin('categories as cat', ['p' => 'posts'])
                    ->where(['u.status' => 'active', 'c.rating' => 3])
                    ->limit(20)
            )->select('u.name, p.title, c.content as comment, cat.name as category'),
            'B2+F2'
        );
        
        $this->printResults($results, 'JOIN 4 tablas');
        $this->results['join_4_tables'] = $results;
    }

    private function testJoin5Tables(): void
    {
        echo "\n3.4 JOIN de 5 tablas (users + posts + comments + categories + countries)\n";
        echo str_repeat('-', 70) . "\n";
        
        $results = [];
        
        // PDO
        $results['PDO'] = $this->measure(fn() => 
            $this->pdo->query("
                SELECT u.name, p.title, c.content as comment, 
                       cat.name as category, co.name as country
                FROM users u
                INNER JOIN posts p ON u.id = p.user_id
                INNER JOIN comments c ON p.id = c.post_id
                INNER JOIN categories cat ON p.category_id = cat.id
                INNER JOIN countries co ON u.country_id = co.id
                WHERE u.status = 'active' AND c.rating >= 3
                LIMIT 20
            ")->fetchAll(),
            'PDO'
        );
        
        // W con 5 tablas
        $results['W'] = $this->measure(fn() => 
            W::from(['users', 'posts', 'comments', 'categories', 'countries'])
                ->select('u.name, p.title, c.content, cat.name as category, co.name as country', 
                         ['u.status' => 'active', 'c.rating' => 3], 20),
            'W'
        );
        
        // Wm con 5 tablas
        $results['Wm'] = $this->measure(fn() => 
            Wm::from(['users', 'posts', 'comments', 'categories', 'countries'])
                ->select('u.name, p.title, c.content, cat.name as category, co.name as country', 
                         ['u.status' => 'active', 'c.rating' => 3], 20),
            'Wm'
        );
        
        // B+F con 5 JOINs
        $results['B+F'] = $this->measure(fn() => 
            F::fromBuilder(
                B::from('users')
                    ->join('INNER JOIN posts p ON u.id = p.user_id')
                    ->join('INNER JOIN comments c ON p.id = c.post_id')
                    ->join('INNER JOIN categories cat ON p.category_id = cat.id')
                    ->join('INNER JOIN countries co ON u.country_id = co.id')
                    ->where(['u.status' => 'active'])
                    ->limit(20)
            )->select('u.name, p.title, c.content, cat.name as category, co.name as country'),
            'B+F'
        );
        
        // EB+EF con 5 JOINs
        $results['EB+EF'] = $this->measure(fn() => 
            EF::fromBuilder(
                EB::from('users')
                    ->join('INNER', '"posts" AS "p"', '"u"."id" = "p"."user_id"')
                    ->join('INNER', '"comments" AS "c"', '"p"."id" = "c"."post_id"')
                    ->join('INNER', '"categories" AS "cat"', '"p"."category_id" = "cat"."id"')
                    ->join('INNER', '"countries" AS "co"', '"u"."country_id" = "co"."id"')
                    ->where(['u.status' => 'active', 'c.rating' => 3])
                    ->limit(20)
            )->select('u.name, p.title, c.content, cat.name as category, co.name as country'),
            'EB+EF'
        );
        
        // B2+F2 con 5 AutoJoins
        $results['B2+F2'] = $this->measure(fn() => 
            F2::fromBuilder(
                B2::from('users as u')
                    ->addAutoJoin('posts as p', ['u' => 'users'])
                    ->addAutoJoin('comments as c', ['p' => 'posts'])
                    ->addAutoJoin('categories as cat', ['p' => 'posts'])
                    ->addAutoJoin('countries as co', ['u' => 'users'])
                    ->where(['u.status' => 'active', 'c.rating' => 3])
                    ->limit(20)
            )->select('u.name, p.title, c.content, cat.name as category, co.name as country'),
            'B2+F2'
        );
        
        $this->printResults($results, 'JOIN 5 tablas');
        $this->results['join_5_tables'] = $results;
    }

    private function printResults(array $results, string $testName): void
    {
        // Encontrar el más rápido como referencia
        $minTime = PHP_FLOAT_MAX;
        $fastestEngine = '';
        foreach ($results as $engine => $data) {
            if ($data['time_ms'] < $minTime) {
                $minTime = $data['time_ms'];
                $fastestEngine = $engine;
            }
        }
        
        printf("%-12s | %12s | %12s | %10s\n", "Engine", "Tiempo (ms)", "Memoria (KB)", "vs Ref");
        echo str_repeat('-', 70) . "\n";
        
        foreach ($results as $engine => $data) {
            $ratio = $minTime > 0 ? $data['time_ms'] / $minTime : 0;
            $memKB = $data['memory_bytes'] / 1024;
            
            $indicator = '';
            if ($engine === $fastestEngine) {
                $indicator = ' 🏆';
            } elseif ($ratio > 2) {
                $indicator = ' ⚠️';
            }
            
            printf("%-12s | %12.2f | %12.2f | %10.2fx%s\n", 
                $engine, 
                $data['time_ms'], 
                $memKB, 
                $ratio,
                $indicator
            );
        }
    }

    private function printSummary(): void
    {
        echo "\n";
        echo "╔══════════════════════════════════════════════════════════════╗\n";
        echo "║                    RESUMEN FINAL                             ║\n";
        echo "╚══════════════════════════════════════════════════════════════╝\n\n";
        
        // Calcular promedios por engine
        $engineTotals = [];
        $engineCounts = [];
        
        foreach ($this->results as $testName => $testResults) {
            foreach ($testResults as $engine => $data) {
                if (!isset($engineTotals[$engine])) {
                    $engineTotals[$engine] = 0;
                    $engineCounts[$engine] = 0;
                }
                $engineTotals[$engine] += $data['time_ms'];
                $engineCounts[$engine]++;
            }
        }
        
        echo "TIEMPO PROMEDIO POR MOTOR (menor es mejor):\n";
        echo str_repeat('-', 70) . "\n";
        
        $averages = [];
        foreach ($engineTotals as $engine => $total) {
            $averages[$engine] = $total / $engineCounts[$engine];
        }
        
        asort($averages);
        
        $rank = 1;
        foreach ($averages as $engine => $avg) {
            $medal = '';
            if ($rank === 1) $medal = '🥇';
            elseif ($rank === 2) $medal = '🥈';
            elseif ($rank === 3) $medal = '🥉';
            
            printf("%s %-12s | %12.2f ms promedio\n", $medal, $engine, $avg);
            $rank++;
        }
        
        echo "\n";
        echo "CONCLUSIONES:\n";
        echo str_repeat('-', 70) . "\n";
        
        $fastest = array_key_first($averages);
        $slowest = array_key_last($averages);
        
        if ($fastest && $slowest) {
            $speedup = $averages[$slowest] / $averages[$fastest];
            echo "• Motor más rápido: {$fastest}\n";
            echo "• Motor más lento: {$slowest}\n";
            echo "• Diferencia: " . number_format($speedup, 2) . "x más rápido\n";
        }
        
        echo "\n";
        echo "NOTAS:\n";
        echo "- PDO Directo sirve como referencia base (1x)\n";
        echo "- Los valores muestran tiempo de generación de SQL (no ejecución)\n";
        echo "- 🏆 indica el ganador en cada categoría\n";
        echo "- ⚠️ indica rendimiento significativamente menor (>2x más lento)\n";
    }
}

// Ejecutar benchmark
$benchmark = new EngineComparisonBenchmark(10000, false);
$benchmark->run();
