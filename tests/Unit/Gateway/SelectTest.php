<?php

namespace Tests\Unit\Gateway;

// Aseguramos que las dependencias necesarias estén presentes
require_once __DIR__ . '/../../../src/RapidBase/Core/SQL.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/Conn.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/Executor.php';
require_once __DIR__ . '/../../../src/RapidBase/Core/Gateway.php';

use RapidBase\Core\Conn;
use RapidBase\Core\Gateway;
use RapidBase\Core\SQL;

echo "--- Ejecutando: Gateway SelectTest (Integración con GroupBy) ---\n";

/**
 * Helper para aserciones simple en consola
 */
function assert_select($name, $assertion, $details = "") {
    if ($assertion) {
        echo "\033[32m[OK]\033[0m $name\n";
    } else {
        echo "\033[31m[FAIL]\033[0m $name\n";
        if ($details) echo "  Detalle: $details\n";
        $status = Gateway::status();
        echo "  SQL generado: " . ($status['sql'] ?? 'N/A') . "\n";
        exit(1);
    }
}

// --- SETUP ---
// Usamos un archivo temporal único para evitar locks entre tests
$tempDb = tempnam(sys_get_temp_dir(), 'rapidbase_test_') . '.sqlite';
Conn::setup("sqlite:$tempDb", "", "", "main");
$pdo = Conn::get();
// Configuramos timeout para busy lock y modo WAL para mejor concurrencia
$pdo->exec("PRAGMA busy_timeout = 5000");
$pdo->exec("PRAGMA journal_mode = WAL");
$pdo->exec("DROP TABLE IF EXISTS leads");
$pdo->exec("CREATE TABLE leads (id INTEGER PRIMARY KEY, source TEXT, status TEXT)");

// Insertamos datos de prueba
$pdo->exec("INSERT INTO leads (source, status) VALUES ('Facebook', 'pending'), ('Facebook', 'approved'), ('Google', 'pending')");

// --- TEST 1: Group By Simple ---
// Nota: Eliminamos DB::row() para evitar errores de clase no cargada y usamos el string directo
SQL::reset();

$result = Gateway::select(
    "source, COUNT(*) as total", // Fields
    "leads",                      // Table
    [],                           // Where
    ["source"]                    // GroupBy (4to argumento según tu Gateway.php)
);

$data = $result['data'] ?? [];
assert_select("Conteo agrupado por source", count($data) === 2);

// Buscamos Facebook en el resultado (el orden puede variar)
$facebook = array_filter($data, fn($r) => $r['source'] === 'Facebook');
$facebook = reset($facebook);

assert_select("Validación de datos (Facebook)", $facebook && $facebook['total'] == 2);

// --- TEST 2: Group By + Where ---
SQL::reset();
$resultFiltered = Gateway::select(
    "source, COUNT(*) as total",
    "leads",
    ["status" => "pending"],      // Where
    ["source"]                    // GroupBy
);

$dataFiltered = $resultFiltered['data'] ?? [];
// Debería haber 2 registros: 1 de Facebook y 1 de Google que son 'pending'
assert_select("Group By con filtro WHERE", count($dataFiltered) === 2);

$totalPending = array_sum(array_column($dataFiltered, 'total'));
assert_select("Suma total de registros filtrados", $totalPending == 2);

// --- TEST 3: Verificación de Estado e Integridad ---
$status = Gateway::status();
assert_select(
    "Sintaxis SQL (Cláusula GROUP BY)", 
    str_contains(strtoupper($status['sql']), "GROUP BY"), 
    "El SQL no contiene la cláusula GROUP BY"
);

assert_select(
    "Persistencia de parámetros en el estado",
    isset($status['params']['p0']) && $status['params']['p0'] === 'pending',
    "Los parámetros del WHERE no se registraron correctamente en el estado"
);

echo "\n\033[32m[SUCCESS]\033[0m Gateway::select maneja agrupamientos y filtros correctamente.\n";

// Cleanup: eliminar archivo temporal
if (isset($tempDb) && file_exists($tempDb)) {
    @unlink($tempDb);
    // También eliminar archivos WAL y SHM si existen
    @unlink($tempDb . '-wal');
    @unlink($tempDb . '-shm');
}