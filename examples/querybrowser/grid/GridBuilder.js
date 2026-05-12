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
        this.sortOrder = null;
        
        this._initResizing();
    }

    _initResizing() {
        let th = null, startX = 0, startWidth = 0;
        
        this.container.addEventListener('mousedown', (e) => {
            if (e.target.classList.contains('grid-resizer')) {
                th = e.target.parentElement;
                startX = e.clientX;
                startWidth = th.offsetWidth;
                e.preventDefault();
                e.stopPropagation();
                document.addEventListener('mousemove', onMouseMove);
                document.addEventListener('mouseup', onMouseUp, { once: true });
                e.target.classList.add('grid-resizing');
            }
        });

        const onMouseMove = (e) => {
            if (!th) return;
            const newWidth = startWidth + (e.clientX - startX);
            if (newWidth > 40) {
                th.style.width = newWidth + 'px';
                th.style.minWidth = newWidth + 'px';
                // Force table layout to respect column widths
                const table = th.closest('table');
                if (table) {
                    table.style.tableLayout = 'fixed';
                }
            }
        };

        const onMouseUp = (e) => {
            if (th) {
                th.querySelector('.grid-resizer').classList.remove('grid-resizing');
                // Restore auto layout after resize for proper horizontal scroll
                const table = th.closest('table');
                if (table) {
                    setTimeout(() => {
                        table.style.tableLayout = 'auto';
                    }, 50);
                }
                th = null;
                document.removeEventListener('mousemove', onMouseMove);
            }
        };
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
                `<th data-column="${this.escapeHtml(metadata.columns[i])}" style="width:150px;">${this.escapeHtml(t)}<div class="grid-resizer"></div></th>`
            ).join('');
        }
        this.bodyContainer.innerHTML = data.map(row => 
            `<tr>${row.map(v => `<td>${v ?? ''}</td>`).join('')}</tr>`
        ).join('');
    }

    renderAssociativeMode(data, metadata = null) {
        const keys = Object.keys(data[0]); 
        this.columns = keys;
        if (metadata?.columns && metadata?.titles) {
            this.headerTemplate.innerHTML = metadata.titles.map((t, i) => 
                `<th data-column="${this.escapeHtml(metadata.columns[i])}" style="width:150px;">${this.escapeHtml(t)}<div class="grid-resizer"></div></th>`
            ).join('');
        } else if (!this.headerTemplate?.querySelectorAll('th').length) {
            this.headerTemplate.innerHTML = keys.map(k => 
                `<th data-column="${k}" style="width:150px;">${this.formatKey(k)}<div class="grid-resizer"></div></th>`
            ).join('');
        }
        this.bodyContainer.innerHTML = data.map(row => 
            `<tr>${keys.map(k => `<td>${row[k] ?? ''}</td>`).join('')}</tr>`
        ).join('');
    }

    /**
     * Actualiza los indicadores visuales basándose en el estado de 3 niveles.
     */
    updateSortIndicator() {
        if (!this.headerTemplate) return;
        this.headerTemplate.querySelectorAll('th').forEach(th => {
            th.removeAttribute('data-sort');
            if (this.sortField && th.dataset.column === this.sortField && this.sortOrder) {
                th.setAttribute('data-sort', this.sortOrder);
            }
        });
    }

    formatKey(key) { return key.charAt(0).toUpperCase() + key.slice(1).replace(/_/g, ' '); }
    escapeHtml(value) {
        if (value === null || value === undefined) return '';
        const div = document.createElement('div');
        div.textContent = value;
        return div.innerHTML;
    }
}