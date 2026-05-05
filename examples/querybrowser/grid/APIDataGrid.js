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
        this.mode = options.mode || 'pagination';
        this.currentPage = 1;
        this.sortField = null;
        this.sortOrder = 'asc';
        this.searchTerm = '';
        this.pageSize = options.pageSize || 20;
        this.hasMore = true;
        this.isLoading = false;
        this.filter = options.filter || {};

        this.controlsContainer = this.container.querySelector('.grid-controls');
        this.loadingIndicator = this.container.querySelector('.grid-loading');
        this.errorContainer = this.container.querySelector('.grid-error');

        this.addControls();

        if (this.mode === 'pagination') {
            const paginatorElement = this.container.querySelector('.grid-paginator');
            if (paginatorElement && typeof Paginator !== 'undefined') {
                this.paginator = new Paginator(paginatorElement, (page) => {
                    this.currentPage = page;
                    this.fetchData();
                });
            }
        }

        if (this.mode === 'infinite') {
            this.enableInfiniteScroll();
        }

        this.scrollCleanup = null;
    }

    addControls() {
        if (!this.controlsContainer) return;

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

    buildParams() {
        const params = new URLSearchParams();
        
        const urlParts = this.apiUrl.split('?');
        if (urlParts.length > 1) {
            const existingParams = new URLSearchParams(urlParts[1]);
            for (const [key, value] of existingParams.entries()) {
                params.set(key, value);
            }
        }
        
        params.set('page', this.currentPage);
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

            if (result.error) {
                throw new Error(result.error);
            }

            const newRows = result.data || [];
            const metadata = result.columns && result.titles ? { columns: result.columns, titles: result.titles } : null;

            if (this.mode === 'pagination') {
                this.render(newRows, metadata);
                if (this.paginator && result.last_page) {
                    this.paginator.update(result.page, result.last_page);
                }
            } 
            else if (this.mode === 'infinite') {
                if (newRows.length > 0) {
                    if (this.currentPage === 1) {
                        this.render(newRows, metadata);
                    } else {
                        this.appendRows(newRows, metadata);
                    }
                    // hasMore: true si la página retornó el máximo de filas (hay más)
                    // La última página retorna menos de pageSize filas
                    this.hasMore = newRows.length === this.pageSize;
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

    appendRows(data, metadata = null) {
        if (!data || data.length === 0) return;

        if (this.bodyContainer.querySelector('.grid-row') === null && metadata) {
            this.render(data, metadata);
            return;
        }

        const isNumericArray = Array.isArray(data[0]);
        
        data.forEach((row, rowIndex) => {
            const rowDiv = document.createElement('div');
            rowDiv.className = 'grid-row';
            if (isNumericArray) {
                row.forEach(value => {
                    const cell = document.createElement('div');
                    cell.className = 'grid-item';
                    cell.textContent = value !== null && value !== undefined ? value : '';
                    rowDiv.appendChild(cell);
                });
            } else {
                this.columns.forEach(key => {
                    const cell = document.createElement('div');
                    cell.className = 'grid-item';
                    cell.textContent = row[key] !== null && row[key] !== undefined ? row[key] : '';
                    rowDiv.appendChild(cell);
                });
            }
            this.bodyContainer.appendChild(rowDiv);
        });
    }

    enableInfiniteScroll() {
        const scrollContainer = this.bodyContainer;
        if (!scrollContainer) return;

        const onScroll = () => {
            if (!this.hasMore || this.isLoading) return;
            
            const { scrollTop, scrollHeight, clientHeight } = scrollContainer;
            if (scrollTop + clientHeight >= scrollHeight - 200) {
                this.currentPage++;
                this.fetchData();
            }
        };
        
        scrollContainer.addEventListener('scroll', onScroll);
        this.scrollCleanup = () => scrollContainer.removeEventListener('scroll', onScroll);
    }

    resetAndFetch() {
        this.currentPage = 1;
        this.hasMore = true;
        this.clear();
        this.fetchData();
    }

    sortBy(field) {
        if (this.sortField === field) {
            this.sortOrder = this.sortOrder === 'asc' ? 'desc' : 'asc';
        } else {
            this.sortField = field;
            this.sortOrder = 'asc';
        }
        this.resetAndFetch();
    }

    setFilter(filter) {
        this.filter = filter;
        this.resetAndFetch();
    }

    showLoading() {
        if (this.loadingIndicator) this.loadingIndicator.style.display = 'block';
    }

    hideLoading() {
        if (this.loadingIndicator) this.loadingIndicator.style.display = 'none';
    }

    showError(message) {
        if (this.errorContainer) {
            this.errorContainer.textContent = `Error: ${message}`;
            this.errorContainer.style.display = 'block';
        }
    }

    hideError() {
        if (this.errorContainer) this.errorContainer.style.display = 'none';
    }

    load() {
        this.fetchData();
    }
}