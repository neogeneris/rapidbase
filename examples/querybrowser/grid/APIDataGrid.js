/**
 * APIDataGrid
 * 
 * Componente de grilla que se conecta a una API para cargar datos dinámicamente.
 * Soporta paginación y scroll infinito.
 * 
 * El formato de respuesta esperado es el que produce DB::grid a través de su método toGridFormat():
 * {
 *   head: { columns: string[], titles: string[] },
 *   data: array[array] (filas en formato FETCH_NUM),
 *   page: { current: number, total: number, records: number },
 *   stats: { ... }
 * }
 */
class APIDataGrid extends GridBuilder {
    constructor(containerSelector, apiUrl, options = {}) {
        super(containerSelector);
        
        this.apiUrl = apiUrl;
        this.mode = options.mode || 'pagination'; // 'pagination' o 'infinite'
        this.currentPage = 1;
        this.offset = 0;
        this.sortField = null;
        this.sortOrder = 'asc';
        this.searchTerm = '';
        this.pageSize = options.pageSize || 20;
        this.hasMore = true;
        this.isLoading = false;
        this.filter = options.filter || {};

        // Elementos del DOM
        this.controlsContainer = this.container.querySelector('.grid-controls');
        this.loadingIndicator = this.container.querySelector('.grid-loading');
        this.errorContainer = this.container.querySelector('.grid-error');

        // Inicializar controles
        this.addControls();

        // Configurar paginador si existe y estamos en modo paginación
        if (this.mode === 'pagination') {
            const paginatorElement = this.container.querySelector('.grid-paginator');
            if (paginatorElement && typeof Paginator !== 'undefined') {
                this.paginator = new Paginator(paginatorElement, (page) => {
                    this.currentPage = page;
                    this.offset = (page - 1) * this.pageSize;
                    this.fetchData();
                });
            }
        }

        // Habilitar scroll infinito si corresponde
        if (this.mode === 'infinite') {
            this.enableInfiniteScroll();
        }

        // Almacenar referencia a la función de limpieza del scroll
        this.scrollCleanup = null;
    }

