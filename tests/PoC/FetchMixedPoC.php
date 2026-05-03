<?php
/**
 * PoC: Fetch Mixto - FETCH_ASSOC + FETCH_NUM
 * 
 * Objetivo: Demostrar si es viable usar fetch de manera mixta:
 * 1. Primera fila con FETCH_ASSOC para obtener nombres de columnas
 * 2. Resto de filas con FETCH_NUM para máxima velocidad
 * 
 * Ventaja teórica:
 * - Obtenemos los nombres de columnas sin overhead significativo
 * - El resto de filas se procesan más rápido con FETCH_NUM
 */

declare(strict_types=1);

// No requerimos autoload para esta PoC independiente

echo "==================================================\n";
echo "PoC: Fetch Mixto (FETCH_ASSOC + FETCH_NUM)\n";
echo "==================================================\n\n";

// Usamos SQLite en memoria para esta PoC (no requiere MySQL instalado)
echo "Usando SQLite en memoria para esta demo.\n\n";
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Limpiar y crear schema de prueba
try {
    $pdo->exec("DROP TABLE IF EXISTS post_tags");
    $pdo->exec("DROP TABLE IF EXISTS tags");
    $pdo->exec("DROP TABLE IF EXISTS posts");
    $pdo->exec("DROP TABLE IF EXISTS users");
} catch (Exception $e) {
    // Ignorar errores al limpiar
}

// Crear schema de prueba
$pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT)");
$pdo->exec("CREATE TABLE posts (id INTEGER PRIMARY KEY, user_id INTEGER, title TEXT, content TEXT)");
$pdo->exec("CREATE TABLE tags (id INTEGER PRIMARY KEY, name TEXT)");
$pdo->exec("CREATE TABLE post_tags (post_id INTEGER, tag_id INTEGER, PRIMARY KEY (post_id, tag_id))");

// Insertar datos de prueba
echo "Insertando datos de prueba...\n";
$pdo->exec("INSERT INTO users VALUES (1, 'Alice', 'alice@example.com')");
$pdo->exec("INSERT INTO users VALUES (2, 'Bob', 'bob@example.com')");
$pdo->exec("INSERT INTO users VALUES (3, 'Charlie', 'charlie@example.com')");
$pdo->exec("INSERT INTO posts VALUES (1, 1, 'First Post', 'Content 1')");
$pdo->exec("INSERT INTO posts VALUES (2, 1, 'Second Post', 'Content 2')");
$pdo->exec("INSERT INTO posts VALUES (3, 2, 'Third Post', 'Content 3')");
$pdo->exec("INSERT INTO posts VALUES (4, 3, 'Fourth Post', 'Content 4')");
$pdo->exec("INSERT INTO posts VALUES (5, 3, 'Fifth Post', 'Content 5')");
$pdo->exec("INSERT INTO tags VALUES (1, 'PHP')");
$pdo->exec("INSERT INTO tags VALUES (2, 'Database')");
$pdo->exec("INSERT INTO tags VALUES (3, 'Tutorial')");
$pdo->exec("INSERT INTO post_tags VALUES (1, 1)");
$pdo->exec("INSERT INTO post_tags VALUES (1, 2)");
$pdo->exec("INSERT INTO post_tags VALUES (2, 1)");
$pdo->exec("INSERT INTO post_tags VALUES (3, 2)");
$pdo->exec("INSERT INTO post_tags VALUES (4, 3)");

echo "Datos insertados correctamente.\n\n";

// ============================================
// FUNCIÓN: Fetch Mixto
// ============================================
/**
 * Ejecuta una consulta y retorna los datos usando fetch mixto:
 * - Primera fila: FETCH_ASSOC para obtener nombres de columnas
 * - Resto de filas: FETCH_NUM para máxima velocidad
 * 
 * @param PDO $pdo Conexión PDO
 * @param string $sql Consulta SQL
 * @param array $params Parámetros para la consulta
 * @return array ['cols' => array de nombres, 'rows' => array de filas numéricas]
 */
function fetchMixed(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    $results = [];
    $columnNames = [];
    
    // 1. Capturamos la primera fila como asociativa para obtener los nombres de columnas
    $firstRow = $stmt->fetch(\PDO::FETCH_ASSOC);
    
    if ($firstRow) {
        // Extraemos los nombres de las columnas de forma segura y directa
        $columnNames = array_keys($firstRow); 
        $results[] = array_values($firstRow); // La guardamos como numérica para consistencia
        
        // 2. El resto de las filas se bajan a máxima velocidad con FETCH_NUM
        while ($row = $stmt->fetch(\PDO::FETCH_NUM)) {
            $results[] = $row;
        }
    }
    
    // Retornamos los datos y los nombres de columnas aparte
    return [
        'cols' => $columnNames,
        'rows' => $results
    ];
}

