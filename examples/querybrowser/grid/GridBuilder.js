/**
 * GridBuilder
 * Clase base para construir grids con <table> nativa.
 */
class GridBuilder {
    constructor(containerSelector) {
        this.container = document.querySelector(containerSelector);
        if (!this.container) throw new Error("Container not found: " + containerSelector);
        this.headerTemplate = this.container.querySelector('.grid-head');
        this.bodyContainer = this.container.querySelector('.grid-body') || this.container;
        this.currentMetadata = null;
        this.columns = [];
        this.sortField = null;
        this.sortOrder = 'asc';
    }

    render(data, metadata = null) {
        this.currentMetadata = metadata;
        if (!data || data.length === 0) {
            this.bodyContainer.innerHTML = '<tr><td colspan="100" class="grid-empty">No hay datos</td></tr>';
            return;
        }
        const isNumericArray = Array.isArray(data[0]);
        if (isNumericArray) this.renderNumericMode(data, metadata);
        else this.renderAssociativeMode(data, metadata);
        this.updateSortIndicator();
    }

    renderNumericMode(data, metadata = null) {
        if (metadata?.columns && metadata?.titles) {
            this.columns = metadata.columns;
            this.headerTemplate.innerHTML = metadata.titles.map((t, i) => 
                `<th data-column="${this.escapeHtml(metadata.columns[i])}">${this.escapeHtml(t)}</th>`
            ).join('');
        } else if (!this.headerTemplate?.querySelectorAll('th').length) {
            const n = data[0].length;
            this.columns = Array.from({length: n}, (_, i) => String(i));
            this.headerTemplate.innerHTML = Array.from({length: n}, (_, i) => 
                `<th data-column="${i}">Col ${i}</th>`
            ).join('');
        }
        this.bodyContainer.innerHTML = data.map(row => 
            `<tr>${row.map(v => `<td>${v ?? ''}</td>`).join('')}</tr>`
        ).join('');
        this.updateSortIndicator();
    }

    renderAssociativeMode(data, metadata = null) {
        const keys = Object.keys(data[0]); this.columns = keys;
        if (metadata?.columns && metadata?.titles) {
            this.headerTemplate.innerHTML = metadata.titles.map((t, i) => 
                `<th data-column="${this.escapeHtml(metadata.columns[i])}">${this.escapeHtml(t)}</th>`
            ).join('');
        } else if (!this.headerTemplate?.querySelectorAll('th').length) {
            this.headerTemplate.innerHTML = keys.map(k => 
                `<th data-column="${k}">${this.formatKey(k)}</th>`
            ).join('');
        }
        this.bodyContainer.innerHTML = data.map(row => 
            `<tr>${keys.map(k => `<td>${row[k] ?? ''}</td>`).join('')}</tr>`
        ).join('');
        this.updateSortIndicator();
    }

    updateSortIndicator() {
        if (!this.headerTemplate) return;
        this.headerTemplate.querySelectorAll('th').forEach(th => {
            th.removeAttribute('data-sort');
            if (th.dataset.column === this.sortField) {
                th.setAttribute('data-sort', this.sortOrder);
            }
        });
    }

    formatKey(key) { return key.charAt(0).toUpperCase() + key.slice(1).replace(/_/g, ' '); }
    escapeHtml(value) {
        if (value === null || value === undefined) return '';
        const div = document.createElement('div'); div.textContent = String(value); return div.innerHTML;
    }
    clear() { this.bodyContainer.innerHTML = ''; if (this.headerTemplate) this.headerTemplate.innerHTML = ''; }
}