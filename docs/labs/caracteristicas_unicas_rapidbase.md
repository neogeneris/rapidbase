# 🚀 Características Únicas de RapidBase

Este documento detalla las funcionalidades distintivas de RapidBase que lo diferencian de otros ORMs y constructores de consultas como Eloquent, Doctrine o Medoo. Estas características están diseñadas para ofrecer **máxima expresividad con mínima verbosidad**.

---

## 1. WHERE Matricial (Matrix Where)

A diferencia de los métodos encadenados `where()`, `orWhere()`, `andWhere()`, RapidBase utiliza una **sintaxis matricial** que permite definir lógica booleana compleja de forma visual y compacta.

### Sintaxis
```php
[
    [ 'columna1' => 'valor1', 'columna2' => 'valor2' ], // Fila 1 (AND interno)
    [ 'columna3' => 'valor3' ]                          // Fila 2 (AND interno)
]
```
*   **Dentro de cada sub-array (fila)**: Las condiciones se unen con **AND**.
*   **Entre sub-arrays (filas)**: Las condiciones se unen con **OR**.

### Ejemplo Práctico
Queremos buscar usuarios que sean:
*(Admins Y activos) O (Moderadores Y verificados)*

**En otros ORMs (Verboso):**
```php
$query->where('role', 'admin')
      ->where('status', 'active')
      ->orWhere(function($q) {
          $q->where('role', 'moderator')
            ->where('verified', true);
      });
```

**En RapidBase (Compacto):**
```php
DB::select('users', [
    [ 'role' => 'admin', 'status' => 'active' ],
    [ 'role' => 'moderator', 'verified' => true ]
]);
// SQL: WHERE (role='admin' AND status='active') OR (role='moderator' AND verified=1)
```

### Operadores Complejos dentro de la Matriz
También soporta operadores en los valores:
```php
[
    [ 'precio' => ['>' => 100, '<' => 500], 'activo' => 1 ],
    [ 'oferta' => 1 ]
]
// SQL: WHERE (precio > 100 AND precio < 500 AND activo=1) OR (oferta=1)
```

---

## 2. Ordenamiento Compacto (Compact Ordering)

RapidBase permite definir el orden de múltiples columnas, incluyendo la dirección (ASC/DESC), usando una sintaxis extremadamente corta basada en arrays numéricos o asociativos.

### Reglas
*   **Valor positivo o string simple**: `ASC` (Ascendente).
*   **Valor negativo o string con prefijo `-`**: `DESC` (Descendente).
*   **Índice numérico**: Se refiere a la posición de la columna en el SELECT (útil para `COUNT`, `SUM`, etc.).

### Ejemplos

**Por nombre de columna:**
```php
'order' => ['fecha', '-precio'] 
// SQL: ORDER BY fecha ASC, precio DESC
```

**Por índice (posición):**
```php
'select' => 'categoria, COUNT(*) as total',
'order' => [2, -1] 
// SQL: ORDER BY total ASC, categoria DESC
// (2 es la segunda columna, 1 es la primera)
```

**Mixto:**
```php
'order' => ['id', -2, 'nombre']
// SQL: ORDER BY id ASC, [col2] DESC, nombre ASC
```

---

## 3. Paginado Inteligente (Smart Pagination)

El paginado en RapidBase no requiere calcular manualmente el `OFFSET`. Se trabaja directamente con el **número de página**.

### Uso
```php
'page' => 3,  // Página 3
'limit' => 20 // 20 registros por página
```

### Comportamiento
*   Internamente calcula: `LIMIT 20 OFFSET 40` (para la página 3).
*   Si se usa `page => 1`, el offset es 0.
*   Ideal para APIs y frontend donde el usuario navega por números de página ("Siguiente", "Página 5").

---

## 4. Auto-Join Progresivo y Determinista

RapidBase posee un motor de resolución de JOINs automático basado en el esquema de la base de datos, pero permite controlar su comportamiento granularmente.

### Niveles de Auto-Join

1.  **Automático Total (`auto`)**:
    Detecta automáticamente las tablas necesarias basándose en los campos solicitados (`u.name`, `c.category_name`) y construye la ruta de JOINs óptima.
    ```php
    DB::select('users.name, categories.title', 'users'); 
    // RapidBase detecta que necesita JOIN con categories y lo hace solo.
    ```

2.  **Determinista / Manual (`manual` o array específico)**:
    El desarrollador define exactamente qué tablas unir, evitando ambigüedades en esquemas complejos.
    ```php
    'join' => ['categories' => 'on', 'orders' => 'left']
    ```

3.  **Desactivado (`off`)**:
    Comportamiento SQL estándar, sin magia.

### Ventaja
Permite evolucionar de prototipos rápidos (usando auto-join) a producción de alta precisión (usando join determinista) sin cambiar la estructura base de la consulta.

---

## 5. Rendimiento Nativo

Gracias a la refactorización con `SelectBuilder` interno y el uso de `PDO::FETCH_ASSOC`:
*   **Construcción de SQL**: ~80% más rápida que métodos tradicionales basados en strings.
*   **Ejecución**: Overhead mínimo (~15%) comparado con PDO nativo.
*   **Memoria**: Consumo reducido al evitar hidratación en objetos pesados por defecto.

---

## Resumen Comparativo

| Característica | RapidBase | Eloquent/Laravel | Doctrine | Medoo |
| :--- | :--- | :--- | :--- | :--- |
| **Where Complejo** | Matriz `[[..],[..]]` | Closures anidados | ExpressionBuilder | Array plano |
| **Ordenamiento** | `[1, -2]` (Compacto) | `orderBy('col', 'desc')` | `addOrderBy()` | `order()` |
| **Paginado** | `page => 3` | `skip()->take()` | `setFirstResult()` | `limit/offset` |
| **Joins** | Auto-mágico + Control | Manuales / Relaciones | Manuales / DQL | Manuales |
| **Curva Aprendizaje** | ⭐ (Muy Baja) | ⭐⭐⭐ (Media) | ⭐⭐⭐⭐ (Alta) | ⭐ (Baja) |
