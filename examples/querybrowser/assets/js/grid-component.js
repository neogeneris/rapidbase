/**
 * Grid Component para QueryBrowser
 * Integra APIDataGrid con el API del querybrowser
 */

// Cargar scripts del grid en orden
const gridScripts = [
    '/grid/GridBuilder.js',
    '/grid/Paginator.js',
    '/grid/APIDataGrid.js'
];

async function loadGridScripts() {
    for (const src of gridScripts) {
        await new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = src;
            script.onload = resolve;
            script.onerror = reject;
            document.head.appendChild(script);
        });
    }
}

/**
 * Crea un grid para una tabla específica
 * @param {string} containerSelector - Selector del contenedor
 * @param {string} connectionId - ID de la conexión activa
 * @param {string} tableName - Nombre de la tabla
 * @param {object} options - Opciones adicionales
 */
function createTableGrid(containerSelector, connectionId, tableName, options = {}) {
    const apiUrl = `api.php?action=grid_data&connectionId=${encodeURIComponent(connectionId)}&table=${encodeURIComponent(tableName)}`;
    
    const defaultOptions = {
        mode: 'pagination',
        pageSize: 20
    };
    
    const gridOptions = { ...defaultOptions, ...options };
    
    return new APIDataGrid(containerSelector, apiUrl, gridOptions);
}

/**
 * Inicializa el grid en el querybrowser
 */
async function initQueryBrowserGrid() {
    try {
        await loadGridScripts();
        console.log('Grid component loaded successfully');
    } catch (error) {
        console.error('Error loading grid scripts:', error);
    }
}

// Auto-inicializar cuando el DOM esté listo
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initQueryBrowserGrid);
} else {
    initQueryBrowserGrid();
}
