# Clase W y Variantes (Wm, Ws)

## Descripción General

La clase `W` es una variante de bajo acoplamiento para construcción de consultas SQL con encadenamiento corto. Diseñada para ser simple, rápida y fácil de extender.

## Filosofía de Diseño

1. **Sin estilo fluent extenso**: Solo `from()` → `action()`
2. **Estado interno como array**: Sin objetos intermedios, máximo rendimiento
3. **Polimorfismo estricto**: String vs Array en inputs
4. **Reutilización de lógica**: WHERE y FROM compartidos
5. **Constantes de 4 caracteres**: Legibilidad + performance
6. **Quoting automático**: Según driver configurado
7. **Parámetros ordenados por frecuencia**: Optimizado para casos de uso común

## API Principal

### W::from($from, $filter)

Punto de entrada único. Prepara el contexto FROM + WHERE base.

```php
// String: SQL crudo o tabla simple
W::from('users')

// Array: Lista de tablas para auto-join
W::from(['users', 'posts'])

// Con filtro WHERE base
W::from('users', ['status' => 'active', 'role_id' => 5])
```

**Parámetros:**
- `$from` (string|array): 
  - String: SQL crudo (ej: "users u" o "SELECT * FROM users")
  - Array: Lista de tablas para auto-join (ej: ['users', 'posts'])
- `$filter` (array): Filtro base para el WHERE

**Retorna:** `self` - Instancia nueva con estado inicializado

---

### ->select($fields, $limit, $sort, $group, $having)

Ejecuta acción SELECT. Parámetros ordenados por frecuencia de uso.

```php
// Básico
W::from('users')->select()
// SELECT * FROM users

// Con campos específicos
W::from('users')->select('id, name, email')

// Con límite (int)
W::from('users')->select('*', 20)
// SELECT * FROM users LIMIT 20

// Con offset y límite [offset, limit]
W::from('users')->select('*', [40, 20])
// SELECT * FROM users LIMIT 20 OFFSET 40

// Con ordenamiento
W::from('users')->select('*', null, '-created_at')
// SELECT * FROM users ORDER BY created_at DESC

W::from('users')->select('*', null, ['name', '-created_at'])
// SELECT * FROM users ORDER BY name ASC, created_at DESC

// Con GROUP BY y HAVING
W::from('orders')
  ->select('status, COUNT(*) as total', null, null, ['status'], ['total' => ['>' => 5]])
// SELECT status, COUNT(*) as total FROM orders 
// GROUP BY status HAVING total > ?
```

**Parámetros:**
1. `$fields` (string|array): Campos a seleccionar. Default: '*'
2. `$limit` (int|array): 
   - int: Solo LIMIT (ej: 20)
   - array: [offset, limit] para control total (scroll infinito)
3. `$sort` (string|array): Ordenamiento
   - String: "-campo" para DESC, "+campo" o "campo" para ASC
   - Array: ["-campo1", "campo2"] para múltiples
4. `$group` (array): Agrupamiento opcional (ej: ['category', 'status'])
5. `$having` (array): Condiciones HAVING opcionales (mismo formato que $filter)

**Retorna:** `array` - [sql, params]

---

### ->delete()

Ejecuta acción DELETE usando FROM y WHERE definidos.

```php
W::from('users', ['id' => 5])->delete()
// DELETE FROM users WHERE id = ?
// params: [5]
```

**Retorna:** `array` - [sql, params]

---

### ->update($data)

Ejecuta acción UPDATE.

```php
W::from('users', ['id' => 5])->update(['name' => 'John', 'email' => 'john@example.com'])
// UPDATE users SET name = ?, email = ? WHERE id = ?
// params: ['John', 'john@example.com', 5]
```

**Parámetros:**
- `$data` (array): Datos a actualizar (key => value)

**Retorna:** `array` - [sql, params]

---

### W::page($currentPage, $pageSize)

Helper estático para paginación. Retorna [offset, limit].

```php
// Grid pagination (página 3, 20 items por página)
$page = W::page(3, 20); // [40, 20]
W::from('users')->select('*', $page)

// Scroll infinito (offset directo)
W::from('posts')->select('*', [100, 50]) // offset=100, limit=50
```

**Parámetros:**
- `$currentPage` (int): Página actual (1-based)
- `$pageSize` (int): Items por página

**Retorna:** `array` - [offset, limit]

---

### W::setDriver($driver)

Configura el driver para quoting automático.

```php
W::setDriver('mysql');  // Usa backticks `
W::setDriver('sqlite'); // Usa comillas dobles "
W::setDriver('pgsql');  // Usa comillas dobles "
```

---

## Clases Derivadas

### Wm - Wrapper con Métricas

Extiende `W` agregando telemetría de tiempo y memoria.

```php
Wm::clearMetrics();
Wm::from('users', ['id' => 1])->select();

$metrics = Wm::getMetrics();
// [
//   ['id' => 1, 'operation' => 'select', 'time_ms' => 0.1234, 'mem_bytes' => 1024, ...]
// ]

$stats = Wm::getStats();
// ['calls' => 1, 'total_time_ms' => 0.1234, 'avg_time_ms' => 0.1234, ...]
```

**Métodos adicionales:**
- `setEnabled(bool)`: Habilita/deshabilita métricas
- `isEnabled()`: Estado de habilitación
- `getMetrics()`: Todas las métricas recolectadas
- `getStats()`: Estadísticas resumidas
- `clearMetrics()`: Limpia métricas

---

### Ws - Wrapper con Optimización de JOINs

Extiende `W` agregando algoritmos genéticos para optimizar orden de JOINs.

```php
Ws::from(['users', 'posts', 'comments'])->select()
// Encuentra automáticamente el mejor orden de JOINs

$stats = Ws::getOptimizationStats();
// ['evaluations' => 100, 'cache_hits' => 5, 'cache_misses' => 2]
```

**Métodos adicionales:**
- `getOptimizationStats()`: Estadísticas de optimización
- `clearPlanCache()`: Limpia cache de planes óptimos

---

## Casos de Uso Comunes

### Paginación para Grid

```php
$pageNum = 3;
$pageSize = 20;
$page = W::page($pageNum, $pageSize);

[$sql, $params] = W::from('products', ['category_id' => 5])
    ->select('id, name, price', $page, '-created_at');
```

### Scroll Infinito

```php
$lastOffset = 100;
$limit = 50;

[$sql, $params] = W::from('posts')
    ->select('*', [$lastOffset, $limit], '-published_at');
```

### Consulta Compleja con JOINs

```php
[$sql, $params] = W::from(['users u', 'posts p', 'comments c'])
    ->select('u.name, COUNT(c.id) as comment_count', 100)
    ->where(['u.status' => 'active', 'p.published' => true])
    ->build();
```

### Reporte con Agregaciones

```php
[$sql, $params] = W::from('orders')
    ->select(
        'status, SUM(total) as total_sum, AVG(total) as avg_total',
        null,
        null,
        ['status'],
        ['total_sum' => ['>' => 1000]]
    );
```

---

## Consideraciones de Performance

1. **Cache de WHERE**: Los filtros con misma estructura se cachean automáticamente
2. **Array indexado**: El estado usa índices numéricos (más rápido que strings)
3. **Sin objetos intermedios**: Todo el estado está en un solo array
4. **Late Static Binding**: `new static()` permite herencia correcta

---

## Limitaciones Conocidas

- No soporta subconsultas complejas directamente
- El parser WHERE es básico (AND, IN, IS NULL, =)
- Para queries complejas, usar SQL::buildSelect() directamente
