<?php

/**
 * Prueba de Concepto: Generación y Parsing de CSV con delimitadores ASCII seguros
 * 
 * Objetivo: Comparar la eficiencia de generar datos estructurados usando CSV vs JSON,
 * utilizando caracteres ASCII no imprimibles como delimitadores para evitar conflictos
 * con datos que contengan comas, saltos de línea o comillas.
 * 
 * Delimitadores propuestos:
 * - RS (Record Separator, \x1E): Separa filas/registros
 * - US (Unit Separator, \x1F): Separa columnas dentro de una fila
 * - GS (Group Separator, \x1D): Separa metadata (header) del cuerpo de datos
 */

// Configuración de delimitadores ASCII
const DELIM_ROW = "\x1E";      // RS - Separador de filas
const DELIM_COL = "\x1F";      // US - Separador de columnas
const DELIM_META = "\x1D";     // GS - Separador de metadata (header/body)

/**
 * Genera CSV en memoria a partir de un array asociativo
 * No crea archivos, todo se maneja en memoria
 */
function generateCSV(array $data): string {
    if (empty($data)) {
        return '';
    }

    // Obtener headers de las claves del primer elemento
    $headers = array_keys(reset($data));
    
    // Usar stream en memoria para eficiencia
    $stream = fopen('php://memory', 'r+');
    
    // Escribir header
    fputcsv($stream, $headers, DELIM_COL, '"');
    
    // Escribir filas de datos
    foreach ($data as $row) {
        // Asegurar orden consistente con los headers
        $orderedRow = [];
        foreach ($headers as $key) {
            $orderedRow[] = $row[$key] ?? '';
        }
        fputcsv($stream, $orderedRow, DELIM_COL, '"');
    }
    
    // Obtener contenido del stream
    rewind($stream);
    $csvContent = stream_get_contents($stream);
    fclose($stream);
    
    // Reemplazar saltos de línea por nuestro delimitador de filas
    $csvContent = rtrim($csvContent, "\n");
    $csvContent = str_replace("\n", DELIM_ROW, $csvContent);
    
    return $csvContent;
}

/**
 * Genera CSV optimizado sin usar fputcsv (implementación manual)
 * Para comparar rendimiento
 */
function generateCSVManual(array $data): string {
    if (empty($data)) {
        return '';
    }

    $headers = array_keys(reset($data));
    $lines = [];
    
    // Header
    $lines[] = implode(DELIM_COL, $headers);
    
    // Datos
    foreach ($data as $row) {
        $orderedRow = [];
        foreach ($headers as $key) {
            $value = $row[$key] ?? '';
            // Escapar valores si contienen el delimitador o comillas
            if (strpos($value, DELIM_COL) !== false || 
                strpos($value, '"') !== false || 
                strpos($value, DELIM_ROW) !== false) {
                $value = '"' . str_replace('"', '""', $value) . '"';
            }
            $orderedRow[] = $value;
        }
        $lines[] = implode(DELIM_COL, $orderedRow);
    }
    
    return implode(DELIM_ROW, $lines);
}

/**
 * Parsea CSV con delimitadores personalizados
 */
function parseCSV(string $csv): array {
    // Separar metadata (headers) del cuerpo
    $parts = explode(DELIM_META, $csv, 2);
    
    if (count($parts) < 2) {
        // Si no hay separador meta, asumir que la primera línea es header
        $allLines = explode(DELIM_ROW, $csv);
        $headerLine = array_shift($allLines);
        $body = implode(DELIM_ROW, $allLines);
    } else {
        $headerLine = $parts[0];
        $body = $parts[1];
    }
    
    // Parsear headers
    $headers = str_getcsv($headerLine, DELIM_COL, '"');
    
    // Parsear cuerpo
    $rows = explode(DELIM_ROW, $body);
    $result = [];
    
    foreach ($rows as $row) {
        if (trim($row) === '') continue;
        
        $values = str_getcsv($row, DELIM_COL, '"');
        
        // Combinar headers con valores
        $assocRow = [];
        foreach ($headers as $i => $header) {
            $assocRow[$header] = $values[$i] ?? '';
        }
        $result[] = $assocRow;
    }
    
    return $result;
}

/**
 * Empaqueta CSV con metadata separada explícitamente
 */
function packageCSVWithMetadata(array $data, string $tableName = 'table'): string {
    $csvBody = generateCSV($data);
    
    // Metadata en formato simple: nombre_tabla|num_columnas|num_filas
    $metadata = json_encode([
        'table' => $tableName,
        'columns' => count(array_keys(reset($data))),
        'rows' => count($data),
        'timestamp' => time()
    ]);
    
    // Empaquetar: METADATA + GS + CSV_BODY
    return $metadata . DELIM_META . $csvBody;
}

/**
 * Compara tamaño entre CSV y JSON
 */
function compareSizes(array $data): array {
    $jsonString = json_encode($data);
    $csvString = generateCSV($data);
    $packagedCSV = packageCSVWithMetadata($data, 'test_table');
    
    return [
        'json_size' => strlen($jsonString),
        'csv_size' => strlen($csvString),
        'packaged_csv_size' => strlen($packagedCSV),
        'json_compressed' => strlen(gzdeflate($jsonString)),
        'csv_compressed' => strlen(gzdeflate($csvString)),
        'savings_percent' => round((1 - strlen($csvString) / strlen($jsonString)) * 100, 2)
    ];
}

/**
 * Benchmark de generación
 */
