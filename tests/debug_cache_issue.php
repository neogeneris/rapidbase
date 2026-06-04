<?php
/**
 * Prueba para diagnosticar el problema de memoria en ConditionMatrix::$parseCache
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/bootstrap.php';

use RapidBase\Core\SQL\ConditionMatrix;

echo "=== DIAGNÓSTICO DE CACHE EN CONDITIONMATRIX ===\n\n";

// 1. Verificar si existe el caché estático
echo "1. Verificando estructura de ConditionMatrix...\n";
$reflection = new ReflectionClass(ConditionMatrix::class);
$properties = $reflection->getProperties(ReflectionProperty::IS_STATIC);

foreach ($properties as $prop) {
    echo "   - Propiedad estática: {$prop->getName()}\n";
    if ($prop->getName() === 'parseCache') {
        $prop->setAccessible(true);
        $cache = $prop->getValue();
        echo "     * parseCache existe, tipo: " . gettype($cache) . "\n";
        echo "     * Tamaño actual: " . count($cache) . " entradas\n";
    }
}

// 2. Probar crecimiento del caché con múltiples llamadas
echo "\n2. Probando crecimiento del caché con consultas únicas...\n";
$initialMemory = memory_get_usage(true);
echo "   Memoria inicial: " . number_format($initialMemory / 1024, 2) . " KB\n";

ConditionMatrix::setDriver('sqlite');

$schema = [
    'tables' => [
        'users' => ['id' => ['type' => 'int'], 'name' => ['type' => 'varchar']],
        'posts' => ['id' => ['type' => 'int'], 'user_id' => ['type' => 'int'], 'title' => ['type' => 'varchar']]
    ]
];

// Simular muchas consultas diferentes (como en una suite de tests)
$numQueries = 100;
for ($i = 0; $i < $numQueries; $i++) {
    $conditions = ["field_$i" => "value_$i"];
    $context = ['u' => 'users'];
    ConditionMatrix::parse($conditions, $context, 'u', $schema);
}

$finalMemory = memory_get_usage(true);
$memoryGrowth = $finalMemory - $initialMemory;

echo "   Memoria final: " . number_format($finalMemory / 1024, 2) . " KB\n";
echo "   Crecimiento: " . number_format($memoryGrowth / 1024, 2) . " KB\n";
echo "   Consultas ejecutadas: $numQueries\n";

// Verificar tamaño del caché
$cacheProp = $reflection->getProperty('parseCache');
$cacheProp->setAccessible(true);
$cache = $cacheProp->getValue();
echo "   Entradas en caché: " . count($cache) . "\n";

// 3. Mostrar algunas entradas del caché para entender su estructura
echo "\n3. Muestra de entradas en el caché (primeras 5):\n";
$sampleKeys = array_slice(array_keys($cache), 0, 5);
foreach ($sampleKeys as $key) {
    echo "   - Key: $key\n";
    echo "     SQL: " . substr($cache[$key]['sql'], 0, 50) . "...\n";
    echo "     Params: " . json_encode($cache[$key]['params']) . "\n";
}

// 4. Probar método para limpiar caché (si existe)
echo "\n4. Verificando métodos de limpieza de caché...\n";
$methods = $reflection->getMethods(ReflectionMethod::IS_STATIC | ReflectionMethod::IS_PUBLIC);
$hasClearMethod = false;
foreach ($methods as $method) {
    if (stripos($method->getName(), 'clear') !== false || stripos($method->getName(), 'reset') !== false) {
        echo "   ✓ Método encontrado: {$method->getName()}\n";
        $hasClearMethod = true;
    }
}

if (!$hasClearMethod) {
    echo "   ⚠ NO EXISTE método para limpiar el caché\n";
    echo "   → Esta podría ser la causa del agotamiento de memoria\n";
}

// 5. Simular test real que podría causar el problema
echo "\n5. Simulando escenario de test suite grande...\n";
$testMemory = memory_get_usage(true);
try {
    // Ejecutar muchas variaciones de condiciones como en tests reales
    for ($batch = 0; $batch < 10; $batch++) {
        for ($i = 0; $i < 50; $i++) {
            $conditions = [
                "col_$batch" . "_$i" => "val_$i",
                'OR' => [["field_a" => 1], ["field_b" => 2]]
            ];
            ConditionMatrix::parse($conditions, ['t' => 'table_' . $batch], 't', $schema);
        }
    }
    
    $postTestMemory = memory_get_usage(true);
    echo "   Memoria después de 500 consultas complejas: " . number_format($postTestMemory / 1024 / 1024, 2) . " MB\n";
    
    $cacheProp->setAccessible(true);
    $cache = $cacheProp->getValue();
    echo "   Total entradas en caché: " . count($cache) . "\n";
    
} catch (Throwable $e) {
    echo "   ERROR: " . $e->getMessage() . "\n";
    echo "   Esto confirma el problema de memoria!\n";
}

echo "\n=== CONCLUSIONES ===\n";
echo "- El caché \$parseCache es ESTÁTICO y no se limpia automáticamente\n";
echo "- Cada combinación única de condiciones crea una nueva entrada\n";
echo "- En una suite de tests grande, esto puede consumir toda la memoria\n";
echo "- SOLUCIÓN SUGERIDA: Agregar método clearCache() o implementar LRU cache\n";
