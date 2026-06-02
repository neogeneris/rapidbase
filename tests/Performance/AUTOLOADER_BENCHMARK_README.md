# Autoloader Storage Benchmark - Resultados y Análisis

## Resumen Ejecutivo

Se realizaron dos benchmarks comparando las técnicas de persistencia para el cache del Autoloader:

1. **Enfoque Actual**: Archivo único `.dat` con `serialize()` / `unserialize()`
2. **Enfoque Alternativo**: Archivos PHP shardados con `var_export()` / `include` (DirectoryCacheAdapter)

---

## Benchmark 1: Storage Technique Comparison

### Metodología
- Compara escritura y lectura de arrays con 100, 500, 1000 y 5000 elementos
- Mide tiempo de operación y uso de memoria
- Considera efecto de OPcache en lecturas subsecuentes

### Resultados Clave

| Tamaño | Write (Serialize) | Write (PHP Include) | Read (Unserialize) | Read (PHP Include + OPcache) |
|--------|------------------|---------------------|--------------------|------------------------------|
| 100    | 0.11 ms          | 0.09 ms             | 0.01 ms            | 0.16 ms                      |
| 500    | 0.17 ms          | 0.24 ms             | 0.05 ms            | 0.40 ms                      |
| 1000   | 2.05 ms          | 0.45 ms             | 0.19 ms            | 0.82 ms                      |
| 5000   | 11.75 ms         | 3.80 ms             | 1.20 ms            | 5.15 ms                      |

### Conclusiones Benchmark 1

**Escritura:**
- PHP Include es **67.5% más rápido** en promedio para escribir
- La diferencia se amplía con datasets grandes (>1000 elementos)
- Serialize usa más memoria en operaciones grandes

**Lectura:**
- Unserialize es **77.7% más rápido** que PHP Include con OPcache
- Esto es CONTRA-INTUITIVO pero medible
- La razón: `unserialize()` de un string en memoria es más directo que el parsing de PHP + include

---

## Benchmark 2: Real-World Scenario

### Metodología
- Simula 100 peticiones HTTP reales
- Cada petición carga ~30 clases
- Cache con 500 clases totales
- Comporta-miento real del Autoloader (cargar cache completo vs lookup individual)

### Resultados

| Métrica                  | Single .dat file | DirectoryCacheAdapter | Winner              |
|--------------------------|------------------|-----------------------|---------------------|
| **Total Read Time**      | 22.82 ms         | 0.82 ms               | DirectoryCache (2,680% faster!) |
| **Per-request Avg**      | 0.23 ms          | 0.008 ms              | DirectoryCache      |
| **Write (single class)** | 0.35 ms          | 0.16 ms               | DirectoryCache (125% faster) |
| **Disk Space**           | 68.95 KB         | 86.21 KB              | DAT (más compacto)  |

### ¿Por qué DirectoryCache es TÁN más rápido en el escenario real?

La clave está en el **patrón de acceso**:

1. **Single .dat file**: 
   - Carga TODO el archivo serializado en CADA petición (aunque solo use 30 clases de 500)
   - overhead: O(n) donde n = total de clases en cache
   
2. **DirectoryCacheAdapter**:
   - Solo carga los archivos de las clases que realmente necesita
   - Usa L1 cache en memoria durante la petición
   - overhead: O(k) donde k = clases usadas en esta petición (k << n)

---

## Recomendaciones

### Para Proyectos Pequeños/Medianos (<1000 clases)

**→ El enfoque actual (.dat) es ACEPTABLE**

- La diferencia absoluta es <1ms por petición
- Más simple de implementar y mantener
- Un solo archivo fácil de deployar
- Menor complejidad

### Para Proyectos Grandes (>1000 clases) o Alto Tráfico

**→ Migrar a DirectoryCacheAdapter**

- Mejora de performance dramática (27x en nuestro test)
- Escala linealmente sin degradación
- Permite invalidación selectiva (ej: limpiar cache de un módulo específico)
- Mejor para entornos distribuidos

---

## Tercera Opción: Arquitectura Híbrida (RECOMENDADA)

El Autoloader podría aceptar **cualquier adapter** que implemente `KeyValueWriterInterface`:

