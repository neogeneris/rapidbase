<?php
/**
 * Prueba de Concepto: Eficiencia de Transferencia de Datos para Grids (API -> Client)
 * 
 * Compara JSON, CSV (con delimitadores seguros) y un Formato Binario Personalizado.
 * 
 * Métricas:
 * 1. Tiempo de generación en servidor.
 * 2. Tamaño en disco (crudo).
 * 3. Tamaño de transferencia (Gzip simulado).
 * 4. Tiempo de parseo/conversión en cliente (simulado).
 */

// Configuración
$record_count = 5000; // Simular 5k registros (un grid grande paginado o virtual)
$iterations = 10;     // Iteraciones para promediar tiempos

// Generador de datos falsos realistas
function generateData(int $count): array {
    $data = [];
    for ($i = 0; $i < $count; $i++) {
        $data[] = [
            'id' => $i + 1,
            'uuid' => uniqid('usr_', true),
            'name' => "Usuario $i",
            'email' => "user$i@example.com",
            'role' => $i % 3 === 0 ? 'admin' : 'user',
            'status' => $i % 2 === 0 ? 'active' : 'inactive',
            'balance' => rand(100, 99999) / 100,
            'last_login' => date('Y-m-d H:i:s', time() - rand(0, 86400 * 30)),
            'bio' => "Descripción breve del usuario $i con algunos caracteres especiales: áéíóú ñ & < >"
        ];
    }
    return $data;
}

// ============================================================================
// 1. IMPLEMENTACIÓN DE FORMATOS
// ============================================================================

class FormatHandler {
    
