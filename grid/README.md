# RapidBase Grid Component

Componente de grilla dinámico en JavaScript puro para visualizar datos de APIs REST.

## Estructura

```
grid/
├── GridBuilder.js      # Clase base para construir grids desde plantillas HTML
├── APIDataGrid.js      # Extiende GridBuilder, se conecta a APIs
├── Paginator.js        # Componente de paginación opcional
├── grid.css            # Estilos modernos y responsive
└── README.md           # Este archivo
```

## Uso Básico

### 1. Incluir los archivos

```html
<link rel="stylesheet" href="/grid/grid.css">
<script src="/grid/GridBuilder.js"></script>
<script src="/grid/Paginator.js"></script>
<script src="/grid/APIDataGrid.js"></script>
```

### 2. HTML Plantilla

```html
<div class="my-grid" id="mi-grid">
    <!-- Encabezado (puede tener 1 columna para modo dinámico) -->
    <div class="grid-head">
        <div class="grid-header">{1}</div>
    </div>

    <!-- Plantilla de fila (oculta) -->
    <div class="grid-row hidden">
        <div class="grid-item">{name}</div>
        <div class="grid-item">{email}</div>
        <div class="grid-item">{age}</div>
    </div>

    <!-- Controles (búsqueda, filtros) -->
    <div class="grid-controls"></div>

    <!-- Cuerpo donde se insertan las filas -->
    <div class="grid-body"></div>

    <!-- Paginador (opcional) -->
    <div class="grid-paginator"></div>

    <!-- Indicadores de estado -->
    <div class="grid-loading">Cargando...</div>
    <div class="grid-error"></div>
</div>
```

### 3. Inicialización en JavaScript

```javascript
// Modo paginación
const grid = new APIDataGrid('#mi-grid', '/api/users', {
    mode: 'pagination',
    pageSize: 20
});

// Cargar datos iniciales
grid.load();

// Modo scroll infinito
const gridInfinite = new APIDataGrid('#mi-grid-infinite', '/api/products', {
    mode: 'infinite',
    pageSize: 50
});
gridInfinite.load();
```

## API del Backend

El grid espera una respuesta JSON con la siguiente estructura:

```json
{
    "data": [
        { "id": 1, "name": "John", "email": "john@example.com" },
        { "id": 2, "name": "Jane", "email": "jane@example.com" }
    ],
    "metadata": [
        { "key": "id", "title": "ID", "index": 1 },
        { "key": "name", "title": "Nombre", "index": 2 },
        { "key": "email", "title": "Email", "index": 3 }
    ],
    "total": 100,
    "offset": 0,
    "limit": 20,
    "hasMore": true
}
```

### Parámetros de Solicitud

| Parámetro | Tipo   | Descripción                              |
|-----------|--------|------------------------------------------|
| offset    | int    | Desplazamiento para paginación           |
| limit     | int    | Número de registros por página           |
| sort      | string | Campo de ordenamiento (-prefijo = DESC)  |
| search    | string | Término de búsqueda global               |
| filter    | JSON   | Filtros avanzados en formato ConditionMatrix |

Ejemplo de URL:
```
/api/users?offset=0&limit=20&sort=-created_at&filter={"status":["=","active"]}
```

## Modos de Renderizado

### 1. Modo Dinámico (una columna en HTML)
Si el `.grid-head` tiene solo una columna `.grid-header`, el grid genera automáticamente todas las columnas basándose en las claves del primer objeto de datos o en la metadata.

### 2. Modo con Metadata
Si se proporciona metadata en la respuesta, el grid usa esa información para generar los encabezados y mapear las columnas.

### 3. Modo Plantilla (interpolación)
Si hay múltiples columnas definidas en el HTML, el grid usa interpolación de cadenas reemplazando `{clave}` por el valor correspondiente. También soporta índices numéricos `{1}`, `{2}`, etc.

## Métodos Públicos

### APIDataGrid

| Método              | Descripción                                    |
|---------------------|------------------------------------------------|
| `load()`            | Carga los datos iniciales                      |
| `fetchData()`       | Obtiene datos de la API                        |
| `resetAndFetch()`   | Reinicia paginación y recarga                  |
| `sortBy(field)`     | Ordena por una columna                         |
| `setFilter(filter)` | Establece filtros avanzados                    |
| `clear()`           | Limpia el grid                                 |

### GridBuilder

| Método              | Descripción                                    |
|---------------------|------------------------------------------------|
| `render(data, meta)`| Renderiza datos en el grid                     |
| `clear()`           | Limpia todo el contenido                       |

## Ejemplo con QueryBrowser

```javascript
// Después de seleccionar una tabla en el querybrowser
function showTableData(connectionId, tableName) {
    const container = document.querySelector('.results-grid');
    container.innerHTML = `
        <div class="my-grid" id="table-grid">
            <div class="grid-head"><div class="grid-header">{1}</div></div>
            <div class="grid-row hidden"><div class="grid-item"></div></div>
            <div class="grid-controls"></div>
            <div class="grid-body"></div>
            <div class="grid-paginator"></div>
            <div class="grid-loading">Cargando...</div>
            <div class="grid-error"></div>
        </div>
    `;
    
    const apiUrl = `api.php?action=grid_data&connectionId=${connectionId}&table=${tableName}`;
    
    const grid = new APIDataGrid('#table-grid', apiUrl, {
        mode: 'pagination',
        pageSize: 20
    });
    
    grid.load();
}
```

## Estilos Personalizados

Los estilos pueden sobrescribirse fácilmente:

```css
.my-grid {
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.grid-header {
    background: #007bff;
    color: white;
}

.grid-row:hover {
    background: #f0f8ff;
}
```

## Soporte

- Navegadores modernos (Chrome, Firefox, Safari, Edge)
- Responsive design
- Accesibilidad básica (teclado, screen readers)
