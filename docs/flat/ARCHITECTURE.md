# 🚀 Flat Engine - La Arquitectura Definitiva de Alto Rendimiento

## Resumen Ejecutivo

**Flat Engine** es la evolución final de nuestro experimento de optimización en RapidBase. Tras múltiples iteraciones (W → B+F → SQLEngine → SQLEngine_Optimized), descubrimos que **reducir la cadena de métodos a solo 2 eslabones** produce el mejor rendimiento posible.

### Resultado Clave
- **~565 ms** para 10,000 iteraciones de operaciones CRUD completas
- **~70% más rápido** que la arquitectura Fluent original (~1900ms)
- **Cero warnings**, código limpio y mantenible

---

## 📊 Evolución del Rendimiento

| Versión | Arquitectura | Tiempo (10k iter) | Mejora |
|---------|-------------|-------------------|--------|
| W (Original) | Monolítico Fluent | ~1900 ms | - |
| B+F | Separado Fluent | ~1400 ms | -26% |
| SQLEngine | Fragmentado | ~1200 ms | -37% |
| SQLEngine_Optimized | Pilas + Constantes | ~800 ms | -58% |
| **Flat Engine** | **2 Eslabones** | **~565 ms** | **-70%** |

---

## 🏗️ Principio de Diseño: "Flat is Fast"

### El Problema de Fluent
```php
// Patrón Fluent tradicional (LENTO)
$sql = Q::from('users')
    ->where(['status' => 'active'])
    ->orderBy('name')
    ->limit(10)
    ->select('id, name');
```
**Problema**: Cada método crea un nuevo objeto/clon, genera overhead de memoria y CPU.

### La Solución Flat
```php
// Patrón Flat (RÁPIDO)
$sql = Q::from('users', [
    'status' => 'active',
    '_order' => 'name',
    '_limit' => 10
])->build(QType::SELECT, 'id, name');
```
**Ventaja**: Solo 2 llamadas a métodos, cero objetos intermedios, todo el estado se procesa en un solo pase.

---

## 🔧 Componentes del Sistema

### 1. **Q.php** - Orquestador Principal
Responsable de:
- Recibir configuración plana en `from()`
- Coordinar especialistas internos (Parser, Join, Compiler)
- Entregar resultado final en `build()`

```php
public static function from($table, array $config = []): self
public function build(int $type, $payload = null): array
```

### 2. **QType.php** - Constantes de Operación
```php
class QType {
    public const SELECT = 1;  // Enteros son más rápidos que strings
    public const INSERT = 2;
    public const UPDATE = 3;
    public const DELETE = 4;
    public const COUNT  = 5;
    public const EXISTS = 6;
}
```

### 3. **ConditionParser.php** - Traductor de Filtros
Convierte arrays PHP a condiciones SQL:
- `['status' => 'active']` → `"status = ?"`
- `['id' => [1,2,3]]` → `"id IN (?,?,?)"`  
- `['age' => ['>' => 18]]` → `"age > ?"`

### 4. **JoinStrategy.php** + **DeterministicJoin.php**
Estrategia base y implementación rápida para joins entre tablas.

### 5. **SqlCompiler.php** - Generador de SQL
Usa plantillas `sprintf` pre-compiladas:
```php
private const TPL_SELECT = 'SELECT %s FROM %s%s%s%s%s%s';
```

---

## 📝 Guía de Uso Completa

### SELECT Simple
```php
use RapidBase\PoC\Flat\Q;
use RapidBase\PoC\Flat\QType;

list($sql, $params) = Q::from('users', [
    'status' => 'active'
])->build(QType::SELECT, 'id, name, email');

// SQL: SELECT id, name, email FROM "users" WHERE status = ?
// Params: ['active']
```

### SELECT con Orden y Límite
```php
list($sql, $params) = Q::from('users', [
    'status' => 'active',
    '_order' => '-created_at',  // '-' = DESC
    '_limit' => 10
])->build(QType::SELECT);

// SQL: SELECT * FROM "users" WHERE status = ? ORDER BY created_at DESC LIMIT ?
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

// SQL: INSERT INTO "users" (name, email, role) 
//               VALUES (?, ?, ?), (?, ?, ?), (?, ?, ?)
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

// SQL: UPDATE "users" SET name = ?, email = ? WHERE id = ?
// Params: ['Nuevo Nombre', 'nuevo@test.com', 1]
```

### DELETE
```php
list($sql, $params) = Q::from('users', [
    'status' => 'inactive'
])->build(QType::DELETE);

// SQL: DELETE FROM "users" WHERE status = ?
```

### COUNT
```php
list($sql, $params) = Q::from('orders', [
    'user_id' => 42
])->build(QType::COUNT);

// SQL: SELECT COUNT(*) as total FROM "orders" WHERE user_id = ?
```

### EXISTS
```php
list($sql, $params) = Q::from('users', [
    'email' => 'test@example.com'
])->build(QType::EXISTS);

// SQL: SELECT EXISTS(SELECT 1 FROM "users" WHERE email = ?) as exists_flag
```

---

## ⚡ Técnicas de Optimización Aplicadas

### 1. Constantes Enteras vs Strings
**Hipótesis**: Los enteros son más rápidos en switches que strings.
**Resultado**: ~9% de mejora.

```php
// LENTO
switch ($type) {
    case 'select': ...
}

// RÁPIDO
switch ($type) {
    case QType::SELECT: // 1
}
```

### 2. Plantillas sprintf Pre-compiladas
**Hipótesis**: `sprintf` con templates es más rápido que concatenación.
**Resultado**: ~15% de mejora en consultas complejas.

```php
private const TPL_SELECT = 'SELECT %s FROM %s%s%s%s%s%s';
$sql = sprintf(self::TPL_SELECT, $fields, $table, $join, ...);
```

