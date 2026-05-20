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
                if (e.target.closest('.grid-resizer')) return;
                const header = e.target.closest('th[data-column]');
                if (header) {
                    this.sortBy(header.dataset.column);
                }
            });
        }
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
        this._showLoading();
        this._hideError();

        if (this.lastResponse?.last_page && this.currentPage > this.lastResponse.last_page) {
            this.currentPage = this.lastResponse.last_page;
        }

        try {
            const fetchStart = performance.now();
            const url = `${this.apiUrl.split('?')[0]}?${this.buildParams().toString()}`;
            const r = await fetch(url);
            if (!r.ok) throw new Error(`HTTP ${r.status}`);
            const d = await r.json();
            const fetchEnd = performance.now();

            this.lastResponse = d;

            if (d.error) throw new Error(d.error);

            const rows = d.data || [];
            const meta = d.columns && d.titles ? { columns: d.columns, titles: d.titles } : null;

            if (this.currentPage === 1) {
                this.render(rows, meta);
            } else {
                this._appendRows(rows);
            }

            this.hasMore = this.currentPage < (d.last_page || 1);

            // ─── Footer con tiempos ─────────────────────────────
            const totalMs = fetchEnd - fetchStart;
            const backendMs = d.stats?.duration || 0;
            const networkMs = Math.max(0, totalMs - backendMs);
            const sqlTime = backendMs ? (backendMs / 1000).toFixed(7) + 's' : 'N/A';
            const netTime = networkMs ? (networkMs/1000).toFixed(7) +'s' : 'N/A';

            if (this.footerContainer) {
                const total = d.total ?? rows.length;
                const colCount = d.columns?.length || this.columns.length;
                this.footerContainer.textContent =
                    `📄 Total: ${total.toLocaleString()} registros | ` +
                    `${colCount} columnas | 📃 Página ${d.page || this.currentPage} de ${d.last_page || '?'} | ` +
                    `⏱️ SQL ${sqlTime} | 🌐 Red ${netTime}`;
            }

        } catch (e) {
            console.error(e);
            this._showError(e.message);
        } finally {
            this.isLoading = false;
            this._hideLoading();
        }
    }

    _appendRows(rows) {
        if (!rows?.length) return;
        const rowHtml = this.rowTemplate.outerHTML;
        rows.forEach(row => {
            const clone = this._createElement(rowHtml);
            clone.style.display = '';
            let html = clone.outerHTML;

            row.forEach((val, idx) => {
                html = html.replace(new RegExp(`\\$\\{${idx}\\}`, 'g'), val ?? '');
            });
            this.columns.forEach((colName, idx) => {
                if (colName) html = html.replace(new RegExp(`\\$\\{${colName}\\}`, 'g'), row[idx] ?? '');
            });
            if (html.includes('${value}')) {
                let dataCells = '';
                row.forEach(val => { dataCells += `<td class="grid-item">${val ?? ''}</td>`; });
                html = html.replace(/<td[^>]*>\s*\$\{value\}\s*<\/td>/, dataCells);
            }

            const finalEl = this._createElement(html);
            finalEl.style.display = '';
            this.bodyContainer.appendChild(finalEl);
        });
    }

    _enableInfiniteScroll() {
        // Find the nearest scrollable ancestor of the grid container.
        // The scroll never happens on tbody; it happens on the parent pane
        // (.qb-grid-pane, .se-results-pane, or .grid-scroll-wrapper).
        let sc = null;
        let el = this.container;
        while (el && el !== document.documentElement) {
            const style = window.getComputedStyle(el);
            if (style.overflowY === 'auto' || style.overflowY === 'scroll' ||
                style.overflow === 'auto' || style.overflow === 'scroll') {
                sc = el;
                break;
            }
            el = el.parentElement;
        }
        if (!sc) {
            sc = this.container.querySelector('.grid-scroll-wrapper');
        }
        if (!sc) sc = this.container;

        const onScroll = () => {
            if (!this.hasMore || this.isLoading) return;
            if (sc.scrollTop + sc.clientHeight >= sc.scrollHeight - 500) {
                this.currentPage++;
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

    setFilter(f) { this.filter = f; this.resetAndFetch(); }

    _showLoading() { if (this.loadingIndicator) this.loadingIndicator.style.display = 'block'; }
    _hideLoading() { if (this.loadingIndicator) this.loadingIndicator.style.display = 'none'; }
    _showError(m) {
        if (this.errorContainer) {
            this.errorContainer.textContent = 'Error: ' + m;
            this.errorContainer.style.display = 'block';
        }
    }
    _hideError() { if (this.errorContainer) this.errorContainer.style.display = 'none'; }

    load() { this.fetchData(); }
}