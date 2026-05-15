/**
 * APIDataGrid – Componente de grilla con scroll infinito y ordenamiento cíclico.
 * Ahora guarda la última respuesta y ofrece fallback para columnas.
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
        this.sortOrder = null;
        this.lastResponse = null;   // ← guarda la última respuesta completa
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
            if (!r.ok) {
                let errMessage = `HTTP ${r.status}`;
                try {
                    const errData = await r.json();
                    if (errData.error) errMessage = errData.error;
                } catch(e) {}
                throw new Error(errMessage);
            }
            const d = await r.json();
            this.lastResponse = d;   // ← guardar respuesta completa

            if (d.error) throw new Error(d.error);

            const rows = d.data || [];
            let meta = null;

            // 1. Intentar metadata del servidor
            if (d.columns && d.titles) {
                meta = { columns: d.columns, titles: d.titles };
            }
            // 2. Fallback desde los datos
            else if (rows.length > 0) {
                if (Array.isArray(rows[0])) {
                    const cols = rows[0].map((_, i) => `col_${i}`);
                    meta = {
                        columns: cols,
                        titles: cols.map(c => c.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()))
                    };
                } else if (typeof rows[0] === 'object') {
                    const keys = Object.keys(rows[0]);
                    meta = {
                        columns: keys,
                        titles: keys.map(k => k.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()))
                    };
                }
            }

            const total = d.total ?? rows.length;
            const colCount = meta ? meta.columns.length : (rows[0]?.length || 0);
            const duration = d.stats?.duration
                ? (Number(d.stats.duration) / 1000).toFixed(4) + 's'
                : (d.time ? Number(d.time).toFixed(4) + 's' : 'N/A');

            this.footerContainer.innerHTML = `📄 Total: ${total.toLocaleString()} registros | ` +
                `${colCount} columnas | 📃 Página ${d.page || this.currentPage} de ${d.last_page || '?'} | ⏱️ ${duration}`;

            if (rows.length > 0) {
                if (this.currentPage === 1) {
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
                if (this.currentPage === 1) {
                    this.bodyContainer.innerHTML = `<div class="grid-empty-state">…</div>`;
                }
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

    renderHeaders(metadata) {
        if (!metadata || !metadata.columns || !metadata.titles) return;
        let columns = metadata.columns;
        let titles = metadata.titles;
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
            return `<th data-column="${this.escapeHtml(col)}"${sortAttr}>${this.escapeHtml(title)}</th>`;
        }).join('');
        this.headerTemplate.innerHTML = html;
    }

    renderBody(rows, metadata) {
        const isNum = Array.isArray(rows[0]);
        if (!isNum && (!this.columns || this.columns.length === 0)) {
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

    sortBy(field) {
        if (this.sortField !== field) {
            this.sortField = field;
            this.sortOrder = 'asc';
        } else {
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

    setSort(field, order) {
        this.sortField = field;
        this.sortOrder = order;
        this.updateSortIndicator();
    }

    setFilter(f) { this.filter = f; this.resetAndFetch(); }

    showLoading() { if (this.loadingIndicator) this.loadingIndicator.style.display = 'block'; }
    hideLoading() { if (this.loadingIndicator) this.loadingIndicator.style.display = 'none'; }
    showError(m) {
        if (this.errorContainer) {
            this.errorContainer.textContent = 'Error: ' + m;
            this.errorContainer.style.display = 'block';
        }
    }
    hideError() { if (this.errorContainer) this.errorContainer.style.display = 'none'; }
    load() { this.fetchData(); }

    escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
}