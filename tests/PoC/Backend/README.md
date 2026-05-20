# Prueba de Concepto: JsonBackend

## Descripción

Esta prueba de concepto implementa un sistema de almacenamiento de datos en archivos JSON que simula una base de datos, con una interfaz similar a la que se usaría en sistemas como RapidBase. **Ahora incluye soporte para JOINs usando SQLite en memoria y medición de tiempos de ejecución**.

## Características Principales

- ✅ **CRUD completo**: insert, update, delete, select
- ✅ **IDs autoincrementales** automáticos por entidad
- ✅ **Archivos JSON independientes** por entidad (ej. `users.json`, `posts.json`)
- ✅ **Sintaxis fluida** tipo `JsonBackend::from('users')->select('*')`
- ✅ **JOINs con SQLite en memoria** sin necesidad de schema previo
- ✅ **Medición de tiempos** de ejecución para cada operación
- ✅ **Sin dependencias externas** más allá de PHP y SQLite3

## Estructura

```
tests/PoC/Backend/
├── Backend.php                 # Clase base abstracta
├── JsonBackend.php             # Implementación con archivos JSON
├── JsonBackendTest.php         # Pruebas CRUD básicas
├── JsonBackendJoinTest.php     # Pruebas de JOINs
├── JsonBackendTimingTest.php   # Pruebas de medición de tiempos
└── README.md                   # Esta documentación
```

## Uso Básico

### Insertar registros

```php
use Tests\PoC\Backend\JsonBackend;

// Insertar múltiples registros
$ids = JsonBackend::into('users')->insert([
    ['name' => 'Carlos', 'email' => 'carlos@example.com'],
    ['name' => 'Luis', 'email' => 'luis@example.com'],
]);

echo "IDs insertados: " . implode(', ', $ids) . "\n";
echo "Tiempo: " . JsonBackend::into('users')->getExecutionTime() . " segundos\n";
```

Esto creará automáticamente un archivo `users.json` en el directorio de datos.

### Seleccionar registros

```php
// Usando from() - Sintaxis alternativa
$allUsers = JsonBackend::from('users')->select('*');
echo "Tiempo de SELECT: " . JsonBackend::from('users')->getExecutionTime() . " s\n";

// Usando into() - Sintaxis original
$allUsers = JsonBackend::into('users')->select('*');

// Seleccionar campos específicos
$names = JsonBackend::from('users')->select(['name', 'email']);

// Con cláusula WHERE
$carlos = JsonBackend::from('users')->select('*', ['name' => 'Carlos']);
```

### Actualizar registros

```php
$affected = JsonBackend::into('users')->update(
    ['email' => 'newemail@example.com'],
    ['name' => 'Carlos']
);
echo "Registros actualizados: $affected\n";
echo "Tiempo de UPDATE: " . JsonBackend::into('users')->getExecutionTime() . " s\n";
```

### Eliminar registros

```php
$deleted = JsonBackend::from('users')->delete(['name' => 'Luis']);
echo "Registros eliminados: $deleted\n";
echo "Tiempo de DELETE: " . JsonBackend::from('users')->getExecutionTime() . " s\n";
```

## JOINs con SQLite en Memoria

Una característica poderosa es la capacidad de hacer JOINs entre entidades sin necesidad de definir un schema previamente:

```php
// Crear usuarios
$userIds = JsonBackend::into('users')->insert([
    ['name' => 'Carlos', 'email' => 'carlos@example.com'],
    ['name' => 'Ana', 'email' => 'ana@example.com'],
]);

// Crear posts relacionados
JsonBackend::into('posts')->insert([
    ['title' => 'Post 1', 'user_id' => $userIds[0]],
    ['title' => 'Post 2', 'user_id' => $userIds[0]],
    ['title' => 'Post 3', 'user_id' => $userIds[1]],
]);

// Hacer INNER JOIN
$results = JsonBackend::from('users')
    ->join('posts', 'id', 'user_id', 'INNER')
    ->selectFields(['users.name', 'posts.title'])
    ->get();

echo "Tiempo del JOIN: " . JsonBackend::from('users')->getExecutionTime() . " s\n";

foreach ($results as $row) {
    echo "{$row['name']} escribió: {$row['title']}\n";
}
```

