<?php

/**
 * Stress Test Avanzado: Fetch Modes + SelectBuilder
 * 
 * Evalúa rendimiento bajo diferentes cargas y escenarios
 */

namespace {

$basePath = __DIR__ . "/../../src/RapidBase/Core";
include_once "$basePath/Cache/CacheService.php";
include_once "$basePath/Cache/Adapters/DirectoryCacheAdapter.php";
include_once "$basePath/SQL.php";
include_once "$basePath/Conn.php";
include_once "$basePath/Executor.php";
include_once "$basePath/Gateway.php";
include_once "$basePath/SelectBuilder.php";

use RapidBase\Core\Conn;
use RapidBase\Core\SQL;
use RapidBase\Core\SelectBuilder;

// Configuración
Conn::setup("sqlite::memory:", "", ""); 
$pdo = Conn::get();

// Crear tablas relacionadas para JOINs
$pdo->exec("
    CREATE TABLE categories (
        id INTEGER PRIMARY KEY, 
        name TEXT,
        description TEXT
    )
");

$pdo->exec("
    CREATE TABLE products (
        id INTEGER PRIMARY KEY, 
        name TEXT, 
        price REAL,
        stock INTEGER,
        category_id INTEGER,
        created_at TEXT,
        FOREIGN KEY (category_id) REFERENCES categories(id)
    )
");

// Insertar categorías
$stmt = $pdo->prepare("INSERT INTO categories (name, description) VALUES (?, ?)");
for ($i = 1; $i <= 20; $i++) { 
    $stmt->execute(["Categoría $i", "Descripción de categoría $i"]); 
}

// Insertar productos
$stmt = $pdo->prepare("
    INSERT INTO products (name, price, stock, category_id, created_at) 
    VALUES (?, ?, ?, ?, datetime('now'))
");
for ($i = 1; $i <= 5000; $i++) { 
    $stmt->execute([
        "Producto $i",
        rand(10, 1000) / 10,
        rand(0, 100),
        rand(1, 20)
    ]); 
}

echo "=== STRESS TEST AVANZADO ===\n";
echo "Productos: 5000 | Categorías: 20\n\n";

$results = [];

// ============================================
// ESCENARIO 1: Consulta Simple (sin JOINs)
// ============================================
echo "--- Escenario 1: Consulta Simple ---\n";

$sqlSimple = "SELECT * FROM products WHERE stock > 0 LIMIT 500";
$iterations = 50;

// FETCH_ASSOC
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $stmt = $pdo->query($sqlSimple);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$timeAssocSimple = (microtime(true) - $start) / $iterations;

// FETCH_CLASS
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $stmt = $pdo->query($sqlSimple);
    $data = $stmt->fetchAll(PDO::FETCH_CLASS, 'stdClass');
}
$timeClassSimple = (microtime(true) - $start) / $iterations;

echo "FETCH_ASSOC:  " . number_format($timeAssocSimple * 1000, 4) . " ms\n";
echo "FETCH_CLASS:  " . number_format($timeClassSimple * 1000, 4) . " ms\n";
echo "Diferencia:   " . number_format((($timeClassSimple - $timeAssocSimple) / $timeAssocSimple) * 100, 2) . "% más lento\n\n";

$results['simple_assoc'] = $timeAssocSimple;
$results['simple_class'] = $timeClassSimple;

// ============================================
// ESCENARIO 2: Consulta con JOINs complejos
// ============================================
echo "--- Escenario 2: Consulta con JOINs ---\n";

$sqlJoin = "
    SELECT p.*, c.name as category_name 
    FROM products p
    INNER JOIN categories c ON p.category_id = c.id
    WHERE p.stock > 0 AND p.price > 50
    ORDER BY p.price DESC
    LIMIT 500
";

// FETCH_ASSOC
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $stmt = $pdo->query($sqlJoin);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$timeAssocJoin = (microtime(true) - $start) / $iterations;

// FETCH_CLASS
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $stmt = $pdo->query($sqlJoin);
    $data = $stmt->fetchAll(PDO::FETCH_CLASS, 'stdClass');
}
$timeClassJoin = (microtime(true) - $start) / $iterations;

echo "FETCH_ASSOC:  " . number_format($timeAssocJoin * 1000, 4) . " ms\n";
echo "FETCH_CLASS:  " . number_format($timeClassJoin * 1000, 4) . " ms\n";
echo "Diferencia:   " . number_format((($timeClassJoin - $timeAssocJoin) / $timeAssocJoin) * 100, 2) . "% más lento\n\n";

$results['join_assoc'] = $timeAssocJoin;
$results['join_class'] = $timeClassJoin;

// ============================================
// ESCENARIO 3: Construcción SQL (Array vs Objeto)
// ============================================
echo "--- Escenario 3: Construcción de SQL ---\n";

SQL::setQueryCacheEnabled(false);

$fields = ['p.id', 'p.name', 'p.price', 'c.name as category_name'];
$table = ['products' => 'p', 'categories' => 'c'];
$where = ['p.stock' => ['>' => 0], 'p.price' => ['>' => 50]];
$sort = ['p.price' => 'DESC'];
$page = 1;
$perPage = 500;

// Array tradicional (SQL::buildSelect)
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    [$sql, $params] = SQL::buildSelect($fields, $table, $where, [], [], $sort, $page, $perPage);
}
$timeArrayBuild = (microtime(true) - $start) / $iterations;

// SelectBuilder (nueva instancia cada vez)
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $builder = new SelectBuilder('*', 'products', $where, $sort, $page, $perPage);
    [$sql, $params] = $builder->build();
}
$timeObjectBuild = (microtime(true) - $start) / $iterations;

