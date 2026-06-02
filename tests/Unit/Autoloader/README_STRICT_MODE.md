# Modo Estricto del Autoloader

## Resumen

Se ha implementado un **modo estricto** configurable para el Autoloader que permite diferenciar el comportamiento entre entornos de desarrollo y producción.

## Implementación

### Nueva Propiedad
```php
private bool $strictMode = false;
```

### Nuevo Método Público
```php
/**
 * Establece el modo estricto para el autoloader.
 * 
 * En modo estricto (desarrollo), el autoloader lanza una excepción cuando no encuentra una clase.
 * En modo normal (producción), retorna false silenciosamente permitiendo que otros autoloaders intenten cargarla.
 */
public function setStrictMode(bool $strict = true): self
```

### Comportamiento Modificado en `loadClass()`

**Modo Producción (default, strictMode = false):**
- Retorna `false` cuando no encuentra una clase
- Permite que `spl_autoload_register` continúe con otros autoloaders registrados
- Ideal para coexistir con Composer u otros autoloaders
- No muestra errores al usuario final

**Modo Desarrollo (strictMode = true):**
- Lanza una excepción `RuntimeException` con mensaje descriptivo
- Mensaje: `"Autoloader no pudo encontrar la clase: {ClassName}. Verifica que el archivo exista y esté en uno de los directorios registrados."`
- Detiene la ejecución inmediatamente para alertar al desarrollador
- Facilita debugging de clases faltantes

## Uso

### En Producción (comportamiento por defecto)
```php
$autoloader = Autoloader::getInstance('/path/to/base');
$autoloader->register();
// Si una clase no existe, retorna false y permite fallback a Composer
```

### En Desarrollo
```php
$autoloader = Autoloader::getInstance('/path/to/base');
$autoloader->setStrictMode(true);  // ← Activar modo estricto
$autoloader->enableDebug(true);    // Opcional: más detalles
$autoloader->register();
// Si una clase no existe, lanza excepción inmediatamente
```

### Configuración Híbrida Recomendada
```php
$isDevelopment = $_ENV['APP_ENV'] === 'development';

$autoloader = Autoloader::getInstance($basePath);
$autoloader
    ->setStrictMode($isDevelopment)
    ->enableDebug($isDevelopment)
    ->register();
```

## Tests

Los tests están en `/workspace/tests/Unit/Autoloader/StrictModeTest.php` e incluyen:

1. ✅ `testProductionModeReturnsFalseWhenClassNotFound` - Verifica que en producción retorna false
2. ✅ `testStrictModeThrowsExceptionWhenClassNotFound` - Verifica que en desarrollo lanza excepción
3. ✅ `testStrictModeLoadsExistingClassesSuccessfully` - Verifica que carga clases existentes en modo estricto
4. ✅ `testStrictModeIsConfigurableAndPersistent` - Verifica que el modo es configurable
5. ✅ `testProductionModeAllowsFallbackToOtherAutoloaders` - Verifica que permite fallback
6. ✅ `testStrictModeDoesNotAllowFallback` - Verifica que en modo estricto no hay fallback

Ejecutar tests:
```bash
./vendor/bin/phpunit tests/Unit/Autoloader/StrictModeTest.php
```

## Ventajas

### Para Desarrollo
- 🔍 **Detección temprana de errores**: Sabes inmediatamente cuando una clase falta
- 📝 **Mensajes claros**: La excepción indica exactamente qué clase falta y qué verificar
- 🚫 **Fail-fast**: Evita errores cascada por clases mal referenciadas

### Para Producción
- 🔄 **Interoperabilidad**: Funciona junto a Composer y otros autoloaders
- 🤫 **Silencioso**: No muestra errores a usuarios finales
- ⚡ **Performance**: Sin overhead adicional (solo un check booleano)

## Consideraciones de Diseño

1. **Default seguro**: El modo producción es el default, evitando problemas en deploy
2. **No breaking change**: Código existente continúa funcionando sin modificaciones
3. **Interface fluida**: Método retorna `$this` para chaining
4. **Separation of concerns**: La lógica de excepción está aislada al final de `loadClass()`

## Ejemplo de Excepción

```
Fatal error: Uncaught RuntimeException: 
Autoloader no pudo encontrar la clase: App\Services\PaymentService. 
Verifica que el archivo exista y esté en uno de los directorios registrados.
```

## Compatibilidad

- ✅ PHP 8.0+
- ✅ Compatible con PSR-4
- ✅ Funciona con spl_autoload_register
- ✅ Coexiste con Composer autoloader
- ✅ Sin dependencias externas