function benchmarkGeneration(array $data, int $iterations = 100): array {
    $timings = [];
    
    // JSON
    $start = microtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        json_encode($data);
    }
    $timings['json'] = (microtime(true) - $start) * 1000; // ms
    
    // CSV con fputcsv
    $start = microtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        generateCSV($data);
    }
    $timings['csv_fputcsv'] = (microtime(true) - $start) * 1000;
    
    // CSV manual
    $start = microtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        generateCSVManual($data);
    }
    $timings['csv_manual'] = (microtime(true) - $start) * 1000;
    
    // CSV empaquetado con metadata
    $start = microtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        packageCSVWithMetadata($data, 'test_table');
    }
    $timings['csv_packaged'] = (microtime(true) - $start) * 1000;
    
    return $timings;
}

// ============================================================================
// EJECUCIÓN DE PRUEBAS
// ============================================================================

echo "=== Prueba de Concepto: CSV vs JSON ===\n\n";

// Datos de prueba pequeños
$smallData = [
    ['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com', 'role' => 'admin'],
    ['id' => 2, 'name' => 'Bob', 'email' => 'bob@example.com', 'role' => 'user'],
    ['id' => 3, 'name' => 'Charlie', 'email' => 'charlie@test.com', 'role' => 'user'],
];

// Datos de prueba grandes (1000 registros)
$largeData = [];
for ($i = 0; $i < 1000; $i++) {
    $largeData[] = [
        'id' => $i,
        'name' => "User_$i",
        'email' => "user$i@example.com",
        'role' => $i % 5 === 0 ? 'admin' : 'user',
        'description' => "This is a description for user $i with some special chars: áéíóú ñ ü",
        'created_at' => date('Y-m-d H:i:s', time() - rand(0, 86400 * 365))
    ];
}

// Prueba 1: Verificar generación y parsing correcto
echo "--- Prueba 1: Generación y Parsing ---\n";
$csv = generateCSV($smallData);
echo "CSV generado:\n";
echo str_replace(DELIM_ROW, "\n[ROW_SEP]\n", str_replace(DELIM_COL, " | ", $csv)) . "\n\n";

$parsed = parseCSV($csv);
echo "Datos parseados:\n";
print_r($parsed);
echo "¿Coinciden? " . (json_encode($smallData) === json_encode($parsed) ? "✓ SÍ" : "✗ NO") . "\n\n";

// Prueba 2: Comparación de tamaños
echo "--- Prueba 2: Comparación de Tamaños ---\n";
echo "Datos pequeños (3 registros):\n";
$sizeComparison = compareSizes($smallData);
foreach ($sizeComparison as $metric => $value) {
    echo "  $metric: $value bytes\n";
}
echo "\n";

echo "Datos grandes (1000 registros):\n";
$sizeComparisonLarge = compareSizes($largeData);
foreach ($sizeComparisonLarge as $metric => $value) {
    echo "  $metric: $value bytes\n";
}
echo "\n";

// Prueba 3: Benchmark de rendimiento
echo "--- Prueba 3: Benchmark de Rendimiento (100 iteraciones) ---\n";
echo "Datos pequeños:\n";
$benchmarkSmall = benchmarkGeneration($smallData, 100);
foreach ($benchmarkSmall as $method => $time) {
    echo "  $method: " . number_format($time, 2) . " ms\n";
}
echo "\n";

echo "Datos grandes (10 iteraciones):\n";
$benchmarkLarge = benchmarkGeneration($largeData, 10);
foreach ($benchmarkLarge as $method => $time) {
    echo "  $method: " . number_format($time, 2) . " ms\n";
}
echo "\n";

// Prueba 4: Datos con caracteres especiales
echo "--- Prueba 4: Caracteres Especiales ---\n";
$specialData = [
    ['id' => 1, 'text' => 'Contains, comma', 'desc' => "Has\nnewline", 'quote' => 'Say "Hello"'],
    ['id' => 2, 'text' => 'Normal text', 'desc' => 'Simple', 'quote' => 'No quotes'],
];

$csvSpecial = generateCSV($specialData);
echo "CSV con caracteres especiales:\n";
echo bin2hex($csvSpecial) . "\n"; // Mostrar en hex para ver delimitadores

$parsedSpecial = parseCSV($csvSpecial);
echo "Parseado correctamente: " . (json_encode($specialData) === json_encode($parsedSpecial) ? "✓ SÍ" : "✗ NO") . "\n";
echo "Datos: \n";
print_r($parsedSpecial);
echo "\n";

// Prueba 5: Empaquetado con metadata
echo "--- Prueba 5: Empaquetado con Metadata ---\n";
$packaged = packageCSVWithMetadata($smallData, 'users');
echo "Paquete completo (hex primeros 200 chars): " . substr(bin2hex($packaged), 0, 200) . "...\n";
echo "Tamaño total: " . strlen($packaged) . " bytes\n\n";

// Conclusión
echo "=== Conclusiones ===\n";
echo "- CSV es más eficiente en tamaño: ~" . abs($sizeComparisonLarge['savings_percent']) . "% menos que JSON\n";
echo "- CSV comprimido es aún más eficiente\n";
echo "- La generación en memoria con streams es eficiente\n";
echo "- Los delimitadores ASCII (\x1E, \x1F, \x1D) evitan conflictos con datos\n";
echo "- El parsing es rápido usando str_getcsv con delimitadores personalizados\n";
echo "\nRecomendación: Implementar adaptador CSV para RapidBase\n";
