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

        // ── Redimensionamiento de columnas ─────────────────
        let resizeStartX = 0;
        let resizeStartWidth = 0;
        let resizing = false;

        if (this.headerTemplate) {
            this.headerTemplate.addEventListener('mousedown', (e) => {
                const resizer = e.target.closest('.grid-resizer');
                if (!resizer) return;
                e.preventDefault();
                e.stopPropagation();

                const th = resizer.closest('th');
                if (!th) return;

                resizeStartX = e.clientX;
                resizeStartWidth = th.offsetWidth;
                resizing = true;
                document.body.style.cursor = 'col-resize';
                document.body.style.userSelect = 'none';

                const onMouseMove = (moveEvent) => {
                    const delta = moveEvent.clientX - resizeStartX;
                    const newWidth = Math.max(50, resizeStartWidth + delta);
                    th.style.width = newWidth + 'px';
                    th.style.minWidth = newWidth + 'px';
                };

                const onMouseUp = () => {
                    document.removeEventListener('mousemove', onMouseMove);
                    document.removeEventListener('mouseup', onMouseUp);
                    document.body.style.cursor = '';
                    document.body.style.userSelect = '';
                    // Evitar que el clic se propague al header tras soltar
                    setTimeout(() => { resizing = false; }, 10);
                };

                document.addEventListener('mousemove', onMouseMove);
                document.addEventListener('mouseup', onMouseUp, { once: true });
            });

            // Prevención de ordenación si venimos de redimensionar
            this.headerTemplate.addEventListener('click', (e) => {
                if (resizing) {
                    e.stopPropagation();
                    e.preventDefault();
                }
            }, true); // captura antes que el listener del grid
        }
    }


    // ─── Render y helpers ──────────────────────────────────
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
                `<th data-column="${this.escapeHtml(metadata.columns[i])}">${this.escapeHtml(t)}<div class="grid-resizer"></div></th>`
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
                `<th data-column="${this.escapeHtml(metadata.columns[i])}">${this.escapeHtml(t)}<div class="grid-resizer"></div></th>`
            ).join('');
        } else if (!this.headerTemplate?.querySelectorAll('th').length) {
            this.headerTemplate.innerHTML = keys.map(k =>
                `<th data-column="${k}">${this.formatKey(k)}<div class="grid-resizer"></div></th>`
            ).join('');
        }
        this.bodyContainer.innerHTML = data.map(row =>
            `<tr>${keys.map(k => `<td>${row[k] ?? ''}</td>`).join('')}</tr>`
        ).join('');
    }

    updateSortIndicator() {
        if (!this.headerTemplate) return;
        const sortColShort = this.sortField
            ? (this.sortField.includes('.') ? this.sortField.split('.').pop() : this.sortField)
            : null;

        const headers = this.headerTemplate.querySelectorAll('th');
        headers.forEach(th => {
            th.removeAttribute('data-sort');
            if (!sortColShort || !this.sortOrder) return;
            const col = th.dataset.column || '';
            const colShort = col.includes('.') ? col.split('.').pop() : col;
            if (colShort === sortColShort) {
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