# Clase W: PoC de Alto Rendimiento para Consultas SQL

## Filosofía de Diseño

La clase **W** es una Prueba de Concepto (PoC) diseñada para superar el rendimiento de `SQL.php` en la construcción de consultas. Su arquitectura se basa en los siguientes principios:

### Principios Fundamentales

1. **Encadenamiento mínimo (2 niveles)**: Solo `W::table()->action()`
2. **Estado interno como array indexado**: Sin objetos intermedios, sin fluency extenso
3. **Constantes de 4 caracteres**: Para índices del array (mejor performance que strings)
4. **Polimorfismo estricto**: Manejo diferenciado de string vs array en inputs
5. **Lógica de construcción separable**: El armado de queries puede modificarse rápidamente para comparar enfoques

## API Básica

```php
// Uso básico con 2 niveles de encadenamiento
W::table($from, $filter)->select('*', W::page(1,20));

// Ejemplos concretos
W::table('users', ['status' => 'active'])->select('*');
W::table(['users', 'posts'], ['u.status' => 'active'])->select(['u.id', 'p.title'], [1, 20], ['-created_at']);
```

## Estructura Interna

### Constantes de Estado (índices numéricos)

```php
private const ST_FROM = 0;           // Tabla(s) del FROM
private const ST_FROM_TYPE = 1;      // Tipo: 'raw' o 'list'
private const ST_WHERE_SQL = 2;      // SQL del WHERE ya construido
private const ST_WHERE_PARAMS = 3;   // Parámetros del WHERE
private const ST_SELECT = 4;         // Campos a seleccionar
private const ST_ORDER = 5;          // ORDER BY
private const ST_LIMIT = 6;          // Límite de registros
private const ST_OFFSET = 7;         // Offset para paginación
```

### Métodos Públicos

| Método | Descripción | Retorno |
|--------|-------------|---------|
| `table($table, $filter)` | Punto de entrada único. Prepara FROM y WHERE base | `self` |
| `select($fields, $page, $sort)` | Ejecuta SELECT con paginación y ordenamiento | `[sql, params]` |
| `delete()` | Ejecuta DELETE usando FROM y WHERE definidos | `[sql, params]` |
| `update(array $data)` | Ejecuta UPDATE con los datos proporcionados | `[sql, params]` |

### Optimizaciones Implementadas

1. **Cache L1 de WHERE**: Almacena templates de WHERE comunes para reutilizar parseo
2. **Arrays indexados por constantes**: Más rápido que usar strings como keys
3. **Sin allocations innecesarias**: Evita presión sobre el GC
4. **vsprintf para armado**: La lógica de construcción puede ir separada para testing de performance

## Clases Relacionadas

### Wm: Wrapper con Métricas (Perfilador)

**Propósito**: Registrar timing y uso de memoria sin ensuciar W con telemetría.

```php
// Mismos métodos que W pero con registro automático de métricas
Wm::table($from, $filter)->select('*', W::page(1,20));

// Acceso a métricas
$metrics = Wm::getMetrics();
$stats = Wm::getStats(); // calls, avg_time_ms, avg_mem_bytes, etc.
```

**Características**:
- Hereda toda la funcionalidad de W
- Registra tiempo de ejecución y memoria por operación
- Métricas almacenadas estáticamente para acceso posterior
- Habilitable/deshabilitable dinámicamente

### Ws: Optimización con Algoritmos Genéticos

**Propósito**: Encontrar automáticamente el mejor orden de JOINs para consultas multi-tabla.

```php
// Optimización automática del orden de tablas
Ws::table(['users', 'posts', 'comments'], ['status' => 'active'])
  ->select('*', [1, 20]);
```

**Algoritmo**:
- **Población**: 20 individuos (permutaciones de tablas)
- **Generaciones**: Máximo 50 iteraciones
- **Crossover rate**: 70%
- **Mutación rate**: 10%
- **Función de score**: Considera relaciones conocidas, tamaño estimado de tablas, complejidad de JOINs

**Cache de planes óptimos**: Los resultados de optimización se cachean por combinación de tablas.

## Comparación con SQL.php

| Característica | SQL.php | W |
|----------------|---------|---|
| Estilo | Fluent extenso (`buildSelect()`, `buildWhere()`, etc.) | Mínimo (`table()->select()`) |
| Estado | Múltiples variables estáticas | Array indexado por constantes |
| Cache | L1 (RAM), L2 (disco), L3 (queries) | L1 (templates WHERE) |
| Telemetría | Integrada con flag | Separada en Wm |
| Optimización JOINs | Básica | Algoritmo genético en Ws |
| Prefijos en métodos | `build*` | Directos (`select`, `delete`, `update`) |

## Experimentos de Performance Documentados

Ver en `/docs/labs/`:
- `FETCH_NUM_VS_FETCH_ASSOC.md`: Comparativa de modos de fetch
- `HASH_ALGORITHMS_BENCHMARK.md`: Algoritmos de hashing para cache keys
- `OPTIMIZATION_EXPERIMENTS.md`: Varios experimentos de optimización

## Guía de Implementación Futura

### Para mantener el performance:

1. **Usar arrays indexados numéricamente** con constantes de 4 caracteres
2. **Evitar strings como keys** en loops críticos
3. **Separar lógica de construcción** para poder swappear implementaciones
4. **Cacheear estructuras**, no valores (el WHERE cachea la estructura, no los valores)
5. **Minimizar allocations** en código hot path

### Para extender funcionalidad:

1. **Nuevas acciones**: Agregar métodos como `insert()`, `upsert()` siguiendo el patrón
2. **Nuevos optimizadores**: Extender Ws con diferentes estrategias de JOIN
3. **Métricas custom**: Wm puede extenderse para registrar métricas específicas

## Ejemplos de Uso

### Consulta simple con paginación
```php
[$sql, $params] = W::table('users', ['status' => 'active'])
    ->select(['id', 'name', 'email'], [1, 20], ['-created_at']);
```

### Multi-tabla con optimización automática
```php
[$sql, $params] = Ws::table(['users u', 'posts p', 'comments c'], ['u.active' => 1])
    ->select(['u.id', 'u.name', 'p.title', 'COUNT(c.id) as comment_count'], 
             [1, 50], 
             ['-p.created_at']);
```

### Perfilado de consultas
```php
Wm::setEnabled(true);

for ($i = 0; $i < 100; $i++) {
    Wm::table('users', ['role' => 'admin'])->select('*');
}

$stats = Wm::getStats();
echo "Avg time: {$stats['avg_time_ms']}ms\n";
echo "Avg memory: {$stats['avg_mem_bytes']} bytes\n";

Wm::clearMetrics();
```

## Notas Importantes

- **W es una PoC**: Está diseñada para experimentación y benchmarking
- **No reemplaza SQL.php completamente**: Carece de algunas features avanzadas
- **El estado NO es thread-safe**: Usar una instancia por consulta
- **Las constantes de 4 caracteres** son un balance entre legibilidad y performance

---

*Documento creado para preservar conocimiento entre sesiones de chat.*
*Última actualización: 2024*
