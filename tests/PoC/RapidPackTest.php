<?php
/**
 * Prueba del formato Rapid-Pack (RPF)
 * Verifica que QueryResponse genere correctamente el formato optimizado para UI.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use RapidBase\Core\QueryResponse;

echo "==================================================\n";
echo "Prueba: Formato Rapid-Pack (RPF)\n";
echo "==================================================\n\n";

// Datos simulados (FETCH_NUM)
$data = [
    [1, 'Alice', 'Post 1', '2026-04-21'],
    [2, 'Bob', 'Post 2', '2026-04-22'],
    [3, 'Charlie', 'Post 3', '2026-04-23'],
];

// Metadata con mapa de proyección
$metadata = [
    'flat_columns' => ['users.id', 'users.name', 'posts.title', 'posts.created_at'],
    'projection_map' => [
        'users' => ['id' => 0, 'name' => 1],
        'posts' => ['title' => 2, 'created_at' => 3]
    ],
    'sort_status' => ['col' => 'posts.created_at', 'dir' => 'DESC'],
    'execution_time' => 0.0045
];

// Estado de paginación
$state = [
    'page' => 2,
    'per_page' => 10
];

// Crear QueryResponse
$response = new QueryResponse(
    data: $data,
    total: 150,
    count: count($data),
    metadata: $metadata,
    state: $state
);

// Probar toRapidPack()
echo "--- Probando toRapidPack() ---\n";
$rpf = $response->toRapidPack();

echo "Estructura generada:\n";
echo json_encode($rpf, JSON_PRETTY_PRINT | JSON_NUMERIC_CHECK) . "\n\n";

// Validaciones
$passed = true;

// 1. Verificar estructura head
if (!isset($rpf['head']['vars']) || !isset($rpf['head']['map'])) {
    echo "❌ ERROR: Falta 'head.vars' o 'head.map'\n";
    $passed = false;
} else {
    echo "✓ head.vars presente: " . count($rpf['head']['vars']) . " columnas\n";
    echo "✓ head.map presente: " . count($rpf['head']['map']) . " tablas\n";
}

// 2. Verificar body (datos numéricos)
if (!isset($rpf['body']) || !is_array($rpf['body'])) {
    echo "❌ ERROR: Falta 'body' o no es array\n";
    $passed = false;
} else {
    echo "✓ body presente: " . count($rpf['body']) . " filas\n";
    // Verificar que sean arrays numéricos
    if (is_numeric(array_keys($rpf['body'][0])[0])) {
        echo "✓ body usa arrays numéricos (FETCH_NUM)\n";
    } else {
        echo "❌ ERROR: body no usa arrays numéricos\n";
        $passed = false;
    }
}

// 3. Verificar meta
if (!isset($rpf['meta'])) {
    echo "❌ ERROR: Falta 'meta'\n";
    $passed = false;
} else {
    echo "✓ meta presente\n";
    echo "  - total: {$rpf['meta']['total']}\n";
    echo "  - page: {$rpf['meta']['page']}\n";
    echo "  - limit: {$rpf['meta']['limit']}\n";
    echo "  - count: {$rpf['meta']['count']}\n";
    echo "  - took: {$rpf['meta']['took']}s\n";
    if (isset($rpf['meta']['sort'])) {
        echo "  - sort: {$rpf['meta']['sort']['col']} ({$rpf['meta']['sort']['dir']})\n";
    }
}

// 4. Probar toJson()
echo "\n--- Probando toJson() ---\n";
$json = $response->toJson();
echo "JSON generado (" . strlen($json) . " bytes):\n";
echo substr($json, 0, 200) . "...\n\n";

// Validar que sea JSON válido
if (json_decode($json, true) !== null) {
    echo "✓ JSON válido\n";
} else {
    echo "❌ ERROR: JSON inválido\n";
    $passed = false;
}

// 5. Comparación de tamaño vs formato asociativo tradicional
echo "\n--- Comparación de tamaño ---\n";
$assoc_data = [];
foreach ($data as $row) {
    $assoc_data[] = [
        'users.id' => $row[0],
        'users.name' => $row[1],
        'posts.title' => $row[2],
        'posts.created_at' => $row[3]
    ];
}
$traditional_json = json_encode(['data' => $assoc_data, 'total' => 150], JSON_NUMERIC_CHECK);
$rpf_json = $response->toJson();

$reduction = round((1 - strlen($rpf_json) / strlen($traditional_json)) * 100, 2);
echo "Formato tradicional: " . strlen($traditional_json) . " bytes\n";
echo "Rapid-Pack Format: " . strlen($rpf_json) . " bytes\n";
echo "Ahorro: {$reduction}% menos bytes\n";

if ($reduction > 0) {
    echo "✓ RPF es más eficiente en tamaño\n";
} else {
    echo "⚠ RPF no mostró mejora en este caso pequeño\n";
}

echo "\n";
if ($passed) {
    echo "=== TODAS LAS PRUEBAS PASARON ===\n";
    echo "El formato Rapid-Pack está listo para producción.\n";
} else {
    echo "=== ALGUNAS PRUEBAS FALLARON ===\n";
    exit(1);
}
