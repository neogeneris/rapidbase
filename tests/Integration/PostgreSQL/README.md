# Pruebas de Integración: RapidBase con PostgreSQL

Esta carpeta contiene pruebas de integración para verificar el funcionamiento de **RapidBase** con **PostgreSQL** como motor de base de datos, en lugar del SQLite3 usado por defecto.

## Archivos

### 1. `PostgreSQLIntegrationTest.php`
Prueba las operaciones CRUD básicas y características fundamentales:
- ✅ Conexión a PostgreSQL
- ✅ CREATE TABLE
- ✅ INSERT (con SERIAL)
- ✅ SELECT (find, all)
- ✅ UPDATE
- ✅ DELETE
- ✅ COUNT, EXISTS
- ✅ UPSERT (ON CONFLICT)
- ✅ Transacciones
- ✅ Rollback
- ✅ Consultas raw con parámetros
- ✅ Características específicas de PostgreSQL (RETURNING clause)

### 2. `PostgreSQLAdvancedTest.php`
Prueba características avanzadas y específicas de PostgreSQL que no están disponibles en SQLite3:
- ✅ Tipos de datos JSONB
- ✅ Arrays de texto (TEXT[])
- ✅ Full Text Search (tsvector, tsquery)
- ✅ Operadores JSONB (->>, @>)
- ✅ Savepoints en transacciones
- ✅ UPSERT avanzado con DO UPDATE
- ✅ CTE (Common Table Expressions / WITH clause)
- ✅ Window Functions (RANK, AVG OVER, etc.)
- ✅ Triggers y funciones PL/pgSQL

## Requisitos

1. **PostgreSQL instalado y ejecutándose**
   ```bash
   sudo service postgresql start
   ```

2. **Base de datos y usuario creados**
   ```bash
   sudo -u postgres psql -c "CREATE DATABASE rapidbase_test;"
   sudo -u postgres psql -c "CREATE USER rapidbase_user WITH PASSWORD 'rapidbase_pass';"
   sudo -u postgres psql -c "GRANT ALL PRIVILEGES ON DATABASE rapidbase_test TO rapidbase_user;"
   sudo -u postgres psql -d rapidbase_test -c "GRANT ALL ON SCHEMA public TO rapidbase_user;"
   ```

3. **PHP con extensión PostgreSQL**
   ```bash
   apt-get install php-cli php-pgsql
   ```

4. **Composer dependencies instaladas**
   ```bash
   composer install
   ```

## Ejecución

### Test Básico
```bash
php tests/Integration/PostgreSQL/PostgreSQLIntegrationTest.php
```

### Test Avanzado
```bash
php tests/Integration/PostgreSQL/PostgreSQLAdvancedTest.php
```

## Resultados Esperados

Ambos tests deben mostrar una serie de verificaciones con marcadores:
- `✓` para operaciones exitosas
- `✗` para errores
- `⚠` para advertencias o casos especiales

Al final, ambos tests limpian las tablas creadas para no dejar residuos.

## Diferencias Clave entre SQLite3 y PostgreSQL en RapidBase

| Característica | SQLite3 | PostgreSQL |
|---------------|---------|------------|
| Driver DSN | `sqlite:` | `pgsql:host=localhost;dbname=` |
| Auto-incremento | INTEGER PRIMARY KEY | SERIAL PRIMARY KEY |
| lastInsertId() | Siempre funciona | Solo después de INSERT con SERIAL |
| UPSERT | INSERT OR REPLACE | ON CONFLICT DO UPDATE |
| Tipos de datos | Limitados | JSONB, arrays, TEXT, NUMERIC, etc. |
| Búsqueda texto | LIKE | Full Text Search (tsvector) |
| Funciones ventana | Limitadas | Completas (RANK, ROW_NUMBER, etc.) |
| CTE | Desde 3.8.3 | Nativas y optimizadas |

## Fix Aplicado a RapidBase

Se modificó `src/RapidBase/Core/Executor.php` para manejar correctamente el comportamiento de `lastInsertId()` en PostgreSQL, que lanza una excepción cuando se llama después de statements que no generan un valor serial (como DELETE o CREATE TABLE).

```php
// PostgreSQL: lastInsertId() puede fallar si no hubo INSERT con SERIAL
if ($driver === 'pgsql') {
    if (strpos($sqlUpper, 'INSERT') === 0 || strpos($sqlUpper, 'INTO') !== false) {
        try {
            $lastId = $pdo->lastInsertId();
        } catch (\PDOException $e) {
            $lastId = null;
        }
    }
}
```

## Notas

- Las pruebas son **autónomas**: crean sus propias tablas y las eliminan al finalizar.
- Se utiliza la caché de RapidBase durante las pruebas para verificar integración.
- Los tests avanzados demuestran ventajas competitivas de usar PostgreSQL sobre SQLite3.
