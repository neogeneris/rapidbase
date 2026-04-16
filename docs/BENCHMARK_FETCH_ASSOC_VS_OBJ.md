# 📊 Benchmark: FETCH_ASSOC vs FETCH_OBJ

## Resumen Ejecutivo

Se compararon los modos de fetch de PDO `FETCH_ASSOC` (array asociativo) vs `FETCH_OBJ` (objeto stdClass) para determinar cuál ofrece mejor rendimiento en RapidBase.

**Referencia:** https://www.php.net/manual/es/pdostatement.fetch.php

---

## 🔬 Metodología

- **Iteraciones:** 1000 por test
- **Base de datos:** SQLite en memoria
- **Datos:** 200 productos, 20 categorías
- **Escenarios:** 4 tests diferentes

### Tests Realizados

1. **Consulta Simple:** 1 tabla, 10 columnas, 100 rows
2. **Consulta con JOINs:** 2 tablas, 7 columnas, 100 rows
3. **Acceso Repetido:** 10 accesos por fila a propiedades/columnas
4. **fetchAll():** Carga masiva de datos

---

## 📈 Resultados

| Test | FETCH_ASSOC | FETCH_OBJ | Diferencia | Ganador |
|------|-------------|-----------|------------|---------|
| **Consulta Simple** | 0.3688 ms/op | 0.4348 ms/op | **+17.91%** (OBJ más lento) | ✅ ASSOC |
| **JOINs (2 tablas)** | 0.3183 ms/op | 0.3000 ms/op | **-5.75%** (OBJ más rápido) | ⚡ OBJ |
| **Acceso Repetido (10x)** | 0.1432 ms/op | 0.1396 ms/op | **-2.54%** (OBJ más rápido) | ⚡ OBJ |
| **fetchAll() Masivo** | 0.2164 ms/op | 0.1982 ms/op | **-8.43%** (OBJ más rápido) | ⚡ OBJ |

### Promedio General
```
+0.30% (prácticamente empate técnico)
```

---

## 🎯 Análisis Detallado

### 1. Consulta Simple - Gana FETCH_ASSOC (+17.91%)
En consultas simples sin JOINs, la creación de objetos stdClass tiene un overhead notable. Los arrays asociativos son más ligeros cuando hay pocas operaciones de acceso.

### 2. JOINs - Gana FETCH_OBJ (-5.75%)
Cuando hay múltiples columnas de diferentes tablas, especialmente con aliases (`c.name as category_name`), el acceso por propiedad (`$obj->category_name`) es ligeramente más eficiente que el acceso por clave de array (`$row['category_name']`).

### 3. Acceso Repetido - Gana FETCH_OBJ (-2.54%)
Con 10 accesos por fila, el objeto muestra ventaja porque:
- El acceso a propiedades de objeto está optimizado en PHP 8+
- No hay hashing de strings como en arrays asociativos

### 4. fetchAll() Masivo - Gana FETCH_OBJ (-8.43%)
En cargas masivas, la diferencia se amplía:
- Menor uso de memoria para estructuras de objeto
- Mejor cacheabilidad de propiedades

---

## 💡 Conclusiones

### Hallazgo Principal
**Contrario a la intuición inicial**, `FETCH_OBJ` no es significativamente más lento. De hecho:
- En escenarios complejos (JOINs, múltiples accesos, cargas masivas) es **ligeramente más rápido**
- Solo en consultas simples es más lento (~18%)
- El promedio general es prácticamente **empate técnico** (+0.30%)

### Recomendación para RapidBase

#### ✅ MANTENER `FETCH_ASSOC` como default por:

1. **Consistencia:** Todo el código base actual usa arrays
2. **Compatibilidad:** Los arrays son más flexibles para manipulación
3. **Legibilidad:** `$row['column']` es más explícito que `$obj->column`
4. **Testing:** Menor riesgo de breaking changes

#### ⚠️ Considerar `FETCH_OBJ` solo si:

- Se migra a un modelo orientado a objetos completo
- Se implementan Data Transfer Objects (DTOs)
- Hay cuellos de botella específicos en consultas complejas con muchos JOINs

---

## 📝 Estado Actual de RapidBase

Revisión del código base confirma que **todo usa `PDO::FETCH_ASSOC`**:

```php
// src/RapidBase/Core/Executor.php
while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) { }

// src/RapidBase/Core/Gateway.php
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

// src/RapidBase/Core/DB.php
return $stmt->fetch(PDO::FETCH_ASSOC);
return $stmt->fetchAll(PDO::FETCH_ASSOC);

// src/RapidBase/Core/Conn.php
\\PDO::ATTR_DEFAULT_FETCH_MODE => \\PDO::FETCH_ASSOC
```

---

## 🚀 Impacto Potencial

Si se cambiara a `FETCH_OBJ`:
- **Mejora estimada:** 2-8% en consultas complejas
- **Penalización:** ~18% en consultas simples
- **Costo de migración:** ALTO (cambiar todo el código base)
- **Riesgo:** MEDIO-ALTO (posibles bugs por cambio de sintaxis)

**Veredicto:** ❌ **NO CAMBIAR** - El costo/beneficio no justifica la migración.

---

## 📁 Archivos Generados

- `tests/Performance/FetchAssocVsObj.php` - Script de benchmark completo
- `docs/BENCHMARK_FETCH_ASSOC_VS_OBJ.md` - Este documento

---

*Fecha del benchmark: 2024*
*PHP Version: 8.x*
*Base de datos: SQLite in-memory*