    /**
     * Agrega controles de búsqueda y filtros
     */
    addControls() {
        if (!this.controlsContainer) return;

        // Input de búsqueda
        const searchInput = document.createElement('input');
        searchInput.type = 'text';
        searchInput.className = 'grid-search';
        searchInput.placeholder = 'Buscar...';
        
        let searchTimeout;
        searchInput.addEventListener('input', (e) => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                this.searchTerm = e.target.value;
                this.resetAndFetch();
            }, 300);
        });

        this.controlsContainer.innerHTML = '';
        this.controlsContainer.appendChild(searchInput);
    }

    /**
     * Construye los parámetros de la URL
     */
    buildParams() {
        const params = new URLSearchParams();
        
        // Extraer parámetros existentes de la URL base si los hay
        const urlParts = this.apiUrl.split('?');
        if (urlParts.length > 1) {
            const existingParams = new URLSearchParams(urlParts[1]);
            for (const [key, value] of existingParams.entries()) {
                params.set(key, value);
            }
        }
        
        // Agregar parámetros del grid
        params.set('offset', this.offset);
        params.set('limit', this.pageSize);

        if (this.sortField) {
            const sortPrefix = this.sortOrder === 'desc' ? '-' : '';
            params.set('sort', `${sortPrefix}${this.sortField}`);
        }

        if (this.searchTerm) {
            params.set('search', this.searchTerm);
        }

        if (Object.keys(this.filter).length > 0) {
            params.set('filter', JSON.stringify(this.filter));
        }

        return params;
    }

    /**
     * Obtiene datos de la API
     */
    async fetchData() {
        if (this.isLoading) return;

        this.isLoading = true;
        this.showLoading();
        this.hideError();

        try {
            const params = this.buildParams();
            const urlBase = this.apiUrl.split('?')[0];
            const url = `${urlBase}?${params.toString()}`;
            
            const response = await fetch(url);
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const result = await response.json();

            if (this.mode === 'pagination') {
                const gridData = result.data || [];
                const metadata = result.head || null;
                
                this.render(gridData, metadata);
                
                if (this.paginator && result.page) {
                    this.paginator.update(result.page.current, result.page.total);
                }
            } 
            else if (this.mode === 'infinite') {
                const newRows = result.data || [];
                const metadata = result.head || null;
                
                if (newRows.length > 0) {
                    // Si es la primera carga (offset === 0) reemplazamos todo
                    if (this.offset === 0) {
                        this.render(newRows, metadata);
                    } else {
                        this.appendRows(newRows, metadata);
                    }
                    // Determinar si hay más datos
                    this.hasMore = result.page ? result.page.current < result.page.total : false;
                    if (!this.hasMore && this.scrollCleanup) {
                        this.scrollCleanup();
                        this.scrollCleanup = null;
                    }
                } else {
                    this.hasMore = false;
                    if (this.scrollCleanup) {
                        this.scrollCleanup();
                        this.scrollCleanup = null;
                    }
                }
            }

        } catch (error) {
            console.error('Error fetching data:', error);
            this.showError(error.message);
        } finally {
            this.isLoading = false;
            this.hideLoading();
        }
    }

    /**
     * Agrega filas al grid (modo scroll infinito)
     * @param {Array} data - Nuevas filas (formato FETCH_NUM)
     * @param {Object|null} metadata - Metadatos con columnas y títulos (se usa solo si no hay encabezado previo)
     */
    appendRows(data, metadata = null) {
        if (!data || data.length === 0) return;

        // Si no hay encabezados renderizados aún, hacer render completo
        if (this.bodyContainer.querySelector('.grid-row') === null && metadata) {
            this.render(data, metadata);
            return;
        }

        const isNumericArray = Array.isArray(data[0]);
        
        data.forEach((row, rowIndex) => {
            let rowHTML = this.rowTemplate ? this.rowTemplate.innerHTML : '';
            
            // Si no hay plantilla de fila, crear una dinámica
            if (!this.rowTemplate) {
                const colsCount = isNumericArray ? row.length : Object.keys(row).length;
                if (isNumericArray) {
                    rowHTML = Array.from({length: colsCount}, (_, i) => 
                        `<div class="grid-item">{${i}}</div>`
                    ).join('');
                } else {
                    const keys = Object.keys(row);
                    rowHTML = keys.map(key => 
                        `<div class="grid-item">{${key}}</div>`
                    ).join('');
                }
            }

            // Reemplazar placeholders
            if (isNumericArray) {
                row.forEach((value, colIndex) => {
                    const placeholder = new RegExp(`\\{${colIndex}\\}`, 'g');
                    rowHTML = rowHTML.replace(placeholder, this.escapeHtml(value));
                });
            } else {
                Object.keys(row).forEach(key => {
                    const placeholder = new RegExp(`\\{${key}\\}`, 'g');
                    rowHTML = rowHTML.replace(placeholder, this.escapeHtml(row[key]));
                });
            }
            
            const absoluteRowIndex = this.offset + rowIndex;
            this.bodyContainer.insertAdjacentHTML('beforeend', 
                `<div class="grid-row" data-row-index="${absoluteRowIndex}">${rowHTML}</div>`
            );
        });
    }

    /**
     * Habilita el scroll infinito sobre el contenedor .grid-body
     */
    enableInfiniteScroll() {
        // El contenedor de scroll es el elemento con clase .grid-body
        const scrollContainer = this.bodyContainer;
        if (!scrollContainer) return;

        const onScroll = () => {
            if (!this.hasMore || this.isLoading) return;
            
            const { scrollTop, scrollHeight, clientHeight } = scrollContainer;
            // Cargar más cuando falten 200px para llegar al final
            if (scrollTop + clientHeight >= scrollHeight - 200) {
                this.offset += this.pageSize;
                this.fetchData();
            }
        };
        
        scrollContainer.addEventListener('scroll', onScroll);
        this.scrollCleanup = () => scrollContainer.removeEventListener('scroll', onScroll);
        
        // También permite que el scroll se ejecute cuando se redimensiona el contenedor
        window.addEventListener('resize', () => {
            if (!this.hasMore || this.isLoading) return;
            const { scrollTop, scrollHeight, clientHeight } = scrollContainer;
            if (scrollTop + clientHeight >= scrollHeight - 200) {
                this.offset += this.pageSize;
                this.fetchData();
            }
        });
    }

    /**
     * Reinicia y carga datos (primera página, limpia grid)
     */
    resetAndFetch() {
        this.currentPage = 1;
        this.offset = 0;
        this.hasMore = true;
        this.clear();
        this.fetchData();
    }

    /**
     * Ordena por una columna
     */
    sortBy(field) {
        if (this.sortField === field) {
            // Alternar orden
            this.sortOrder = this.sortOrder === 'asc' ? 'desc' : 'asc';
        } else {
            this.sortField = field;
            this.sortOrder = 'asc';
        }
        this.resetAndFetch();
    }

    /**
     * Establece un filtro adicional
     */
    setFilter(filter) {
        this.filter = filter;
        this.resetAndFetch();
    }

    /**
     * Muestra indicador de carga
     */
    showLoading() {
        if (this.loadingIndicator) {
            this.loadingIndicator.style.display = 'block';
        }
    }

    /**
     * Oculta indicador de carga
     */
    hideLoading() {
        if (this.loadingIndicator) {
            this.loadingIndicator.style.display = 'none';
        }
    }

    /**
     * Muestra mensaje de error
     */
    showError(message) {
        if (this.errorContainer) {
            this.errorContainer.textContent = `Error: ${message}`;
            this.errorContainer.style.display = 'block';
        }
    }

    /**
     * Oculta mensaje de error
     */
    hideError() {
        if (this.errorContainer) {
            this.errorContainer.style.display = 'none';
        }
    }

    /**
     * Carga inicial de datos
     */
    load() {
        this.fetchData();
    }
}