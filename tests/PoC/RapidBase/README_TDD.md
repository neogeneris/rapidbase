# RapidBase API + TDD Framework

## Arquitectura

Esta PoC implementa una API RESTful minimalista con un framework TDD integrado, diseñado para que los endpoints sean **fácilmente testeables desde el primer momento**.

### Estructura de Directorios

```
tests/PoC/RapidBase/
├── Api/
│   ├── ApiContext.php      # Contexto inyectable (reemplaza globals)
│   └── BaseEndpoint.php    # Clase base con describe(), version(), catalog()
├── Endpoints/
│   ├── HealthService.php   # Ejemplo: endpoint de salud
│   └── UserService.php     # Ejemplo: gestión de usuarios
├── Tdd/
│   └── Runner.php          # Framework TDD con persistencia SQLite
├── api.php                 # Router HTTP principal
└── tdd_runner.php          # CLI para ejecutar pruebas
```

## Uso de la API

### Patrón de URL

```
api.php?ep={EndpointName}&action={method}&{params}
```

### Ejemplos

#### 1. Obtener catálogo de un endpoint
```bash
curl "http://localhost/api.php?ep=HealthService"
```

**Respuesta:**
```json
{
  "endpoint": "HealthService",
  "version": {"endpoint": "RapidBase\\Endpoints\\HealthService", "version": "1.0.0"},
  "catalog": {"endpoint": "RapidBase\\Endpoints\\HealthService", "methods": ["ping"]},
  "methods": {
    "ping": {
      "description": "Verifica el estado de una conexión y devuelve la latencia.",
      "parameters": [
        {"name": "connectionId", "type": "int", "optional": false}
      ]
    }
  }
}
```

#### 2. Llamar a un método
```bash
curl "http://localhost/api.php?ep=HealthService&action=ping&connectionId=5"
```

**Respuesta:**
```json
{
  "status": "online",
  "user_context": 0,
  "latency": "0.002ms"
}
```

#### 3. Endpoint de Usuarios
```bash
curl "http://localhost/api.php?ep=UserService&action=getUser&userId=42"
curl "http://localhost/api.php?ep=UserService&action=createUser&name=Juan&email=juan@example.com"
curl "http://localhost/api.php?ep=UserService&action=listUsers&page=1&limit=5"
```

## Framework TDD

### Características

- **Auto-descubrimiento**: Escanea automáticamente la carpeta `Endpoints/`
- **Generación inteligente de parámetros**: Infere valores por defecto según tipos
- **Persistencia**: Guarda historial en SQLite para análisis de tendencias
- **Múltiples modos**: `--all`, `--first`, `--failing`

### Comandos Disponibles

```bash
# Ejecutar TODAS las pruebas
php tdd_runner.php --all -v

# Modo TDD: detenerse en el primer fallo
php tdd_runner.php --first

# Re-ejecutar solo las que fallaron (iterativo)
php tdd_runner.php --failing -v

# Ver estadísticas
php tdd_runner.php --stats

# Ver historial de ejecuciones
php tdd_runner.php --history 20

# Listar endpoints disponibles
php tdd_runner.php --scan
```

### Ejemplo de Salida

```
╔══════════════════════════════════════════════════════════╗
║              RAPIDBASE TDD TEST REPORT                   ║
╠══════════════════════════════════════════════════════════╣
║  Total: 4     Pass: 4     Fail: 0                     ║
╠══════════════════════════════════════════════════════════╣
║  ✓ UserService::getUser                                         ║
║  ✓ UserService::createUser                                         ║
║  ✓ UserService::listUsers                                         ║
║  ✓ HealthService::ping                                         ║
╚══════════════════════════════════════════════════════════╝
```

## Crear un Nuevo Endpoint

### Paso 1: Crear la clase en `Endpoints/`

```php
<?php

namespace RapidBase\Endpoints;

use RapidBase\Api\BaseEndpoint;

class MiServicio extends BaseEndpoint {
    /**
     * Descripción del método para autogenerar documentación.
     */
    public function miMetodo(int $id, string $nombre = 'default') {
        $userId = $this->context->session['user_id'] ?? 0;
        
        return [
            'id' => $id,
            'nombre' => $nombre,
            'created_by' => $userId
        ];
    }
}
```

### Paso 2: Probar automáticamente

```bash
php tdd_runner.php --scan         # Verificar que fue detectado
php tdd_runner.php --all -v       # Ejecutar pruebas
```

### Paso 3: Acceder vía HTTP

```bash
curl "http://localhost/api.php?ep=MiServicio&action=miMetodo&id=123"
```

## Testing Unitario Manual

El diseño permite testing aislado sin dependencias:

```php
<?php

require 'vendor/autoload.php';

// 1. Crear contexto mock
$context = new \RapidBase\Api\ApiContext(
    params:  ['connectionId' => 5],
    session: ['user_id' => 101],
    auth:    ['role' => 'admin']
);

// 2. Instanciar endpoint e inyectar contexto
$api = new \RapidBase\Endpoints\HealthService();
$api->setContext($context);

// 3. Probar autodescripción
print_r($api->describe());

// 4. Probar ejecución funcional
$result = $api->ping(5);
assert($result['status'] === 'online');
assert($result['user_context'] === 101);
```

## Ventajas del Diseño

| Característica | Beneficio |
|----------------|-----------|
| **Inyección de contexto** | Tests aislados sin $_GET, $_SESSION |
| **Auto-descripción** | Documentación siempre actualizada |
| **Parámetros tipados** | Validación automática y generación de datos |
| **Historial SQLite** | Análisis de tendencias y regresiones |
| **CLI multi-modo** | Flujo TDD iterativo eficiente |

## Próximos Pasos

1. **Auto-loader de dependencias**: Detectar automáticamente qué clases necesita cada endpoint
2. **Fixtures**: Sistema para cargar datos de prueba predefinidos
3. **Mocks**: Capacidad de mockear dependencias externas (DB, APIs)
4. **Coverage**: Medir qué líneas de código son ejecutadas por los tests
5. **Parallel execution**: Ejecutar tests en paralelo para mayor velocidad
