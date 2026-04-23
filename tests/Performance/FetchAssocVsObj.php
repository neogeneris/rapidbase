<?php
/**
 * Benchmark: FETCH_ASSOC vs FETCH_OBJ
 * 
 * Compara el rendimiento de fetch(PDO::FETCH_ASSOC) vs fetch(PDO::FETCH_OBJ)
 * Según: https://www.php.net/manual/es/pdostatement.fetch.php
 * 
 * - FETCH_ASSOC: Retorna un array asociativo
 * - FETCH_OBJ:   Retorna un objeto anónimo con propiedades mapeadas a columnas
 */

namespace {
// 1. CARGA DE LA FUNDACIÓN
$basePath = __DIR__ . "/../../src/RapidBase/Core";
include_once "$basePath/Cache/CacheService.php";
include_once "$basePath/Cache/Adapters/DirectoryCacheAdapter.php";
include_once "$basePath/SQL.php";
include_once "$basePath/Conn.php";
include_once "$basePath/Executor.php";
include_once "$basePath/Gateway.php";
include_once "$basePath/DBInterface.php";
include_once "$basePath/DB.php";

use RapidBase\Core\Conn;

/**
 * CONFIGURACIÓN DE CONEXIÓN
 */
Conn::setup("sqlite::memory:", "", "");
$pdo = Conn::get();

// Crear tabla de prueba con más columnas para simular caso real
$pdo->exec("
    CREATE TABLE products (
        id INTEGER PRIMARY KEY,
        name TEXT,
        price REAL,
        stock INTEGER,
        category_id INTEGER,
        created_at TEXT,
        updated_at TEXT,
        status TEXT,
        weight REAL,
        sku TEXT
    )
");

// Insertar datos de prueba
$stmt = $pdo->prepare("
    INSERT INTO products (name, price, stock, category_id, created_at, updated_at, status, weight, sku)
    VALUES (?, ?, ?, 1, datetime('now'), datetime('now'), 'active', 1.5, ?)
");

for ($i = 0; $i < 200; $i++) {
    $stmt->execute(["Product $i", 10.0 + $i, 100 + $i, "SKU-$i"]);
}

// Crear tablas adicionales para JOINs
$pdo->exec("
    CREATE TABLE categories (
        id INTEGER PRIMARY KEY,
        name TEXT
    )
");

$stmt = $pdo->prepare("INSERT INTO categories (name) VALUES (?)");
for ($i = 0; $i < 20; $i++) {
    $stmt->execute(["Category $i"]);
}

$pdo->exec("UPDATE products SET category_id = (id % 20) + 1");

$iterations = 1000;
$results = [];

echo "==============================================\n";
echo "BENCHMARK: FETCH_ASSOC vs FETCH_OBJ\n";
echo "Iteraciones: $iterations\n";
echo "==============================================\n\n";

// ============================================
// TEST 1: Consulta Simple (1 tabla, 10 columnas)
// ============================================
echo "TEST 1: Consulta Simple (products, 10 columnas)\n";
echo "----------------------------------------------\n";

$sql = "SELECT id, name, price, stock, category_id, created_at, updated_at, status, weight, sku 
        FROM products LIMIT 100";

// FETCH_ASSOC
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $stmt = $pdo->query($sql);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $temp = $row['id'] . $row['name'];
    }
}
$timeAssoc = (microtime(true) - $start) / $iterations * 1000;
$results['simple_assoc'] = $timeAssoc;

// FETCH_OBJ
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $stmt = $pdo->query($sql);
    while ($obj = $stmt->fetch(PDO::FETCH_OBJ)) {
        $temp = $obj->id . $obj->name;
    }
}
$timeObj = (microtime(true) - $start) / $iterations * 1000;
$results['simple_obj'] = $timeObj;

$difference = (($timeObj - $timeAssoc) / $timeAssoc) * 100;
echo sprintf("  FETCH_ASSOC: %.4f ms/op\n", $timeAssoc);
echo sprintf("  FETCH_OBJ:   %.4f ms/op\n", $timeObj);
echo sprintf("  Diferencia:  %+.2f%% (%s)\n\n", 
    $difference, 
    $difference > 0 ? "FETCH_OBJ más lento" : "FETCH_OBJ más rápido"
);

// ============================================
// TEST 2: Consulta con JOINs (2 tablas)
// ============================================
echo "TEST 2: Consulta con JOINs (2 tablas)\n";
echo "----------------------------------------------\n";

$sql = "SELECT 
            p.id, p.name, p.price,
            c.name as category_name,
            p.stock, p.created_at
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LIMIT 100";

// FETCH_ASSOC
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $stmt = $pdo->query($sql);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $temp = $row['id'] . $row['category_name'];
    }
}
$timeAssoc = (microtime(true) - $start) / $iterations * 1000;
$results['join_assoc'] = $timeAssoc;

// FETCH_OBJ
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $stmt = $pdo->query($sql);
    while ($obj = $stmt->fetch(PDO::FETCH_OBJ)) {
        $temp = $obj->id . $obj->category_name;
    }
}
$timeObj = (microtime(true) - $start) / $iterations * 1000;
$results['join_obj'] = $timeObj;

$difference = (($timeObj - $timeAssoc) / $timeAssoc) * 100;
echo sprintf("  FETCH_ASSOC: %.4f ms/op\n", $timeAssoc);
echo sprintf("  FETCH_OBJ:   %.4f ms/op\n", $timeObj);
echo sprintf("  Diferencia:  %+.2f%% (%s)\n\n", 
    $difference, 
    $difference > 0 ? "FETCH_OBJ más lento" : "FETCH_OBJ más rápido"
);

