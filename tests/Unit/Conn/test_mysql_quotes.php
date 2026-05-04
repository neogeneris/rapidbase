<?php
/**
 * Pruebas unitarias para Conn, Q y CompiledQuery con MySQL
 * Verifica que las comillas se usen correctamente segun el driver
 */

require_once __DIR__ . '/../../../examples/querybrowser/RapidBase.php';

use RapidBase\Core\Conn;
use RapidBase\Core\SQL\Q;
use RapidBase\Core\SQL\ConditionMatrix;
use RapidBase\Core\SchemaMap;

echo "=== Pruebas de Conn y Q con MySQL ===\n\n";

// Configurar conexion MySQL
$dsn = "mysql:host=localhost;port=3306;dbname=test_rapidbase;charset=utf8mb4";
$user = "testuser";
$pass = "testpass";
$connectionId = "mysql_test";

echo "1. Configurando conexion MySQL...\n";
try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    echo "   ✓ Conexion PDO exitosa\n";
} catch (PDOException $e) {
    echo "   ✗ Error en conexion PDO: " . $e->getMessage() . "\n";
    exit(1);
}

// Configurar Conn pool
Conn::setup($dsn, $user, $pass, $connectionId);
echo "   ✓ Conn setup completado\n";

// Obtener driver desde PDO
$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
echo "   Driver detectado: $driver\n";

// Configurar ConditionMatrix con el driver correcto
ConditionMatrix::setDriver($driver);
echo "   ✓ ConditionMatrix configurado para $driver\n";

// Verificar caracter de comilla
$quoteChar = ($driver === 'mysql') ? '`' : '"';
echo "   Caracter de comilla esperado: $quoteChar\n\n";

// Crear un schema map basico para la tabla users
$schemaMap = [
    'connection' => $connectionId,
    'driver' => $driver,
    'checksum' => '',
    'generated_at' => date('Y-m-d H:i:s'),
    'features' => ['window_functions' => true],
    'relationships' => ['from' => [], 'to' => []],
    'tables' => [
        'users' => [
            'id' => ['type' => 'int', 'nullable' => false, 'primary' => true],
            'name' => ['type' => 'varchar', 'nullable' => true, 'primary' => false],
            'email' => ['type' => 'varchar', 'nullable' => true, 'primary' => false]
        ]
    ]
];

SchemaMap::setMap($schemaMap, $connectionId);
SchemaMap::setDefaultConnection($connectionId);
echo "2. SchemaMap configurado\n\n";

// Prueba 1: Query simple SELECT
echo "3. Prueba: Q::from()->select() con MySQL\n";
$query = Q::from('users', []);
$compiled = $query->select('*');
$sql = $compiled->getSql();
echo "   SQL generado: $sql\n";

// Verificar que usa comillas invertidas para MySQL
if ($driver === 'mysql') {
    if (strpos($sql, '`') !== false) {
        echo "   ✓ Usa comillas invertidas (`) correctamente\n";
    } else {
        echo "   ✗ ERROR: No usa comillas invertidas para MySQL\n";
    }
} else {
    if (strpos($sql, '"') !== false) {
        echo "   ✓ Usa comillas dobles (\") correctamente\n";
    } else {
        echo "   ✓ No requiere comillas especiales\n";
    }
}

// Ejecutar la consulta
echo "\n4. Ejecutando consulta...\n";
try {
    $result = $compiled->run(PDO::FETCH_ASSOC, null, $connectionId);
    echo "   ✓ Consulta ejecutada exitosamente\n";
    echo "   Filas obtenidas: " . count($result['rows']) . "\n";
    if (count($result['rows']) > 0) {
        echo "   Primera fila: " . json_encode($result['rows'][0]) . "\n";
    }
} catch (Exception $e) {
    echo "   ✗ Error al ejecutar: " . $e->getMessage() . "\n";
    echo "   SQL: $sql\n";
    exit(1);
}

// Prueba 2: Query con WHERE
echo "\n5. Prueba: Q::from()->select() con WHERE\n";
$query2 = Q::from('users', ['name' => 'Test User']);
$compiled2 = $query2->select('*');
$sql2 = $compiled2->getSql();
echo "   SQL generado: $sql2\n";
echo "   Parametros: " . json_encode($compiled2->getParams()) . "\n";

try {
    $result2 = $compiled2->run(PDO::FETCH_ASSOC, null, $connectionId);
    echo "   ✓ Consulta con WHERE ejecutada exitosamente\n";
    echo "   Filas obtenidas: " . count($result2['rows']) . "\n";
} catch (Exception $e) {
    echo "   ✗ Error al ejecutar con WHERE: " . $e->getMessage() . "\n";
}

// Prueba 3: Verificar Gateway
echo "\n6. Prueba: Gateway::select() con MySQL\n";
use RapidBase\Core\Gateway;

// Seleccionar la conexion correcta
Conn::select($connectionId);
try {
    $gatewayResult = Gateway::select('*', 'users', [], [], [], [], null, false, PDO::FETCH_ASSOC);
    echo "   ✓ Gateway::select() ejecutado exitosamente\n";
    echo "   Total filas: " . $gatewayResult['total'] . "\n";
    echo "   Fuente: " . $gatewayResult['source'] . "\n";
} catch (Exception $e) {
    echo "   ✗ Error en Gateway::select(): " . $e->getMessage() . "\n";
}

echo "\n=== Todas las pruebas completadas ===\n";

// Limpiar
Conn::close($connectionId);
echo "Conexion cerrada.\n";
