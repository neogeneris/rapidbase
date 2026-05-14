# Tutorial: Crear, Probar y Usar un Endpoint en RapidBase

Este tutorial te guiará paso a paso para crear un nuevo endpoint, probarlo con el sistema TDD integrado y consumirlo desde el frontend usando el **Magic Client**.

## Escenario de Ejemplo
Vamos a crear un endpoint llamado `MathService` con una acción `add` que sume dos números.

---

## Paso 1: Crear el Endpoint (Backend)

Crea un archivo PHP en la carpeta de endpoints de tu proyecto:
`examples/querybrowser/api/v1/Endpoints/MathService.php`

```php
<?php
namespace RapidBase\Endpoints;

use RapidBase\Api\BaseEndpoint;

/**
 * MathService Endpoint
 * Ejemplo básico de operaciones matemáticas
 */
class MathService extends BaseEndpoint
{
    /**
     * Suma dos números
     * @param int $a Primer número
     * @param int $b Segundo número
     * @return array Resultado con la suma
     */
    public function add($a = 0, $b = 0)
    {
        $result = intval($a) + intval($b);
        
        return [
            'success' => true,
            'data' => [
                'operation' => 'add',
                'a' => $a,
                'b' => $b,
                'result' => $result
            ]
        ];
    }

    /**
     * Multiplica dos números
     */
    public function multiply($a = 1, $b = 1)
    {
        return [
            'success' => true,
            'data' => [
                'result' => intval($a) * intval($b)
            ]
        ];
    }
}
```

**Nota:** El nombre de la clase (`MathService`) debe coincidir con el nombre del archivo. El Router usará este nombre automáticamente.

---

## Paso 2: Probar con TDD

Ahora crearemos una prueba unitaria para asegurar que nuestro endpoint funciona correctamente.

### 2.1 Crear el archivo de Test
Crea: `examples/querybrowser/tests/Unit/MathServiceTest.php`

```php
<?php
use RapidBase\Tdd\TestCase;

class MathServiceTest extends TestCase
{
    protected $endpoint = 'MathService';

    public function testAdd()
    {
        // Llamada al endpoint
        $response = $this->call('add', ['a' => 5, 'b' => 3]);

        // Aserciones
        $this->assertTrue($response['success']);
        $this->assertEquals(8, $response['data']['result']);
        $this->assertEquals(5, $response['data']['a']);
    }

    public function testMultiply()
    {
        $response = $this->call('multiply', ['a' => 4, 'b' => 5]);

        $this->assertTrue($response['success']);
        $this->assertEquals(20, $response['data']['result']);
    }
}
```

### 2.2 Ejecutar las pruebas
Desde la terminal, navega a la carpeta de tests de tu proyecto y ejecuta:

```bash
cd examples/querybrowser/tests/Unit
php tdd-runner.php MathService
```

**Salida esperada:**
```text
--------------------------------------------------
RapidBase TDD Test Runner
--------------------------------------------------
Suite: QueryBrowser App
Filter: MathService

Running: MathServiceTest::testAdd ... [SUCCESS]
Running: MathServiceTest::testMultiply ... [SUCCESS]

--------------------------------------------------
Total: 2    Success: 2    Failure: 0
--------------------------------------------------
```

---

## Paso 3: Usar desde JavaScript (Frontend)

Gracias al **Magic Client**, no necesitas registrar el endpoint ni configurar nada extra. Simplemente llámalo.

### 3.1 Incluir el Cliente
Asegúrate de incluir el script en tu HTML (`test_layout.php` o similar):

```html
<script src="components/lib/RapidBaseClient.js"></script>
```

### 3.2 Consumir el Endpoint
En tu código JavaScript:

```javascript
// 1. Instanciar el cliente mágico
const api = new RapidBaseClient('api/v1/index.php');

// 2. Llamar al endpoint (¡La magia ocurre aquí!)
// Esto se traduce automáticamente a: 
// GET api/v1/index.php?ep=MathService&action=add&a=10&b=20
async function probarSuma() {
    try {
        const resultado = await api.mathService.add({ a: 10, b: 20 });
        console.log('Resultado:', resultado); 
        // Salida: { operation: 'add', a: 10, b: 20, result: 30 }
    } catch (error) {
        console.error('Error:', error.message);
    }
}

// 3. Llamar a otra acción
async function probarMultiplicacion() {
    const data = await api.mathService.multiply({ a: 5, b: 6 });
    console.log('Producto:', data.result); // 30
}

// Ejecutar
probarSuma();
```

### ¿Por qué funciona sin configurar?
El cliente usa **Proxies de JavaScript**:
1. Intercepta `api.mathService`.
2. Convierte `mathService` (camelCase) a `MathService` (PascalCase) para buscar la clase PHP.
3. Intercepta `.add()` y lo convierte en `action=add`.
4. Envía los parámetros como query string.

---

## Resumen del Flujo de Trabajo

1.  **Backend:** Creas `Endpoints/MiServicio.php` con métodos públicos.
2.  **Tests:** Creas `tests/Unit/MiServicioTest.php` y verificas con `tdd-runner.php`.
3.  **Frontend:** Llamas `api.miServicio.accion()` directamente.

¡No hay configuración intermedia, registro de rutas ni doble trabajo!

## Comandos Útiles

| Acción | Comando |
|--------|---------|
| Probar todo el proyecto | `php tests/Unit/tdd-runner.php` |
| Probar un endpoint específico | `php tests/Unit/tdd-runner.php MathService` |
| Ver ayuda del runner | `php tests/Unit/tdd-runner.php --help` |

---
¡Felicidades! Has creado, probado y consumido tu primer endpoint en RapidBase.
