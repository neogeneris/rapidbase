<?php

declare(strict_types=1);

/**
 * Prueba de concepto comparativa: SQL vs S vs V
 * 
 * Esta prueba compara las tres implementaciones en términos de:
 * 1. Estética y legibilidad del código
 * 2. Performance (tiempo de ejecución)
 * 3. Uso de memoria
 * 4. Flexibilidad
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use RapidBase\Core\SQL;
use RapidBase\Core\S;
use RapidBase\Core\V;

// Configuración inicial
SQL::setDriver('sqlite');
S::setDriver('sqlite');
V::setDriver('sqlite');

echo "==============================================\n";
echo "PRUEBA DE CONCEPTO: SQL vs S vs V\n";
echo "==============================================\n\n";

// ============================================
// 1. COMPARACIÓN DE ESTÉTICA Y LEGIBILIDAD
// ============================================

echo "1. COMPARACIÓN DE ESTÉTICA\n";
echo "----------------------------------------------\n\n";

// Ejemplo 1: SELECT simple
echo "Ejemplo 1: SELECT simple con WHERE\n";
echo "--- SQL ---\n";
echo "[\$sql, \$params] = SQL::buildSelect(['id', 'name'], 'users', ['status' => 'active']);\n\n";

echo "--- S ---\n";
echo "[\$sql, \$params] = S::select(['id', 'name'])\n";
echo "    ->from('users')\n";
echo "    ->where(['status' => 'active'])\n";
echo "    ->build();\n\n";

echo "--- V ---\n";
echo "[\$sql, \$params] = V::select(['id', 'name'], 'users', ['status' => 'active']);\n";
echo "// O también:\n";
echo "[\$sql, \$params] = V::query()\n";
echo "    ->select(['id', 'name'])\n";
echo "    ->from('users')\n";
echo "    ->where(['status' => 'active'])\n";
echo "    ->build();\n\n";

// Ejemplo 2: Query compleja con JOINs y paginación
echo "Ejemplo 2: Query compleja con JOINs y paginación\n";
echo "--- SQL ---\n";
echo "[\$sql, \$params] = SQL::buildSelect(\n";
echo "    ['u.id', 'u.name', 'p.title'],\n";
echo "    ['users AS u', 'posts AS p'],\n";
echo "    ['u.status' => 'active'],\n";
echo "    [],\n";
echo "    [],\n";
echo "    ['-u.created_at'],\n";
echo "    1,\n";
echo "    10\n";
echo ");\n\n";

echo "--- S ---\n";
echo "[\$sql, \$params] = S::select(['u.id', 'u.name', 'p.title'])\n";
echo "    ->from(['users AS u', 'posts AS p'])\n";
echo "    ->where(['u.status' => 'active'])\n";
echo "    ->orderBy('-u.created_at')\n";
echo "    ->page(1, 10)\n";
echo "    ->build();\n\n";

echo "--- V ---\n";
echo "[\$sql, \$params] = V::query()\n";
echo "    ->select(['u.id', 'u.name', 'p.title'])\n";
echo "    ->from(['users AS u', 'posts AS p'])\n";
echo "    ->where(['u.status' => 'active'])\n";
echo "    ->orderBy('-u.created_at')\n";
echo "    ->page(1, 10)\n";
echo "    ->build();\n\n";

// Ejemplo 3: Template reutilizable (solo V)
echo "Ejemplo 3: Template reutilizable (exclusivo de V)\n";
echo "--- V ---\n";
echo "\$template = V::template('SELECT * FROM users WHERE status = ? AND role = ?');\n";
echo "[\$sql, \$params] = \$template('active', 'admin');\n\n";

echo "\n";

// ============================================
// 2. PRUEBAS DE PERFORMANCE
// ============================================

echo "2. PRUEBAS DE PERFORMANCE\n";
echo "----------------------------------------------\n\n";

$iterations = 1000;

// Preparar datos de prueba
$fields = ['id', 'name', 'email', 'created_at'];
$table = 'users';
$where = ['status' => 'active', 'role' => 'user'];
$sort = ['-created_at'];
$page = 1;
$perPage = 10;

// ========== TEST SQL ==========
gc_collect_cycles();
$startMem = memory_get_usage();
$start = microtime(true);

for ($i = 0; $i < $iterations; $i++) {
    [$sql, $params] = SQL::buildSelect($fields, $table, $where, [], [], $sort, [$page, $perPage]);
}

$timeSQL = (microtime(true) - $start) * 1000;
$memSQL = memory_get_usage() - $startMem;

echo "SQL ({$iterations} iteraciones):\n";
echo "  Tiempo: " . number_format($timeSQL, 2) . " ms\n";
echo "  Memoria: " . number_format($memSQL / 1024, 2) . " KB\n";
echo "  SQL generado: {$sql}\n\n";

// ========== TEST S ==========
gc_collect_cycles();
$startMem = memory_get_usage();
$start = microtime(true);

for ($i = 0; $i < $iterations; $i++) {
    [$sql, $params] = S::selectFields($fields)
        ->from($table)
        ->where($where)
        ->orderBy($sort)
        ->page($page, $perPage)
        ->build();
}

$timeS = (microtime(true) - $start) * 1000;
$memS = memory_get_usage() - $startMem;

echo "S ({$iterations} iteraciones):\n";
echo "  Tiempo: " . number_format($timeS, 2) . " ms\n";
echo "  Memoria: " . number_format($memS / 1024, 2) . " KB\n";
echo "  SQL generado: {$sql}\n\n";

// ========== TEST V (modo estático) ==========
gc_collect_cycles();
$startMem = memory_get_usage();
$start = microtime(true);

for ($i = 0; $i < $iterations; $i++) {
    [$sql, $params] = V::select($fields, $table, $where, $sort, $page, $perPage);
}

$timeVStatic = (microtime(true) - $start) * 1000;
$memVStatic = memory_get_usage() - $startMem;

echo "V - Modo Estático ({$iterations} iteraciones):\n";
echo "  Tiempo: " . number_format($timeVStatic, 2) . " ms\n";
echo "  Memoria: " . number_format($memVStatic / 1024, 2) . " KB\n";
echo "  SQL generado: {$sql}\n\n";

// ========== TEST V (modo builder) ==========
gc_collect_cycles();
$startMem = memory_get_usage();
$start = microtime(true);

for ($i = 0; $i < $iterations; $i++) {
    [$sql, $params] = V::query()
        ->selectFields($fields)
        ->from($table)
        ->where($where)
        ->orderBy($sort)
        ->page($page, $perPage)
        ->build();
}

$timeVBuilder = (microtime(true) - $start) * 1000;
$memVBuilder = memory_get_usage() - $startMem;

echo "V - Modo Builder ({$iterations} iteraciones):\n";
echo "  Tiempo: " . number_format($timeVBuilder, 2) . " ms\n";
echo "  Memoria: " . number_format($memVBuilder / 1024, 2) . " KB\n";
echo "  SQL generado: {$sql}\n\n";

// ========== TEST V (fast path) ==========
gc_collect_cycles();
$startMem = memory_get_usage();
$start = microtime(true);

for ($i = 0; $i < $iterations; $i++) {
    [$sql, $params] = V::select('*', 'users');
}

$timeVFast = (microtime(true) - $start) * 1000;
$memVFast = memory_get_usage() - $startMem;

echo "V - Fast Path ({$iterations} iteraciones):\n";
echo "  Tiempo: " . number_format($timeVFast, 2) . " ms\n";
echo "  Memoria: " . number_format($memVFast / 1024, 2) . " KB\n";
echo "  SQL generado: {$sql}\n\n";

// ============================================
// 3. COMPARATIVA RESUMEN
// ============================================

echo "3. COMPARATIVA RESUMEN\n";
echo "----------------------------------------------\n\n";

// Calcular relativos a SQL (como base 100%)
$relTimeS = round(($timeS / $timeSQL) * 100, 1);
$relTimeVStatic = round(($timeVStatic / $timeSQL) * 100, 1);
$relTimeVBuilder = round(($timeVBuilder / $timeSQL) * 100, 1);
$relTimeVFast = round(($timeVFast / $timeSQL) * 100, 1);

$relMemS = round(($memS / $memSQL) * 100, 1);
$relMemVStatic = round(($memVStatic / $memSQL) * 100, 1);
$relMemVBuilder = round(($memVBuilder / $memSQL) * 100, 1);
$relMemVFast = round(($memVFast / $memSQL) * 100, 1);

echo "TIEMPO DE EJECUCIÓN (menor es mejor, SQL = 100%):\n";
echo sprintf("  SQL:         %7.2f ms (100%% - base)\n", $timeSQL);
echo sprintf("  S:           %7.2f ms (%5.1f%%)\n", $timeS, $relTimeS);
echo sprintf("  V (Static):  %7.2f ms (%5.1f%%)\n", $timeVStatic, $relTimeVStatic);
echo sprintf("  V (Builder): %7.2f ms (%5.1f%%)\n", $timeVBuilder, $relTimeVBuilder);
echo sprintf("  V (Fast):    %7.2f ms (%5.1f%%)\n", $timeVFast, $relTimeVFast);
echo "\n";

echo "USO DE MEMORIA (menor es mejor, SQL = 100%):\n";
echo sprintf("  SQL:         %7.2f KB (100%% - base)\n", $memSQL / 1024);
echo sprintf("  S:           %7.2f KB (%5.1f%%)\n", $memS / 1024, $relMemS);
echo sprintf("  V (Static):  %7.2f KB (%5.1f%%)\n", $memVStatic / 1024, $relMemVStatic);
echo sprintf("  V (Builder): %7.2f KB (%5.1f%%)\n", $memVBuilder / 1024, $relMemVBuilder);
echo sprintf("  V (Fast):    %7.2f KB (%5.1f%%)\n", $memVFast / 1024, $relMemVFast);
echo "\n";

// ============================================
// 4. CARACTERÍSTICAS POR CLASE
// ============================================

echo "4. CARACTERÍSTICAS POR CLASE\n";
echo "----------------------------------------------\n\n";

echo "SQL:\n";
echo "  ✓ Máximo performance optimizado\n";
echo "  ✓ Cache L3 con proyección de mapas\n";
echo "  ✓ Telemetría integrada\n";
echo "  ✓ Soporte completo para JOINs automáticos\n";
echo "  ✗ Sintaxis menos legible\n";
echo "  ✗ No es inmutable\n";
echo "\n";

echo "S:\n";
echo "  ✓ Sintaxis muy legible y expresiva\n";
echo "  ✓ Builder inmutable (sin efectos secundarios)\n";
echo "  ✓ Fácil de testear\n";
echo "  ✓ Métodos helper (one, all, value, exists)\n";
echo "  ✗ Menor performance por cloning\n";
echo "  ✗ Más presión en el GC\n";
echo "\n";

echo "V:\n";
echo "  ✓ Hybrid API (estática + builder)\n";
echo "  ✓ Fast path para queries simples\n";
echo "  ✓ Templates reutilizables\n";
echo "  ✓ Balance entre performance y estética\n";
echo "  ✓ Mismo cache L3 que SQL\n";
echo "  ✗ JOINs automáticos limitados (PoC)\n";
echo "  ✗ Sin telemetría avanzada (aún)\n";
echo "\n";

// ============================================
// 5. ESTADÍSTICAS DE CACHE
// ============================================

echo "5. ESTADÍSTICAS DE CACHE\n";
echo "----------------------------------------------\n\n";

$sqlStats = SQL::getQueryCacheStats();
echo "SQL Cache:\n";
echo "  Hits: {$sqlStats['hits']}, Misses: {$sqlStats['misses']}\n";
echo "  Hit Rate: " . ($sqlStats['hit_rate'] * 100) . "%\n";
echo "  Size: {$sqlStats['size']}/{$sqlStats['max_size']}\n\n";

$sStats = S::getQueryCacheStats();
echo "S Cache:\n";
echo "  Size: {$sStats['size']}/{$sStats['max_size']}\n";
echo "  Enabled: " . ($sStats['enabled'] ? 'Yes' : 'No') . "\n\n";

$vStats = V::getQueryCacheStats();
echo "V Cache:\n";
echo "  Hits: {$vStats['hits']}, Misses: {$vStats['misses']}\n";
echo "  Hit Rate: " . ($vStats['hit_rate'] * 100) . "%\n";
echo "  Size: {$vStats['size']}/{$vStats['max_size']}\n\n";

$vTemplateStats = V::getTemplateStats();
echo "V Templates:\n";
echo "  Count: {$vTemplateStats['count']}\n\n";

// ============================================
// 6. CONCLUSIÓN
// ============================================

echo "6. CONCLUSIÓN\n";
echo "----------------------------------------------\n\n";

$winner = '';
if ($timeVStatic < $timeS && $timeVStatic < $timeSQL * 1.2) {
    $winner = 'V';
} elseif ($timeSQL < $timeVStatic && $timeSQL < $timeS) {
    $winner = 'SQL';
} else {
    $winner = 'Depende del caso de uso';
}

echo "Para queries simples y máximo performance: SQL\n";
echo "Para máxima legibilidad y testing: S\n";
echo "Para balance entre ambos: V\n\n";
echo "Ganador en esta prueba: {$winner}\n\n";

echo "==============================================\n";
echo "FIN DE LA PRUEBA\n";
echo "==============================================\n";
