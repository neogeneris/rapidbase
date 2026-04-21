<?php
/**
 * PoC: Mapa de Proyección Dinámico con FETCH_NUM
 * 
 * Objetivo: Demostrar que podemos usar FETCH_NUM manteniendo la precisión
 * incluso con SELECT * en JOINs complejos, usando un mapa de proyección.
 */

// Configuración directa con PDO (sin depender de DB)
$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

echo "==================================================\n";
echo "PoC: Mapa de Proyección Dinámico (FETCH_NUM)\n";
echo "==================================================\n\n";

// Crear tablas de prueba
echo "Creando esquema de prueba...\n";
$pdo->exec("
    CREATE TABLE users (
        id INTEGER PRIMARY KEY,
        name TEXT,
        email TEXT
    );
    
    CREATE TABLE posts (
        id INTEGER PRIMARY KEY,
        user_id INTEGER,
        title TEXT,
        content TEXT
    );
    
    CREATE TABLE categories (
        id INTEGER PRIMARY KEY,
        parent_id INTEGER,
        name TEXT
    );
");

// Insertar datos de prueba
echo "Insertando datos de prueba...\n";
$pdo->exec("
    INSERT INTO users (name, email) VALUES 
        ('Alice', 'alice@example.com'),
        ('Bob', 'bob@example.com');
    
    INSERT INTO posts (user_id, title, content) VALUES 
        (1, 'First Post', 'Content 1'),
        (2, 'Second Post', 'Content 2'),
        (1, 'Third Post', 'Content 3');
    
    INSERT INTO categories (parent_id, name) VALUES 
        (NULL, 'Root'),
        (1, 'Child 1'),
        (1, 'Child 2');
");

echo "Esquema listo.\n\n";

// ============================================================================
// PRUEBA 1: SELECT * simple (sin JOIN)
// ============================================================================
echo "--- PRUEBA 1: SELECT * simple ---\n";

$sql = "SELECT * FROM users";
$stmt = $pdo->prepare($sql);
$stmt->execute();

// Obtener metadata de columnas
$columnCount = $stmt->columnCount();
$columns = [];
for ($i = 0; $i < $columnCount; $i++) {
    $meta = $stmt->getColumnMeta($i);
    $table = $meta['table'] ?? 'unknown';
    $name = $meta['name'];
    $columns[] = ['table' => $table, 'name' => $name, 'index' => $i];
}

echo "Columnas detectadas:\n";
foreach ($columns as $col) {
    echo "  Índice {$col['index']}: {$col['table']}.{$col['name']}\n";
}

// FETCH_NUM
$stmt->execute();
$rowsNum = $stmt->fetchAll(PDO::FETCH_NUM);
echo "\nFETCH_NUM resultado:\n";
print_r($rowsNum);

// Reconstruir array asociativo usando el mapa
$assocFromNum = [];
foreach ($rowsNum as $row) {
    $assoc = [];
    foreach ($columns as $col) {
        $key = "{$col['table']}.{$col['name']}";
        $assoc[$key] = $row[$col['index']];
    }
    $assocFromNum[] = $assoc;
}

echo "\nReconstruido a asociativo:\n";
print_r($assocFromNum);

// Comparar con FETCH_ASSOC directo
$stmt->execute();
$rowsAssoc = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "\nFETCH_ASSOC directo (para comparar):\n";
print_r($rowsAssoc);

echo "\n";

// ============================================================================
// PRUEBA 2: SELECT * con JOIN (columnas duplicadas)
// ============================================================================
echo "--- PRUEBA 2: SELECT * con JOIN (columnas duplicadas) ---\n";

$sql = "SELECT * FROM posts JOIN users ON posts.user_id = users.id";
$stmt = $pdo->prepare($sql);
$stmt->execute();

// Obtener metadata de columnas
$columnCount = $stmt->columnCount();
$columns = [];
for ($i = 0; $i < $columnCount; $i++) {
    $meta = $stmt->getColumnMeta($i);
    $table = $meta['table'] ?? 'unknown';
    $name = $meta['name'];
    $columns[] = ['table' => $table, 'name' => $name, 'index' => $i];
}

echo "Columnas detectadas (notar IDs duplicados):\n";
foreach ($columns as $col) {
    echo "  Índice {$col['index']}: {$col['table']}.{$col['name']}\n";
}

// FETCH_NUM
$stmt->execute();
$rowsNum = $stmt->fetchAll(PDO::FETCH_NUM);
echo "\nFETCH_NUM resultado (array numérico):\n";
print_r($rowsNum);

// Construir mapa de proyección
$projectionMap = [];
foreach ($columns as $col) {
    if (!isset($projectionMap[$col['table']])) {
        $projectionMap[$col['table']] = [];
    }
    // Usar prefijo de tabla para evitar colisiones
    $key = "{$col['table']}.{$col['name']}";
    $projectionMap[$col['table']][$col['name']] = $col['index'];
}

echo "\nMapa de proyección generado:\n";
print_r($projectionMap);

// Reconstruir array asociativo usando el mapa
$assocFromNum = [];
foreach ($rowsNum as $row) {
    $assoc = [];
    foreach ($columns as $col) {
        $key = "{$col['table']}.{$col['name']}";
        $assoc[$key] = $row[$col['index']];
    }
    $assocFromNum[] = $assoc;
}

echo "\nReconstruido a asociativo (con todas las columnas, incluyendo duplicadas):\n";
print_r($assocFromNum);

// Comparar con FETCH_ASSOC directo (pierde columnas duplicadas!)
$stmt->execute();
$rowsAssoc = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "\nFETCH_ASSOC directo (¡OJO! Pierde columns duplicadas - solo queda el último 'id'):\n";
print_r($rowsAssoc);

echo "\n=== CONCLUSIÓN ===\n";
echo "FETCH_NUM + Mapa de Proyección:\n";
echo "  ✓ Mantiene TODAS las columnas, incluso duplicadas\n";
echo "  ✓ Permite acceso determinista por tabla.columna\n";
echo "  ✓ Más eficiente en memoria (sin strings repetidos en cada fila)\n";
echo "\nFETCH_ASSOC tradicional:\n";
echo "  ✗ Pierde columnas con nombres duplicados en JOINs\n";
echo "  ✗ Mayor uso de memoria (strings repetidos)\n";
echo "\n";
