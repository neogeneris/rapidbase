/**
 * Grid Component para QueryBrowser
 * 
 * Integración del sistema de grids con el QueryBrowser.
 * Permite mostrar datos de tablas en un grid dinámico con paginación o scroll infinito.
 */
class QueryBrowserGrid {
    /**
     * Crea un grid para una tabla específica.
     * 
     * @param {string} containerSelector - Selector del contenedor HTML
     * @param {string} connectionId - ID de la conexión registrada
     * @param {string} table - Nombre de la tabla
     * @param {Object} options - Opciones adicionales
     */
    static createTableGrid(containerSelector, connectionId, table, options = {}) {
        const apiUrl = 'api.php?action=grid_data';
        
        const config = {
            mode: options.mode || 'pagination',
            pageSize: options.pageSize || 20,
            filter: options.filter || {}
        };

        // Crear instancia del grid
        const grid = new APIDataGrid(containerSelector, apiUrl, config);
        
        // Guardar referencia a la conexión y tabla
        grid.connectionId = connectionId;
        grid.table = table;
        
        // Sobrescribir buildParams para incluir connection_id y table
        const originalBuildParams = grid.buildParams.bind(grid);
        grid.buildParams = function() {
            const params = originalBuildParams();
            params.set('connection_id', this.connectionId);
            params.set('table', this.table);
            return params;
        };

        // Agregar funcionalidad de ordenamiento por columnas
        grid.container.addEventListener('click', (e) => {
            const header = e.target.closest('.grid-header');
            if (header && header.dataset.column) {
                const field = header.dataset.column;
                grid.sortBy(field);
            }
        });

        // Cargar datos iniciales
        grid.load();
        
        return grid;
    }

    /**
     * Destruye un grid existente.
     */
    static destroy(grid) {
        if (grid && typeof grid.clear === 'function') {
            grid.clear();
        }
    }
}

// Exportar para uso global
window.QueryBrowserGrid = QueryBrowserGrid;
