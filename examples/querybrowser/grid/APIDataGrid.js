/**
 * APIDataGrid
 * Componente de grilla con scroll infinito y ordenamiento cíclico (asc → desc → sin orden)
 */
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
        this.sortField = null;
        this.sortOrder = null; // 'asc', 'desc', o null
        this.controlsContainer = this.container.querySelector('.grid-controls');
        this.loadingIndicator = this.container.querySelector('.grid-loading');
        this.errorContainer = this.container.querySelector('.grid-error');
        this.footerContainer = this.container.querySelector('.grid-footer');
        if (!this.footerContainer) {
            this.footerContainer = document.createElement('div');
            this.footerContainer.className = 'grid-footer';
            this.container.appendChild(this.footerContainer);
        }
        this.addControls();
        if (this.mode === 'infinite') this.enableInfiniteScroll();
        this.scrollCleanup = null;

        // Delegación de eventos
        this.container.addEventListener('click', (e) => {
            const th = e.target.closest('th');
            if (th && th.dataset.column) {
                this.sortBy(th.dataset.column);
            }
        });
    }

    addControls() {
        if (!this.controlsContainer) return;
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'grid-search';
        input.placeholder = 'Buscar...';
        let timeout;
        input.addEventListener('input', e => {
            clearTimeout(timeout);
            timeout = setTimeout(() => {
                this.searchTerm = e.target.value;
                this.resetAndFetch();
            }, 300);
        });
        this.controlsContainer.innerHTML = '';
        this.controlsContainer.appendChild(input);
    }

    buildParams() {
        const p = new URLSearchParams();
        const urlParts = this.apiUrl.split('?');
        if (urlParts.length > 1) new URLSearchParams(urlParts[1]).forEach((v, k) => p.set(k, v));
        p.set('page', this.currentPage);
        p.set('limit', this.pageSize);
        // Enviar sort solo si hay orden activo
        if (this.sortField && this.sortOrder) {
            const prefix = this.sortOrder === 'desc' ? '-' : '';
            p.set('sort', `${prefix}${this.sortField}`);
        }
        if (this.searchTerm) p.set('search', this.searchTerm);
        if (Object.keys(this.filter).length) p.set('filter', JSON.stringify(this.filter));
        return p;
    }

    async fetchData() {
        if (this.isLoading) return;
        this.isLoading = true;
        this.showLoading();
        this.hideError();
        try {
            const url = `${this.apiUrl.split('?')[0]}?${this.buildParams().toString()}`;
            const r = await fetch(url);
            if (!r.ok) throw new Error(`HTTP ${r.status}`);
            const d = await r.json();
            if (d.error) throw new Error(d.error);
            const rows = d.data || [];
            const meta = d.columns && d.titles ? { columns: d.columns, titles: d.titles } : null;
            const total = d.total || rows.length;
            const duration = d.stats?.duration ? (Number(d.stats.duration) / 1000).toFixed(4) + 's' : (d.time ? Number(d.time).toFixed(4) + 's' : 'N/A');
            this.footerContainer.innerHTML = `📄 Total: ${total.toLocaleString()} registros | ${d.columns?.length || rows[0]?.length || 0} columnas | 📃 Página ${d.page || this.currentPage} de ${d.last_page || '?'} | ⏱️ ${duration}`;
            
            if (rows.length > 0) {
                if (this.currentPage === 1) {
                    // Llamamos a nuestro propio método renderHeaders y renderBody
                    this.renderHeaders(meta);
                    this.renderBody(rows, meta);
                } else {
                    this.appendRows(rows);
                }
                this.hasMore = rows.length === this.pageSize;
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
        } catch(e) {
            console.error(e);
            this.showError(e.message);
        } finally {
            this.isLoading = false;
            this.hideLoading();
        }
    }

    /**
     * Dibuja las cabeceras con indicador de orden activo.
     */
    renderHeaders(metadata) {
        if (!metadata || !metadata.columns || !metadata.titles) {
            // si no hay metadata, no hacemos nada
            return;
        }
        let columns = metadata.columns;
        let titles = metadata.titles;
        // Filtrar columnas no deseadas (*, _total, COUNT...)
        const filtered = [];
        columns.forEach((col, idx) => {
            if (col !== '*' && col !== '_total' && !col.startsWith('COUNT(')) {
                filtered.push({ col, title: titles[idx] });
            }
        });
        this.columns = filtered.map(f => f.col);
        const html = filtered.map(({col, title}) => {
            let sortAttr = '';
            if (this.sortField === col && this.sortOrder) {
                sortAttr = ` data-sort="${this.sortOrder}"`;
            }
            return `<th data-column="${this.escapeHtml(col)}"${sortAttr} style="width:150px;">${this.escapeHtml(title)}<div class="grid-resizer"></div></th>`;
        }).join('');
        this.headerTemplate.innerHTML = html;
    }

    /**
     * Dibuja las filas del cuerpo.
     */
    renderBody(rows, metadata) {
        const isNum = Array.isArray(rows[0]);
        if (!isNum && (!this.columns || this.columns.length === 0)) {
            // Si no hay columnas definidas (caso raro), usar las llaves de la primera fila
            this.columns = Object.keys(rows[0]);
        }
        const html = rows.map(row => {
            const cells = isNum ? row : this.columns.map(k => row[k]);
            return `<tr>${cells.map(v => `<td>${v ?? ''}</td>`).join('')}</tr>`;
        }).join('');
        this.bodyContainer.innerHTML = html;
    }

    appendRows(rows) {
        if (!rows?.length) return;
        const isNum = Array.isArray(rows[0]);
        if (!isNum && (!this.columns || this.columns.length === 0) && rows[0]) {
            this.columns = Object.keys(rows[0]);
        }
        const html = rows.map(row => {
            const cells = isNum ? row : this.columns.map(k => row[k]);
            return `<tr>${cells.map(v => `<td>${v ?? ''}</td>`).join('')}</tr>`;
        }).join('');
        this.bodyContainer.insertAdjacentHTML('beforeend', html);
    }

    enableInfiniteScroll() {
        const sc = this.container.querySelector('.grid-scroll-wrapper');
        if (!sc) return;
        const onScroll = () => {
            if (!this.hasMore || this.isLoading) return;
            if (sc.scrollTop + sc.clientHeight >= sc.scrollHeight - 500) {
                this.currentPage++;
                this.fetchData();
            }
        };
        sc.addEventListener('scroll', onScroll);
        this.scrollCleanup = () => sc.removeEventListener('scroll', onScroll);
    }

    resetAndFetch() {
        this.currentPage = 1;
        this.hasMore = true;
        this.bodyContainer.innerHTML = '';
        this.fetchData();
    }

    /**
     * Ordenamiento cíclico: null → asc → desc → null
     */
    sortBy(field) {
        if (this.sortField !== field) {
            // Nueva columna → asc
            this.sortField = field;
            this.sortOrder = 'asc';
        } else {
            // Misma columna → ciclar
            if (this.sortOrder === 'asc') {
                this.sortOrder = 'desc';
            } else if (this.sortOrder === 'desc') {
                this.sortOrder = null;
                this.sortField = null;
            } else {
                this.sortOrder = 'asc';
            }
        }
        this.resetAndFetch();
    }

    setFilter(f) {
        this.filter = f;
        this.resetAndFetch();
    }

    showLoading() {
        if (this.loadingIndicator) this.loadingIndicator.style.display = 'block';
    }
    hideLoading() {
        if (this.loadingIndicator) this.loadingIndicator.style.display = 'none';
    }
    showError(m) {
        if (this.errorContainer) {
            this.errorContainer.textContent = 'Error: ' + m;
            this.errorContainer.style.display = 'block';
        }
    }
    hideError() {
        if (this.errorContainer) this.errorContainer.style.display = 'none';
    }
    load() {
        this.fetchData();
    }

    escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
}