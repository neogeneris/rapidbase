# Guía Rápida - Pruebas de Rendimiento MySQL/MariaDB

## 📁 Archivos Disponibles

- `config.php` - Configuración centralizada (¡EDITA ESTO!)
- `MySQLPerformanceTest.php` - Pruebas de rendimiento progresivas
- `ORMComparisonBenchmark.php` - Comparativa de ORMs/Query Builders
- `README.md` - Documentación detallada

---

## ⚙️ Configuración

### Editar `config.php`

Solo necesitas modificar **un archivo** para cambiar la configuración:

```php
public const DB_HOST = 'localhost';      // Tu host
public const DB_PORT = 3306;             // Tu puerto
public const DB_NAME = 'rapidbase_test'; // Tu base de datos
public const DB_USER = 'tu_usuario';     // Tu usuario
public const DB_PASS = 'tu_password';    // Tu contraseña
```

Todos los scripts usarán automáticamente esta configuración.

---

## 🚀 Ejecución de Pruebas

### 1. Pruebas de Rendimiento Progresivas

```bash
cd tests/Performance/MySQL
php MySQLPerformanceTest.php
```

**Qué incluye:**
- INSERTs simples y batch
- SELECTs básicos
- WHERE y ORDER BY
- JOINs (2-3 tablas)
- Agregaciones (COUNT, SUM, AVG, GROUP BY)
- Subconsultas y CTEs
- Operaciones masivas

### 2. Comparativa de ORMs

```bash
cd tests/Performance/MySQL
php ORMComparisonBenchmark.php
```

**ORMs comparados:**
- PDO (nativo)
- Medoo
- Pixie
- FatFree Framework (DB)
- Q (con y sin cache)

---

## 🔧 Funciones Helper Disponibles

Desde cualquier script PHP en esta carpeta:

```php
require_once 'config.php';

// Obtener PDO configurado
$pdo = mysql_pdo();

// Configurar RapidBase y obtener PDO
$pdo = mysql_setup();

// Limpiar tablas de prueba
mysql_cleanup();

// Usar la clase directamente
$dsn = MySQLConfig::getDsn();
MySQLConfig::setupRapidBase();
MySQLConfig::cleanup();
```

---

## 🧹 Limpieza Manual

Si necesitas limpiar las tablas manualmente:

```bash
mysql -u rapidbase_user -p rapidbase_test -e "DROP TABLE IF EXISTS order_items, orders, products, categories, customers;"
```

O desde PHP:
```php
require_once 'config.php';
MySQLConfig::cleanup();
```

---

## 🐛 Solución de Problemas

### Error: "Table already exists"
✅ **Solucionado**: Los scripts ahora usan `DROP TABLE IF EXISTS` y `CREATE TABLE IF NOT EXISTS`

### Error: "Connection refused"
1. Verifica que MySQL/MariaDB esté corriendo: `systemctl status mariadb`
2. Verifica credenciales en `config.php`
3. Asegúrate que la BD existe: `CREATE DATABASE rapidbase_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`

### Error: "Access denied"
1. Verifica usuario y password en `config.php`
2. Otorga permisos: 
```sql
GRANT ALL PRIVILEGES ON rapidbase_test.* TO 'rapidbase_user'@'localhost' IDENTIFIED BY 'rapidbase_pass';
FLUSH PRIVILEGES;
```

### Error: "PDO extension not found"
Instala PDO para MySQL:
```bash
# Ubuntu/Debian
sudo apt-get install php-mysql

# CentOS/RHEL
sudo yum install php-mysql

# Reinicia PHP-FPM/Apache después de instalar
```

---

## 📊 Interpretación de Resultados

Los resultados muestran:
- **Tiempo promedio (avg)**: Tiempo medio en milisegundos
- **Tiempo mínimo (min)**: Mejor tiempo registrado
- **Tiempo máximo (max)**: Peor tiempo registrado
- **Iteraciones**: Cantidad de veces que se ejecutó la prueba

**Ejemplo:**
```
INSERT individual: 1.945ms
INSERT batch (100 registros): 4.58ms (21834 regs/seg)
```

---

## 💡 Tips de Rendimiento

1. **Índices**: Agrega índices a columnas usadas en WHERE y JOIN
2. **EXPLAIN**: Usa `EXPLAIN` para analizar queries lentos
3. **Batch operations**: Siempre prefiere INSERTs masivos sobre individuales
4. **Prepared statements**: Usa parámetros para evitar SQL injection
5. **Conexiones persistentes**: Configura `PDO::ATTR_PERSISTENT` para producción

---

## 📝 Notas Importantes

- Las pruebas **eliminan y recrean** las tablas en cada ejecución
- Los datos de prueba son **generados aleatoriamente**
- Para pruebas consistentes, usa siempre la misma cantidad de registros
- MariaDB 10.3+ es recomendado para mejor compatibilidad

---

## 🔗 Enlaces Útiles

- [Documentación MySQL](https://dev.mysql.com/doc/)
- [Documentación MariaDB](https://mariadb.com/kb/en/)
- [Optimización de Queries](https://dev.mysql.com/doc/refman/8.0/en/optimization.html)
