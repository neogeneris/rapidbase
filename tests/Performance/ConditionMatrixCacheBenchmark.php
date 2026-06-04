<?php
/**
 * Benchmark para evaluar el impacto de performance al deshabilitar el cache
 * en ConditionMatrix.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use RapidBase\Core\SQL\ConditionMatrix;

// Configuración inicial
ConditionMatrix::setDriver('sqlite');

// Datos de prueba simulando un esquema complejo
$schema = [
    'tables' => [
        'users' => ['id', 'name', 'email', 'status', 'created_at'],
        'posts' => ['id', 'user_id', 'title', 'content', 'published', 'views'],
        'comments' => ['id', 'post_id', 'user_id', 'body', 'rating'],
        'tags' => ['id', 'name', 'slug'],
        'post_tags' => ['post_id', 'tag_id']
    ]
];

$context = [
    'u' => 'users',
    'p' => 'posts',
    'c' => 'comments',
    't' => 'tags',
    'pt' => 'post_tags'
];

// Generar múltiples condiciones variadas para simular carga real
function generateConditions($seed) {
    $conditions = [];
    
    // Condiciones simples
    $conditions['u.status'] = ($seed % 3) === 0 ? 'active' : 'pending';
    $conditions['p.published'] = (bool)($seed % 2);
    
    // Condiciones con operadores
    $conditions['p.views']['>'] = $seed * 100;
    $conditions['c.rating']['>='] = ($seed % 5) + 1;
    
    // Condiciones IN
    $conditions['u.id']['IN'] = range(1, ($seed % 10) + 1);
    
    // Condiciones OR complejas
    $conditions[] = [
        'OR' => [
            ['u.email' => ['LIKE' => "%test{$seed}%"]],
            ['p.title' => ['LIKE' => "%post{$seed}%"]]
        ]
    ];
    
    // Más condiciones AND
    $conditions['p.created_at']['>'] = date('Y-m-d', strtotime("-{$seed} days"));
    
    return $conditions;
}

echo "=== Benchmark ConditionMatrix Cache ===\n\n";

// Configurar número de iteraciones
$iterations = 1000;
$uniqueConditionSets = 100; // Número de conjuntos de condiciones únicos

echo "Configuración:\n";
echo "- Iteraciones totales: {$iterations}\n";
echo "- Conjuntos de condiciones únicos: {$uniqueConditionSets}\n";
echo "- Total de llamadas parse(): {$iterations}\n\n";

// ---------------------------------------------------------
// PRUEBA 1: CON CACHE ACTIVADO (comportamiento actual)
// ---------------------------------------------------------
echo "PRUEBA 1: CON CACHE ACTIVADO\n";
echo str_repeat('-', 50) . "\n";

// Limpiar cache estático mediante reflection
$reflectionClass = new ReflectionClass(ConditionMatrix::class);
$parseCacheProperty = $reflectionClass->getProperty('parseCache');
$parseCacheProperty->setAccessible(true);
$parseCacheProperty->setValue(null, []);

$preMemory = memory_get_usage(true);
$startMemory = memory_get_usage();
$startTime = microtime(true);

for ($i = 0; $i < $iterations; $i++) {
    $seed = $i % $uniqueConditionSets;
    $conditions = generateConditions($seed);
    
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

echo "Tiempo total: " . number_format($timeWithCache * 1000, 2) . " ms\n";
echo "Tiempo por llamada: " . number_format(($timeWithCache / $iterations) * 1000000, 2) . " μs\n";
echo "Memoria usada (pico): " . number_format($memoryUsedWithCache / 1024, 2) . " KB\n";
echo "Memoria total asignada: " . number_format($memoryTotalWithCache / 1024 / 1024, 2) . " MB\n";

// Obtener tamaño del cache
$cacheSize = count($parseCacheProperty->getValue());
echo "Entradas en caché: {$cacheSize}\n\n";

// ---------------------------------------------------------
// PRUEBA 2: CON CACHE DESACTIVADO (simulado)
// ---------------------------------------------------------
echo "PRUEBA 2: SIN CACHE (simulado)\n";
echo str_repeat('-', 50) . "\n";

// Limpiar cache y desactivarlo temporalmente
$parseCacheProperty->setValue(null, []);

// Usar reflexión para modificar el comportamiento
// En este caso, llamaremos directamente a doParse mediante reflection
$doParseMethod = $reflectionClass->getMethod('doParse');
$doParseMethod->setAccessible(true);

$startMemoryNoCache = memory_get_usage();
$preMemoryNoCache = memory_get_usage(true);
$startTimeNoCache = microtime(true);

for ($i = 0; $i < $iterations; $i++) {
    $seed = $i % $uniqueConditionSets;
    $conditions = generateConditions($seed);
    
    try {
        // Llamada directa a doParse sin cache
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
echo "Memoria total asignada: " . number_format($memoryTotalWithoutCache / 1024 / 1024, 2) . " MB\n";
echo "Entradas en caché: 0 (desactivado)\n\n";

// ---------------------------------------------------------
// ANÁLISIS DE RESULTADOS
// ---------------------------------------------------------
echo str_repeat('=', 50) . "\n";
echo "ANÁLISIS COMPARATIVO\n";
echo str_repeat('=', 50) . "\n\n";

$performanceLoss = $timeWithCache > 0 ? (($timeWithoutCache - $timeWithCache) / $timeWithCache) * 100 : 0;
$memorySavings = $memoryTotalWithCache - $memoryTotalWithoutCache;
$memorySavingsPercent = $memoryTotalWithCache > 0 ? ($memorySavings / $memoryTotalWithCache) * 100 : 0;

echo "Impacto en Performance:\n";
echo "- Tiempo adicional sin cache: +" . number_format($performanceLoss, 2) . "%\n";
echo "- Diferencia absoluta: +" . number_format(($timeWithoutCache - $timeWithCache) * 1000, 2) . " ms en total\n";
echo "- Por llamada: +" . number_format((($timeWithoutCache - $timeWithCache) / $iterations) * 1000000, 2) . " μs\n\n";

echo "Impacto en Memoria:\n";
echo "- Memoria ahorrada sin cache: " . number_format($memorySavings / 1024 / 1024, 2) . " MB (" . number_format($memorySavingsPercent, 2) . "%)\n";
echo "- El cache consumió: " . number_format($memoryTotalWithCache / 1024 / 1024, 2) . " MB para {$cacheSize} entradas\n\n";

echo "Conclusiones:\n";
if ($performanceLoss < 10) {
    echo "✓ El impacto en performance es BAJO (<10%). Desactivar el cache es VIABLE.\n";
} elseif ($performanceLoss < 50) {
    echo "⚠ El impacto en performance es MODERADO (10-50%). Considerar cache más inteligente.\n";
} else {
    echo "✗ El impacto en performance es ALTO (>50%). Se recomienda optimizar el cache en lugar de desactivarlo.\n";
}

if ($memorySavingsPercent > 30) {
    echo "✓ El ahorro de memoria es SIGNIFICATIVO (>30%). Vale la pena considerar desactivarlo en tests.\n";
} else {
    echo "⚠ El ahorro de memoria es LIMITADO (<30%). Quizás implementar un TTL o límite de entradas sea mejor.\n";
}

echo "\nRecomendación:\n";
if ($performanceLoss < 20 && $memorySavingsPercent > 20) {
    echo "Se recomienda agregar una opción para deshabilitar el cache en entornos de testing.\n";
    echo "Esto resolvería los problemas de 'memory exhausted' sin afectar significativamente el rendimiento.\n";
} else {
    echo "Se recomienda implementar un cache con TTL o límite máximo de entradas en lugar de desactivarlo completamente.\n";
}
