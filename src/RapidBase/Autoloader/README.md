# RapidBase Autoloader

Un autoloader de alto rendimiento basado en caché serializada (.dat) diseñado para proyectos PHP modernos. Soporta inyección de dependencias, modo estricto para desarrollo y configuración flexible de rutas.

## Características Principales

- **Persistencia Rápida**: Usa archivos `.dat` serializados para lecturas ultra-rápidas en frío.
- **Inyección de Dependencias**: Compatible con cualquier implementación de `KeyValueWriterInterface`.
- **Modo Estricto**: Lanzamiento de excepciones en desarrollo para detectar clases faltantes temprano.
- **Estadísticas**: Registro automático de hits, misses y tiempo de carga.
- **Configurable**: Rutas de caché personalizables y soporte para múltiples directorios base.

## Instalación Básica

```php
use RapidBase\Autoloader\Autoloader;

$basePath = '/var/www/my-project';

// Obtener instancia única
$autoloader = Autoloader::getInstance($basePath);

// Registrar en SPL
$autoloader->register();
```

## Configuración Avanzada

### 1. Entorno de Desarrollo vs Producción

El comportamiento cambia drásticamente según el entorno:

```php
$isDev = $_ENV['APP_ENV'] === 'development';

$autoloader = Autoloader::getInstance($basePath);

if ($isDev) {
    // Modo estricto: Lanza excepción si no encuentra la clase
    $autoloader->setStrictMode(true);
    
    // Debug: Muestra logs detallados de carga
    $autoloader->enableDebug(true);
} else {
    // Modo producción: Retorna false silenciosamente (fallback a otros autoloaders)
    $autoloader->setStrictMode(false);
    $autoloader->enableDebug(false);
}

$autoloader->register();
```

### 2. Cambiar Directorio de Caché

Por defecto, los archivos `.dat` se guardan en `$basePath`. Puedes cambiarlo:

```php
$customCachePath = '/tmp/my-app-cache';

$autoloader = Autoloader::getInstance($basePath);
$autoloader->setCacheDirectory($customCachePath);
$autoloader->register();
```

> **Nota**: El directorio debe existir y tener permisos de escritura.

### 3. Inyección de Adaptador Personalizado

Puedes reemplazar el mecanismo de persistencia interno usando un adaptador que implemente `KeyValueWriterInterface`:

```php
use RapidBase\Core\Cache\Adapters\DirectoryCacheAdapter;

$adapter = new DirectoryCacheAdapter('/path/to/cache');

$autoloader = Autoloader::getInstance($basePath);
$autoloader->setCache($adapter); // Inyecta tu propia lógica de persistencia
$autoloader->register();
```

## API Reference

| Método | Descripción | Retorno |
|--------|-------------|---------|
| `getInstance(string $basePath)` | Obtiene la instancia singleton | `Autoloader` |
| `register()` | Registra el autoloader en SPL | `self` |
| `unregister()` | Desregistra el autoloader | `self` |
| `setStrictMode(bool $mode)` | Activa modo excepción (dev) o fallback (prod) | `self` |
| `enableDebug(bool $enable)` | Activa logs detallados en consola | `self` |
| `setCacheDirectory(string $path)` | Define ruta personalizada para archivos .dat | `self` |
| `getCacheDirectory()` | Obtiene la ruta actual de caché | `string` |
| `setCache(KeyValueWriterInterface $cache)` | Inyecta adaptador personalizado | `self` |
| `flushCache()` | Limpia la caché interna y el archivo .dat | `self` |
| `getStats()` | Obtiene estadísticas de rendimiento | `array` |

## Flujo de Funcionamiento

1. **Registro**: `spl_autoload_register` apunta a `loadClass()`.
2. **Búsqueda**:
   - Revisa memoria RAM (L1 Cache).
   - Si no está, revisa archivo `.dat` (Persistencia).
   - Si no está, escanea filesystem según PSR-4/0 configurado.
3. **Persistencia**:
   - Al encontrar una clase nueva, la guarda en RAM.
   - El método `persist()` (llamado automáticamente o manual) escribe en disco.
4. **Fallback**:
   - Si `strictMode = false` y no encuentra la clase → retorna `false` (siguiente autoloader).
   - Si `strictMode = true` y no encuentra → lanza `RuntimeException`.

## Mejores Prácticas

- **Producción**: Mantener `strictMode = false` para permitir que Composer u otros autoloaders actúen como respaldo.
- **Desarrollo**: Usar `strictMode = true` para evitar errores silenciosos por typos en namespaces.
- **Cache**: En servidores con mucho tráfico, considerar usar un adaptador externo (ej: Redis) inyectado vía `setCache()`.

## Archivos Generados

El autoloader crea dos archivos en el directorio de caché:
1. `autoloader_cache.dat`: Mapa de clases -> rutas de archivo.
2. `autoloader_stats.dat`: Estadísticas de uso (hits, misses, tiempo promedio).