### Tipos de JOIN soportados

- `INNER JOIN` (por defecto)
- `LEFT JOIN`
- `RIGHT JOIN`

```php
// LEFT JOIN
JsonBackend::from('users')
    ->leftJoin('posts', 'id', 'user_id')
    ->get();

// RIGHT JOIN
JsonBackend::from('users')
    ->rightJoin('posts', 'id', 'user_id')
    ->get();
```

## Medición de Tiempos

Todas las operaciones miden automáticamente su tiempo de ejecución:

```php
// Insertar y medir tiempo
JsonBackend::into('products')->insert([
    ['name' => 'Product A', 'price' => 100],
    ['name' => 'Product B', 'price' => 200],
]);

$time = JsonBackend::into('products')->getExecutionTime();
echo "Tiempo de inserción: " . number_format($time * 1000, 4) . " ms\n";

// Select y medir tiempo
$products = JsonBackend::from('products')->select('*');
$time = JsonBackend::from('products')->getExecutionTime();
echo "Tiempo de consulta: " . number_format($time * 1000, 4) . " ms\n";
```

## Ejecutar Pruebas

```bash
# Ejecutar todas las pruebas
php vendor/bin/phpunit tests/PoC/Backend/

# Ejecutar solo pruebas de timing
php vendor/bin/phpunit tests/PoC/Backend/JsonBackendTimingTest.php --testdox

# Ejecutar solo pruebas de JOINs
php vendor/bin/phpunit tests/PoC/Backend/JsonBackendJoinTest.php --testdox
```

## Consideraciones de Rendimiento

- **Operaciones simples** (INSERT, SELECT, UPDATE, DELETE): Muy rápidas para volúmenes pequeños y medianos de datos (< 10,000 registros)
- **JOINs**: El rendimiento depende del tamaño de los datos, ya que se cargan en memoria SQLite temporal
- **ID Autoincremental**: Se calcula buscando el máximo ID existente en cada inserción
- **Caché**: Los datos se mantienen en caché durante la vida del objeto para mejorar rendimiento

## Ventajas

1. **Sin configuración**: No requiere instalar ni configurar servidores de bases de datos
2. **Portable**: Los datos son archivos JSON que pueden versionarse o transferirse fácilmente
3. **Schema-less**: No es necesario definir estructuras de tablas previamente
4. **JOINs poderosos**: Usa el motor SQL de SQLite para consultas complejas
5. **Transparente**: Los tiempos de ejecución permiten profiling fácil

## Limitaciones

1. **Concurrencia**: No está diseñado para acceso concurrente múltiple
2. **Volumen**: Para grandes volúmenes de datos (> 100k registros), considerar una BD real
3. **Transacciones**: No soporta transacciones ACID completas
4. **Índices**: No hay soporte para índices más allá del ID principal
$carlos = JsonBackend::from('users')->select('*', ['name' => 'Carlos']);
```

### Actualizar registros

```php
// Actualizar registros que coincidan con el criterio
$affected = JsonBackend::from('users')->update(
    ['email' => 'nuevo@email.com'],
    ['name' => 'Carlos']
);
```

### Eliminar registros

```php
// Eliminar registros que coincidan con el criterio
$deleted = JsonBackend::from('users')->delete(['name' => 'Ana']);
```

## JOINs con SQLite en Memoria

JsonBackend ahora soporta operaciones de JOIN utilizando SQLite en memoria como motor temporal. Esto permite realizar consultas complejas entre múltiples entidades.

### INNER JOIN

```php
// Crear datos de usuarios con role_id
JsonBackend::into('users')->insert([
    ['name' => 'Carlos', 'email' => 'carlos@example.com', 'role_id' => 1],
    ['name' => 'Luis', 'email' => 'luis@example.com', 'role_id' => 2],
]);

// Crear datos de roles
JsonBackend::into('roles')->insert([
    ['id' => 1, 'name' => 'Admin', 'level' => 10],
    ['id' => 2, 'name' => 'User', 'level' => 5],
]);

// Hacer INNER JOIN
$result = JsonBackend::from('users')
    ->join('roles', 'role_id', 'id', 'INNER')
    ->get();

