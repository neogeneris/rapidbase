# PostgreSQL Performance Tests for RapidBase

Este directorio contiene pruebas de rendimiento para evaluar el desempeño de **RapidBase** trabajando con **PostgreSQL** como motor de base de datos.

## 📋 Descripción

Las pruebas miden tiempos de ejecución para diferentes tipos de operaciones, desde consultas simples hasta operaciones complejas, permitiendo identificar cuellos de botella y comparar el rendimiento entre diferentes tipos de operaciones.

## 🎯 Pruebas Realizadas

### 1. INSERTs Simples
- **INSERT individual**: Inserción de un solo registro
- **INSERT batch (100 registros)**: Inserción masiva de 100 registros

### 2. SELECTs Simples
- **SELECT * (sin WHERE)**: Lectura completa de hasta 1000 registros
- **SELECT columnas específicas**: Lectura selectiva de columnas
- **SELECT COUNT(*)**: Conteo total de registros

### 3. WHERE y ORDER BY
- **WHERE simple**: Filtrado por una condición
- **WHERE múltiple (AND)**: Filtrado por múltiples condiciones
- **ORDER BY DESC**: Ordenamiento descendente
- **WHERE + ORDER BY + LIMIT**: Combinación de filtrado, ordenamiento y limitación

### 4. JOINs
- **INNER JOIN (2 tablas)**: Unión básica entre dos tablas
- **JOIN múltiple (3 tablas)**: Unión entre tres tablas
- **JOIN + WHERE**: Unión con filtrado

### 5. Agregaciones
- **COUNT + GROUP BY**: Agrupamiento con conteo
- **SUM + GROUP BY**: Agrupamiento con sumatoria
- **AVG, MIN, MAX**: Funciones agregadas estadísticas
- **GROUP BY + HAVING**: Agrupamiento con filtrado post-agregación

### 6. Subconsultas y CTEs
- **Subquery en WHERE**: Subconsulta en cláusula WHERE
- **CTE (WITH clause)**: Expresiones de tabla comunes
- **Subquery correlacionada**: Subconsulta dependiente de la consulta externa

### 7. Operaciones Masivas
- **Bulk UPDATE**: Actualización masiva de registros
- **Bulk DELETE**: Eliminación masiva de registros
- **UPSERT (ON CONFLICT)**: Inserción con actualización en conflicto

### 8. Consultas Complejas
- **Query compleja (reporte de ventas)**: Reporte con múltiples JOINs, agregaciones y filtros
- **Window Function (RANK)**: Funciones de ventana para ranking

## 🚀 Ejecución

```bash
php tests/Performance/PostgreSQL/PostgreSQLPerformanceTest.php
```

### Requisitos Previos

1. PostgreSQL instalado y ejecutándose
2. Base de datos `rapidbase_test` creada
3. Usuario `rapidbase_user` con contraseña `rapidbase_pass`
4. Composer instalado para autoload

### Configuración de PostgreSQL

```sql
-- Crear base de datos
CREATE DATABASE rapidbase_test;

-- Crear usuario
CREATE USER rapidbase_user WITH PASSWORD 'rapidbase_pass';

-- Otorgar permisos
GRANT ALL PRIVILEGES ON DATABASE rapidbase_test TO rapidbase_user;
```

## 📊 Interpretación de Resultados

El reporte muestra:

1. **Tiempos individuales**: Cada prueba muestra su tiempo promedio en milisegundos
2. **Tabla resumen**: Todas las operaciones ordenadas con su tipo (READ/WRITE)
3. **Estadísticas generales**:
   - Total de pruebas ejecutadas
   - Promedio general de todos los tests
   - Operación más rápida
   - Operación más lenta
   - Factor de desviación (cuántas veces más lenta es la operación más lenta vs la más rápida)

### Métricas Clave

- **< 1ms**: Excelente rendimiento
- **1-5ms**: Buen rendimiento
- **5-50ms**: Rendimiento aceptable para operaciones complejas
- **> 50ms**: Puede requerir optimización (índices, query tuning, etc.)

## 🔍 Factores que Afectan el Rendimiento

1. **Índices**: La falta de índices en columnas de filtrado/join puede degradar el rendimiento
2. **Volumen de datos**: Los tiempos pueden variar significativamente con más registros
3. **Hardware**: CPU, RAM y tipo de almacenamiento (SSD vs HDD)
4. **Configuración de PostgreSQL**: shared_buffers, work_mem, etc.
5. **Concurrencia**: Otras conexiones activas pueden afectar los tiempos

## 💡 Optimizaciones Recomendadas

Basado en los resultados, considera:

1. **Índices estratégicos**: En columnas usadas frecuentemente en WHERE y JOIN
2. **Query optimization**: Usar EXPLAIN ANALYZE para identificar cuellos de botella
3. **Connection pooling**: Para aplicaciones con muchas conexiones concurrentes
4. **Caching**: Implementar caché para consultas frecuentes
5. **Batch operations**: Preferir inserciones/actualizaciones masivas sobre operaciones individuales

## 📈 Comparativa con SQLite3

Para comparar el rendimiento entre PostgreSQL y SQLite3:

1. Ejecutar este test con PostgreSQL
2. Ejecutar test equivalente con SQLite3
3. Comparar:
   - Tiempos promedio por tipo de operación
   - Escalabilidad con mayor volumen de datos
   - Características exclusivas (window functions, CTEs, JSONB, etc.)

## 📝 Notas

- Los tiempos pueden variar entre ejecuciones debido a caching del sistema
- Se recomienda ejecutar múltiples veces y promediar los resultados
- El test limpia y recrea las tablas en cada ejecución
- Los datos son generados aleatoriamente para cada corrida

## 🔗 Recursos Adicionales

- [Documentación de PostgreSQL](https://www.postgresql.org/docs/)
- [EXPLAIN Visualizer](https://explain.depesz.com/)
- [PostgreSQL Performance Tuning](https://wiki.postgresql.org/wiki/Performance_Optimization)
