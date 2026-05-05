/**
 * APIDataGrid
 * Componente de grilla con scroll infinito.
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

        // Click en headers para ordenar
        this.container.addEventListener('click', (e) => {
            const th = e.target.closest('th');
            if (th && th.dataset.column) this.sortBy(th.dataset.column);
        });
    }

    addControls() {
        if (!this.controlsContainer) return;
        const input = document.createElement('input');
        input.type = 'text'; input.className = 'grid-search'; input.placeholder = 'Buscar...';
        let t; input.addEventListener('input', e => { clearTimeout(t); t = setTimeout(() => { this.searchTerm = e.target.value; this.resetAndFetch(); }, 300); });
        this.controlsContainer.innerHTML = ''; this.controlsContainer.appendChild(input);
    }

    buildParams() {
        const p = new URLSearchParams();
        const urlParts = this.apiUrl.split('?');
        if (urlParts.length > 1) new URLSearchParams(urlParts[1]).forEach((v, k) => p.set(k, v));
        p.set('page', this.currentPage); p.set('limit', this.pageSize);
        if (this.sortField) p.set('sort', `${this.sortOrder === 'desc' ? '-' : ''}${this.sortField}`);
        if (this.searchTerm) p.set('search', this.searchTerm);
        if (Object.keys(this.filter).length) p.set('filter', JSON.stringify(this.filter));
        return p;
    }

    async fetchData() {
        if (this.isLoading) return;
        this.isLoading = true; this.showLoading(); this.hideError();
        try {
            const url = `${this.apiUrl.split('?')[0]}?${this.buildParams().toString()}`;
            const r = await fetch(url);
            if (!r.ok) throw new Error(`HTTP ${r.status}`);
            const d = await r.json();
            if (d.error) throw new Error(d.error);
            const rows = d.data || [];
            const meta = d.columns && d.titles ? { columns: d.columns, titles: d.titles } : null;
            this.footerContainer.textContent = `Total: ${(d.total || rows.length).toLocaleString()} registros | ${d.columns ? d.columns.length : rows[0]?.length || 0} columnas | Página ${d.page || this.currentPage} de ${d.last_page || '?'}`;
            if (rows.length > 0) {
                if (this.currentPage === 1) this.render(rows, meta);
                else this.appendRows(rows);
                this.hasMore = rows.length === this.pageSize;
                if (!this.hasMore && this.scrollCleanup) { this.scrollCleanup(); this.scrollCleanup = null; }
            } else { this.hasMore = false; if (this.scrollCleanup) { this.scrollCleanup(); this.scrollCleanup = null; } }
        } catch(e) { console.error(e); this.showError(e.message); }
        finally { this.isLoading = false; this.hideLoading(); }
    }

    appendRows(data) {
        if (!data?.length) return;
        const isNum = Array.isArray(data[0]);
        const html = data.map(row => `<tr>${(isNum ? row : this.columns.map(k => row[k])).map(v => `<td>${v ?? ''}</td>`).join('')}</tr>`).join('');
        this.bodyContainer.insertAdjacentHTML('beforeend', html);
    }

    enableInfiniteScroll() {
        const sc = this.bodyContainer?.parentElement; if (!sc) return;
        const onScroll = () => {
            if (!this.hasMore || this.isLoading) return;
            if (sc.scrollTop + sc.clientHeight >= sc.scrollHeight - 500) { this.currentPage++; this.fetchData(); }
        };
        sc.addEventListener('scroll', onScroll);
        this.scrollCleanup = () => sc.removeEventListener('scroll', onScroll);
    }

    resetAndFetch() { this.currentPage = 1; this.hasMore = true; this.clear(); this.fetchData(); }
    sortBy(field) {
        this.sortOrder = this.sortField === field ? (this.sortOrder === 'asc' ? 'desc' : 'asc') : 'asc';
        this.sortField = field; this.resetAndFetch();
    }
    setFilter(f) { this.filter = f; this.resetAndFetch(); }
    showLoading() { if (this.loadingIndicator) this.loadingIndicator.style.display = 'block'; }
    hideLoading() { if (this.loadingIndicator) this.loadingIndicator.style.display = 'none'; }
    showError(m) { if (this.errorContainer) { this.errorContainer.textContent = 'Error: ' + m; this.errorContainer.style.display = 'block'; } }
    hideError() { if (this.errorContainer) this.errorContainer.style.display = 'none'; }
    load() { this.fetchData(); }
}