    // --- JSON Standard ---
    public static function encodeJson(array $data): string {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function decodeJson(string $payload): array {
        return json_decode($payload, true);
    }

    // --- CSV Optimizado (Delimitadores ASCII) ---
    // US: \x1F (Unit Separator) para columnas
    // RS: \x1E (Record Separator) para filas
    // GS: \x1D (Group Separator) para separar metadata de datos (opcional)
    public static function encodeCsv(array $data): string {
        if (empty($data)) return '';
        
        $columns = array_keys($data[0]);
        $output = [];
        
        // Cabecera
        $output[] = implode("\x1F", $columns);
        
        // Filas
        foreach ($data as $row) {
            // Asegurar orden de columnas consistente
            $flatRow = [];
            foreach ($columns as $col) {
                $val = $row[$col];
                // Escapar delimitadores y saltos de línea dentro de los datos si ocurrieran
                // Aunque usamos chars raros, es buena práctica escapar el char de columna
                $val = str_replace("\x1F", "\x1F\x1F", (string)$val); 
                $flatRow[] = $val;
            }
            $output[] = implode("\x1F", $flatRow);
        }
        
        return implode("\x1E", $output);
    }

    public static function decodeCsv(string $payload): array {
        if (empty($payload)) return [];
        
        $rows = explode("\x1E", $payload);
        $headers = explode("\x1F", array_shift($rows));
        $result = [];
        
        foreach ($rows as $rowStr) {
            // Manejar escape simple (doble delimiter)
            // Nota: str_getcsv no funciona bien con escapes personalizados complejos sin archivo,
            // así que hacemos split manual y reparo básico.
            $cells = explode("\x1F", $rowStr);
            
            $record = [];
            foreach ($headers as $index => $header) {
                $val = $cells[$index] ?? '';
                // Desescapar
                $val = str_replace("\x1F\x1F", "\x1F", $val);
                
                // Tipado automático básico (números)
                if (is_numeric($val)) {
                    $val = strpos($val, '.') !== false ? (float)$val : (int)$val;
                }
                $record[$header] = $val;
            }
            $result[] = $record;
        }
        return $result;
    }

    // --- Formato Binario Personalizado (RapidBin) ---
    // Estructura: [Total Records:4b][Num Cols:4b][ColNamesLen:4b][ColNamesString][RowData...]
    // RowData: [Type:1b][Len:4b][ValueBytes]... por cada columna
    // Tipos: 1=Int, 2=Float, 3=String
    public static function encodeBin(array $data): string {
        if (empty($data)) return pack('VV', 0, 0);
        
        $cols = array_keys($data[0]);
        $numCols = count($cols);
        $numRows = count($data);
        
        // Serializar nombres de columnas
        $colNamesStr = implode("\x00", $cols);
        $colNamesLen = strlen($colNamesStr);
        
        $buffer = [];
        $buffer[] = pack('V', $numRows);      // Total Records (Unsigned Long 32bit)
        $buffer[] = pack('V', $numCols);      // Num Cols
        $buffer[] = pack('V', $colNamesLen);  // Len de nombres
        $buffer[] = $colNamesStr;             // Nombres
        
        foreach ($data as $row) {
            foreach ($cols as $col) {
                $val = $row[$col];
                $type = 3; // String default
                $binVal = (string)$val;
                
                if (is_int($val)) {
                    $type = 1;
                    $binVal = pack('l', $val); // Signed Long
                } elseif (is_float($val)) {
                    $type = 2;
                    $binVal = pack('d', $val); // Double
                } else {
                    // String: longitud + bytes
                    $len = strlen($binVal);
                    $binVal = pack('V', $len) . $binVal;
                }
                
                $buffer[] = chr($type) . $binVal;
            }
        }
        
        return implode('', $buffer);
    }

    public static function decodeBin(string $payload): array {
        if (strlen($payload) < 12) return [];
        
        $offset = 0;
        $unpack = function($fmt) use (&$offset, $payload) {
            $len = unpack('Vlen', substr($payload, $offset, 4))['len'] ?? 0; // Helper para V
            // Nota: esta closure es simplificada para el ejemplo, en prod usaríamos unpack directo con offset calculado
            
            // Usaremos unpack directo con cálculo manual de offset para velocidad
            return null; 
        };

        // Parseo manual rápido
        $header = unpack('Vrows/Vcols/Vcollen', substr($payload, 0, 12));
        $numRows = $header['rows'];
        $numCols = $header['cols'];
        $colNameLen = $header['collen'];
        
        $offset = 12;
        $colNamesStr = substr($payload, $offset, $colNameLen);
        $offset += $colNameLen;
        $cols = explode("\x00", $colNamesStr);
        
        $result = [];
        
        for ($r = 0; $r < $numRows; $r++) {
            $record = [];
            for ($c = 0; $c < $numCols; $c++) {
                $type = ord($payload[$offset]);
                $offset++;
                
                $val = null;
                if ($type === 1) { // Int
                    $un = unpack('l', substr($payload, $offset, 4));
                    $val = $un[1];
                    $offset += 4;
                } elseif ($type === 2) { // Float
                    $un = unpack('d', substr($payload, $offset, 8));
                    $val = $un[1];
                    $offset += 8;
                } elseif ($type === 3) { // String
                    $un = unpack('Vlen', substr($payload, $offset, 4));
                    $len = $un['len'];
                    $offset += 4;
                    $val = substr($payload, $offset, $len);
                    $offset += $len;
                }
                $record[$cols[$c]] = $val;
            }
            $result[] = $record;
        }
        
        return $result;
    }
}

// ============================================================================
// 2. BENCHMARKING
// ============================================================================

echo "=== Benchmark: Transferencia de Datos para Grid ($record_count registros) ===\n\n";

$rawData = generateData($record_count);
$results = [];

$formats = [
    'JSON' => ['enc' => [FormatHandler::class, 'encodeJson'], 'dec' => [FormatHandler::class, 'decodeJson']],
    'CSV (ASCII)' => ['enc' => [FormatHandler::class, 'encodeCsv'], 'dec' => [FormatHandler::class, 'decodeCsv']],
    'BIN (RapidBin)' => ['enc' => [FormatHandler::class, 'encodeBin'], 'dec' => [FormatHandler::class, 'decodeBin']],
];

foreach ($formats as $name => $methods) {
    echo "--- Probando formato: $name ---\n";
    
    $genTimes = [];
    $sizesRaw = [];
    $sizesGzip = [];
    $parseTimes = [];
    
    for ($i = 0; $i < $iterations; $i++) {
        // 1. Generación
        $t0 = microtime(true);
        $payload = call_user_func($methods['enc'], $rawData);
        $t1 = microtime(true);
        
        // 2. Tamaño Raw
        $sizeRaw = strlen($payload);
        
        // 3. Simulación de Transferencia (Gzip Nivel 9)
        $gzipped = gzencode($payload, 9);
        $sizeGzip = strlen($gzipped);
        
        // 4. Parseo (Simulación de Cliente)
        $t2 = microtime(true);
        $decoded = call_user_func($methods['dec'], $payload);
        $t3 = microtime(true);
        
        // Validar integridad
        if (count($decoded) !== count($rawData)) {
            die("ERROR: Integridad fallida en $name. Registros esperados: " . count($rawData) . ", obtenidos: " . count($decoded));
        }
        
        $genTimes[] = ($t1 - $t0) * 1000; // ms
        $sizesRaw[] = $sizeRaw;
        $sizesGzip[] = $sizeGzip;
        $parseTimes[] = ($t3 - $t2) * 1000; // ms
    }
    
    $avgGen = array_sum($genTimes) / $iterations;
    $avgSizeRaw = array_sum($sizesRaw) / $iterations;
    $avgSizeGzip = array_sum($sizesGzip) / $iterations;
    $avgParse = array_sum($parseTimes) / $iterations;
    
    // Guardamos los datos brutos primero, calcularemos los ratios relativos al final
    $results[$name] = [
        'gen_ms' => $avgGen,
        'raw_bytes' => $avgSizeRaw,
        'gzip_bytes' => $avgSizeGzip,
        'parse_ms' => $avgParse,
        'ratio_gzip' => 0 // Se calculará después
    ];
    
    echo "  Gen: " . number_format($avgGen, 2) . " ms\n";
    echo "  Raw: " . number_format($avgSizeRaw/1024, 2) . " KB\n";
    echo "  Gzip: " . number_format($avgSizeGzip/1024, 2) . " KB\n";
    echo "  Parse: " . number_format($avgParse, 2) . " ms\n\n";
}

// Calcular ratios relativos ahora que tenemos todos los datos (especialmente JSON)
if (isset($results['JSON']['gzip_bytes']) && $results['JSON']['gzip_bytes'] > 0) {
    $baseGzip = $results['JSON']['gzip_bytes'];
    foreach ($results as $name => $data) {
        $results[$name]['ratio_gzip'] = ($data['gzip_bytes'] / $baseGzip) * 100;
    }
} else {
    // Fallback si JSON falló o es 0 (improbable)
    foreach ($results as $name => $data) {
        $results[$name]['ratio_gzip'] = 100;
    }
}

// ============================================================================
// 3. TABLA COMPARATIVA FINAL
// ============================================================================

echo "=== Resumen Comparativo (Base: JSON = 100%) ===\n";
printf("%-15s | %-10s | %-10s | %-10s | %-10s\n", "Formato", "Gen (ms)", "Red (KB)", "Parse (ms)", "Peso Relativo");
echo str_repeat("-", 65) . "\n";

$baseGzip = $results['JSON']['gzip_bytes'];
$baseGen = $results['JSON']['gen_ms'];
$baseParse = $results['JSON']['parse_ms'];

foreach ($results as $name => $data) {
    $ratioSize = ($data['gzip_bytes'] / $baseGzip) * 100;
    $ratioGen = ($data['gen_ms'] / $baseGen) * 100;
    $ratioParse = ($data['parse_ms'] / $baseParse) * 100;
    
    printf("%-15s | %-10.2f | %-10.2f | %-10.2f | %-9.1f%%\n", 
        $name, 
        $data['gen_ms'], 
        $data['gzip_bytes']/1024, 
        $data['parse_ms'],
        $ratioSize
    );
}

echo "\n=== Conclusiones Preliminares ===\n";
$fastestParse = array_key_first(array_column($results, 'parse_ms')); // Simplificado
// Encontrar el mejor en parseo manualmente
$minParse = min(array_column($results, 'parse_ms'));
$bestParseName = '';
foreach($results as $k => $v) { if($v['parse_ms'] == $minParse) $bestParseName = $k; }

echo "- El formato más ligero en red es: " . min(array_column($results, 'gzip_bytes'))/1024 . " KB\n";
echo "- El formato más rápido de parsear es: $bestParseName (" . $minParse . " ms)\n";
echo "- Recomendación: Si el cuello de botella es la red, usar el de menor tamaño Gzip.\n";
echo "  Si el cuello de botella es el render en cliente (miles de filas), usar el de parseo más rápido.\n";