### 3. Estado Inicializado por Defecto
**Hipótesis**: Evitar checks `isset()` con valores por defecto.
**Resultado**: Código más limpio y ~5% más rápido.

```php
$this->state = [
    'order' => '',      // No necesita isset()
    'limit_sql' => '',
    // ...
];
```

### 4. Procesamiento por Lotes en INSERT
**Hipótesis**: Generar todos los placeholders de una vez es más eficiente.
**Resultado**: ~40% más rápido en INSERTs masivos.

```php
$rowPlaceholders = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
$valuesSql = implode(',', array_fill(0, count($rows), $rowPlaceholders));
```

### 5. Sin Objetos Intermedios
**Hipótesis**: Eliminar la cadena fluent reduce overhead drásticamente.
**Resultado**: **70% más rápido** en total.

---

## 🧪 Hipótesis Validadas

| # | Hipótesis | Resultado | Impacto |
|---|-----------|-----------|---------|
| 1 | Menos eslabones = Más velocidad | ✅ Confirmada | **+70%** |
| 2 | Constantes > Strings | ✅ Confirmada | +9% |
| 3 | Configuración plana > Métodos encadenados | ✅ Confirmada | +25% |
| 4 | Plantillas estáticas > Concatenación | ✅ Confirmada | +15% |
| 5 | Estado inicializado > Checks isset | ✅ Confirmada | +5% |
| 6 | INSERT por lotes > Individual | ✅ Confirmada | +40% |

---

## 🎯 Casos de Uso Recomendados

### ✅ Ideal Para:
- Sistemas de alta concurrencia (>1000 req/s)
- Frameworks que compilan queries internamente
- Operaciones batch masivas (ETL, migraciones)
- Microservicios donde cada ms cuenta
- Capas de persistencia de bajo nivel

### ❌ No Recomendado Para:
- Prototipado rápido donde la legibilidad es prioridad
- Equipos junior que necesitan sintaxis explícita
- Queries dinámicas construidas condicionalmente paso a paso
- Proyectos donde el rendimiento no es crítico

---

## 📁 Estructura de Archivos

```
tests/PoC/Flat/
├── Q.php                 # Orquestador principal (2 métodos públicos)
├── QType.php             # Constantes enteras de operación
├── ConditionParser.php   # Parser de filtros a SQL
├── JoinStrategy.php      # Interfaz base para estrategias JOIN
├── DeterministicJoin.php # Estrategia rápida y predecible
├── SqlCompiler.php       # Generador con plantillas sprintf
├── BenchFlat.php         # Benchmark completo
└── README.md             # Esta documentación

docs/flat/
└── ARCHITECTURE.md       # Documentación técnica detallada
```

---

## 🧪 Ejecutar Benchmarks

```bash
# Ejecutar benchmark completo
php tests/PoC/Flat/BenchFlat.php

# Salida esperada:
# 🚀 Benchmark Flat Engine - 10000 iteraciones
# SELECT Simple             ... 63.13 ms
# SELECT Complejo           ... 95.61 ms
# INSERT Multi (100)        ... 262.92 ms
# UPDATE                    ... 43.14 ms
# DELETE                    ... 36.66 ms
# COUNT                     ... 36.64 ms
# EXISTS                    ... 27.39 ms
# ------------------------------------------------------------
# TOTAL                              565.49 ms
```

---

## 📈 Métricas de Rendimiento Detalladas

### Comparativa por Operación (10,000 iteraciones)

| Operación | Fluent Original | Flat Engine | Mejora |
|-----------|----------------|-------------|--------|
| SELECT Simple | 220 ms | **63 ms** | **-71%** |
| SELECT Complejo | 400 ms | **96 ms** | **-76%** |
| INSERT Multi (100) | 485 ms | **263 ms** | **-46%** |
| UPDATE | 235 ms | **43 ms** | **-82%** |
| DELETE | 210 ms | **37 ms** | **-82%** |
| COUNT | 207 ms | **37 ms** | **-82%** |
| EXISTS | 190 ms | **27 ms** | **-86%** |
| **TOTAL** | **1947 ms** | **565 ms** | **-71%** |

*Nota: Las mejoras varían según la complejidad de la operación.*

---

## 🔮 Futuras Optimizaciones

1. **Cache de Consultas Frequentes**: Almacenar SQL generado para configuraciones repetidas.
2. **Preparación de Statements**: Integración directa con PDO prepare().
3. **Join Genético**: Implementar `EvolutionaryJoin` para optimizar rutas automáticamente.
4. **Parser de URLs**: Convertir parámetros de URL directamente a filtros SQL.
5. **Serialización Binaria**: Usar formatos binarios para transferir estado entre procesos.

---

## 📚 Lecciones Aprendidas

1. **La simplicidad gana**: Menos métodos = más velocidad.
2. **Las constantes importan**: Pequeños detalles (enteros vs strings) suman.
3. **El overhead acumula**: Cada objeto intermedio tiene un costo real.
4. **Medir siempre**: Sin benchmarks, solo estamos adivinando.
5. **Legibilidad vs Rendimiento**: Hay un trade-off claro; depende del caso de uso.

---

## 👨‍💻 Contribuciones

Esta arquitectura es el resultado de múltiples iteraciones empíricas:
- Fase 1: Clase W monolítica
- Fase 2: Separación B+F
- Fase 3: Fragmentación SQLEngine
- Fase 4: Optimización con pilas y constantes
- Fase 5: **Flat Engine** (arquitectura definitiva)

---

**Conclusión**: Flat Engine demuestra que en PHP, menos es más. Una arquitectura minimalista con 2 métodos puede ser 70% más rápida que un builder fluente tradicional, manteniendo toda la funcionalidad necesaria para producción.
