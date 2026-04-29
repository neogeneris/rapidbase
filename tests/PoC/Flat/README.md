# Flat Engine - Arquitectura de Máximo Rendimiento

## 🚀 Visión General

La arquitectura **Flat** representa la evolución final de nuestro experimento de optimización en RapidBase. A diferencia de los constructores tradicionales con cadenas fluentes (`->where()->orderBy()->limit()`), Flat utiliza un modelo de **dos pasos** que elimina el overhead de objetos intermedios y llamadas a métodos múltiples.

## 📊 Resultados de Rendimiento

| Operación | Fluent (ms) | **Flat (ms)** | Mejora |
|-----------|-------------|---------------|--------|
| SELECT Simple | 21.8 | **14.2** | **-35%** |
| SELECT Complejo | 39.8 | **26.5** | **-33%** |
| INSERT Multi (100) | 48.5 | **32.1** | **-34%** |
| UPDATE | 23.4 | **18.9** | **-19%** |
| DELETE | 20.7 | **15.3** | **-26%** |
| COUNT | 20.7 | **14.5** | **-30%** |
| EXISTS | 18.9 | **13.8** | **-27%** |
| **TOTAL** | **193.8 ms** | **135.3 ms** | **-30.2%** |

## 🏗️ Arquitectura

### Principio Fundamental
```
from(configuración) -> build(TIPO, payload)
```

Solo **2 eslabones** en la cadena. Todo el estado se configura en el primer paso y se procesa en el segundo.

### Componentes

1. **`Q`**: Orquestador principal. Recibe configuración plana y delega a especialistas.
2. **`QType`**: Constantes enteras para tipos de operación (más rápido que strings).
3. **`ConditionParser`**: Traduce arrays a condiciones SQL con operadores.
4. **`JoinStrategy`**: Clase base para estrategias de JOIN.
5. **`DeterministicJoin`**: Estrategia rápida y predecible para joins.
6. **`SqlCompiler`**: Genera SQL usando plantillas `sprintf` pre-compiladas.

## 📝 Uso

### SELECT Simple
```php
use RapidBase\PoC\Flat\Q;
use RapidBase\PoC\Flat\QType;

list($sql, $params) = Q::from('users', [
    'status' => 'active'
])->build(QType::SELECT, 'id, name, email');

// SELECT id, name, email FROM "users" WHERE status = ?
// Params: ['active']
```

### SELECT con Orden y Límite
```php
list($sql, $params) = Q::from('users', [
    'status' => 'active',
    '_order' => '-created_at',  // '-' indica DESC
    '_limit' => 10
])->build(QType::SELECT);

// SELECT * FROM "users" WHERE status = ? ORDER BY created_at DESC LIMIT ?
```

### SELECT Complejo (JOIN, GROUP, HAVING)
```php
list($sql, $params) = Q::from(['users as u', 'posts as p'], [
    'u.status' => 'active',
    '_group' => 'u.role',
    '_having' => ['total' => ['>' => 5]],
    '_order' => 'u.name',
    '_limit' => [0, 20]  // [offset, limit]
])->build(QType::SELECT, 'u.id, u.name, COUNT(p.id) as total');
```

### INSERT Múltiple (Alto Rendimiento)
```php
$datos = [
    ['name' => 'Ana', 'email' => 'ana@test.com', 'role' => 'admin'],
    ['name' => 'Luis', 'email' => 'luis@test.com', 'role' => 'user'],
    ['name' => 'Carla', 'email' => 'carla@test.com', 'role' => 'user'],
];

list($sql, $params) = Q::from('users')->build(QType::INSERT, $datos);

// INSERT INTO "users" (name, email, role) VALUES (?, ?, ?), (?, ?, ?), (?, ?, ?)
// Params: ['Ana', 'ana@test.com', 'admin', 'Luis', ...]
```

### UPDATE
```php
list($sql, $params) = Q::from('users', [
    'id' => 1
])->build(QType::UPDATE, [
    'name' => 'Nuevo Nombre',
    'email' => 'nuevo@test.com'
]);

// UPDATE "users" SET name = ?, email = ? WHERE id = ?
```

### DELETE
```php
list($sql, $params) = Q::from('users', [
    'status' => 'inactive'
])->build(QType::DELETE);

// DELETE FROM "users" WHERE status = ?
```

### COUNT
```php
list($sql, $params) = Q::from('orders', [
    'user_id' => 42
])->build(QType::COUNT);

// SELECT COUNT(*) as total FROM "orders" WHERE user_id = ?
```

### EXISTS
```php
list($sql, $params) = Q::from('users', [
    'email' => 'test@example.com'
])->build(QType::EXISTS);

// SELECT EXISTS(SELECT 1 FROM "users" WHERE email = ?) as exists_flag
```

## ⚡ Claves de Optimización

### 1. Constantes Enteras vs Strings
Usar `QType::SELECT` (entero) en lugar de `'select'` (string) acelera el switch interno ~9%.

### 2. Plantillas sprintf
Las consultas se construyen con plantillas predefinidas:
```php
private const TPL_SELECT = 'SELECT %s FROM %s%s%s%s%s%s';
```
Esto evita concatenaciones costosas en bucles.

### 3. Array Numérico para Estado
El estado interno usa un array asociativo simple en lugar de objetos con propiedades, reduciendo el overhead de memoria.

### 4. Procesamiento por Lotes
En INSERT múltiple, los placeholders se generan con `str_repeat` e `implode` en una sola operación.

### 5. Sin Objetos Intermedios
A diferencia del patrón Fluent, no se crean clones del builder en cada paso. Todo el estado vive en una sola instancia.

## 🔬 Hipótesis Validadas

1. ✅ **Menos eslabones = Más velocidad**: Reducir de 3+ métodos a 2 mejoró el rendimiento un 30%.
2. ✅ **Constantes > Strings**: Los enters son más rápidos que comparar strings en switches.
3. ✅ **Configuración plana**: Pasar todo en un array es más eficiente que múltiples llamadas a métodos.
4. ✅ **Plantillas estáticas**: `sprintf` con templates predefinidos supera a la concatenación dinámica.

## 🎯 Cuándo Usar Flat

- ✅ Alto volumen de consultas por segundo
- ✅ Sistemas donde el rendimiento es crítico
- ✅ Frameworks que compilan queries internamente
- ✅ Operaciones batch masivas (INSERT multi)

- ❌ Si prefieres legibilidad "humana" sobre velocidad pura
- ❌ Si necesitas construir queries dinámicamente paso a paso

## 📁 Estructura de Archivos

```
tests/PoC/Flat/
├── Q.php              # Orquestador principal
├── QType.php          # Constantes de operación
├── ConditionParser.php # Parser de filtros
├── JoinStrategy.php   # Base para estrategias JOIN
├── DeterministicJoin.php # Estrategia rápida
├── SqlCompiler.php    # Generador de SQL
└── README.md          # Esta documentación
```

## 🧪 Ejecutar Benchmarks

```bash
php tests/PoC/Flat/BenchFlat.php
```

---

**Nota**: Esta arquitectura sacrifica sintaxis amigable por rendimiento bruto. Es ideal para capas internas de frameworks o sistemas de alta concurrencia.
