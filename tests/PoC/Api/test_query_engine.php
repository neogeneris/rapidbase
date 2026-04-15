<?php
/**
 * Venon PoC - Query Engine Directo (Metadata + Data)
 * Parámetros:
 *   m = clave de metadata (tables, relationships, checksum, generated_at)
 *   t = tabla o lista de tablas separadas por coma (ej. users,drivers)
 *   s = ordenamiento (ej. -id,nombre)
 *   l = límite (default 10)
 *   p = página (default 1)
 *   f = campos (default *)
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/configure.php';

$rootDir = dirname(__DIR__, 3);
require_once $rootDir . '/src/RapidBase/Core/SQL.php';
require_once $rootDir . '/src/RapidBase/Core/Gateway.php';
require_once $rootDir . '/src/RapidBase/Core/Executor.php';
require_once $rootDir . '/src/RapidBase/Core/Conn.php';

use RapidBase\Core\Gateway;
use RapidBase\Core\SQL;
use RapidBase\Core\Conn;

$schemaPath = $rootDir . '/tests/tmp/schema/schema_map.php';
if (!file_exists($schemaPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'Metadata no encontrada', 'path' => $schemaPath]);
    exit;
}
$schemaMap = include($schemaPath);
SQL::setRelationsMap($schemaMap);

// Parámetros
$metadataKey = $_GET['m'] ?? null;
$tableParam  = $_GET['t'] ?? null;

// CASO 1: METADATA
if ($metadataKey !== null) {
    $validMetadata = ['tables', 'relationships', 'checksum', 'generated_at'];
    if (!in_array($metadataKey, $validMetadata)) {
        http_response_code(400);
        echo json_encode(['error' => 'Clave de metadata no válida', 'allowed' => $validMetadata]);
        exit;
    }
    http_response_code(200);
    echo json_encode([
        'type' => 'metadata',
        'key'  => $metadataKey,
        'data' => $schemaMap[$metadataKey] ?? null
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// CASO 2: DATOS DE TABLA(S)
if ($tableParam === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Debe proporcionar ?m=clave o ?t=tabla[,otra]']);
    exit;
}

// Convertir lista de tablas separada por comas en array
$tables = array_map('trim', explode(',', $tableParam));
$validTables = array_keys($schemaMap['tables'] ?? []);

foreach ($tables as $tbl) {
    if (!in_array($tbl, $validTables)) {
        http_response_code(404);
        echo json_encode([
            'error' => "Tabla '$tbl' no existe en el esquema.",
            'available_tables' => $validTables
        ], JSON_PRETTY_PRINT);
        exit;
    }
}

// Parámetros de consulta
$sortRaw = $_GET['s'] ?? '';
$limit   = (int)($_GET['l'] ?? 10);
$page    = (int)($_GET['p'] ?? 1);
$fields  = $_GET['f'] ?? '*';
$where   = [];

$sort = [];
if ($sortRaw) {
    foreach (explode(',', $sortRaw) as $field) {
        $field = trim($field);
        if ($field === '') continue;
        if (str_starts_with($field, '-')) {
            $sort[ltrim($field, '-')] = 'DESC';
        } else {
            $sort[$field] = 'ASC';
        }
    }
}

try {
    // Si hay más de una tabla, pasamos el array; si es una sola, pasamos string
    $tableArg = count($tables) === 1 ? $tables[0] : $tables;
    $result = Gateway::select($fields, $tableArg, $where, $sort, $page, $limit, true);

    http_response_code(200);
    echo json_encode([
        'type' => 'data',
        'tables' => $tables,
        'query' => [
            'sort'  => $sort,
            'page'  => $page,
            'limit' => $limit,
        ],
        'telemetry' => [
            'db_ms'   => Gateway::status()['duration'] ?? 'N/A',
            'memory'  => round(memory_get_usage() / 1024 / 1024, 2) . ' MB'
        ],
        'data'       => $result['data'],
        'pagination' => [
            'total' => $result['total'],
            'page'  => $page,
            'limit' => $limit,
            'pages' => ceil($result['total'] / $limit)
        ]
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'file'  => $e->getFile(),
        'line'  => $e->getLine()
    ], JSON_PRETTY_PRINT);
}