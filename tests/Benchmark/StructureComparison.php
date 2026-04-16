<?php
/**
 * Benchmark Puro: Array vs Objeto para ensamblaje de Query
 * Objetivo: Medir exclusivamente el overhead de crear la estructura de datos.
 */

require __DIR__ . '/../../vendor/autoload.php';

use RapidBase\Core\SQL\Builders\SelectBuilder;
use RapidBase\Core\SQL\Builders\Field;
use RapidBase\Core\SQL\Builders\Table;
use RapidBase\Core\SQL\Builders\Join;

$iterations = 1000000;

echo "=== BENCHMARK PURO: ESTRUCTURA DE DATOS ({$iterations} iteraciones) ===\n\n";

// --- PRUEBA 1: ENFOQUE TRADICIONAL (ARRAYS) ---
gc_collect_cycles();
$startArray = microtime(true);
$memoryStartArray = memory_get_usage();

for ($i = 0; $i < $iterations; $i++) {
    // Simulación del método antiguo: $parts = []
    $parts = [];
    
    // Asignación directa (lo más rápido en arrays)
    $parts['select'] = ['id', 'name'];
    $parts['from'] = 'users';
    $parts['where'] = ['active' => 1, 'role' => 'admin'];
    $parts['join'] = [['table' => 'roles', 'on' => 'users.role_id=roles.id']];
    $parts['order'] = ['name' => 'ASC'];
    $parts['limit'] = 10;
    $parts['offset'] = 0;
    
    // Simular un poco de manipulación típica
    if ($i % 2 == 0) {
        $parts['group'] = ['role'];
    }
}

$endArray = microtime(true);
$memoryEndArray = memory_get_usage();

$timeArray = ($endArray - $startArray) * 1000; // ms
$memArray = ($memoryEndArray - $memoryStartArray) / 1024 / 1024; // MB

echo "1. ENFOQUE ARRAY (Tradicional)\n";
echo "   Tiempo Total: " . number_format($timeArray, 2) . " ms\n";
echo "   Tiempo/Iter:  " . number_format($timeArray / $iterations * 1000, 4) . " µs\n";
echo "   Memoria Delta: " . number_format($memArray, 2) . " MB\n\n";

// --- PRUEBA 2: ENFOQUE NUEVO (OBJETOS / SelectBuilder) ---
gc_collect_cycles();
$startObj = microtime(true);
$memoryStartObj = memory_get_usage();

for ($i = 0; $i < $iterations; $i++) {
    // Simulación del nuevo método: new SelectBuilder()
    $builder = new SelectBuilder();
    
    // Asignación de propiedades públicas (equivalente a $parts['key'])
    $builder->select = [new Field('id'), new Field('name')];
    $builder->from = new Table('users');
    $builder->where = ['active' => 1, 'role' => 'admin'];
    $builder->join = [new Join('roles', 'users.role_id=roles.id')];
    $builder->order = ['name' => 'ASC'];
    $builder->limit = 10;
    $builder->offset = 0;
    
    // Simular manipulación
    if ($i % 2 == 0) {
        $builder->group = ['role'];
    }
}

$endObj = microtime(true);
$memoryEndObj = memory_get_usage();

$timeObj = ($endObj - $startObj) * 1000; // ms
$memObj = ($memoryEndObj - $memoryStartObj) / 1024 / 1024; // MB

echo "2. ENFOQUE OBJETO (SelectBuilder)\n";
echo "   Tiempo Total: " . number_format($timeObj, 2) . " ms\n";
echo "   Tiempo/Iter:  " . number_format($timeObj / $iterations * 1000, 4) . " µs\n";
echo "   Memoria Delta: " . number_format($memObj, 2) . " MB\n\n";

// --- COMPARATIVA ---
$speedDiff = (($timeObj - $timeArray) / $timeArray) * 100;
$memDiff = (($memObj - $memArray) / $memArray) * 100;

echo "=== RESULTADOS ===\n";
if ($speedDiff > 0) {
    echo "❌ El enfoque de OBJETOS es un " . number_format($speedDiff, 2) . "% MÁS LENTO que los Arrays.\n";
} else {
    echo "✅ El enfoque de OBJETOS es un " . number_format(abs($speedDiff), 2) . "% MÁS RÁPIDO que los Arrays.\n";
}

if ($memDiff > 0) {
    echo "📉 Los Objetos consumen un " . number_format($memDiff, 2) . "% MÁS de memoria.\n";
} else {
    echo "📈 Los Objetos consumen un " . number_format(abs($memDiff), 2) . "% MENOS de memoria.\n";
}

echo "\nConclusión: ";
if ($speedDiff > 5) {
    echo "El overhead de instanciar objetos (SelectBuilder, Field, Table, Join) es significativo comparado con arrays planos.\n";
    echo "Recomendación: Usar arrays internos para rendimiento crítico o híbrido.\n";
} elseif ($speedDiff > -5) {
    echo "La diferencia de rendimiento es negligible. La ganancia en tipado y mantenimiento de los objetos justifica el uso.\n";
} else {
    echo "¡Sorpresa! Los objetos son más rápidos o iguales. Probablemente debido a optimizaciones internas de PHP 8+ para objetos simples.\n";
}