// Resultado:
// [
//     ['name' => 'Carlos', 'email' => 'carlos@example.com', 'role_id' => 1, 'roles.name' => 'Admin', 'level' => '10'],
//     ['name' => 'Luis', 'email' => 'luis@example.com', 'role_id' => 2, 'roles.name' => 'User', 'level' => '5']
// ]
```

### LEFT JOIN

```php
// LEFT JOIN incluye todos los usuarios, incluso si no tienen rol
$result = JsonBackend::from('users')
    ->leftJoin('roles', 'role_id', 'id')
    ->get();
```

### Múltiples JOINs

```php
$result = JsonBackend::from('users')
    ->join('roles', 'role_id', 'id')
    ->join('departments', 'department_id', 'id')
    ->get();
```

### JOIN con WHERE

```php
$result = JsonBackend::from('users')
    ->join('roles', 'role_id', 'id')
    ->where(['name' => 'Carlos'])
    ->get();
```

### Seleccionar campos específicos en JOINs

```php
$result = JsonBackend::from('users')
    ->join('roles', 'role_id', 'id')
    ->selectFields(['name', 'email', 'roles.name'])
    ->get();
```

## Características

### CRUD Básico
1. **ID Autoincremental**: Cada registro recibe automáticamente un ID único y autoincremental.
2. **Archivos por Entidad**: Cada entidad (tabla) se almacena en su propio archivo JSON.
3. **Caché Interna**: Los datos se cargan en memoria para operaciones múltiples y se guardan al finalizar.
4. **Soporte WHERE**: Filtrado simple con coincidencia exacta de valores.
5. **Persistencia JSON**: Los datos se guardan en formato JSON legible.

### JOINs Avanzados
1. **SQLite en Memoria**: Usa SQLite como motor temporal para procesar JOINs.
2. **Tipos de JOIN Soportados**: INNER, LEFT, RIGHT.
3. **Múltiples JOINs**: Soporta encadenar múltiples JOINs en una sola consulta.
4. **WHERE en JOINs**: Filtrado de resultados después del JOIN.
5. **Selección de Campos**: Capacidad de seleccionar campos específicos del resultado.

## Ejemplo de Archivo JSON Generado

```json
[
    {
        "id": 1,
        "name": "Carlos",
        "email": "carlos@example.com"
    },
    {
        "id": 2,
        "name": "Luis",
        "email": "luis@example.com"
    }
]
```

## Métodos Disponibles

### Backend (Clase Base)
- `from(string $entity): static` - **Alias de into()** - Establece la entidad a trabajar (sintaxis tipo RapidBase/X)
- `into(string $entity): static` - Establece la entidad a trabajar
- `insert(array $records)` - Inserta registros
- `update(array $data, ?array $where)` - Actualiza registros
- `delete(?array $where)` - Elimina registros
- `select(array|string $fields, ?array $where)` - Selecciona registros

### JsonBackend (Métodos Adicionales)
- `setBaseDir(string $baseDir): static` - Establece el directorio base para los archivos JSON
- `clearCache()` - Limpia la caché interna
- `dropEntity()` - Elimina completamente la entidad y su archivo
- `join(string $table, string $localField, string $foreignField, string $type = 'INNER'): static` - Configura un JOIN
- `leftJoin(string $table, string $localField, string $foreignField): static` - Alias para LEFT JOIN
- `rightJoin(string $table, string $localField, string $foreignField): static` - Alias para RIGHT JOIN
- `selectFields(array|string $fields): static` - Establece campos a seleccionar en JOINs
- `where(array $where): static` - Establece cláusula WHERE para JOINs
- `get(): array` - Ejecuta la consulta (con o sin JOINs)

## Notas

- Esta es una prueba de concepto y no está optimizada para producción.
- No hay manejo de concurrencia ni transacciones.
- El filtrado WHERE es básico (solo igualdad exacta).
- **Requisito para JOINs**: La extensión SQLite3 debe estar habilitada en PHP.
- Para usar en producción, se recomienda agregar:
  - Validación de datos
  - Manejo de errores robusto
  - Soporte para operadores de comparación en WHERE
  - Transacciones
  - Índices para mejor rendimiento
