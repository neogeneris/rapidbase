class APIDataGrid extends GridBuilder {
    constructor(containerSelector, apiUrl, options = {}) {
        super(containerSelector);

        this.apiUrl = apiUrl;
        this.mode = options.mode || 'infinite';
        this.currentPage = 1;
        this.pageSize = options.pageSize || 30;
        this.hasMore = true;
        this.isLoading = false;
        this.filter = options.filter || {};
        this.searchTerm = '';
        this.lastResponse = null;

        this.searchInput = this.container.querySelector('.grid-search');
        this.loadingIndicator = this.container.querySelector('.grid-loading');
        this.errorContainer = this.container.querySelector('.grid-error');
        this.footerContainer = this.container.querySelector('.grid-footer');

        this._bindEvents();
        if (this.mode === 'infinite') this._enableInfiniteScroll();
    }

    _bindEvents() {
        if (this.searchInput) {
            let timeout;
            this.searchInput.addEventListener('input', e => {
                clearTimeout(timeout);
                timeout = setTimeout(() => {
                    this.searchTerm = e.target.value;
                    this.resetAndFetch();
                }, 300);
            });
        }

        if (this.headContainer) {
            this.headContainer.addEventListener('click', e => {
                // ── FILTRO ANTI-REFRESCO AL REDIMENSIONAR ──
                // Si el flag de redimensionamiento heredado está activo, matamos el evento
                if (this.isResizing || e.target.closest('.grid-resizer')) {
                    e.preventDefault();
                    e.stopPropagation();
                    return;
                }

                const header = e.target.closest('th, .grid-header');
                if (header && header.dataset.column) {
                    this.sortBy(header.dataset.column);
                }
            });
        }
    }

    buildParams() {
        const params = new URLSearchParams();
        params.set('page', this.currentPage);
        params.set('pageSize', this.pageSize);
        if (this.searchTerm) params.set('search', this.searchTerm);
        if (this.sortField) {
            params.set('sortField', this.sortField);
            params.set('sortOrder', this.sortOrder);
        }
        
        if (this.filter && typeof this.filter === 'object') {
            Object.keys(this.filter).forEach(k => {
                params.set(`filter[${k}]`, this.filter[k]);
            });
        }
        return params;
    }

    async fetchData() {
        if (this.isLoading || !this.hasMore) return;
        this.isLoading = true;
        this._showLoading();
        if (this.errorContainer) this.errorContainer.style.display = 'none';

        try {
            const params = this.buildParams();
            const url = `${this.apiUrl}${this.apiUrl.includes('?') ? '&' : '?'}${params.toString()}`;
            
            const response = await fetch(url);
            if (!response.ok) throw new Error(`HTTP Error: ${response.status}`);
            
            const result = await response.json();
            
            if (result.success) {
                this.lastResponse = result;

                if (result.columns) {
                    this.setColumns(result.columns, result.titles || []);
                }

                if (result.data && result.data.length > 0) {
                    result.data.forEach(row => {
                        this.appendRow(row); 
                    });

                    if (result.data.length < this.pageSize) {
                        this.hasMore = false;
                    } else {
                        this.currentPage++;
                    }
                } else {
                    this.hasMore = false;
                    if (this.currentPage === 1) {
                        this._showError('No se encontraron registros.');
                    }
                }

                this.updateFooter(result);
            } else {
                throw new Error(result.error || 'Error desconocido en el servidor');
            }
        } catch (error) {
            console.error("Grid Fetch Error:", error);
            this._showError(error.message);
            this.hasMore = false;
        } finally {
            this.isLoading = false;
            this._hideLoading();
        }
    }

    load() {
        this.resetAndFetch();
    }

    _enableInfiniteScroll() {
        const sc = this.container.querySelector('.grid-scrollable') || this.container;
        
        const onScroll = () => {
            if (this.mode !== 'infinite') return;
            const threshold = 50; 
            const isNearBottom = sc.scrollHeight - sc.scrollTop - sc.clientHeight <= threshold;
            
            if (isNearBottom && !this.isLoading && this.hasMore) {
                this.fetchData();
            }
        };

        sc.addEventListener('scroll', onScroll);
        this._scrollCleanup = () => sc.removeEventListener('scroll', onScroll);
    }

    resetAndFetch() {
        this.currentPage = 1;
        this.hasMore = true;
        this.clear();
        this.fetchData();
    }

    sortBy(field) {
        if (this.sortField !== field) {
            this.sortField = field;
            this.sortOrder = 'asc';
        } else {
            if (this.sortOrder === 'asc') this.sortOrder = 'desc';
            else if (this.sortOrder === 'desc') {
                this.sortOrder = null;
                this.sortField = null;
            } else this.sortOrder = 'asc';
        }
        this.updateSortIndicator();
        this.resetAndFetch();
    }

    setSort(field, order) {
        this.sortField = field;
        this.sortOrder = order;
        this.updateSortIndicator();
    }

    setFilter(f) { 
        this.filter = f; 
        this.resetAndFetch(); 
    }

    updateFooter(result) {
        if (!this.footerContainer) return;
        const total = result.total !== undefined ? result.total : 0;
        const duration = result.durationMs !== undefined ? `${result.durationMs.toFixed(2)} ms` : '';
        
        const infoText = this.footerContainer.querySelector('.grid-info') || this.footerContainer;
        if (infoText) {
            infoText.textContent = `Total registros: ${total} ${duration ? `(${duration})` : ''}`;
        }
    }

    _showLoading() { 
        if (this.loadingIndicator) this.loadingIndicator.style.display = 'block'; 
    }
    
    _hideLoading() { 
        if (this.loadingIndicator) this.loadingIndicator.style.display = 'none'; 
    }
    
    _showError(m) {
        if (this.errorContainer) {
            this.errorContainer.textContent = m;
            this.errorContainer.style.display = 'block';
        }
    }

    destroy() {
        if (typeof this._scrollCleanup === 'function') {
            this._scrollCleanup();
        }
        this.clear();
    }
}

window.APIDataGrid = APIDataGrid;