```php
// Opción 1: Mantener enfoque actual
$autoloader->setCache(new SimpleDatCache($basePath));

// Opción 2: Usar DirectoryCache para producción
$autoloader->setCache(new DirectoryCacheAdapter($cachePath));

// Opción 3: Redis para entornos distribuidos
$autoloader->setCache(new RedisCacheAdapter(['host' => 'redis']));
```

### Ventajas de esta arquitectura:

1. **Flexibilidad**: Cada proyecto elige según sus necesidades
2. **Testabilidad**: Fácil mockear el cache en tests unitarios
3. **Evolución**: Se pueden agregar nuevos adapters sin tocar el Autoloader
4. **Contrato claro**: `KeyValueWriterInterface` define exactamente lo necesario

### Interfaz Requerida

```php
interface KeyValueWriterInterface extends KeyValueReaderInterface
{
    public function set(string $key, mixed $value): void;
    public function delete(string $key): void;
    public function clear(): void;
}

interface KeyValueReaderInterface
{
    public function get(string $key, mixed $default = null): mixed;
    public function has(string $key): bool;
}
```

El Autoloader actualmente usa:
- `get(string $key): mixed` ✓
- `set(string $key, mixed $value): void` ✓
- `flush(): void` → equivalente a `clear()` ✓

**¡Ya es compatible!** Solo necesita un wrapper pequeño o adaptar el método `flush()` a `clear()`.

---

## Plan de Acción Sugerido

### Fase 1: Crear Adapter Wrapper (1-2 horas)
```php
class AutoloaderCacheAdapter implements KeyValueWriterInterface
{
    // Wrapper alrededor del cache interno actual del Autoloader
    // o delegar directamente a DirectoryCacheAdapter
}
```

### Fase 2: Modificar Autoloader para Inyección (2-3 horas)
```php
// En lugar de initDefaultCache() hardcodeado
public function setCache(KeyValueWriterInterface $cache): self
{
    $this->cache = $cache;
    return $this;
}
```

### Fase 3: Tests y Validación (2-3 horas)
- Unit tests para cada adapter
- Integration tests con el Autoloader
- Performance tests (ya creados)

### Fase 4: Documentación y Migración (1 hora)
- Documentar cómo elegir adapter
- Guía de migración para proyectos existentes
- Ejemplos de configuración

---

## Archivos Creados

1. `/tests/Performance/AutoloaderStorageBenchmark.php`
   - Compara serialize() vs var_export() a nivel de almacenamiento

2. `/tests/Performance/AutoloaderRealWorldBenchmark.php`
   - Simula escenario real con múltiples peticiones HTTP

3. `/tests/Performance/AUTOLOADER_BENCHMARK_README.md` (este archivo)
   - Documentación completa de resultados

---

## Cómo Ejecutar los Benchmarks

```bash
# Benchmark 1: Técnicas de almacenamiento
php tests/Performance/AutoloaderStorageBenchmark.php

# Benchmark 2: Escenario real
php tests/Performance/AutoloaderRealWorldBenchmark.php
```

### Requisitos
- PHP 8.2+ (ya instalado)
- SQLite (ya instalado)
- OPcache habilitado (ya habilitado)

---

## Próximos Pasos (si decides continuar)

1. **Revisar contracts existentes** en `/src/RapidBase/Core/Contracts/`
   - `KeyValueReaderInterface` ✓
   - `KeyValueWriterInterface` ✓
   - `KeyValueInterface`
   - `CacheInterface` (extiende Writer + TTL)

2. **Decidir estrategia**:
   - ¿Mantener .dat para simplicidad?
   - ¿Migrar a DirectoryCache para performance?
   - ¿Implementar arquitectura híbrida con inyección?

3. **Si hybrid approach**:
   - Crear wrapper adapter para el cache actual
   - Refactorizar Autoloader para aceptar inyección
   - Tests de regresión

---

## Conclusión Final

**Los benchmarks muestran claramente que DirectoryCacheAdapter es superior en escenarios reales**, especialmente para proyectos grandes. Sin embargo, la arquitectura de inyección de dependencias ofrece lo mejor de ambos mundos: flexibilidad para elegir según el caso de uso específico.

**Recomendación personal**: Implementar la arquitectura híbrida. El esfuerzo es mínimo (4-8 horas) y los beneficios son significativos en términos de flexibilidad, testabilidad y performance opcional.
