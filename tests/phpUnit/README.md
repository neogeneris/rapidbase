# RapidBase PHPUnit Tests

Este directorio contiene las pruebas formales unitarias para el proyecto RapidBase utilizando PHPUnit.

## Requisitos

- PHP 8.2+
- Composer
- PHPUnit 10+

## Instalación

```bash
composer install
```

## Ejecutar tests

### Todos los tests
```bash
./vendor/bin/phpunit tests/phpUnit/
```

### Tests específicos
```bash
./vendor/bin/phpunit tests/phpUnit/DBTest.php
./vendor/bin/phpunit tests/phpUnit/ModelTest.php
```

## Tests disponibles

### DBTest
Pruebas para la clase `RapidBase\Core\DB`:
- Conexión a base de datos
- Operaciones CRUD (insert, find, update, delete)
- Consultas raw (query, one, many, value)
- Upsert
- Streaming
- Grid con paginación
- Caché

### ModelTest
Pruebas para el ORM `RapidBase\ORM\ActiveRecord\Model`:
- Creación y guardado de modelos
- Lectura de registros
- Actualización y eliminación
- Dirty tracking
- Getters/setters mágicos
- Hidratación de objetos

## Configuración

Los tests utilizan SQLite en memoria por defecto para asegurar aislamiento y rapidez.

## Estructura de namespaces

- Código fuente: `RapidBase\`
- Tests: `RapidBase\Tests\Unit\`
