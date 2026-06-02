class GridBuilder {
    constructor(containerSelector) {
        this.container = document.querySelector(containerSelector);
        if (!this.container) throw new Error("Container not found: " + containerSelector);

        this.bodyContainer = this.container.querySelector('.grid-body, tbody');
        this.headContainer = this.container.querySelector('.grid-head, thead');

        this.headerRowTemplate = this.headContainer?.querySelector('tr');
        this.headerTemplate = this.headContainer?.querySelector('th, .grid-header');
        this.rowTemplate = this.bodyContainer?.querySelector('tr, .grid-row');

        if (this.headerRowTemplate) this.headerRowTemplate.style.display = 'none';
        else if (this.headerTemplate) this.headerTemplate.style.display = 'none';

        if (this.rowTemplate) this.rowTemplate.style.display = 'none';

        this.columns = [];
        this.sortField = null;
        this.sortOrder = null;

        // ── CONTROL DE REDIMENSIONAMIENTO CRUCIAL ──
        this.isResizing = false; 

        if (this.headContainer) {
            this.headContainer.addEventListener('mousedown', (e) => {
                const resizer = e.target.closest('.grid-resizer');
                if (!resizer) return;
                
                // Bloquear propagación inmediata para que no llegue a los listeners superiores
                e.preventDefault();
                e.stopPropagation();

                const th = resizer.closest('th');
                if (!th) return;

                this.isResizing = true;
                th.classList.add('resizing');

                const startX = e.pageX;
                const startWidth = th.offsetWidth;

                const onMouseMove = (moveEvent) => {
                    if (!this.isResizing) return;
                    const newWidth = startWidth + (moveEvent.pageX - startX);
                    if (newWidth > 40) {
                        th.style.width = `${newWidth}px`;
                        th.style.minWidth = `${newWidth}px`;
                    }
                };

                const onMouseUp = (upEvent) => {
                    th.classList.remove('resizing');
                    document.removeEventListener('mousemove', onMouseMove);
                    document.removeEventListener('mouseup', onMouseUp);
                    
                    // Tiempo de gracia infinitesimal para evitar que el 'click' se dispare en APIDataGrid
                    setTimeout(() => {
                        this.isResizing = false;
                    }, 50);
                };

                document.addEventListener('mousemove', onMouseMove);
                document.addEventListener('mouseup', onMouseUp);
            });
        }
    }

    /**
     * Define dinámicamente las columnas y regenera las cabeceras si el API las provee.
     */
    setColumns(columns, titles = []) {
        this.columns = columns;
        if (!this.headContainer || !this.headerTemplate) return;

        const row = this.headerRowTemplate || this.headContainer.querySelector('tr') || document.createElement('tr');
        row.innerHTML = '';
        row.style.display = '';

        this.columns.forEach((col, index) => {
            const th = this.headerTemplate.cloneNode(true);
            th.style.display = '';
            th.dataset.column = col;

            const titleText = titles[index] || col;
            if (th.querySelector('.grid-header-text')) {
                th.querySelector('.grid-header-text').textContent = titleText;
            } else {
                th.textContent = titleText;
            }

            if (!th.querySelector('.grid-resizer')) {
                const resizer = document.createElement('div');
                resizer.className = 'grid-resizer';
                th.appendChild(resizer);
            }

            row.appendChild(th);
        });

        if (!this.headerRowTemplate) {
            this.headContainer.innerHTML = '';
            this.headContainer.appendChild(row);
        }
        this.updateSortIndicator();
    }

    /**
     * Agrega una fila al Grid adaptándose a FETCH_NUM (arrays) o FETCH_ASSOC (objetos)
     */
    appendRow(row) {
        if (!this.bodyContainer) return null;

        let tr;
        if (this.rowTemplate) {
            tr = this.rowTemplate.cloneNode(true);
            tr.style.display = '';
        } else {
            tr = document.createElement('tr');
        }

        tr.innerHTML = '';

        this.columns.forEach((col, index) => {
            let value;

            if (Array.isArray(row)) {
                value = row[index];
            } else if (row !== null && typeof row === 'object') {
                value = row[col];
            }

            if (value === undefined || value === null) {
                value = '';
            }

            const td = document.createElement('td');
            td.dataset.column = col;
            
            if (typeof value === 'string' && value.includes('\n')) {
                td.style.whiteSpace = 'pre-wrap';
            }
            
            td.textContent = value;
            tr.appendChild(td);
        });

        this.bodyContainer.appendChild(tr);
        return tr;
    }

    updateSortIndicator() {
        if (!this.headContainer) return;
        const headers = this.headContainer.querySelectorAll('th, .grid-header');
        headers.forEach(th => {
            th.removeAttribute('data-sort');
            const col = th.dataset.column;
            if (!col || !this.sortField || !this.sortOrder) return;

            const shortField = this.sortField.includes('.') ? this.sortField.split('.').pop() : this.sortField;
            const colShort = col.includes('.') ? col.split('.').pop() : col;

            if (colShort === shortField) {
                th.setAttribute('data-sort', this.sortOrder);
            }
        });
    }

    setSort(field, order) {
        this.sortField = field;
        this.sortOrder = order;
        this.updateSortIndicator();
    }

    _createElement(html) {
        const trimmed = html.trim();
        if (/^<tr/i.test(trimmed)) {
            const tbody = document.createElement('tbody');
            tbody.innerHTML = trimmed;
            return tbody.firstElementChild;
        }
        if (/^<(th|td)/i.test(trimmed)) {
            const tr = document.createElement('tr');
            tr.innerHTML = trimmed;
            return tr.firstElementChild;
        }
        const div = document.createElement('div');
        div.innerHTML = trimmed;
        return div.firstElementChild || div;
    }

    clear() {
        if (this.bodyContainer) {
            while (this.bodyContainer.firstChild) {
                this.bodyContainer.removeChild(this.bodyContainer.firstChild);
            }
        }
    }
}

window.GridBuilder = GridBuilder;