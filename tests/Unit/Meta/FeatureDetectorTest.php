<?php

/**
 * Suite de Pruebas para Meta\Discovery\FeatureDetector
 * Verifica la autodetección de capacidades del motor de BD.
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use RapidBase\Core\Conn;
use RapidBase\Core\SchemaMap;
use RapidBase\Meta\Discovery\FeatureDetector;
use RapidBase\Meta\SchemaMapper;

$failed = 0;
function assert_ft($msg, $cond) {
    global $failed;
    if ($cond) {
        echo "  [OK] $msg\n";
    } else {
        echo "  [FAIL] $msg\n";
        $failed++;
    }
}

echo "==================================================\n";
echo "META\DISCOVERY\FEATUREDETECTOR: PRUEBAS\n";
echo "==================================================\n";

// ─── SQLite en memoria ───────────────────────────────
echo "\n--- Bloque 1: Detección en SQLite (memory) ---\n";
Conn::setup('sqlite::memory:', '', '', 'test_features');
$pdo = Conn::get('test_features');

$detector = new FeatureDetector($pdo);
$features = $detector->detect();

echo "  Features detectadas:\n";
foreach ($features as $key => $val) {
    $display = is_bool($val) ? ($val ? 'true' : 'false') : $val;
    echo "    $key => $display\n";
}

assert_ft("driver es 'sqlite'", $features['driver'] === 'sqlite');
assert_ft("driver_version no está vacío", !empty($features['driver_version']));
assert_ft("window_functions es true (SQLite 3.25+)", $features['window_functions'] === true);
assert_ft("get_column_meta es bool", is_bool($features['get_column_meta']));
assert_ft("named_parameters es true", $features['named_parameters'] === true);
assert_ft("atomic_upsert es bool", is_bool($features['atomic_upsert']));
assert_ft("cte es true (SQLite 3.8.3+)", $features['cte'] === true);
assert_ft("returning es bool", is_bool($features['returning']));
assert_ft("native_json_column es bool", is_bool($features['native_json_column']));
assert_ft("transactions es true", $features['transactions'] === true);
assert_ft("savepoints es true (en SQLite)", $features['savepoints'] === true);
assert_ft("limit_on_update es false (en SQLite)", $features['limit_on_update'] === false);

// ─── Integración con SchemaMapper ────────────────────
echo "\n--- Bloque 2: Integración SchemaMapper ---\n";
$pdo->exec("CREATE TABLE test_ft (id INTEGER PRIMARY KEY, name TEXT)");

$outputPath = __DIR__ . '/../../tmp/test_features_map.php';
SchemaMapper::setOutputFile($outputPath);
$ok = SchemaMapper::generate($pdo, 'main', null, 'test_features');
assert_ft("SchemaMapper::generate retorna true", $ok === true);
assert_ft("Archivo generado existe", file_exists($outputPath));

if (file_exists($outputPath)) {
    $map = include $outputPath;
    assert_ft("Mapa contiene 'features'", isset($map['features']));
    assert_ft("Mapa contiene 'connection'", isset($map['connection']));
    assert_ft("Mapa contiene 'driver'", isset($map['driver']));
    assert_ft("connection es 'test_features'", $map['connection'] === 'test_features');
    assert_ft("driver es 'sqlite'", $map['driver'] === 'sqlite');
    assert_ft("features.window_functions es true", ($map['features']['window_functions'] ?? null) === true);
    assert_ft("features.driver_version coincide", !empty($map['features']['driver_version']));

    echo "\n  Contenido del mapa generado (features):\n";
    foreach ($map['features'] as $key => $val) {
        $display = is_bool($val) ? ($val ? 'true' : 'false') : $val;
        echo "    $key => $display\n";
    }

    // Limpiar
    unlink($outputPath);
}

// ─── Integración con SchemaMap (lectura) ─────────────
echo "\n--- Bloque 3: Acceso via SchemaMap ---\n";
SchemaMap::setMap([
    'features' => $features,
    'tables'   => [],
], 'test_access');

assert_ft("getFeatures retorna array", is_array(SchemaMap::getFeatures('test_access')));
assert_ft("getFeature('window_functions') es true", SchemaMap::getFeature('window_functions', false, 'test_access') === true);
assert_ft("getFeature('inexistente') retorna default", SchemaMap::getFeature('inexistente', 'N/A', 'test_access') === 'N/A');
assert_ft("getFeature('driver') retorna 'sqlite'", SchemaMap::getFeature('driver', '', 'test_access') === 'sqlite');

// ─── Probes personalizados ───────────────────────────
echo "\n--- Bloque 4: Probes personalizados ---\n";
FeatureDetector::registerProbe('custom_test', function (PDO $pdo, string $driver): bool {
    return $driver === 'sqlite';
});

$detector2 = new FeatureDetector($pdo);
$features2 = $detector2->detect();
assert_ft("Probe personalizado ejecutado", isset($features2['custom_test']));
assert_ft("Probe personalizado retorna true para sqlite", $features2['custom_test'] === true);

FeatureDetector::clearCustomProbes();
$features3 = (new FeatureDetector($pdo))->detect();
assert_ft("Probe limpiado correctamente", !isset($features3['custom_test']));

// ─── Limpieza ────────────────────────────────────────
Conn::close('test_features');

echo "\n==================================================\n";
if ($failed === 0) {
    echo "RESULTADO: TODAS LAS PRUEBAS PASARON\n";
} else {
    echo "RESULTADO: FALLARON $failed PRUEBAS\n";
}
echo "==================================================\n";
