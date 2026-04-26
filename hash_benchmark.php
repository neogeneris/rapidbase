<?php
/**
 * Prueba de Concepto: Comparación de Algoritmos de Hash en PHP 8.1+
 * 
 * Este script compara el rendimiento y características de varios algoritmos
 * de hash disponibles en PHP, con énfasis en la familia xxhash introducida
 * oficialmente en PHP 8.1.
 */

// Configuración de prueba
$testData = [
    'small' => str_repeat('Lorem ipsum dolor sit amet, ', 10),      // ~280 bytes
    'medium' => str_repeat('Lorem ipsum dolor sit amet, ', 1000),   // ~28 KB
    'large' => str_repeat('Lorem ipsum dolor sit amet, ', 10000),   // ~280 KB
];

// Algoritmos a comparar agrupados por categoría
$algorithms = [
    'No criptográficos (rápidos)' => [
        'xxh32' => 'XXH32 (32-bit)',
        'xxh64' => 'XXH64 (64-bit)',
        'xxh3' => 'XXH3 (recomendado)',
        'xxh128' => 'XXH128 (128-bit)',
        'murmur3a' => 'MurmurHash3A',
        'murmur3c' => 'MurmurHash3C',
        'crc32' => 'CRC32',
        'adler32' => 'Adler32',
    ],
    'Criptográficos (seguros)' => [
        'md5' => 'MD5 (obsoleto)',
        'sha1' => 'SHA1 (obsoleto)',
        'sha256' => 'SHA256 (recomendado)',
        'sha512' => 'SHA512',
        'sha3-256' => 'SHA3-256',
    ],
];

echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║     COMPARATIVA DE ALGORITMOS DE HASH - PHP " . PHP_VERSION . "          ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n\n";

/**
 * Función para medir el tiempo de ejecución de un hash
 */
function benchmarkHash(string $algo, string $data, int $iterations = 100): array
{
    $start = microtime(true);
    
    for ($i = 0; $i < $iterations; $i++) {
        $hash = hash($algo, $data);
    }
    
    $end = microtime(true);
    $timeMs = ($end - $start) * 1000 / $iterations;
    
    return [
        'hash' => $hash,
        'length' => strlen($hash),
        'time_ms' => $timeMs,
    ];
}

/**
 * Función para formatear números con separadores de miles
 */
function formatNumber(float $number): string
{
    return number_format($number, 4);
}

// Mostrar información sobre xxhash
echo "┌──────────────────────────────────────────────────────────────────┐\n";
echo "│ INFORMACIÓN SOBRE XXHASH                                         │\n";
echo "├──────────────────────────────────────────────────────────────────┤\n";
echo "│ • Disponible desde PHP 8.1                                       │\n";
echo "│ • Familia de funciones de hash no criptográficas                 │\n";
echo "│ • Extremadamente rápidas comparadas con SHA/MD5                  │\n";
echo "│ • Variantes: xxh32, xxh64, xxh3 (recomendada), xxh128            │\n";
echo "│ • Ideales para: checksums, tablas hash, validación de datos      │\n";
echo "│ • NO usar para: contraseñas, seguridad criptográfica             │\n";
echo "└──────────────────────────────────────────────────────────────────┘\n\n";

// Verificar disponibilidad de algoritmos
$availableAlgos = hash_algos();
echo "Algoritmos disponibles en este sistema: " . count($availableAlgos) . "\n";
echo "Familia xxhash disponible: " . (in_array('xxh3', $availableAlgos) ? '✓ SÍ' : '✗ NO') . "\n\n";

// ============================================================================
// PRUEBA 1: Rendimiento por tamaño de datos
// ============================================================================
echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║  PRUEBA 1: TIEMPO DE EJECUCIÓN POR TAMAÑO DE DATOS (ms/op)       ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n\n";

foreach ($algorithms as $category => $algos) {
    echo "【{$category}】\n";
    printf("%-15s | %10s | %10s | %10s | %s\n", "Algoritmo", "Small", "Medium", "Large", "Longitud");
    echo str_repeat("-", 70) . "\n";
    
    foreach ($algos as $algo => $name) {
        if (!in_array($algo, $availableAlgos)) {
            continue;
        }
        
        $results = [];
        $totalTime = 0;
        
        foreach ($testData as $size => $data) {
            $result = benchmarkHash($algo, $data, 100);
            $results[$size] = $result;
            $totalTime += $result['time_ms'];
            $hashLength = $result['length'];
        }
        
        // Color coding para tiempos (solo en terminal que soporte ANSI)
        $smallTime = formatNumber($results['small']['time_ms']);
        $mediumTime = formatNumber($results['medium']['time_ms']);
        $largeTime = formatNumber($results['large']['time_ms']);
        
        printf("%-15s | %10s | %10s | %10s | %d chars\n", 
            $name, 
            $smallTime, 
            $mediumTime, 
            $largeTime,
            $hashLength
        );
    }
    echo "\n";
}

// ============================================================================
// PRUEBA 2: Comparativa directa de velocidad
// ============================================================================
echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║  PRUEBA 2: VELOCIDAD RELATIVA (datos medianos, base = xxh3)      ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n\n";

