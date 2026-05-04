/**
 * APIDataGrid
 * 
 * Componente de grilla que se conecta a una API para cargar datos dinámicamente.
 * Soporta paginación y scroll infinito.
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

        // Configurar paginator si existe
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
        
        // Debounce para la búsqueda
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
            const url = `${this.apiUrl}?${params.toString()}`;
            
            const response = await fetch(url);
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const result = await response.json();

            if (this.mode === 'pagination') {
                this.render(result.data || [], result.metadata || null);
                
                if (this.paginator && result.total !== undefined) {
                    const totalPages = Math.ceil(result.total / this.pageSize);
                    this.paginator.update(this.currentPage, totalPages);
                }
            } else if (this.mode === 'infinite') {
                if (result.data && result.data.length > 0) {
                    this.appendRows(result.data, result.metadata || null);
                    this.hasMore = result.hasMore !== undefined 
                        ? result.hasMore 
                        : result.data.length === this.pageSize;
                } else {
                    this.hasMore = false;
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
     */
    appendRows(data, metadata = null) {
        if (!data || data.length === 0) return;

        // Si es el primer lote, renderizar completo con metadata
        if (this.bodyContainer.querySelector('.grid-row') === null && metadata) {
            this.render(data, metadata);
            return;
        }

        // Para lotes subsiguientes, solo agregar las filas
        const isNumericArray = Array.isArray(data[0]);
        
        data.forEach((row, rowIndex) => {
            let rowHTML = this.rowTemplate ? this.rowTemplate.innerHTML : '';
            
            // Si no hay plantilla, crear una dinámica
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
            
            this.bodyContainer.insertAdjacentHTML('beforeend', 
                `<div class="grid-row" data-row-index="${this.offset + rowIndex}">${rowHTML}</div>`
            );
        });
    }

    /**
     * Habilita el scroll infinito
     */
    enableInfiniteScroll() {
        const scrollContainer = this.bodyContainer.closest('.grid-body') || 
                                this.bodyContainer.parentElement || 
                                window;

        scrollContainer.addEventListener('scroll', () => {
            if (!this.hasMore || this.isLoading) return;

            const { scrollTop, scrollHeight, clientHeight } = 
                scrollContainer === window 
                    ? { scrollTop: window.scrollY, scrollHeight: document.documentElement.scrollHeight, clientHeight: window.innerHeight }
                    : scrollContainer;

            // Cuando estamos cerca del final (100px)
            if (scrollTop + clientHeight >= scrollHeight - 100) {
                this.offset += this.pageSize;
                this.fetchData();
            }
        });
    }

    /**
     * Reinicia y carga datos
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
     * Establece un filtro
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