// ============================================
// TEST 3: Acceso Repetido a Propiedades (10 accesos por row)
// ============================================
echo "TEST 3: Acceso Repetido a Propiedades (10 accesos por row)\n";
echo "----------------------------------------------\n";

$sql = "SELECT id, name, price, stock, category_id FROM products LIMIT 50";

// FETCH_ASSOC - múltiples accesos
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $stmt = $pdo->query($sql);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $a = $row['id'];
        $b = $row['name'];
        $c = $row['price'];
        $d = $row['stock'];
        $e = $row['category_id'];
        $f = $row['id'] + $row['price'];
        $g = $row['name'] . $row['category_id'];
        $h = $row['stock'] * $row['price'];
        $i_val = $row['id'] % $row['category_id'];
        $j = $row['price'] / $row['stock'];
    }
}
$timeAssoc = (microtime(true) - $start) / $iterations * 1000;
$results['multi_access_assoc'] = $timeAssoc;

// FETCH_OBJ - múltiples accesos
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $stmt = $pdo->query($sql);
    while ($obj = $stmt->fetch(PDO::FETCH_OBJ)) {
        $a = $obj->id;
        $b = $obj->name;
        $c = $obj->price;
        $d = $obj->stock;
        $e = $obj->category_id;
        $f = $obj->id + $obj->price;
        $g = $obj->name . $obj->category_id;
        $h = $obj->stock * $obj->price;
        $i_val = $obj->id % $obj->category_id;
        $j = $obj->price / $obj->stock;
    }
}
$timeObj = (microtime(true) - $start) / $iterations * 1000;
$results['multi_access_obj'] = $timeObj;

$difference = (($timeObj - $timeAssoc) / $timeAssoc) * 100;
echo sprintf("  FETCH_ASSOC: %.4f ms/op\n", $timeAssoc);
echo sprintf("  FETCH_OBJ:   %.4f ms/op\n", $timeObj);
echo sprintf("  Diferencia:  %+.2f%% (%s)\n\n", 
    $difference, 
    $difference > 0 ? "FETCH_OBJ más lento" : "FETCH_OBJ más rápido"
);

// ============================================
// TEST 4: fetchAll() - Carga masiva
// ============================================
echo "TEST 4: fetchAll() - Carga Masiva (1000 rows simuladas)\n";
echo "----------------------------------------------\n";

$sql = "SELECT id, name, price, stock, category_id, created_at, status 
        FROM products LIMIT 100";

// FETCH_ASSOC
$start = microtime(true);
for ($i = 0; $i < $iterations / 10; $i++) {
    $stmt = $pdo->query($sql);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($data as $row) {
        $temp = $row['id'] . $row['name'];
    }
}
$timeAssoc = (microtime(true) - $start) / ($iterations / 10) * 1000;
$results['fetchall_assoc'] = $timeAssoc;

// FETCH_OBJ
$start = microtime(true);
for ($i = 0; $i < $iterations / 10; $i++) {
    $stmt = $pdo->query($sql);
    $data = $stmt->fetchAll(PDO::FETCH_OBJ);
    foreach ($data as $obj) {
        $temp = $obj->id . $obj->name;
    }
}
$timeObj = (microtime(true) - $start) / ($iterations / 10) * 1000;
$results['fetchall_obj'] = $timeObj;

$difference = (($timeObj - $timeAssoc) / $timeAssoc) * 100;
echo sprintf("  FETCH_ASSOC: %.4f ms/op\n", $timeAssoc);
echo sprintf("  FETCH_OBJ:   %.4f ms/op\n", $timeObj);
echo sprintf("  Diferencia:  %+.2f%% (%s)\n\n", 
    $difference, 
    $difference > 0 ? "FETCH_OBJ más lento" : "FETCH_OBJ más rápido"
);

// ============================================
// RESUMEN FINAL
// ============================================
echo "==============================================\n";
echo "RESUMEN FINAL\n";
echo "==============================================\n\n";

$totalDiff = 0;
$count = 0;
foreach ($results as $key => $time) {
    if (strpos($key, 'assoc') !== false) {
        $objKey = str_replace('assoc', 'obj', $key);
        if (isset($results[$objKey])) {
            $diff = (($results[$objKey] - $time) / $time) * 100;
            $totalDiff += $diff;
            $count++;
            echo sprintf("%-30s: %+.2f%%\n", 
                ucfirst(str_replace('_', ' ', $key)), 
                $diff
            );
        }
    }
}

$avgDiff = $totalDiff / $count;
echo "\n" . str_repeat("-", 46) . "\n";
echo sprintf("PROMEDIO GENERAL: %+.2f%% %s\n", 
    $avgDiff,
    $avgDiff > 0 ? "(FETCH_OBJ es más lento)" : "(FETCH_OBJ es más rápido)"
);

echo "\n==============================================\n";
echo "CONCLUSIÓN:\n";
if ($avgDiff > 5) {
    echo "✅ FETCH_ASSOC es significativamente más rápido\n";
    echo "   RECOMENDACIÓN: Mantener FETCH_ASSOC como default\n";
} elseif ($avgDiff < -5) {
    echo "⚠️  FETCH_OBJ es más rápido (inesperado)\n";
    echo "   RECOMENDACIÓN: Considerar migrar a FETCH_OBJ\n";
} else {
    echo "➡️  Diferencia mínima (< 5%)\n";
    echo "   RECOMENDACIÓN: Usar FETCH_ASSOC por consistencia\n";
}
echo "==============================================\n";

} // Fin namespace
