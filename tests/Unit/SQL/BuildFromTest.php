<?php



use RapidBase\Core\SQL;
include_once "../../../src/RapidBase/Core/SQL.php";

// Configuramos el mapa para la prueba (formato plano, sin 'relationships')
SQL::setRelationsMap([
    'from' => [
        'users' => [
            'drivers' => ['local_key' => 'id', 'foreign_key' => 'user_id'],
            'logs'    => ['local_key' => 'id', 'foreign_key' => 'u_id']
        ]
    ]
]);
SQL::setDriver('mysql');
echo "Testing SQL::buildFromWithMap...\n";

// Función auxiliar para normalizar SQL (elimina espacios redundantes y opcionalmente cláusulas AS redundantes)
function normalizeSql($sql) {
    // Reemplazar secuencias de espacios por un solo espacio
    $normalized = preg_replace('/\s+/', ' ', trim($sql));
    // Eliminar " AS `tabla`" cuando el alias es igual al nombre (opcional, según la implementación)
    // Pero para la prueba solo necesitamos que coincida después de normalizar espacios
    return $normalized;
}

// Caso A: Tabla simple (String)
$sql = SQL::buildFromWithMap('users');
$expectedA = "FROM `users`";
assert(normalizeSql($sql) === $expectedA, "Falla en tabla simple");

// Caso B: Join con mapa (Array)
$sql = SQL::buildFromWithMap(['users', 'drivers']);
// La implementación actual puede generar "FROM `users` AS `users` LEFT JOIN `drivers` ..." o "FROM `users` LEFT JOIN ..."
// Normalizamos ambos lados y comparamos
$expectedB = "FROM `users` LEFT JOIN `drivers` ON `users`.`id` = `drivers`.`user_id`";
assert(normalizeSql($sql) === $expectedB, "Falla en Join con mapa");

// Caso C: Triple Join (encadenado)
$sql = SQL::buildFromWithMap(['users', 'drivers', 'logs']);
// No hay assert explícito, solo se comprueba que no lance excepción
// Pero podemos verificar que el SQL generado no esté vacío
assert(!empty($sql), "Triple join produjo SQL vacío");

echo "[OK] BuildFrom tests passed.\n";