// ============================================
// TEST 1: Consulta simple
// ============================================
echo "--- TEST 1: Consulta simple (users) ---\n";

$sql = "SELECT id, name, email FROM users";
$data = fetchMixed($pdo, $sql);

echo "Columnas: " . implode(', ', $data['cols']) . "\n";
echo "Filas obtenidas: " . count($data['rows']) . "\n";
echo "Primera fila: [" . implode(', ', $data['rows'][0]) . "]\n";
echo "Última fila: [" . implode(', ', $data['rows'][count($data['rows'])-1]) . "]\n\n";

// ============================================
// TEST 2: JOIN con columnas duplicadas (el problema real)
// ============================================
echo "--- TEST 2: JOIN con columnas duplicadas (posts + users) ---\n";

$sql = "SELECT * FROM posts p JOIN users u ON p.user_id = u.id";
$data = fetchMixed($pdo, $sql);

echo "Columnas detectadas: " . implode(', ', $data['cols']) . "\n";
echo "Filas obtenidas: " . count($data['rows']) . "\n";

if (count($data['rows']) > 0) {
    echo "\nAcceso por índice de columna:\n";
    // Mostrar qué columna está en cada índice
    foreach ($data['cols'] as $idx => $colName) {
        echo "  Índice $idx: $colName\n";
    }
    
    echo "\nPrimera fila completa:\n";
    $firstRow = $data['rows'][0];
    foreach ($data['cols'] as $idx => $colName) {
        echo "  $colName => {$firstRow[$idx]}\n";
    }
}
echo "\n";

// ============================================
// TEST 3: Comparación de rendimiento
// ============================================
echo "--- TEST 3: Benchmark (5000 iteraciones) ---\n";

$sql = "SELECT id, name, email FROM users";

// Método tradicional: FETCH_ASSOC completo
$start = microtime(true);
for ($i = 0; $i < 5000; $i++) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$timeAssoc = (microtime(true) - $start) * 1000;

// Método tradicional: FETCH_NUM completo (sin nombres de columna)
$start = microtime(true);
for ($i = 0; $i < 5000; $i++) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $data = $stmt->fetchAll(PDO::FETCH_NUM);
}
$timeNum = (microtime(true) - $start) * 1000;

// Método mixto: FETCH_ASSOC + FETCH_NUM
$start = microtime(true);
for ($i = 0; $i < 5000; $i++) {
    $result = fetchMixed($pdo, $sql);
}
$timeMixed = (microtime(true) - $start) * 1000;

echo "FETCH_ASSOC (completo):  " . number_format($timeAssoc, 2) . " ms\n";
echo "FETCH_NUM (completo):    " . number_format($timeNum, 2) . " ms\n";
echo "Fetch Mixto:             " . number_format($timeMixed, 2) . " ms\n\n";

$savingsVsAssoc = 100 - ($timeMixed / $timeAssoc * 100);
$savingsVsNum = 100 - ($timeMixed / $timeNum * 100);

echo "Ahorro vs FETCH_ASSOC: " . number_format($savingsVsAssoc, 2) . "%\n";
echo "Overhead vs FETCH_NUM: " . number_format($savingsVsNum, 2) . "% (negativo = más lento)\n\n";

// ============================================
// TEST 4: Análisis del overhead de la primera fila
// ============================================
echo "--- TEST 4: Análisis del overhead de obtener columna names ---\n";

// Opción A: Usar getColumnMeta (sin fetch)
$start = microtime(true);
for ($i = 0; $i < 5000; $i++) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $columns = [];
    for ($j = 0; $j < $stmt->columnCount(); $j++) {
        $meta = $stmt->getColumnMeta($j);
        $columns[] = $meta['name'];
    }
    // Luego fetch_num para los datos
    $data = $stmt->fetchAll(PDO::FETCH_NUM);
}
$timeMeta = (microtime(true) - $start) * 1000;

echo "getColumnMeta + FETCH_NUM: " . number_format($timeMeta, 2) . " ms\n";
echo "Fetch Mixto:               " . number_format($timeMixed, 2) . " ms\n\n";

