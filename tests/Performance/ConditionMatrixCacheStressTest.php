<?php
/**
 * Benchmark extremo para simular el escenario de tests unitarios
 * donde hay muchas condiciones únicas que llenan el cache
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use RapidBase\Core\SQL\ConditionMatrix;

ConditionMatrix::setDriver('sqlite');

$schema = [
    'tables' => [
        'users' => ['id', 'name', 'email', 'status', 'created_at', 'role', 'country'],
        'posts' => ['id', 'user_id', 'title', 'content', 'published', 'views', 'category_id'],
        'comments' => ['id', 'post_id', 'user_id', 'body', 'rating', 'approved'],
        'tags' => ['id', 'name', 'slug', 'color'],
        'categories' => ['id', 'name', 'parent_id', 'slug'],
        'post_tags' => ['post_id', 'tag_id']
    ]
];

$context = [
    'u' => 'users',
    'p' => 'posts',
    'c' => 'comments',
    't' => 'tags',
    'cat' => 'categories',
    'pt' => 'post_tags'
];

// Generar condiciones MUY variadas para maximizar entradas únicas en cache
function generateUniqueConditions($seed) {
    $conditions = [];
    
    // Variación en cada campo posible
    $conditions['u.id'] = $seed;
    $conditions['u.status'] = ['active', 'pending', 'inactive', 'banned'][$seed % 4];
    $conditions['u.role'] = ['admin', 'user', 'moderator', 'guest'][$seed % 4];
    $conditions['u.created_at']['>'] = date('Y-m-d H:i:s', strtotime("-{$seed} days"));
    
    $conditions['p.user_id'] = ($seed * 7) % 1000;
    $conditions['p.published'] = (bool)($seed % 2);
    $conditions['p.views']['>='] = $seed * 10;
    $conditions['p.title']['LIKE'] = "%post_{$seed}%";
    
    $conditions['c.post_id'] = ($seed * 3) % 500;
    $conditions['c.rating']['BETWEEN'] = [($seed % 5), 5];
    $conditions['c.approved'] = (bool)($seed % 3);
    
    // Condiciones OR complejas y anidadas
    $conditions[] = [
        'OR' => [
            ['u.email' => ['LIKE' => "%user{$seed}@test%"]],
            ['p.title' => ['LIKE' => "%article{$seed}%"]],
            ['c.body' => ['LIKE' => "%comment{$seed}%"]]
        ]
    ];
    
    // Más variaciones
    $conditions['t.slug'] = "tag_{$seed}";
    $conditions['cat.parent_id'] = $seed % 10;
    
    // IN con valores únicos
    $conditions['u.country']['IN'] = ["US", "UK", "CA", "AU", "DE", "FR", "ES"][$seed % 7];
    
    return $conditions;
}

echo "=== Benchmark EXTREMO - Escenario Tests Unitarios ===\n\n";

// Escenario más realista de suite de tests grande
$iterations = 5000; // Más iteraciones
$uniqueConditionSets = 2000; // Muchas condiciones únicas

echo "Configuración:\n";
echo "- Iteraciones totales: {$iterations}\n";
echo "- Conjuntos de condiciones únicos: {$uniqueConditionSets}\n";
echo "- Ratio reutilización: " . round($iterations / $uniqueConditionSets, 2) . "x\n\n";

// ---------------------------------------------------------
// PRUEBA 1: CON CACHE ACTIVADO
// ---------------------------------------------------------
echo "PRUEBA 1: CON CACHE ACTIVADO\n";
echo str_repeat('-', 60) . "\n";

$reflectionClass = new ReflectionClass(ConditionMatrix::class);
$parseCacheProperty = $reflectionClass->getProperty('parseCache');
$parseCacheProperty->setAccessible(true);
$parseCacheProperty->setValue(null, []);

$preMemory = memory_get_usage(true);
$startMemory = memory_get_usage();
$startTime = microtime(true);

for ($i = 0; $i < $iterations; $i++) {
    $seed = $i % $uniqueConditionSets;
    $conditions = generateUniqueConditions($seed);
    
    try {
        $result = ConditionMatrix::parse($conditions, $context, 'u', $schema);
    } catch (\Exception $e) {
        echo "Error en iteración {$i}: " . $e->getMessage() . "\n";
        break;
    }
}

$endTime = microtime(true);
$endMemory = memory_get_usage();
$postMemory = memory_get_usage(true);

$timeWithCache = $endTime - $startTime;
$memoryUsedWithCache = $endMemory - $startMemory;
$memoryTotalWithCache = $postMemory - $preMemory;
$cacheSize = count($parseCacheProperty->getValue());

echo "Tiempo total: " . number_format($timeWithCache * 1000, 2) . " ms\n";
echo "Tiempo por llamada: " . number_format(($timeWithCache / $iterations) * 1000000, 2) . " μs\n";
echo "Memoria usada (pico): " . number_format($memoryUsedWithCache / 1024, 2) . " KB\n";
echo "Memoria total asignada: " . number_format($memoryTotalWithCache / 1024 / 1024, 2) . " MB\n";
echo "Entradas en caché: {$cacheSize}\n";
echo "Tamaño estimado por entrada: " . number_format(($memoryUsedWithCache / 1024) / max($cacheSize, 1), 3) . " KB\n\n";

// Forzar garbage collection antes de la siguiente prueba
gc_collect_cycles();
usleep(100000);

// ---------------------------------------------------------
// PRUEBA 2: SIN CACHE
// ---------------------------------------------------------
echo "PRUEBA 2: SIN CACHE (simulado)\n";
echo str_repeat('-', 60) . "\n";

$parseCacheProperty->setValue(null, []);
$doParseMethod = $reflectionClass->getMethod('doParse');
$doParseMethod->setAccessible(true);

$startMemoryNoCache = memory_get_usage();
$preMemoryNoCache = memory_get_usage(true);
$startTimeNoCache = microtime(true);

for ($i = 0; $i < $iterations; $i++) {
    $seed = $i % $uniqueConditionSets;
    $conditions = generateUniqueConditions($seed);
    
    try {
        $result = $doParseMethod->invoke(null, $conditions, $context, 'u', $schema);
    } catch (\Exception $e) {
        echo "Error en iteración {$i}: " . $e->getMessage() . "\n";
        break;
    }
}

$endTimeNoCache = microtime(true);
$endMemoryNoCache = memory_get_usage();
$postMemoryNoCache = memory_get_usage(true);

$timeWithoutCache = $endTimeNoCache - $startTimeNoCache;
$memoryUsedWithoutCache = $endMemoryNoCache - $startMemoryNoCache;
$memoryTotalWithoutCache = $postMemoryNoCache - $preMemoryNoCache;

echo "Tiempo total: " . number_format($timeWithoutCache * 1000, 2) . " ms\n";
echo "Tiempo por llamada: " . number_format(($timeWithoutCache / $iterations) * 1000000, 2) . " μs\n";
echo "Memoria usada (pico): " . number_format($memoryUsedWithoutCache / 1024, 2) . " KB\n";
echo "Memoria total asignada: " . number_format($memoryTotalWithoutCache / 1024 / 1024, 2) . " MB\n\n";

// ---------------------------------------------------------
// ANÁLISIS
// ---------------------------------------------------------
echo str_repeat('=', 60) . "\n";
echo "ANÁLISIS COMPARATIVO\n";
echo str_repeat('=', 60) . "\n\n";

$performanceLoss = $timeWithCache > 0 ? (($timeWithoutCache - $timeWithCache) / $timeWithCache) * 100 : 0;
$memoryDiff = $memoryTotalWithCache - $memoryTotalWithoutCache;
$memoryDiffPercent = $memoryTotalWithCache > 0 ? ($memoryDiff / $memoryTotalWithCache) * 100 : 0;

echo "Impacto en Performance:\n";
if ($performanceLoss > 0) {
    echo "- El cache es +" . number_format(abs($performanceLoss), 2) . "% más RÁPIDO\n";
} else {
    echo "- Sin cache es +" . number_format(abs($performanceLoss), 2) . "% más RÁPIDO\n";
}
echo "- Diferencia: " . number_format(abs($timeWithoutCache - $timeWithCache) * 1000, 2) . " ms en total\n";
echo "- Por llamada: " . number_format(abs(($timeWithoutCache - $timeWithCache) / $iterations) * 1000000, 3) . " μs\n\n";

echo "Impacto en Memoria:\n";
echo "- Cache consumió: " . number_format($memoryTotalWithCache / 1024 / 1024, 2) . " MB\n";
echo "- Sin cache usó: " . number_format($memoryTotalWithoutCache / 1024 / 1024, 2) . " MB\n";
echo "- Overhead del cache: " . number_format($memoryDiff / 1024 / 1024, 2) . " MB (" . number_format($memoryDiffPercent, 2) . "%)\n";
echo "- Entradas en cache: {$cacheSize}\n\n";

echo "Proyección para suites de tests grandes:\n";
$projectedEntries = 10000; // Ej: 10k tests únicos
$projectedMemory = ($memoryUsedWithCache / max($cacheSize, 1)) * $projectedEntries;
echo "- Con {$projectedEntries} condiciones únicas estimadas:\n";
echo "  Memoria proyectada: " . number_format($projectedMemory / 1024 / 1024, 2) . " MB\n";
echo "  (Considerando memory_limit típico de 128-256MB en CLI)\n\n";

echo "Conclusiones:\n";
if ($performanceLoss > 20) {
    echo "✓ El cache proporciona una mejora SIGNIFICATIVA de performance (>20% más rápido)\n";
} elseif ($performanceLoss > 5) {
    echo "⚠ El cache proporciona una mejora MODERADA de performance (5-20% más rápido)\n";
} else {
    echo "○ El cache proporciona una mejora MÍNIMA de performance (<5%)\n";
}

if ($memoryDiffPercent > 50 || $projectedMemory / 1024 / 1024 > 100) {
    echo "✗ ALERTA: El consumo de memoria del cache es CRÍTICO para tests grandes\n";
    echo "  Solución recomendada: Implementar límite máximo de entradas o TTL\n";
} elseif ($memoryDiffPercent > 20) {
    echo "⚠ El consumo de memoria del cache es MODERADO\n";
    echo "  Solución recomendada: Considerar desactivar cache solo en tests\n";
} else {
    echo "✓ El consumo de memoria del cache es ACEPTABLE\n";
}

echo "\nRecomendación Final:\n";
if ($performanceLoss > 10 && ($memoryDiffPercent < 30 || $projectedMemory / 1024 / 1024 < 50)) {
    echo "Mantener cache PERO agregar mecanismo de limpieza:\n";
    echo "  1. Agregar método ConditionMatrix::clearCache() para usar en setUp() de tests\n";
    echo "  2. O implementar un límite máximo de entradas (ej: 1000)\n";
    echo "  3. O usar LRU cache para eliminar entradas antiguas automáticamente\n";
} else {
    echo "Agregar opción para deshabilitar cache en tests:\n";
    echo "  1. Variable de entorno CONDITION_MATRIX_CACHE=0\n";
    echo "  2. O método ConditionMatrix::enableCache(false)\n";
}
