<?php

/**
 * Benchmark para comparar modos de fetch de PDO:
 * - FETCH_ASSOC (array asociativo)
 * - FETCH_CLASS (objeto de clase dinámica)
 * - FETCH_OBJ (objeto stdClass)
 * 
 * También compara SelectBuilder (objeto) vs array tradicional
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
include_once "$basePath/SelectBuilder.php";

use RapidBase\Core\Gateway;
use RapidBase\Core\Conn;
use RapidBase\Core\SQL;
use RapidBase\Core\SelectBuilder;

/**
 * CONFIGURACIÓN DE CONEXIÓN
 */
Conn::setup("sqlite::memory:", "", ""); 
$pdo = Conn::get();

// Crear tabla de prueba con más columnas para simular caso real
$pdo->exec("
    CREATE TABLE users (
        id INTEGER PRIMARY KEY, 
        name TEXT, 
        email TEXT,
        age INTEGER,
        active INTEGER,
        created_at TEXT,
        updated_at TEXT
    )
");

// Insertar datos de prueba
$stmt = $pdo->prepare("
    INSERT INTO users (name, email, age, active, created_at, updated_at) 
    VALUES (?, ?, ?, 1, datetime('now'), datetime('now'))
");

$rows = 1000;
for ($i = 1; $i <= $rows; $i++) { 
    $stmt->execute([
        "Usuario Test $i",
        "user$i@test.com",
        rand(18, 65)
    ]); 
}

echo "=== PDO Fetch Mode Benchmark ===\n";
echo "Filas: $rows\n\n";

/**
 * TEST 1: FETCH_ASSOC (Actual)
 */
$sql = "SELECT * FROM users WHERE active = 1 LIMIT 100";
$iterations = 100;

$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $stmt = $pdo->query($sql);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$timeAssoc = (microtime(true) - $start) / $iterations;
$count = count($data);

echo "[1] PDO::FETCH_ASSOC:      " . number_format($timeAssoc * 1000, 4) . " ms/op ($count rows)\n";

/**
 * TEST 2: FETCH_CLASS (stdClass dinámica)
 */
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $stmt = $pdo->query($sql);
    $data = $stmt->fetchAll(PDO::FETCH_CLASS, 'stdClass');
}
$timeClass = (microtime(true) - $start) / $iterations;

echo "[2] PDO::FETCH_CLASS:      " . number_format($timeClass * 1000, 4) . " ms/op\n";

/**
 * TEST 3: FETCH_OBJ
 */
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $stmt = $pdo->query($sql);
    $data = [];
    while ($row = $stmt->fetch(PDO::FETCH_OBJ)) {
        $data[] = $row;
    }
}
$timeObj = (microtime(true) - $start) / $iterations;

echo "[3] PDO::FETCH_OBJ:        " . number_format($timeObj * 1000, 4) . " ms/op\n";

/**
 * TEST 4: FETCH_NUM (array numérico - referencia)
 */
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $stmt = $pdo->query($sql);
    $data = $stmt->fetchAll(PDO::FETCH_NUM);
}
$timeNum = (microtime(true) - $start) / $iterations;

echo "[4] PDO::FETCH_NUM:        " . number_format($timeNum * 1000, 4) . " ms/op\n";

echo "\n=== SelectBuilder vs Array Tradicional ===\n\n";

/**
 * TEST 5: buildSelect con array tradicional (actual)
 */
$fields = '*';
$table = 'users';
$where = ['active' => 1];
$sort = ['id' => 'ASC'];
$page = 1;
$perPage = 100;

SQL::setQueryCacheEnabled(false); // Desactivar caché para medir construcción pura

$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    [$sql, $params] = SQL::buildSelect($fields, $table, $where, [], [], $sort, $page, $perPage);
}
$timeArrayBuild = (microtime(true) - $start) / $iterations;

echo "[5] SQL::buildSelect (array): " . number_format($timeArrayBuild * 1000, 4) . " ms/op\n";

/**
 * TEST 6: SelectBuilder (objeto)
 */
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $builder = new SelectBuilder($fields, $table, $where, $sort, $page, $perPage);
    [$sql, $params] = $builder->build();
}
$timeObjectBuild = (microtime(true) - $start) / $iterations;

echo "[6] SelectBuilder (objeto):   " . number_format($timeObjectBuild * 1000, 4) . " ms/op\n";

/**
 * TEST 7: SelectBuilder reutilizando instancia
 */
$builder = new SelectBuilder();
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $builder->reset();
    $builder->setSelect($fields);
    $builder->setFrom($table);
    $builder->setWhere($where);
    $builder->setOrderBy($sort);
    $builder->setPagination($page, $perPage);
    [$sql, $params] = $builder->build();
}
$timeObjectReuse = (microtime(true) - $start) / $iterations;

echo "[7] SelectBuilder (reuse):    " . number_format($timeObjectReuse * 1000, 4) . " ms/op\n";

/**
 * RESULTADOS
 */
echo "\n=== ANÁLISIS ===\n";

$speedupClass = $timeAssoc / $timeClass;
$speedupObj = $timeAssoc / $timeObj;
$speedupBuilder = $timeArrayBuild / $timeObjectBuild;

echo "FETCH_CLASS es " . number_format($speedupClass, 2) . "x " . ($speedupClass > 1 ? "más rápido" : "más lento") . " que FETCH_ASSOC\n";
echo "FETCH_OBJ es " . number_format($speedupObj, 2) . "x " . ($speedupObj > 1 ? "más rápido" : "más lento") . " que FETCH_ASSOC\n";
echo "SelectBuilder es " . number_format($speedupBuilder, 2) . "x " . ($speedupBuilder > 1 ? "más rápido" : "más lento") . " que array tradicional\n";

if ($speedupClass > 1 || $speedupObj > 1) {
    echo "\n✓ RECOMENDACIÓN: Considerar cambiar a FETCH_CLASS o FETCH_OBJ\n";
} else {
    echo "\n✗ FETCH_ASSOC sigue siendo la opción más rápida\n";
}

if ($speedupBuilder > 1) {
    echo "✓ RECOMENDACIÓN: SelectBuilder ofrece mejor rendimiento\n";
} else {
    echo "✗ SelectBuilder tiene overhead pero mejora legibilidad\n";
}

echo "\n=== MÉTRICAS ADICIONALES ===\n";
echo "Memoria usada: " . number_format(memory_get_usage() / 1024, 2) . " KB\n";
echo "Pico de memoria: " . number_format(memory_get_peak_usage() / 1024, 2) . " KB\n";

} // Fin namespace global
