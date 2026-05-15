class GridViewer {
    constructor(queryBuilder) {
        this.qb = queryBuilder;
        this.grid = null;
    }

    init(parentElement) {
        const id = `grid-${this.qb.connectionId}-${this.qb.tables.join('_')}`;
        parentElement.innerHTML = `
            <div class="grid-container" id="${id}">
                <div class="grid-controls">
                    <div class="grid-search-wrapper">
                        <input type="text" class="grid-search" placeholder="Buscar..." value="${this.qb.state.search}">
                        <span class="kbd-hint">/</span>
                    </div>
                </div>
                <div class="grid-scroll-wrapper">
                    <table class="grid-table">
                        <thead class="grid-head"></thead>
                        <tbody class="grid-body"></tbody>
                    </table>
                </div>
                <div class="grid-footer"></div>
                <div class="grid-loading" style="display:none;">Cargando...</div>
                <div class="grid-error" style="display:none;"></div>
            </div>
        `;

        const apiUrl = `api/v1/index.php?ep=Grid&action=data`;
        this.grid = new APIDataGrid(`#${id}`, apiUrl, { mode: 'infinite', pageSize: 50 });

        // Desactivar ordenamiento automático del grid
        this.grid.sortBy = () => {};

        const origBuildParams = this.grid.buildParams.bind(this.grid);
        this.grid.buildParams = () => {
            const p = origBuildParams();
            p.set('connectionId', this.qb.connectionId);
            p.set('table', JSON.stringify(this.qb.tables));
            if (this.qb.state.search) p.set('search', this.qb.state.search);

            // Enviar sort (array → string compacto)
            if (this.qb.state.sort.length > 0) {
                const sortString = this.qb.state.sort.map(s => {
                    const q = this.qb._qualifySortField(s.field);
                    return `${s.order === 'desc' ? '-' : ''}${q}`;
                }).join(',');
                p.set('sort', sortString);
            }

            // Enviar columnas seleccionadas
            if (this.qb.state.columns && this.qb.state.columns.length > 0) {
                p.set('columns', JSON.stringify(this.qb.state.columns));
            }

            return p;
        };

        this._bindEvents();
        this.grid.load();
    }

    _bindEvents() {
        const searchInput = this.grid.container.querySelector('.grid-search');
        if (searchInput) {
            let timeout;
            searchInput.addEventListener('input', (e) => {
                clearTimeout(timeout);
                timeout = setTimeout(() => {
                    this.qb.state.search = e.target.value;
                    this.qb._updateQuery();
                }, 300);
            });
        }

        // Ordenación por cabeceras (sincronizada con el estado array)
        this.grid.container.addEventListener('click', (e) => {
            const th = e.target.closest('th');
            if (!th || !th.dataset.column) return;
            const col = th.dataset.column;
            const qualified = this.qb._qualifyColumn(col);
            const current = this.qb.state.sort.length > 0 && this.qb.state.sort[0].field === qualified
                ? this.qb.state.sort[0]
                : { field: null, order: null };

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
        // Restaurar flechas del grid
        if (this.grid && typeof this.grid.setSort === 'function') {
            const primarySort = this.qb.state.sort.length > 0 ? this.qb.state.sort[0] : { field: null, order: null };
            const shortField = primarySort.field ? primarySort.field.split('.').pop() : null;
            this.grid.setSort(shortField, primarySort.order);
        }
    }
}