$baseAlgo = 'xxh3';
$baseResult = benchmarkHash($baseAlgo, $testData['medium'], 100);
$baseTime = $baseResult['time_ms'];

printf("%-15s | %10s | %12s | %s\n", "Algoritmo", "Tiempo(ms)", "Relativo", "Hash Sample");
echo str_repeat("-", 75) . "\n";

$allAlgos = array_keys(array_merge(...array_values($algorithms)));

usort($allAlgos, function($a, $b) use ($testData, $availableAlgos) {
    if (!in_array($a, $availableAlgos) || !in_array($b, $availableAlgos)) {
        return 0;
    }
    $timeA = benchmarkHash($a, $testData['medium'], 50)['time_ms'];
    $timeB = benchmarkHash($b, $testData['medium'], 50)['time_ms'];
    return $timeA <=> $timeB;
});

foreach ($allAlgos as $algo) {
    // Buscar en las categorías para obtener el nombre correcto
    $displayName = $algo;
    foreach ($algorithms as $cat => $algosList) {
        if (isset($algosList[$algo])) {
            $displayName = $algosList[$algo];
            break;
        }
    }
    
    if (!in_array($algo, $availableAlgos)) {
        continue;
    }
    
    $result = benchmarkHash($algo, $testData['medium'], 100);
    $relative = $result['time_ms'] / $baseTime;
    $speedMark = $relative < 1 ? '⚡ MÁS RÁPIDO' : ($relative > 10 ? '🐌 LENTO' : '');
    
    printf("%-15s | %10.4f | %12.2fx %s\n", 
        $displayName, 
        $result['time_ms'], 
        $relative,
        $speedMark
    );
}

echo "\n";

// ============================================================================
// PRUEBA 3: Ejemplos de uso de xxhash
// ============================================================================
echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║  PRUEBA 3: EJEMPLOS DE USO DE XXHASH                             ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n\n";

$sampleData = "Hello, World! Esta es una prueba de xxhash en PHP.";

echo "Datos de entrada: \"{$sampleData}\"\n\n";

$xxhAlgos = ['xxh32', 'xxh64', 'xxh3', 'xxh128'];

foreach ($xxhAlgos as $algo) {
    if (!in_array($algo, $availableAlgos)) {
        continue;
    }
    
    $hash = hash($algo, $sampleData);
    $binary = hash($algo, $sampleData, true);
    
    echo sprintf("%-10s: %s (%d bytes)\n", 
        strtoupper($algo), 
        $hash, 
        strlen($binary)
    );
}

echo "\n";

// ============================================================================
// PRUEBA 4: Caso de uso práctico - Checksum de archivos
// ============================================================================
echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║  PRUEBA 4: CASO PRÁCTICO - CHECKSUM DE ARCHIVOS                  ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n\n";

// Crear un archivo temporal para pruebas
$tempFile = tempnam(sys_get_temp_dir(), 'hash_test_');
file_put_contents($tempFile, $testData['large']);
$fileSize = filesize($tempFile);

echo "Archivo de prueba: {$fileSize} bytes\n\n";

$checksumAlgos = ['xxh3', 'sha256', 'md5', 'crc32'];

printf("%-10s | %10s | %-64s\n", "Algoritmo", "Tiempo(ms)", "Checksum");
echo str_repeat("-", 90) . "\n";

foreach ($checksumAlgos as $algo) {
    if (!in_array($algo, $availableAlgos)) {
        continue;
    }
    
    $start = microtime(true);
    $checksum = hash_file($algo, $tempFile);
    $end = microtime(true);
    $timeMs = ($end - $start) * 1000;
    
    // Truncar checksum si es muy largo
    $displayChecksum = strlen($checksum) > 64 ? substr($checksum, 0, 60) . '...' : $checksum;
    
    printf("%-10s | %10.4f | %-64s\n", 
        strtoupper($algo), 
        $timeMs, 
        $displayChecksum
    );
}

// Limpiar archivo temporal
unlink($tempFile);

echo "\n";

// ============================================================================
// CONCLUSIONES
// ============================================================================
echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║  CONCLUSIONES Y RECOMENDACIONES                                  ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n\n";

echo "✅ CUÁNDO USAR XXHASH:\n";
echo "   • Validación de integridad de datos\n";
echo "   • Tablas hash y estructuras de datos\n";
echo "   • Checksums de archivos grandes\n";
echo "   • Detección de duplicados\n";
echo "   • Particionamiento consistente (consistent hashing)\n\n";

echo "❌ CUÁNDO NO USAR XXHASH:\n";
echo "   • Almacenamiento de contraseñas (usar password_hash())\n";
echo "   • Firmas digitales\n";
echo "   • Tokens de seguridad\n";
echo "   • Cualquier aplicación que requiera resistencia a colisiones\n\n";

echo "🏆 RECOMENDACIÓN: Usar XXH3 para la mayoría de casos no criptográficos.\n";
echo "   Ofrece el mejor equilibrio entre velocidad y calidad de distribución.\n\n";

echo "Script ejecutado en PHP " . PHP_VERSION . "\n";
echo "Fecha: " . date('Y-m-d H:i:s') . "\n";
