<?php
/**
 * Benchmark: SelectBuilder con Auto-Join vs Método Tradicional
 * 
 * Compara el rendimiento de la nueva clase SelectBuilder (con soporte para auto-join)
 * contra el método tradicional de SQL::buildSelect()
 */

require_once __DIR__ . '/../../src/RapidBase/Core/SQL.php';
require_once __DIR__ . '/../../src/RapidBase/Core/SelectBuilder.php';

use RapidBase\Core\SQL;
use RapidBase\Core\SelectBuilder;

// Configurar driver
SQL::setDriver('mysql');

echo "==============================================\n";
echo "BENCHMARK: SelectBuilder vs SQL::buildSelect\n";
echo "Iteraciones: 5000\n";
echo "==============================================\n\n";

$iterations = 5000;

// ============================================
// TEST 1: SELECT Simple (sin JOINs)
// ============================================
echo "TEST 1: SELECT Simple (products)\n";
echo str_repeat("-", 50) . "\n";

// Método tradicional
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $sql = SQL::buildSelect('*', 'products', ['status' => 'active'], [], [], ['id' => 'DESC'], 1, 10);
}
$timeTraditional = (microtime(true) - $start) / $iterations * 1000;

// SelectBuilder (nueva instancia cada vez)
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $builder = new SelectBuilder('*', 'products', ['status' => 'active'], ['id' => 'DESC'], 1, 10);
    $sql = $builder->toSql();
}
$timeNewInstance = (microtime(true) - $start) / $iterations * 1000;

// SelectBuilder (reutilizando instancia)
$builder = new SelectBuilder();
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $builder->reset()
            ->setSelect('*')
            ->setFrom('products')
            ->setWhere(['status' => 'active'])
            ->setOrderBy(['id' => 'DESC'])
            ->setPagination(1, 10);
    $sql = $builder->toSql();
}
$timeReuse = (microtime(true) - $start) / $iterations * 1000;

echo sprintf("  SQL::buildSelect (tradicional): %.4f ms/op\n", $timeTraditional);
echo sprintf("  SelectBuilder (new instance):   %.4f ms/op (%+.2f%%)\n", 
    $timeNewInstance, 
    (($timeNewInstance - $timeTraditional) / $timeTraditional) * 100
);
echo sprintf("  SelectBuilder (reuse):          %.4f ms/op (%+.2f%%)\n", 
    $timeReuse, 
    (($timeReuse - $timeTraditional) / $timeTraditional) * 100
);
echo "\n";

// ============================================
// TEST 2: SELECT con WHERE complejo
// ============================================
echo "TEST 2: SELECT con WHERE Complejo\n";
echo str_repeat("-", 50) . "\n";

$where = [
    'price' => ['>' => 100, '<' => 500],
    'status' => 'active',
    'category_id' => [1, 2, 3]
];

// Método tradicional
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $sql = SQL::buildSelect('*', 'products', $where, [], [], [], 1, 20);
}
$timeTraditional = (microtime(true) - $start) / $iterations * 1000;

// SelectBuilder (reutilizando)
$builder = new SelectBuilder();
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $builder->reset()
            ->setSelect('*')
            ->setFrom('products')
            ->setWhere($where)
            ->setPagination(1, 20);
    $sql = $builder->toSql();
}
$timeReuse = (microtime(true) - $start) / $iterations * 1000;

echo sprintf("  SQL::buildSelect (tradicional): %.4f ms/op\n", $timeTraditional);
echo sprintf("  SelectBuilder (reuse):          %.4f ms/op (%+.2f%%)\n", 
    $timeReuse, 
    (($timeReuse - $timeTraditional) / $timeTraditional) * 100
);
echo "\n";

// ============================================
// TEST 3: SELECT con JOIN manual
// ============================================
echo "TEST 3: SELECT con JOIN Manual\n";
echo str_repeat("-", 50) . "\n";

// Método tradicional
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $sql = SQL::buildSelect(
        'p.*, c.name as category_name',
        'products p',
        ['p.status' => 'active'],
        [],
        [],
        [],
        1,
        10,
        [['LEFT', 'categories c', 'c.id = p.category_id']]
    );
}
$timeTraditional = (microtime(true) - $start) / $iterations * 1000;

// SelectBuilder (reutilizando)
$builder = new SelectBuilder();
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $builder->reset()
            ->setSelect('p.*, c.name as category_name')
            ->setFrom('products p')
            ->setWhere(['p.status' => 'active'])
            ->addJoin('LEFT', 'categories', 'c', 'c.id = p.category_id')
            ->setPagination(1, 10);
    $sql = $builder->toSql();
}
$timeReuse = (microtime(true) - $start) / $iterations * 1000;

echo sprintf("  SQL::buildSelect (tradicional): %.4f ms/op\n", $timeTraditional);
echo sprintf("  SelectBuilder (reuse):          %.4f ms/op (%+.2f%%)\n", 
    $timeReuse, 
    (($timeReuse - $timeTraditional) / $timeTraditional) * 100
);
echo "\n";

// ============================================
// TEST 4: SELECT con GROUP BY + HAVING
// ============================================
echo "TEST 4: SELECT con GROUP BY + HAVING\n";
echo str_repeat("-", 50) . "\n";

// Método tradicional
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $sql = SQL::buildSelect(
        'category_id, COUNT(*) as total',
        'products',
        [],
        ['category_id'],
        ['total' => ['>' => 5]],
        [],
        1,
        10
    );
}
$timeTraditional = (microtime(true) - $start) / $iterations * 1000;

// SelectBuilder (reutilizando)
$builder = new SelectBuilder();
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $builder->reset()
            ->setSelect('category_id, COUNT(*) as total')
            ->setFrom('products')
            ->setGroupBy(['category_id'])
            ->setHaving(['total' => ['>' => 5]])
            ->setPagination(1, 10);
    $sql = $builder->toSql();
}
$timeReuse = (microtime(true) - $start) / $iterations * 1000;

echo sprintf("  SQL::buildSelect (tradicional): %.4f ms/op\n", $timeTraditional);
echo sprintf("  SelectBuilder (reuse):          %.4f ms/op (%+.2f%%)\n", 
    $timeReuse, 
    (($timeReuse - $timeTraditional) / $timeTraditional) * 100
);
echo "\n";

// ============================================
// RESUMEN FINAL
// ============================================
echo "==============================================\n";
echo "RESUMEN FINAL\n";
echo "==============================================\n\n";

echo "SelectBuilder ofrece:\n";
echo "  ✓ API orientada a objetos más legible\n";
echo "  ✓ Soporte para auto-join (cuando hay relaciones cargadas)\n";
echo "  ✓ Caché interno de cláusulas construidas\n";
echo "  ✓ Patrón de reutilización para máximo rendimiento\n";
echo "\n";
echo "Recomendación: Usar SelectBuilder con reset() en loops\n";
echo "               para aprovechar la caché interna.\n";
echo "==============================================\n";