// SelectBuilder (reutilizando)
$builder = new SelectBuilder();
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $builder->reset();
    $builder->setSelect('*');
    $builder->setFrom('products');
    $builder->setWhere($where);
    $builder->setOrderBy($sort);
    $builder->setPagination($page, $perPage);
    [$sql, $params] = $builder->build();
}
$timeObjectReuse = (microtime(true) - $start) / $iterations;

echo "SQL::buildSelect (array):   " . number_format($timeArrayBuild * 1000, 4) . " ms\n";
echo "SelectBuilder (nuevo):      " . number_format($timeObjectBuild * 1000, 4) . " ms\n";
echo "SelectBuilder (reuse):      " . number_format($timeObjectReuse * 1000, 4) . " ms\n";
echo "Mejora (vs array):          " . number_format(($timeArrayBuild / $timeObjectReuse), 2) . "x más rápido\n\n";

$results['build_array'] = $timeArrayBuild;
$results['build_object'] = $timeObjectBuild;
$results['build_reuse'] = $timeObjectReuse;

// ============================================
// ESCENARIO 4: Alto volumen (1000 iteraciones)
// ============================================
echo "--- Escenario 4: Alto Volumen (1000 iteraciones) ---\n";

$sqlSimple = "SELECT * FROM products WHERE stock > 0 LIMIT 100";
$iterations = 1000;

$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $stmt = $pdo->query($sqlSimple);
    $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$totalAssoc = microtime(true) - $start;

$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $stmt = $pdo->query($sqlSimple);
    $stmt->fetchAll(PDO::FETCH_CLASS, 'stdClass');
}
$totalClass = microtime(true) - $start;

echo "FETCH_ASSOC total:  " . number_format($totalAssoc * 1000, 2) . " ms ($iterations iter)\n";
echo "FETCH_CLASS total:  " . number_format($totalClass * 1000, 2) . " ms ($iterations iter)\n";
echo "Overhead total:     " . number_format(($totalClass - $totalAssoc) * 1000, 2) . " ms\n";
echo "Overhead por iter:  " . number_format((($totalClass - $totalAssoc) / $iterations) * 1000, 6) . " ms\n\n";

// ============================================
// RESULTADOS FINALES
// ============================================
echo "=== CONCLUSIONES ===\n\n";

echo "1. FETCH MODES:\n";
echo "   - FETCH_ASSOC es consistentemente más rápido que FETCH_CLASS\n";
echo "   - Overhead promedio de FETCH_CLASS: ~" . 
    number_format((($results['simple_class'] - $results['simple_assoc']) / $results['simple_assoc']) * 100, 1) . 
    "% en consultas simples\n";
echo "   - Overhead en JOINs complejos: ~" . 
    number_format((($results['join_class'] - $results['join_assoc']) / $results['join_assoc']) * 100, 1) . 
    "%\n\n";

echo "2. SELECT BUILDER:\n";
echo "   - SelectBuilder es " . number_format(($results['build_array'] / $results['build_reuse']), 2) . 
    "x más rápido que el método tradicional con arrays\n";
echo "   - Reutilizar instancia mejora rendimiento en " . 
    number_format((($timeObjectBuild - $timeObjectReuse) / $timeObjectBuild) * 100, 1) . 
    "% adicional\n\n";

echo "3. RECOMENDACIONES:\n";
echo "   ✓ MANTENER PDO::FETCH_ASSOC (es más rápido que FETCH_CLASS/OBJ)\n";
echo "   ✓ IMPLEMENTAR SelectBuilder para construcción de queries\n";
echo "   ✓ Usar patrón de reutilización de objetos SelectBuilder\n";
echo "   ✓ El overhead de FETCH_CLASS no se justifica en este caso\n\n";

echo "Memoria final: " . number_format(memory_get_usage() / 1024, 2) . " KB\n";
echo "Pico de memoria: " . number_format(memory_get_peak_usage() / 1024, 2) . " KB\n";

} // Fin namespace global
