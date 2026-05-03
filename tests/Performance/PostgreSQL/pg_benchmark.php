<?php
/**
 * PostgreSQL Performance Benchmark
 * Idéntico al de SQLite3 pero usando PostgreSQL y el schema_map generado
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/config.php';

use RapidBase\Core\Conn;
use RapidBase\Core\DB;
use RapidBase\Meta\SchemaMapper;

// Usar configuración centralizada
$dsn = PGConfig::getDSN();
$user = PGConfig::DB_USER;
$pass = PGConfig::DB_PASS;

echo "==================================================\n";
echo "PERFORMANCE BENCHMARK (PostgreSQL)\n";
echo "PDO vs RapidBase (No Cache) vs RapidBase (Cache)\n";
echo "==================================================\n\n";

try {
    // 1. Configurar conexión
    Conn::setup($dsn, $user, $pass, 'pgsql');
    $pdo = Conn::get('pgsql');
    
    // 2. Cargar el Schema Map generado previamente
    $schemaFile = __DIR__ . '/schema_map.php';
    if (!file_exists($schemaFile)) {
        die("ERROR: No se encontró $schemaFile. Ejecuta primero generate_schema_map.php\n");
    }
    
    echo "Cargando schema_map desde: $schemaFile...\n";
    require_once $schemaFile;
    
    // El schema_map.php ya registra automáticamente las relaciones al ser incluido
    // No es necesario llamar a DB::init()
    
    echo "Conexión y Schema cargados correctamente.\n\n";

    // 3. Preparar datos (asumiendo que ya existen, si no, crearlos)
    // Verificamos si hay datos
    $count = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($count < 1000) {
        echo "Insertando datos de prueba (10,000 registros)...\n";
        $pdo->exec("TRUNCATE TABLE posts, comments, tags, post_tags, users RESTART IDENTITY CASCADE");
        
        $stmtUser = $pdo->prepare("INSERT INTO users (name, email) VALUES (?, ?)");
        $stmtPost = $pdo->prepare("INSERT INTO posts (user_id, title, content) VALUES (?, ?, ?)");
        $stmtTag = $pdo->prepare("INSERT INTO tags (name) VALUES (?)");
        $stmtRel = $pdo->prepare("INSERT INTO post_tags (post_id, tag_id) VALUES (?, ?)");

        $pdo->beginTransaction();
        for ($i = 0; $i < 10000; $i++) {
            $stmtUser->execute(["User $i", "user$i@test.com"]);
        }
        for ($i = 1; $i <= 5000; $i++) {
            $stmtPost->execute([($i % 10000) + 1, "Post $i", "Content $i"]);
        }
        for ($i = 0; $i < 50; $i++) {
            $stmtTag->execute(["Tag $i"]);
        }
        for ($i = 1; $i <= 5000; $i++) {
            $stmtRel->execute([$i, ($i % 50) + 1]);
        }
        $pdo->commit();
        echo "Datos insertados.\n\n";
    }

    $results = [];

    // --- ESCENARIO 1: Select Simple (100 iteraciones) ---
    echo "--- SCENARIO 1: Simple Select (100 iterations) ---\n";
    echo "Fetching 50 users...\n";

    // PDO
    $start = microtime(true);
    for ($i = 0; $i < 100; $i++) {
        $stmt = $pdo->query("SELECT * FROM users LIMIT 50");
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    $timePdoSimple = (microtime(true) - $start) / 100 * 1000;
    echo "PDO Native                    : " . number_format($timePdoSimple, 4) . " ms\n";

    // RapidBase No Cache
    $start = microtime(true);
    for ($i = 0; $i < 100; $i++) {
        $data = DB::table('users')->limit(50)->getAll();
    }
    $timeRbNoCacheSimple = (microtime(true) - $start) / 100 * 1000;
    echo "RapidBase (No Cache)          : " . number_format($timeRbNoCacheSimple, 4) . " ms\n";

    // RapidBase Cache (Warmup + Test)
    // Warmup
    DB::table('users')->limit(50)->getAll();
    
    $start = microtime(true);
    for ($i = 0; $i < 100; $i++) {
        $data = DB::table('users')->limit(50)->getAll();
    }
    $timeRbCacheSimple = (microtime(true) - $start) / 100 * 1000;
    echo "RapidBase (Cache Hit)         : " . number_format($timeRbCacheSimple, 4) . " ms\n\n";

    $results['simple'] = [$timePdoSimple, $timeRbNoCacheSimple, $timeRbCacheSimple];

    // --- ESCENARIO 2: Join 2 Tablas (50 iteraciones) ---
    echo "--- SCENARIO 2: Join 2 Tables (50 iterations) ---\n";
    echo "Fetching posts with users...\n";

    // PDO
    $start = microtime(true);
    for ($i = 0; $i < 50; $i++) {
        $stmt = $pdo->query("SELECT p.*, u.name as user_name FROM posts p JOIN users u ON p.user_id = u.id LIMIT 50");
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    $timePdoJoin2 = (microtime(true) - $start) / 50 * 1000;
    echo "PDO Native                    : " . number_format($timePdoJoin2, 4) . " ms\n";

    // RapidBase No Cache
    DB::useCache(false);
    $start = microtime(true);
    for ($i = 0; $i < 50; $i++) {
        // Usando relaciones definidas en el schema_map
        $data = DB::table('posts')
            ->join('users', 'posts.user_id', '=', 'users.id')
            ->fields(['posts.*', 'users.name as user_name'])
            ->limit(50)
            ->getAll();
    }
    $timeRbNoCacheJoin2 = (microtime(true) - $start) / 50 * 1000;
    echo "RapidBase (No Cache)          : " . number_format($timeRbNoCacheJoin2, 4) . " ms\n";

    // RapidBase Cache
    DB::useCache(true);
    // Warmup
    DB::table('posts')->join('users', 'posts.user_id', '=', 'users.id')->fields(['posts.*', 'users.name as user_name'])->limit(50)->getAll();

    $start = microtime(true);
    for ($i = 0; $i < 50; $i++) {
        $data = DB::table('posts')
            ->join('users', 'posts.user_id', '=', 'users.id')
            ->fields(['posts.*', 'users.name as user_name'])
            ->limit(50)
            ->getAll();
    }
    $timeRbCacheJoin2 = (microtime(true) - $start) / 50 * 1000;
    echo "RapidBase (Cache Hit)         : " . number_format($timeRbCacheJoin2, 4) . " ms\n\n";

    $results['join2'] = [$timePdoJoin2, $timeRbNoCacheJoin2, $timeRbCacheJoin2];

    // --- ESCENARIO 3: Join 3 Tablas (20 iteraciones) ---
    echo "--- SCENARIO 3: Join 3 Tables (20 iterations) ---\n";
    echo "Fetching posts with users and tags...\n";

    // PDO
    $start = microtime(true);
    for ($i = 0; $i < 20; $i++) {
        $sql = "SELECT p.*, u.name as user_name, t.name as tag_name 
                FROM posts p 
                JOIN users u ON p.user_id = u.id 
                JOIN post_tags pt ON p.id = pt.post_id 
                JOIN tags t ON pt.tag_id = t.id 
                LIMIT 50";
        $stmt = $pdo->query($sql);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    $timePdoJoin3 = (microtime(true) - $start) / 20 * 1000;
    echo "PDO Native                    : " . number_format($timePdoJoin3, 4) . " ms\n";

    // RapidBase No Cache
    DB::useCache(false);
    $start = microtime(true);
    for ($i = 0; $i < 20; $i++) {
        $data = DB::table('posts')
            ->join('users', 'posts.user_id', '=', 'users.id')
            ->join('post_tags', 'posts.id', '=', 'post_tags.post_id')
            ->join('tags', 'post_tags.tag_id', '=', 'tags.id')
            ->fields(['posts.*', 'users.name as user_name', 'tags.name as tag_name'])
            ->limit(50)
            ->getAll();
    }
    $timeRbNoCacheJoin3 = (microtime(true) - $start) / 20 * 1000;
    echo "RapidBase (No Cache)          : " . number_format($timeRbNoCacheJoin3, 4) . " ms\n";

    // RapidBase Cache
    DB::useCache(true);
    // Warmup
    DB::table('posts')
        ->join('users', 'posts.user_id', '=', 'users.id')
        ->join('post_tags', 'posts.id', '=', 'post_tags.post_id')
        ->join('tags', 'post_tags.tag_id', '=', 'tags.id')
        ->fields(['posts.*', 'users.name as user_name', 'tags.name as tag_name'])
        ->limit(50)
        ->getAll();

    $start = microtime(true);
    for ($i = 0; $i < 20; $i++) {
        $data = DB::table('posts')
            ->join('users', 'posts.user_id', '=', 'users.id')
            ->join('post_tags', 'posts.id', '=', 'post_tags.post_id')
            ->join('tags', 'post_tags.tag_id', '=', 'tags.id')
            ->fields(['posts.*', 'users.name as user_name', 'tags.name as tag_name'])
            ->limit(50)
            ->getAll();
    }
    $timeRbCacheJoin3 = (microtime(true) - $start) / 20 * 1000;
    echo "RapidBase (Cache Hit)         : " . number_format($timeRbCacheJoin3, 4) . " ms\n\n";

    $results['join3'] = [$timePdoJoin3, $timeRbNoCacheJoin3, $timeRbCacheJoin3];

    // --- SUMMARY ---
    echo "==================================================\n";
    echo "SUMMARY (Relative to PDO = 1.0x)\n";
    echo "==================================================\n\n";

    $scenarios = [
        'Simple Select' => $results['simple'],
        'Join 2 Tables' => $results['join2'],
        'Join 3 Tables' => $results['join3']
    ];

    foreach ($scenarios as $name => $times) {
        list($pdoT, $rbNcT, $rbCT) = $times;
        
        $ratioNc = $rbNcT / $pdoT;
        $ratioC = $rbCT / $pdoT;
        
        $speedup = ($pdoT / $rbCT);

        echo "$name:\n";
        echo "  PDO: 1.00x (Base)\n";
        echo "  RapidBase (No Cache): " . number_format($ratioNc, 2) . "x\n";
        echo "  RapidBase (Cache): " . number_format($ratioC, 2) . "x (" . number_format($speedup, 1) . "x FASTER than PDO)\n\n";
    }

    echo "✓ Benchmark completed.\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