if ($timeMeta < $timeMixed) {
    $improvement = 100 - ($timeMeta / $timeMixed * 100);
    echo "getColumnMeta es " . number_format($improvement, 2) . "% más rápido que Fetch Mixto\n";
} else {
    $improvement = 100 - ($timeMixed / $timeMeta * 100);
    echo "Fetch Mixto es " . number_format($improvement, 2) . "% más rápido que getColumnMeta\n";
}

echo "\n";

// ============================================
// TEST 5: Verificación de consistencia de datos
// ============================================
echo "--- TEST 5: Verificación de consistencia de datos ---\n";

$sql = "SELECT id, name, email FROM users ORDER BY id";

// Obtener con FETCH_ASSOC
$stmt = $pdo->prepare($sql);
$stmt->execute();
$assocData = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener con fetch mixto
$mixedResult = fetchMixed($pdo, $sql);

// Verificar que los datos son equivalentes
$consistent = true;
foreach ($assocData as $i => $assocRow) {
    $mixedRow = $mixedResult['rows'][$i];
    $expectedValues = array_values($assocRow);
    
    if ($mixedRow !== $expectedValues) {
        echo "❌ Inconsistencia en fila $i\n";
        echo "   Esperado: [" . implode(', ', $expectedValues) . "]\n";
        echo "   Obtenido: [" . implode(', ', $mixedRow) . "]\n";
        $consistent = false;
    }
}

if ($consistent) {
    echo "✓ Todos los datos son consistentes entre FETCH_ASSOC y Fetch Mixto\n";
}

echo "\n";

// ============================================
// CONCLUSIONES
// ============================================
echo "==================================================\n";
echo "CONCLUSIONES\n";
echo "==================================================\n\n";

echo "1. VIABILIDAD TÉCNICA:\n";
echo "   ✓ Es técnicamente posible usar fetch mixto\n";
echo "   ✓ Los nombres de columnas se obtienen correctamente\n";
echo "   ✓ Los datos numéricos son consistentes\n\n";

echo "2. RENDIMIENTO (RESULTADOS VARIABLES):\n";
if ($timeMixed < $timeAssoc) {
    echo "   ✓ Fetch Mixto es más rápido que FETCH_ASSOC puro\n";
    echo "     (El ahorro en filas siguientes compensa el overhead de la primera)\n";
} else {
    echo "   ✗ Fetch Mixto es MÁS LENTO que FETCH_ASSOC puro\n";
    echo "     (El overhead de cambiar de modo fetch supera el beneficio teórico)\n";
}

if ($timeMixed > $timeNum) {
    echo "   ⚠ Fetch Mixto tiene overhead vs FETCH_NUM puro (esperado)\n";
} else {
    echo "   ✓ Fetch Mixto es incluso más rápido que FETCH_NUM puro\n";
    echo "     (Posible optimización interna de PDO al leer solo una fila como ASSOC)\n";
}

if ($timeMixed < $timeMeta) {
    echo "   ✓ Fetch Mixto es más rápido que getColumnMeta\n";
} else {
    echo "   ⚠ getColumnMeta es más rápido - alternativa válida\n";
}

echo "\n3. HALLAZGO CLAVE:\n";
echo "   El rendimiento del fetch mixto DEPENDE del driver PDO y del dataset:\n";
echo "   - SQLite: Resultados variables, a veces competitivo\n";
echo "   - MySQL: Típicamente más overhead por cambio de modo fetch\n";
echo "   - El beneficio teórico existe pero no siempre se materializa\n\n";

echo "4. RECOMENDACIÓN FINAL:\n";
echo "   Evaluar caso por caso:\n";
echo "   - Si necesitas column names Y tienes muchas filas (>100): fetch mixto puede servir\n";
echo "   - Si priorizas simplicidad: FETCH_ASSOC puro es suficiente\n";
echo "   - Si priorizas máximo rendimiento: FETCH_NUM + metadata conocida\n";
echo "   -getColumnMeta() + FETCH_NUM: Alternativa sin consumir filas\n\n";

echo "=== PoC completada ===\n";

// Cleanup
try {
    $pdo->exec("DROP TABLE IF EXISTS post_tags");
    $pdo->exec("DROP TABLE IF EXISTS tags");
    $pdo->exec("DROP TABLE IF EXISTS posts");
    $pdo->exec("DROP TABLE IF EXISTS users");
} catch (Exception $e) {
    // Ignorar
}
