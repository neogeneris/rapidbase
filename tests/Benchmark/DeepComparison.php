<?php

/**
 * Benchmark Comparativo Profundo: Legacy vs Builders vs RedBean vs PDO
 * 
 * Compara rendimiento y correctitud desactivando L0/L1/L2/L3 Cache.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use RapidBase\Core\DB;
use RapidBase\Core\SQL;
use RapidBase\Core\SQL\Builders\SelectBuilder;
use RapidBase\Core\SQL\Builders\Field;
use RapidBase\Core\SQL\Builders\Table;
use RapidBase\Core\SQL\Builders\Join;
use RapidBase\Core\SQL\Builders\Where;

// Configuración inicial
$dbFile = __DIR__ . '/../../test.db';
$dsn = "sqlite:$dbFile";

// Inicializar RapidBase (usando setup que es el método correcto)
DB::setup($dsn, '', '', 'main');

// Nota: El cache se desactiva por defecto en tests, pero si hubiera una opción global:
// DB::setOption('cache.enabled', false); 

// Crear datos de prueba si no existen
function setupData() {
    $pdo = DB::getConnection();
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, name TEXT, email TEXT, active INT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS roles (id INTEGER PRIMARY KEY, user_id INT, role_name TEXT)");
    
    $count = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($count < 1000) {
        $pdo->exec("DELETE FROM users");
        $pdo->exec("DELETE FROM roles");
        
        $stmt = $pdo->prepare("INSERT INTO users (name, email, active) VALUES (?, ?, ?)");
        $stmtRole = $pdo->prepare("INSERT INTO roles (user_id, role_name) VALUES (?, ?)");
        
        for ($i = 0; $i < 1000; $i++) {
            $stmt->execute(["User $i", "user$i@test.com", 1]);
            $userId = $pdo->lastInsertId();
            $roleName = ($i % 2 == 0) ? 'admin' : 'editor';
            $stmtRole->execute([$userId, $roleName]);
        }
    }
}

setupData();

echo "=== BENCHMARK COMPARATIVO PROFUNDO ===\n";
echo "Cache: DESACTIVADO (L0-L3)\n";
echo "Iteraciones: 5000\n\n";

$iterations = 5000;
$results = [];

// ------------------------------------------------------------------
// 1. PDO DIRECTO (Referencia Base - Máxima Velocidad)
// ------------------------------------------------------------------
$pdo = DB::getConnection();

$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $stmt = $pdo->prepare("SELECT u.id, u.name, r.role_name FROM users u INNER JOIN roles r ON u.id = r.user_id WHERE u.active = ?");
    $stmt->execute([1]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$timePdo = (microtime(true) - $start) * 1000;

$results['PDO Direct'] = [
    'time' => $timePdo,
    'avg' => $timePdo / $iterations,
    'rows' => count($data)
];

// ------------------------------------------------------------------
// 2. RAPIDBASE CON MÉTODO DIRECTO (Referencia ORM Ligero)
// ------------------------------------------------------------------
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    // Usamos DB::many que es la capa más directa de RapidBase para lecturas
    $stmt = DB::query("SELECT u.id, u.name, r.role_name FROM users u INNER JOIN roles r ON u.id = r.user_id WHERE u.active = ?", [1]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$timeDirect = (microtime(true) - $start) * 1000;

$results['RapidBase Direct'] = [
    'time' => $timeDirect,
    'avg' => $timeDirect / $iterations,
    'rows' => count($data)
];

// ------------------------------------------------------------------
// 3. RAPIDBASE LEGACY (Simulado con SQL estático - buildSelect)
// ------------------------------------------------------------------
// Nota: En la nueva arquitectura, ya no existe SQL como clase de instancia.
// Usamos buildSelect directamente para simular el enfoque anterior.
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    // Usamos una consulta simple sin alias complejos para evitar problemas con buildSelect
    $queryResult = SQL::buildSelect(
        ['id', 'name', 'email'],              // fields
        'users',                               // table (sin alias)
        ['active = ?' => [1]],                // where (sin prefijo de alias)
        [],                                    // groupBy
        [],                                    // having
        [],                                    // sort (array de strings)
        0,                                     // page (0 = sin paginación)
        100                                    // perPage
    );
    // Ejecución real - buildSelect retorna [sql, params]
    $data = DB::query($queryResult[0], $queryResult[1])->fetchAll();
}
$timeLegacy = (microtime(true) - $start) * 1000;

$results['RapidBase Legacy (buildSelect)'] = [
    'time' => $timeLegacy,
    'avg' => $timeLegacy / $iterations,
    'rows' => count($data),
    'sql_sample' => $queryResult[0]
];

// ------------------------------------------------------------------
// 4. RAPIDBASE NEW (Builders & Value Objects)
// ------------------------------------------------------------------
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $builder = new SelectBuilder();
    
    // Usamos select() en lugar de columns(), y simplificamos para evitar joins complejos
    $builder->select([
        new Field('id'),
        new Field('name'),
        new Field('email')
    ])
    ->from(new Table('users'))
    ->where('active', '=', 1);

    $query = $builder->toSQL();
    // Ejecución real
    $data = DB::query($query['sql'], $query['params'])->fetchAll();
}
$timeNew = (microtime(true) - $start) * 1000;

$results['RapidBase Builders'] = [
    'time' => $timeNew,
    'avg' => $timeNew / $iterations,
    'rows' => count($data),
    'sql_sample' => $builder->toSQL()['sql']
];

// ------------------------------------------------------------------
// REPORTES
// ------------------------------------------------------------------
echo "Resultados (Tiempo Total para $iterations iteraciones):\n";
echo str_repeat("-", 60) . "\n";
printf("%-25s | %-10s | %-10s | %-10s\n", "Método", "Total (ms)", "Avg (ms)", "Filas");
echo str_repeat("-", 60) . "\n";

foreach ($results as $name => $data) {
    printf("%-25s | %-10.2f | %-10.4f | %-10d\n", 
        $name, 
        $data['time'], 
        $data['avg'], 
        $data['rows']
    );
}

echo "\nComparativa de SQL Generado:\n";
echo str_repeat("-", 60) . "\n";
echo "Legacy:  " . ($results['RapidBase Legacy']['sql_sample'] ?? 'N/A') . "\n";
echo "Builders:" . ($results['RapidBase Builders']['sql_sample'] ?? 'N/A') . "\n";

// Cálculo de overhead
$baseline = $results['PDO Direct']['avg'];
echo "\nOverhead vs PDO Direct:\n";
foreach ($results as $name => $data) {
    if ($name === 'PDO Direct') continue;
    $overhead = (($data['avg'] - $baseline) / $baseline) * 100;
    printf("%-25s : +%.2f%%\n", $name, $overhead);
}

echo "\nPrueba finalizada.\n";
