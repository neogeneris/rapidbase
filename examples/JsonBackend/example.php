<?php

/**
 * Ejemplo de uso de JsonBackend
 * 
 * JsonBackend permite usar archivos JSON como si fueran una base de datos,
 * con la misma sintaxis fluida que X::con()->from()->select()
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use RapidBase\Core\Backend\JsonBackend;

// Configurar path opcional (si no, usa getcwd() . '/data/jsondb')
define('JSON_BACKEND_PATH', __DIR__ . '/json_data');

echo "=== Ejemplo de uso de JsonBackend ===\n\n";

// 1. INSERTAR registros
echo "1. Insertando usuarios...\n";
JsonBackend::con('jsonDB')->from('users')->insert([
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'age' => 30
]);

JsonBackend::con('jsonDB')->from('users')->insert([
    'name' => 'Jane Smith',
    'email' => 'jane@example.com',
    'age' => 25
]);

JsonBackend::con('jsonDB')->from('users')->insert([
    'name' => 'Bob Johnson',
    'email' => 'bob@example.com',
    'age' => 35
]);

echo "   ✓ 3 usuarios insertados\n\n";

// 2. SELECT - obtener todos los registros
echo "2. Seleccionando todos los usuarios:\n";
$result = JsonBackend::con('jsonDB')->from('users')->select('*');
foreach ($result->data as $user) {
    echo "   - {$user['name']} ({$user['email']})\n";
}
echo "   Total: {$result->total}\n\n";

// 3. SELECT con filtro
echo "3. Seleccionando usuarios con edad > 28 (usando from con filtro):\n";
// Nota: Los filtros en from() son igualdad exacta
// Para filtros más complejos, se puede usar select y filtrar manualmente
$result = JsonBackend::con('jsonDB')->from('users')->select('*');
$filtered = array_filter($result->data, fn($u) => $u['age'] > 28);
foreach ($filtered as $user) {
    echo "   - {$user['name']} (edad: {$user['age']})\n";
}
echo "\n";

// 4. SELECT con paginación
echo "4. Paginación (página 1, 2 por página):\n";
$result = JsonBackend::con('jsonDB')->from('users')->select('*', [1, 2]);
echo "   Página {$result->page} de {$result->limit} registros\n";
foreach ($result->data as $user) {
    echo "   - {$user['name']}\n";
}
echo "   Total general: {$result->total}\n\n";

// 5. SELECT con ordenamiento
echo "5. Ordenar por edad descendente:\n";
$result = JsonBackend::con('jsonDB')->from('users')->select('*', null, '-age');
foreach ($result->data as $user) {
    echo "   - {$user['name']} (edad: {$user['age']})\n";
}
echo "\n";

// 6. UPSERT - Actualizar o insertar
echo "6. Upsert (actualizar usuario con id=1):\n";
JsonBackend::con('jsonDB')->from('users')->upsert([
    'id' => 1,
    'name' => 'John Updated',
    'email' => 'john.updated@example.com',
    'age' => 31
], ['id']);

$result = JsonBackend::con('jsonDB')->from('users')->select('*', [0, 1], 'id');
$user = $result->data[0] ?? null;
if ($user) {
    echo "   Usuario actualizado: {$user['name']} ({$user['email']})\n";
}
echo "\n";

// 7. UPDATE con filtro
echo "7. Actualizar todos los usuarios con edad 25:\n";
JsonBackend::con('jsonDB')->from('users', ['age' => 25])->update([
    'age' => 26
]);
echo "   ✓ Usuarios con edad 25 actualizados a 26\n\n";

// 8. READ - leer un solo registro
echo "8. Leer primer usuario:\n";
$user = JsonBackend::con('jsonDB')->from('users')->read();
if ($user) {
    echo "   Primer usuario: {$user['name']}\n";
}
echo "\n";

// 9. COUNT
echo "9. Contar usuarios:\n";
$count = JsonBackend::con('jsonDB')->from('users')->count();
echo "   Total de usuarios: $count\n\n";

// 10. EXISTS
echo "10. Verificar existencia:\n";
$exists = JsonBackend::con('jsonDB')->from('users', ['name' => 'Bob Johnson'])->exists();
echo "   ¿Existe Bob Johnson? " . ($exists ? 'Sí' : 'No') . "\n\n";

// 11. DELETE
echo "11. Eliminar usuario con id=3:\n";
JsonBackend::con('jsonDB')->from('users', ['id' => 3])->delete();
echo "   ✓ Usuario eliminado\n";

$count = JsonBackend::con('jsonDB')->from('users')->count();
echo "   Total después de eliminar: $count\n\n";

// 12. SELECT con proyección de campos específicos
echo "12. Seleccionar solo nombre y email:\n";
$result = JsonBackend::con('jsonDB')->from('users')->select(['name', 'email']);
foreach ($result->data as $user) {
    echo "   - {$user['name']} <{$user['email']}>\n";
}
echo "\n";

// 13. FIRST - obtener el primer registro
echo "13. Obtener primer usuario con first():\n";
$first = JsonBackend::con('jsonDB')->from('users')->first();
if ($first) {
    echo "   Primer usuario: {$first['name']}\n";
}
echo "\n";

// Mostrar ubicación de los archivos
echo "=== Archivos JSON creados ===\n";
$basePath = defined('JSON_BACKEND_PATH') ? JSON_BACKEND_PATH : getcwd() . '/data/jsondb';
$dbPath = $basePath . '/jsonDB';
if (is_dir($dbPath)) {
    $files = scandir($dbPath);
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..') {
            echo "   📁 $dbPath/$file\n";
        }
    }
}

echo "\n=== ¡Ejemplo completado! ===\n";
