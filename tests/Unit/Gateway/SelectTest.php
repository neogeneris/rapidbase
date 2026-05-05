<?php

/**
 * Gateway SelectTest – Integración con GroupBy y verificación de metadatos.
 *
 * Usa Q, Conn y Gateway. Comprueba que la respuesta incluya columns.
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use RapidBase\Core\Conn;
use RapidBase\Core\Gateway;
use RapidBase\Core\SQL\ConditionMatrix;   // ← namespace correcto

// ── Preparar entorno ────────────────────────────────
$tempDb = tempnam(sys_get_temp_dir(), "rapidbase_test_") . ".sqlite";
Conn::setup("sqlite:$tempDb", "", "", "main");
$pdo = Conn::get("main");
$pdo->exec("CREATE TABLE leads (id INTEGER PRIMARY KEY, source TEXT, status TEXT)");
$pdo->exec("INSERT INTO leads (source, status) VALUES ('Facebook', 'pending'), ('Facebook', 'approved'), ('Google', 'pending')");

// Configurar el driver para ConditionMatrix (necesario para Q)
ConditionMatrix::setDriver('sqlite');

// ── Funciones de aserción ──────────────────────────
function assert_select($name, $assertion, $details = "") {
    if ($assertion) {
        echo "  [OK] $name\n";
    } else {
        echo "  [FAIL] $name\n";
        if ($details) echo "    Detalle: $details\n";
        $status = Gateway::status();
        echo "    SQL generado: " . ($status["sql"] ?? "N/A") . "\n";
        exit(1);
    }
}

echo "=== Gateway Select con GroupBy ===\n";

// ── Consulta con GROUP BY ──────────────────────────
$result = Gateway::select(
    "source, COUNT(*) as total",
    "leads",
    [],
    ["source"],    // groupBy
    [],             // having
    [],             // sort
    1,              // page (1 = sin límite? En realidad page=1 con perPage=10 por defecto; para todas las filas usar page=0)
    false,          // withTotal
    \PDO::FETCH_ASSOC
);


//print_r($result);

$data = $result["data"] ?? [];
assert_select("Conteo agrupado por source", count($data) === 2, "Esperados 2 grupos");

$facebook = array_filter($data, fn($r) => $r["source"] === "Facebook");
$facebook = reset($facebook);
assert_select("Validación de datos (Facebook)", $facebook && $facebook["total"] == 2);

// ── Consulta con WHERE y GROUP BY ──────────────────
$resultFiltered = Gateway::select(
    "source, COUNT(*) as total",
    "leads",
    ["status" => "pending"],
    ["source"],
    [], [], 1, false, \PDO::FETCH_ASSOC
);

$dataFiltered = $resultFiltered["data"] ?? [];
assert_select("Group By con filtro WHERE", count($dataFiltered) === 2);
$totalPending = array_sum(array_column($dataFiltered, "total"));
assert_select("Suma total de registros filtrados", $totalPending == 2);

// ── Verificación de metadatos (columnas) ──────────
$metadata = $resultFiltered['metadata'] ?? [];
assert_select("Metadata contiene columnas", !empty($metadata['cols']), "cols está vacío");
assert_select("Columnas correctas", $metadata['cols'] === ['source', 'total'], "Se obtuvo: " . json_encode($metadata['cols']));

// ── Verificación de SQL generado ──────────────────
$status = Gateway::status();
assert_select("Sintaxis SQL (Cláusula GROUP BY)", str_contains(strtoupper($status["sql"]), "GROUP BY"));
assert_select("Parámetros en el estado", isset($status["params"][0]) && $status["params"][0] === "pending");

echo "\n✅ Gateway::select maneja agrupamientos, filtros y metadatos correctamente.\n";

// ── Limpiar ───────────────────────────────────────
if (isset($tempDb) && file_exists($tempDb)) {
    @unlink($tempDb);
    @unlink($tempDb . "-wal");
    @unlink($tempDb . "-shm");
}