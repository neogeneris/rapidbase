# Plan de Reorganización de tests/Unit

## Análisis Actual

### Estructura actual de src/RapidBase/:
```
src/RapidBase/
├── Api/
├── Autoloader/
├── Core/
│   ├── Auth/
│   ├── Backend/
│   ├── Cache/
│   │   └── Adapters/
│   ├── SQL/
│   └── (DB.php, Gateway.php, Executor.php, etc.)
├── Infrastructure/
│   └── UI/
├── Meta/
│   └── Discovery/
├── ORM/
│   └── ActiveRecord/
├── Search/
└── Tdd/
```

### Estructura actual de tests/Unit/:
```
tests/Unit/
├── Autojoins/          ✓ Coincide con Core/SQL/JoinResolver
├── Cache/              ✓ Coincide con Core/Cache/
├── Conn/               ✓ Coincide con Core/Conn.php
├── Core/               ⚠ Mezcla varios componentes
│   ├── X/              ✓ Específico para X.php
│   └── X2/             ✓ Específico para X.php v2
├── Endpoints/          ⚠ Debería estar en Api/
├── Executor/           ✓ Coincide con Core/Executor.php
├── Gateway/            ✓ Coincide con Core/Gateway.php
├── Meta/               ✓ Coincide con Meta/
│   └── Discovery/      ✓ Coincide con Meta/Discovery/
├── Models/             ⚠ Debería estar en ORM/
├── ORM/                ✓ Coincide con ORM/
├── SQL/                ⚠ Debería ser Core/SQL/
└── Sort/               ⚠ Probablemente Core/SQL/Sort
```

## Problemas Identificados

1. **Falta correspondencia 1:1** entre src/ y tests/Unit/
2. **Tests dispersos**: SQL/, Sort/, Autojoins/ deberían estar bajo Core/
3. **Endpoints/** debería moverse a Api/
4. **Models/** debería consolidarse con ORM/
5. **No hay bootstrap.php** en cada subcarpeta
6. **Mezcla de estilos**: Algunos tests usan autoloader, otros carga manual
7. **Bases de datos dispersas** en varias carpetas en lugar de centralizadas

## Propuesta de Nueva Estructura

### tests/Unit/ reorganizado:
```
tests/Unit/
├── bootstrap.php                    # Bootstrap general (ya creado)
├── Api/                             # ← Mover desde Endpoints/
│   └── bootstrap.php
├── Autoloader/                      # ← Nuevo
│   └── bootstrap.php
├── Core/
│   ├── bootstrap.php
│   ├── Auth/                        # ← Nuevo
│   │   └── bootstrap.php
│   ├── Backend/                     # ← Nuevo
│   │   └── bootstrap.php
│   ├── Cache/
│   │   ├── bootstrap.php
│   │   └── Adapters/                # ← Nuevo
│   │       └── bootstrap.php
│   ├── SQL/                         # ← Mover desde tests/Unit/SQL/
│   │   ├── bootstrap.php
│   │   └── Sort/                    # ← Mover desde tests/Unit/Sort/
│   ├── Connection/                  # ← Renombrar desde Conn/
│   │   └── bootstrap.php
│   ├── DB/                          # ← Nuevo específico para DB.php
│   │   └── bootstrap.php
│   ├── Executor/                    # ← Mantener
│   │   └── bootstrap.php
│   ├── Gateway/                     # ← Mantener
│   │   └── bootstrap.php
│   └── X/                           # ← Mantener
├── Infrastructure/                  # ← Nuevo
│   └── bootstrap.php
├── Meta/
│   ├── bootstrap.php
│   └── Discovery/
│       └── bootstrap.php
├── ORM/
│   ├── bootstrap.php
│   └── ActiveRecord/                # ← Consolidar Models/ aquí
│       └── bootstrap.php
├── Search/                          # ← Nuevo
│   └── bootstrap.php
└── Tdd/                             # ← Nuevo para tests del framework TDD
    └── bootstrap.php
```

### tests/lib/ (ya creado):
- RapidBase.php (bundle copiado desde bin/)

### tests/config/ (ya creado):
- test-config.php (configuración centralizada)
- mysql-test-config.local.php (configuración local opcional)

### tests/data/ (existente, mantener):
- Bases de datos de muestra
- JSON files para testing
- autoloader_test.sqlite

### tests/tmp/ (existente, mantener):
- Bases de datos temporales de pruebas
- Logs
- Cache temporal

## Migración de Tests Existentes

### Tests que necesitan modernización:
1. **tests/Unit/SQL/** → Mover a **tests/Unit/Core/SQL/**
   - Actualizar para usar `bin/tdd-runner.php`
   - Agregar bootstrap.php específico
   
2. **tests/Unit/Sort/** → Mover a **tests/Unit/Core/SQL/Sort/**
   - Integrar como parte de Core/SQL

3. **tests/Unit/Autojoins/** → Mover a **tests/Unit/Core/SQL/Autojoins/**
   - Relacionado con JoinResolver

4. **tests/Unit/Endpoints/** → Mover a **tests/Unit/Api/Endpoints/**
   - Actualizar namespace y bootstrap

5. **tests/Unit/Models/** → Consolidar en **tests/Unit/ORM/ActiveRecord/**
   - Unificar con tests existentes de ORM

### Tests bien ubicados (mantener):
- tests/Unit/Cache/ ✓
- tests/Unit/Conn/ → Renombrar a Core/Connection/
- tests/Unit/Executor/ ✓
- tests/Unit/Gateway/ ✓
- tests/Unit/Meta/ ✓
- tests/Unit/ORM/ ✓

## Modernización con TDD Runner

Cada archivo Test.php debe seguir el patrón:

```php
<?php
declare(strict_types=1);

namespace RapidBase\Core\SQL;

use RapidBase\Tdd\TestCase;

/**
 * Test Suite for Q Class
 */
