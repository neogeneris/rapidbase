# Guía Rápida para Ejecutar Tests de PostgreSQL

## Configuración Centralizada

Todos los tests ahora usan `config.php` para centralizar la configuración de conexión.

### Parámetros por Defecto

```php
Host: localhost
Puerto: 5432
Base de datos: rapidbase_test
Usuario: rapidbase_user
Contraseña: rapidbase_pass
```

## Cómo Ejecutar los Tests

### 1. Test Principal de Rendimiento

```bash
cd /workspace/tests/Performance/PostgreSQL
php PostgreSQLPerformanceTest.php
```

### 2. Comparativa de ORMs

```bash
php ORMComparisonBenchmark.php
```

### 3. Comparativa RapidBase vs PDO

```bash
php RapidBaseVsPDOComparison.php
```

### 4. Benchmark con Schema Map

Primero genera el schema map (solo la primera vez):
```bash
php generate_schema_map.php
```

Luego ejecuta el benchmark:
```bash
php pg_benchmark.php
```

## Modificar la Configuración

Edita `config.php` y cambia las constantes:

```php
public const DB_HOST = 'tu_host';
public const DB_PORT = 5432;
public const DB_NAME = 'tu_base_de_datos';
public const DB_USER = 'tu_usuario';
public const DB_PASS = 'tu_contraseña';
```

Todos los scripts usarán automáticamente la nueva configuración.

## Funciones Helper Disponibles

Desde cualquier script, después de incluir `config.php`:

```php
require_once __DIR__ . '/config.php';

// Obtener array de configuración
$config = PGConfig::get();

// Obtener DSN
$dsn = PGConfig::getDSN();

// Obtener conexión PDO
$pdo = PGConfig::getPDO();

// Configurar RapidBase
PGConfig::setupRapidBase();

// Limpiar tablas
PGConfig::cleanup($pdo);

// O usar funciones globales
$pdo = pg_pdo();
pg_cleanup();
```

## Requisitos

- PHP 8.0+
- PostgreSQL 12+
- Extensión PDO PostgreSQL (`pdo_pgsql`)
- Composer dependencies instaladas

## Solución de Problemas

### Error de Conexión

Verifica que PostgreSQL esté corriendo:
```bash
sudo systemctl status postgresql
```

### Error de Autenticación

Revisa `pg_hba.conf` y asegúrate que el usuario `rapidbase_user` tenga permisos:
```sql
GRANT ALL PRIVILEGES ON DATABASE rapidbase_test TO rapidbase_user;
```

### Tablas No Existen

Ejecuta primero el test principal que crea las tablas automáticamente:
```bash
php PostgreSQLPerformanceTest.php
```
