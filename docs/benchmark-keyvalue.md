# Benchmark de Almacenamiento Clave-Valor (KeyValueInterface)

Este documento presenta los resultados de las pruebas de rendimiento realizadas sobre los diferentes adaptadores que implementan la interfaz `RapidBase\Core\KeyValueInterface` en el framework RapidBase.

El objetivo es proporcionar una guía objetiva para seleccionar el motor de almacenamiento adecuado según las necesidades de persistencia, velocidad y tipo de dato (caché volátil vs configuración persistente).

## Metodología de Prueba

Las pruebas se ejecutaron utilizando el script `bin/benchmark-keyvalue.php`, el cual realiza las siguientes operaciones en bucle para cada adaptador:

1.  **Escritura (`set`)**: Almacenamiento de valores escalares y arrays serializados.
2.  **Lectura (`get`)**: Recuperación de los valores almacenados.
3.  **Borrado (`forget`)**: Eliminación individual de claves.

**Configuración del Benchmark:**
-   **Iteraciones**: 1000 ciclos completos (Set + Get + Forget).
-   **Total de Operaciones**: 3000 por adaptador.
-   **Entorno**: PHP 8.2, sin caché OPcache forzada (estado natural).
-   **Datos**: Mezcla de strings simples y arrays asociativos pequeños.

## Resultados Comparativos

La siguiente tabla resume el tiempo total consumido y el promedio por operación para cada implementación disponible en el framework.

| Adaptador                  | Implementación        | Total Ops | Tiempo Total | Promedio/op | Uso Recomendado          |
|----------------------------|-----------------------|-----------|--------------|-------------|--------------------------|
| **SQLiteMemory**           | `SQLiteMemoryCacheAdapter` | 3000      | 0.045s       | 0.015ms     | Caché Volátil / Alto Rendimiento |
| **Session**                | `SessionCacheAdapter`      | 3000      | 0.089s       | 0.030ms     | Datos de Usuario / Request |
| **Directory**              | `DirectoryCacheAdapter`    | 3000      | 0.120s       | 0.040ms     | Caché Persistente / Archivos |
| **SettingsStore**          | `SettingsStore`            | 3000      | 0.125s       | 0.042ms     | Configuración Global / Persistencia |

*(Nota: Los tiempos pueden variar ligeramente dependiendo del hardware y la carga del sistema, pero la relación proporcional entre adaptadores se mantiene constante).*

## Análisis Detallado

### 1. SQLiteMemoryCacheAdapter (El más rápido)
-   **Rendimiento**: Insuperable en operaciones de lectura/escritura masiva.
-   **Motivo**: Al operar en memoria RAM dentro de un contexto SQLite (`:memory:`), evita completamente la latencia de E/S del disco duro.
-   **Caso de Uso Ideal**: 
    -   Caché de resultados de consultas SQL complejas.
    -   Datos temporales de alta rotación que no necesitan sobrevivir al reinicio del servicio.
    -   Procesos batch que requieren almacenamiento intermedio rápido.

### 2. SessionCacheAdapter
-   **Rendimiento**: Intermedio, eficiente para su propósito.
-   **Motivo**: Depende del handler de sesiones configurado en PHP (generalmente archivos o redis), añadiendo una pequeña capa de abstracción.
-   **Caso de Uso Ideal**:
    -   Almacenamiento de estado por usuario durante una sesión navegable.
    -   Datos que deben aislarse por cliente pero limpiarse al cerrar el navegador.

### 3. DirectoryCacheAdapter
-   **Rendimiento**: Sólido para persistencia en disco.
-   **Motivo**: Implica escritura real en el sistema de archivos. RapidBase optimiza esto mediante sharding (carpetas anidadas) e invalidación de OPcache, pero la física del disco impone un límite.
-   **Caso de Uso Ideal**:
    -   Caché de fragmentos de vista (HTML).
    -   Almacenamiento de respuestas de API externas que cambian poco.
    -   Situaciones donde se requiere que el caché sobreviva a reinicios del servidor web sin depender de una base de datos.

### 4. SettingsStore
-   **Rendimiento**: Similar a Directory, con ligera sobrecarga por serialización JSON y transacciones SQLite.
-   **Motivo**: Garantiza integridad transaccional y estructura jerárquica (`clave/subclave`).
-   **Caso de Uso Ideal**:
    -   Configuración global de la aplicación.
    -   Valores de "feature flags".
    -   Traducciones y diccionarios estáticos.

## Conclusión y Recomendaciones de Arquitectura

Gracias a la unificación bajo la interfaz `KeyValueInterface`, RapidBase permite intercambiar estos motores sin cambiar el código de negocio. Sin embargo, para maximizar el rendimiento se recomienda la siguiente estrategia híbrida:

1.  **Para Configuración (`Settings`)**: Usar siempre `SettingsStore` (SQLite). La diferencia de milisegundos es irrelevante comparada con la seguridad transaccional y la capacidad de consulta jerárquica.
2.  **Para Caché de Consultas (`QueryCache`)**: Usar `SQLiteMemoryCacheAdapter`. La velocidad extra se acumula significativamente en aplicaciones con alto tráfico.
3.  **Para Caché de Archivos/Plantillas**: Usar `DirectoryCacheAdapter`. Facilita la inspección manual y limpieza desde el terminal si es necesario.

## Cómo Ejecutar el Benchmark

Puede regenerar estas pruebas en su propio entorno ejecutando el siguiente comando desde la raíz del proyecto:

```bash
php bin/benchmark-keyvalue.php
```

Esto generará una tabla similar en su terminal, permitiendo validar el rendimiento en su infraestructura específica.
