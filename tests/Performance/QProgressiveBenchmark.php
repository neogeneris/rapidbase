<?php
/**
 * Q Progressive Benchmark with SQLite (execution + projection)
 *
 * Mide la generación de SQL Y la ejecución completa (incluyendo fetch y proyección)
 * para diferentes escenarios de relaciones usando tablas genéricas conectadas.
 * Incluye prueba de caché L1 (RAM) ejecutando el mismo JOIN pesado varias veces.
 * Imprime el SQL generado, el multiplicador respecto al caso base,
 * y el tamaño de las cachés estáticas del JoinResolver.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use RapidBase\Core\SQL\Q;
use RapidBase\Core\SQL\ConditionMatrix;
use RapidBase\Core\SchemaMap;
use RapidBase\Core\Conn;

// ── Setup in-memory SQLite ────────────────────────────
Conn::setup('sqlite::memory:', '', '', 'main');
ConditionMatrix::setDriver('sqlite');
$pdo = Conn::get('main');

// ── Generic tables for the chain (c1 -> c2 -> ... -> c7) ──
$tableDefs = [];
for ($i = 1; $i <= 7; $i++) {
    $cols = ['id' => 'INTEGER PRIMARY KEY', 'f1' => 'TEXT', 'f2' => 'INTEGER'];
    if ($i > 1) {
        $prev = 'c' . ($i - 1);
        $cols["{$prev}_id"] = 'INTEGER';
    }
    $tableDefs["c$i"] = $cols;
}
// Self-reference table
$tableDefs['tree'] = ['id' => 'INTEGER PRIMARY KEY', 'parent_id' => 'INTEGER', 'name' => 'TEXT'];

foreach ($tableDefs as $name => $cols) {
    $colDefs = [];
    foreach ($cols as $c => $type) {
        $colDefs[] = "$c $type";
    }
    $pdo->exec("CREATE TABLE $name (" . implode(',', $colDefs) . ")");
}

// ── Insert minimal data ──────────────────────────────
for ($i = 1; $i <= 7; $i++) {
    $values = ["'val$i'", $i];
    if ($i > 1) {
        $values[] = $i - 1;
    }
    $pdo->exec("INSERT INTO c$i (f1, f2" . ($i > 1 ? ", c" . ($i-1) . "_id" : "") . ") VALUES (" . implode(',', $values) . ")");
}
$pdo->exec("INSERT INTO tree (id, parent_id, name) VALUES (1, NULL, 'root'), (2, 1, 'child1'), (3, 1, 'child2')");

// ── Schema map (c1->c2, c2->c3, ..., c6->c7, tree->tree) ──
$schema = [
    'relationships' => [
        'from' => [],
        'to'   => [],
    ],
    'tables' => [],
];
for ($i = 1; $i < 7; $i++) {
    $curr = "c$i";
    $next = "c" . ($i + 1);
    $schema['relationships']['from'][$curr][$next] = [
        'type'        => 'hasMany',
        'local_key'   => 'id',
        'foreign_key' => "{$curr}_id",
    ];
    $schema['relationships']['to'][$next][$curr] = [
        'type'        => 'belongsTo',
        'local_key'   => "{$curr}_id",
        'foreign_key' => 'id',
    ];
}
// Self-reference
$schema['relationships']['from']['tree']['tree'] = [
    'type'        => 'hasMany',
    'local_key'   => 'id',
    'foreign_key' => 'parent_id',
];

// Fill table metadata
foreach ($tableDefs as $name => $cols) {
    foreach ($cols as $c => $type) {
        $schema['tables'][$name][$c] = ['primary' => ($c === 'id')];
    }
}
SchemaMap::setMap($schema, 'main');

// ── Benchmark helper (SQL gen + execution + fetch) ────
function benchmarkQ(array $tables, int $iterations = 200): float {
    // Warmup específico para esta combinación
    for ($i = 0; $i < 30; $i++) {
        $cq = Q::from($tables)->select('*');
        $cq->run();
    }
    $start = microtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $cq = Q::from($tables)->select('*');
        $cq->run();
    }
    $end = microtime(true);
    return (($end - $start) / $iterations) * 1000;
}

echo "=========================================================\n";
echo "   Q Progressive Benchmark (SQL gen + execution + fetch)\n";
echo "=========================================================\n\n";

$scenarios = [
    "1. Single table"                          => ['c1'],
    "2. 1:n (c1->c2)"                          => ['c1', 'c2'],
    "3. m:1 (c2->c1)"                          => ['c2', 'c1'],
    "4. Cascade (c1->c2->c3)"                  => ['c1', 'c2', 'c3'],
    "5. 4 tables optimal (c1..c4)"             => ['c1', 'c2', 'c3', 'c4'],
    "6. 4 tables random  (c4,c2,c1,c3)"        => ['c4', 'c2', 'c1', 'c3'],
    "7. 5 tables (c1..c5)"                     => ['c1', 'c2', 'c3', 'c4', 'c5'],
    "8. 6 tables (c1..c6)"                     => ['c1', 'c2', 'c3', 'c4', 'c5', 'c6'],
    "9. 7 tables (c1..c7)"                     => ['c1', 'c2', 'c3', 'c4', 'c5', 'c6', 'c7'],
    "10. Self-reference (tree->tree)"          => ['tree', 'tree as parent'],
];

// Guardar el tiempo base (primera prueba)
$baseTime = null;

foreach ($scenarios as $label => $tables) {
    // Mostrar SQL generado (solo una vez)
    $cq = Q::from($tables)->select('*');
    $sql = $cq->getSql();
    echo "SQL for $label:\n  $sql\n\n";
    
    $time = benchmarkQ($tables);
    
    if ($baseTime === null) {
        $baseTime = $time;
        echo sprintf("%-40s : %.6f ms  (1.00x)\n", $label, $time);
    } else {
        $ratio = $time / $baseTime;
        echo sprintf("%-40s : %.6f ms  (%.2fx)\n", $label, $time, $ratio);
    }
}

// ── Cache L1 (RAM) warm‑up test ─────────────────────
echo "\n--- Cache L1 (RAM) Warm‑up Test (7 tables) ---\n";
$heavyTables = ['c1', 'c2', 'c3', 'c4', 'c5', 'c6', 'c7'];

$time1 = benchmarkQ($heavyTables);
echo sprintf("1st run (7 tables)                    : %.6f ms  (%.2fx)\n", $time1, $time1 / $baseTime);

$time2 = benchmarkQ($heavyTables);
echo sprintf("2nd run (7 tables)                    : %.6f ms  (%.2fx)\n", $time2, $time2 / $baseTime);

$time3 = benchmarkQ($heavyTables);
echo sprintf("3rd run (7 tables)                    : %.6f ms  (%.2fx)\n", $time3, $time3 / $baseTime);

// ── Medir tamaño de cachés estáticas ─────────────────
$ref = new ReflectionClass(\RapidBase\Core\SQL\JoinResolver::class);

$treeProp = $ref->getStaticPropertyValue('joinTreeCache');
$treeSize = strlen(serialize($treeProp));

$fromProp = $ref->getStaticPropertyValue('fromClauseCache');
$fromSize = strlen(serialize($fromProp));

echo "\n--- Static Cache Size ---\n";
echo "joinTreeCache entries : " . count($treeProp) . "\n";
echo "joinTreeCache size    : " . number_format($treeSize / 1024, 2) . " KB\n";
echo "fromClauseCache entries: " . count($fromProp) . "\n";
echo "fromClauseCache size  : " . number_format($fromSize / 1024, 2) . " KB\n";
echo "Total cache size      : " . number_format(($treeSize + $fromSize) / 1024, 2) . " KB\n";

echo "\n=========================================================\n";
echo "Benchmark completed.\n";