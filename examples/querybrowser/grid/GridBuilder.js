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

        // ── Redimensionamiento de columnas con protección anti-ordenación ──
        let resizing = false;

        if (this.headContainer) {
            this.headContainer.addEventListener('mousedown', (e) => {
                const resizer = e.target.closest('.grid-resizer');
                if (!resizer) return;
                e.preventDefault();
                e.stopPropagation();

                const th = resizer.closest('th');
                if (!th) return;

                const startX = e.clientX;
                const startWidth = th.offsetWidth;
                resizing = true;
                document.body.style.cursor = 'col-resize';
                document.body.style.userSelect = 'none';

                const onMouseMove = (moveEvent) => {
                    const delta = moveEvent.clientX - startX;
                    const newWidth = Math.max(50, startWidth + delta);
                    th.style.width = newWidth + 'px';
                    th.style.minWidth = newWidth + 'px';
                };

                const onMouseUp = () => {
                    document.removeEventListener('mousemove', onMouseMove);
                    document.removeEventListener('mouseup', onMouseUp);
                    document.body.style.cursor = '';
                    document.body.style.userSelect = '';

                    // Pequeña demora para evitar que el clic active la ordenación
                    setTimeout(() => { resizing = false; }, 50);
                };

                document.addEventListener('mousemove', onMouseMove);
                document.addEventListener('mouseup', onMouseUp, { once: true });
            });

            // Prevenir ordenación si acabamos de redimensionar
            this.headContainer.addEventListener('click', (e) => {
                if (resizing) {
                    e.stopPropagation();
                    e.preventDefault();
                }
            }, true); // captura antes que el listener de APIDataGrid
        }
    }

    render(data, metadata = null) {
        if (!data || data.length === 0) return;
        this.columns = metadata?.columns || [];
        const titles = metadata?.titles || this.columns;

        if (this.headerTemplate && titles.length > 0) {
            this._renderHeaders(titles);
        }

        if (this.rowTemplate) {
            this._renderRows(data);
        }
    }

    _renderHeaders(titles) {
        const head = this.headContainer;
        head.innerHTML = '';

        if (!this.headerRowTemplate) return;

        const wrapper = document.createElement('tr');
        head.appendChild(wrapper);

        const originalThs = Array.from(this.headerRowTemplate.querySelectorAll('th, td, .grid-header'));

        originalThs.forEach(th => {
            const html = th.outerHTML;
            if (html.includes('${header}')) {
                titles.forEach((title, idx) => {
                    let thHtml = html.replace(/\$\{header\}/g, title);
                    if (!thHtml.includes('grid-resizer')) {
                        thHtml = thHtml.replace('</th>', '<div class="grid-resizer"></div></th>');
                    }
                    const el = this._createElement(thHtml);
                    el.style.display = '';
                    // Usar el nombre cualificado completo si está disponible en this.columns
                    // Esto preserva información como "comments.post_id" en lugar de solo "post_id"
                    el.dataset.column = this.columns[idx] || idx;
                    wrapper.appendChild(el);
                });
            } else {
                let fixedHtml = html;
                if (!fixedHtml.includes('grid-resizer') && th.tagName === 'TH') {
                    fixedHtml = fixedHtml.replace('</th>', '<div class="grid-resizer"></div></th>');
                }
                const el = this._createElement(fixedHtml);
                el.style.display = '';
                wrapper.appendChild(el);
            }
        });

        this.updateSortIndicator();
    }

    _renderRows(data) {
        const body = this.bodyContainer;
        body.innerHTML = '';

        const rowHtml = this.rowTemplate.outerHTML;

        data.forEach(row => {
            const clone = this._createElement(rowHtml);
            clone.style.display = '';

            let html = clone.outerHTML;

            row.forEach((val, idx) => {
                html = html.replace(new RegExp(`\\$\\{${idx}\\}`, 'g'), val ?? '');
            });

            this.columns.forEach((colName, idx) => {
                if (colName) {
                    html = html.replace(new RegExp(`\\$\\{${colName}\\}`, 'g'), row[idx] ?? '');
                }
            });

            if (html.includes('${value}')) {
                let dataCells = '';
                row.forEach(val => {
                    dataCells += `<td class="grid-item">${val ?? ''}</td>`;
                });
                html = html.replace(/<td[^>]*>\s*\$\{value\}\s*<\/td>/, dataCells);
            }

            const finalEl = this._createElement(html);
            finalEl.style.display = '';
            body.appendChild(finalEl);
        });
    }

    updateSortIndicator() {
        if (!this.headContainer) return;
        const headers = this.headContainer.querySelectorAll('th[data-column]');
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
            const children = Array.from(this.bodyContainer.children);
            children.forEach(child => {
                if (child !== this.rowTemplate) child.remove();
            });
        }
    }
}