# Prueba de Concepto: API Testable con Inyección de Contexto

## Objetivo
Crear una arquitectura de API donde los endpoints sean **fácilmente testeables** desde el primer momento, permitiendo pruebas unitarias y funcionales sin dependencias de globals (`$_REQUEST`, `$_SESSION`, etc.).

## Estructura Propuesta

```
tests/PoC/RapidBase/
├── Api/
│   ├── ApiContext.php       # Contexto inyectable (params, session, auth)
│   └── BaseEndpoint.php     # Clase base con autodescripción
├── Endpoints/
│   ├── HealthService.php    # Ejemplo: endpoint de salud
│   └── UserService.php      # Ejemplo: endpoint de usuarios
├── api.php                  # Router HTTP (api.php?ep=X&action=Y)
├── test_api.php             # Demo básica
└── test_suite.php           # Suite completa de pruebas unitarias
```

## Uso vía URL

```bash
# Listar métodos disponibles
curl "http://localhost/api.php?ep=HealthService"

# Ejecutar método
curl "http://localhost/api.php?ep=HealthService&action=ping&connectionId=5"

# Endpoint de usuarios
curl "http://localhost/api.php?ep=UserService&action=getUser&userId=42"
curl "http://localhost/api.php?ep=UserService&action=createUser&name=Test&email=test@example.com"
curl "http://localhost/api.php?ep=UserService&action=listUsers&page=2&limit=5"
```

## Testing Unitario

La clave es la **inyección de contexto**:

```php
use RapidBase\Api\ApiContext;
use RapidBase\Endpoints\HealthService;

// Crear contexto simulado (sin dependencias de globals)
$context = new ApiContext(
    params:  ['connectionId' => 5],
    session: ['user_id' => 101],
    auth:    ['role' => 'admin']
);

// Inyectar contexto en el endpoint
$api = new HealthService();
$api->setContext($context);

// Probar funcionalidad
$result = $api->ping(5);
assert($result['user_context'] === 101);

// Autodescripción del endpoint
$description = $api->describe();
// Devuelve: descripción, parámetros, tipos, opcionales
```

## Ventajas

1. **Testeabilidad total**: No hay dependencias de `$_GET`, `$_POST`, `$_SESSION`
2. **Aislamiento**: Cada prueba tiene su propio contexto
3. **Autodescripción**: Los endpoints se documentan automáticamente vía reflection
4. **Fácil expansión**: Nuevos endpoints solo requieren extender `BaseEndpoint`
5. **Router automático**: `api.php` enruta solicitudes HTTP sin configuración adicional

## Ejecutar Pruebas

```bash
php tests/PoC/RapidBase/test_api.php      # Demo básica
php tests/PoC/RapidBase/test_suite.php    # Suite completa (20 tests)
```

## Namespace

- `RapidBase\Api\` - Clases base (ApiContext, BaseEndpoint)
- `RapidBase\Endpoints\` - Endpoints específicos (HealthService, UserService, etc.)
