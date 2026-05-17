# RapidBase Core TDD Framework

## Descripción

Este directorio contiene las pruebas unitarias del Core de RapidBase (clases X, Gateway, Q) utilizando nuestro propio framework TDD.

## Estructura

```
tests/Unit/Core/
├── core-runner.php           # CLI Runner para ejecutar pruebas
├── rapidbase_core_tdd.sqlite # Base de datos de historial (auto-generada)
├── X/                        # Pruebas de la clase X
│   ├── bootstrap.php         # Carga de dependencias
│   ├── NewTests/             # Nuevas pruebas con el framework TDD
│   │   └── XTest.php         # Suite completa de pruebas para X
│   └── *Test.php             # Pruebas legacy (compatibilidad)
├── Gateway/                  # Pruebas de la clase Gateway
└── Q/                        # Pruebas de la clase Q
```

## Uso del CoreRunner

### Ejecutar todas las pruebas

```bash
php tests/Unit/Core/core-runner.php --all -v
```

### Ejecutar pruebas de una categoría específica

```bash
php tests/Unit/Core/core-runner.php --category X -v
php tests/Unit/Core/core-runner.php --category Gateway -v
php tests/Unit/Core/core-runner.php --category Q -v
```

### Modo TDD: detenerse en el primer fallo

```bash
php tests/Unit/Core/core-runner.php --first
```

### Re-ejecutar solo las pruebas que fallaron

```bash
php tests/Unit/Core/core-runner.php --failing -v
```

### Ver estadísticas

```bash
php tests/Unit/Core/core-runner.php --stats
```

### Ver historial de ejecuciones

```bash
php tests/Unit/Core/core-runner.php --history 20
```

## Crear nuevas pruebas

### 1. Crear archivo de test

Crea un nuevo archivo en `tests/Unit/Core/X/NewTests/` siguiendo esta estructura:

```php
<?php

namespace RapidBase\Tests\X;

use RapidBase\Core\X;
use RapidBase\Core\DB;
use RapidBase\Core\Conn;

class MiNuevoTest {
    private X $x;
    private string $connectionId = 'mi_test';
    
    public function setUp(): void {
        // Setup de base de datos
        DB::setup('sqlite::memory:', '', '', $this->connectionId);
        $pdo = Conn::get($this->connectionId);
        
        // Crear tablas de prueba
        $pdo->exec("CREATE TABLE mi_tabla (...)");
        
        $this->x = X::con($this->connectionId);
    }
    
    public function tearDown(): void {
        // Limpieza si es necesaria
    }
    
    /**
     * Prueba: descripción de lo que se prueba
     */
    public function testNombreDescriptivo(): void {
        // Arrange
        $res = $this->x->from('mi_tabla')->select();
        
        // Assert (usando excepciones para fallos)
        if (!($res instanceof \RapidBase\Core\XResponse)) {
            throw new \Exception("select() should return XResponse");
        }
        
        if (count($res->data) !== 3) {
            throw new \Exception("Expected 3 rows, got " . count($res->data));
        }
    }
}
```

### 2. Ejecutar las pruebas

```bash
php tests/Unit/Core/core-runner.php --category X -v
```

## Convenciones

### Naming
- Los archivos de test deben terminar en `Test.php` (ej: `XTest.php`)
- Los métodos de prueba deben comenzar con `test` (ej: `testSelectBasic()`)
- Las clases deben estar en el namespace `RapidBase\Tests\<Categoria>`

### Assertions
En lugar de usar un framework de assertions, usamos excepciones directamente:

```php
// ✅ Correcto
if ($result !== $expected) {
    throw new \Exception("Expected $expected, got $result");
}

// ❌ Evitar
assert($result === $expected); // No da mensajes claros
```

### Setup y Teardown
- `setUp()`: Se ejecuta antes de cada método de prueba
- `tearDown()`: Se ejecuta después de cada método de prueba

### Base de datos
- Usar SQLite en memoria (`sqlite::memory:`) para pruebas aisladas
- Cada test debe crear sus propias tablas
- El nombre de conexión debe ser único para evitar colisiones

## Migración desde pruebas legacy

Las pruebas actuales en `/tests/Unit/Core/X/` usan un estilo procedural. Para migrarlas:

1. Identificar la funcionalidad probada en cada archivo legacy
2. Crear métodos `test*()` equivalentes en `XTest.php`
3. Convertir los checks con `echo "[ERROR]"` a excepciones
4. Ejecutar ambas versiones en paralelo hasta verificar equivalencia
5. Eliminar archivos legacy cuando todas las pruebas estén migradas

## Ventajas del nuevo enfoque

| Característica | Legacy | Nuevo Framework |
|----------------|--------|-----------------|
| Organización | Archivos sueltos | Clases con métodos |
| Setup/Teardown | Manual en cada archivo | Automático con setUp()/tearDown() |
| Reportes | Texto plano | Historial en SQLite + estadísticas |
| Ejecución selectiva | Todo o nada | Por categoría, por test, o solo failing |
| Mensajes de error | `[ERROR]` texto | Excepciones con stack trace |
| Re-ejecución | Manual automática con `--failing` |

## Troubleshooting

### Error: "Connection already exists"
Cada test debe usar un nombre de conexión único o limpiar entre tests:

```php
public function setUp(): void {
    $this->connectionId = 'test_' . uniqid();
    // ...
}
```

### Error: "Table already exists"
Usar SQLite en memoria crea una BD nueva por proceso. Si hay errores, verificar que no se esté compartiendo la conexión.

### Las pruebas no se detectan
Verificar que:
- El archivo está en el subdirectorio correcto (ej: `X/`)
- El nombre termina en `Test.php`
- La clase está en el namespace correcto (`RapidBase\Tests\X`)
- Los métodos de prueba comienzan con `test`
