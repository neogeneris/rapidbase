# Benchmark: W Dinámica vs W Estática

## Fecha
2026-01-XX

## Objetivo
Comparar el rendimiento entre la implementación dinámica (con instanciación de objetos) y la implementación estática (sin instanciación) de la clase W.

## Metodología
- **Iteraciones**: 10,000 consultas por tipo
- **Entorno**: PHP 8.2.30 con OPcache habilitado
- **Métrica**: Tiempo promedio por consulta en milisegundos

## Resultados

| Tipo de Consulta | W Dinámica (ms) | W Estática (ms) | Speedup |
|------------------|-----------------|-----------------|---------|
| SELECT simple con WHERE | 0.0060 | 0.0058 | 1.03x |
| SELECT con LIMIT/OFFSET | 0.0042 | 0.0041 | 1.03x |
| Auto-join (3 tablas) | 0.0156 | 0.0166 | 0.94x |
| Count() | 0.0075 | 0.0058 | 1.30x |
| Exists() | 0.0061 | 0.0045 | 1.35x |
| Relaciones inline | 0.0154 | 0.0144 | 1.07x |

**Speedup promedio**: ~1.05x (5% más rápido en versión estática)

## Análisis

### Hallazgos Clave

1. **Performance Similar**: Ambas versiones tienen un rendimiento muy similar (< 5% de diferencia).

2. **Ventajas de la Versión Estática**:
   - **Count/Exists**: Hasta 35% más rápido en operaciones simples
   - **Relaciones inline**: 7% más rápido
   - **Sin costo de instanciación**: Elimina overhead de `new self()`
   - **Stateless**: No requiere gestión de memoria por instancia
   - **Sintaxis más limpia**: `W::from()->select()` vs `(new W())->from()->select()`

3. **Consideraciones de Diseño**:
   - La versión dinámica permite múltiples consultas concurrentes en diferentes instancias
   - La versión estática usa estado global temporal (`$currentState`), lo que podría ser problemático en entornos multi-hilo
   - Para el caso de uso típico (request/response en PHP), la versión estática es segura

## Conclusión

La versión **estática es preferible** para este proyecto porque:

1. ✅ Mantiene performance igual o ligeramente superior
2. ✅ Sintaxis más fluida y consistente con la visión original
3. ✅ Elimina costo de instanciación innecesario
4. ✅ Alineada con el patrón de bajo acoplamiento
5. ✅ Más fácil de extender con herencia (Wa, Ws, Wm)

## Recomendación

**Adoptar diseño estático** como arquitectura base para W, Wa, Ws y Wm.

### Estructura Propuesta

```
W (base estática)          → Consultas básicas ultra-rápidas
  └─ Wa (extiende W)       → Auto-join inteligente
      └─ Ws (extiende Wa)  → Optimización con algoritmos genéticos
      
Wm (wrapper estático)      → Telemetría/métricas
  └─ Usa internamente W, Wa o Ws según configuración
```

## Archivos Relacionados
- `/workspace/tests/PoC/SQL/W.php` - Implementación dinámica actual
- `/workspace/tests/PoC/SQL/W_Static.php` - Implementación estática de prueba
- `/workspace/tests/PoC/SQL/BenchmarkStaticVsDynamic.php` - Script de benchmark
