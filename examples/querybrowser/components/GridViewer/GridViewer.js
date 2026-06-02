class GridViewer {
    constructor(queryBuilder) {
        this.qb = queryBuilder;
        this.grid = null;
    }

 init(parentElement) {
    const id = `grid-${this.qb.connectionId}-${this.qb.tables.join('_')}`;
    parentElement.innerHTML = `
        <div class=\"grid-container\" id=\"${id}\">\n            <div class=\"grid-controls\">\n                <div class=\"grid-search-wrapper\">\n                    <input type=\"text\" class=\"grid-search\" placeholder=\"Buscar...\" value=\"${this.qb.state.search}\" name=\"grid-search\" >\n                    <span class=\"kbd-hint\">/</span>\n                </div>\n                \n                <div class=\"grid-actions\">\n                    <button class=\"grid-btn-action btn-refresh\" title=\"Refrescar\">\n                        <span class=\"icon icon-refresh\"></span>\n                    </button>\n                    <button class=\"grid-btn-action btn-add\" title=\"Añadir Registro\">\n                        <span class=\"icon icon-add\"></span>\n                    </button>\n                    <button class=\"grid-btn-action btn-save\" title=\"Guardar Cambios\">\n                        <span class=\"icon icon-save\"></span>\n                    </button>\n                </div>\n            </div>\n            <div class=\"grid-scroll-wrapper\">\n                <table class=\"grid-table\">\n                    <thead class=\"grid-head\"></thead>\n                    <tbody class=\"grid-body\"></tbody>\n                </table>\n            </div>\n            <div class=\"grid-footer\">\n                <span class=\"grid-info\">Total registros: 0</span>\n                <div class=\"grid-loading\" style=\"display: none;\">Cargando...</div>\n                <div class=\"grid-error\" style=\"display: none;\"></div>\n            </div>\n        </div>\n    `;

    const container = parentElement.querySelector('.grid-container');
    const searchInput = container.querySelector('.grid-search');
    
    // Configuración para el API unificado usando FETCH_NUM o FETCH_ASSOC
    this.grid = new APIDataGrid(`#${id}`, 'api.php?action=grid_data', {
        mode: 'infinite',
        pageSize: 7
    });

    // Inyectar parámetros personalizados del QueryBuilder
    const origBuildParams = this.grid.buildParams.bind(this.grid);
    this.grid.buildParams = () => {
        const params = origBuildParams();
        params.set('connectionId', this.qb.connectionId);
        params.set('query', this.qb.buildSQL()); // Envía el SQL generado en tiempo real
        return params;
    };

    // Sobrescribir updateFooter para asegurar que use el formato correcto del QueryBrowser
    this.grid.updateFooter = (result) => {
        const footerContainer = container.querySelector('.grid-footer');
        if (!footerContainer) return;
        
        const total = result.total !== undefined ? result.total : (result.data ? result.data.length : 0);
        const duration = result.durationMs !== undefined ? `${result.durationMs.toFixed(2)} ms` : '';
        
        const infoText = footerContainer.querySelector('.grid-info');
        if (infoText) {
            infoText.textContent = `Total registros: ${total} ${duration ? `(${duration})` : ''}`;
        }
    };

    // Vincular controles nativos de la interfaz de GridViewer
    container.querySelector('.btn-refresh')?.addEventListener('click', () => this.grid.resetAndFetch());
    
    // Detener la ordenación/recarga si se está aplicando redimensionamiento de columnas
    container.querySelector('.grid-head')?.addEventListener('click', (e) => {
        // CORRECCIÓN PROTECCIÓN DE REDIMENSIONADO: 
        // Si el grid heredado está cambiando el tamaño o se hizo click en el resizer, matamos la acción
        if (this.grid.isResizing || e.target.closest('.grid-resizer')) {
            e.preventDefault();
            e.stopPropagation();
            return;
        }

        const th = e.target.closest('th');
        if (!th || !th.dataset.column) return;
        
        const col = th.dataset.column;
        const qualified = this.qb._qualifyColumn(col);
        const current = this.qb.state.sort.length > 0 && this.qb.state.sort[0].field === qualified\n            ? this.qb.state.sort[0]\n            : { field: null, order: null };

        if (current.field === qualified) {
            if (current.order === 'asc') {
                this.qb.state.sort = [{ field: qualified, order: 'desc' }];
            } else {
                this.qb.state.sort = [];
            }
        } else {
            this.qb.state.sort = [{ field: qualified, order: 'asc' }];
        }
        
        this.qb._updateQuery();
    });

    document.addEventListener('keydown', (e) => {
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
        if (e.key === '/' && this.grid.container.contains(document.activeElement) === false) {
            e.preventDefault();
            searchInput?.focus();
        }
    });
 }

    onActivate() {
        if (this.grid && typeof this.grid.setSort === 'function') {
            const primarySort = this.qb.state.sort.length > 0 ? this.qb.state.sort[0] : { field: null, order: null };
            const shortField = primarySort.field ? primarySort.field.split('.').pop() : null;
            this.grid.setSort(shortField, primarySort.order);
        }
        if (this.grid) {
            this.grid.resetAndFetch();
        }
    }
}

window.GridViewer = GridViewer;