class QTest extends TestCase
{
    public function setUp(): void
    {
        // Configuración inicial
    }

    public function tearDown(): void
    {
        // Limpieza
    }

    public function testSelectBasic(): void
    {
        $this->env('sqlite')->test('should generate basic SELECT', function($db) {
            // Test implementation
            $this->assertTrue(true);
        });
    }
}
```

## Bootstrap por Subcarpeta

Cada subcarpeta tendrá su propio bootstrap.php que:
1. Carga el bootstrap general de tests/
2. Permite override de configuración específica
3. Proporciona helpers específicos para ese módulo

Ejemplo: tests/Unit/Core/SQL/bootstrap.php
```php
<?php
// Cargar bootstrap general
require_once __DIR__ . '/../../../bootstrap.php';

// Configuración específica para SQL tests
ConditionMatrix::setDriver('sqlite');

// Helpers específicos
function createTestSchema(PDO $pdo): void {
    // Schema común para tests de SQL
}
```

## Beneficios Esperados

1. **Correspondencia 1:1** con src/RapidBase/
2. **Fácil navegación**: Sabes dónde están los tests mirando src/
3. **Bootstrap flexible**: Bundle o source según necesidad
4. **Datos centralizados**: configs, data, tmp bien organizados
5. **TDD moderno**: Todos los tests usan bin/tdd-runner.php
6. **Mantenibilidad**: Nueva estructura escalable y clara

## Pasos de Implementación

1. ✅ Crear tests/lib/ con RapidBase.php
2. ✅ Crear tests/config/ con configuración centralizada
3. ✅ Crear tests/bootstrap.php general
4. 🔄 Mover carpetas para coincidir con src/
5. 🔄 Crear bootstrap.php en cada subcarpeta
6. 🔄 Actualizar tests existentes para usar TDD Runner
7. 🔄 Eliminar archivos duplicados y bases de datos dispersas
8. 🔄 Crear script de migración automatizado
9. 🔄 Documentar nueva estructura
