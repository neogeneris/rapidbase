class SchemaExplorer {
    constructor(options = {}) {
        this.container = null;
        this.options = options;
        this.isOpen = false;
        this.init();
    }

    init() {
        this.createDOM();
        this.loadSchema();
        this.setupEvents();
    }

    createDOM() {
        this.container = document.createElement('aside');
        this.container.className = 'schema-explorer-sidebar closed';
        this.container.innerHTML = `
            <div class="se-header">
                <h3>METADATA EXPLORER</h3>
                <button class="se-close">&times;</button>
            </div>
            <div class="se-search">
                <input type="text" placeholder="Filtrar tablas o columnas..." id="se-filter">
            </div>
            <div class="se-content" id="se-tree"></div>
        `;
        const target = this.options.containerId ? document.getElementById(this.options.containerId) : document.body;
        if (this.options.containerId) {
            this.container.classList.remove('closed');
            this.container.style.position = 'relative';
            this.container.style.width = '100%';
            this.container.style.height = '100%';
            this.container.style.borderLeft = 'none';
            this.container.style.boxShadow = 'none';
        }
        target.appendChild(this.container);
    }

    update(schemaData, activeTables = []) {
        this.options.schemaData = schemaData;
        // Normalize activeTables: if it's a string or JSON array, convert to array
        if (typeof activeTables === 'string') {
            try {
                const parsed = JSON.parse(activeTables);
                this.options.activeTables = Array.isArray(parsed) ? parsed : [parsed];
            } catch (e) {
                this.options.activeTables = [activeTables];
            }
        } else {
            this.options.activeTables = Array.isArray(activeTables) ? activeTables : (activeTables ? [activeTables] : []);
        }
        this.loadSchema();
    }

    loadSchema() {
        const data = this.options.schemaData;
        if (!data) return;

        let tablesArray = [];
        if (data.schema_tables) {
            // New API format: { success: true, schema_tables: [...] }
            tablesArray = data.schema_tables;
        } else if (Array.isArray(data)) {
            // Array format
            tablesArray = data;
        } else {
            // Old format: { "table1": { "columns": [...] }, ... }
            tablesArray = Object.keys(data).map(name => ({
                name: name,
                columns: data[name].columns || []
            }));
        }

        // Filter only active tables if requested (on-demand loading)
        if (this.options.activeTables && this.options.activeTables.length > 0) {
            tablesArray = tablesArray.filter(t => this.options.activeTables.includes(t.name));
        }
        
        this.renderTree(tablesArray);
    }

    renderTree(tables) {
        const tree = this.container.querySelector('#se-tree');
        if (!tables || tables.length === 0) {
            tree.innerHTML = '<div style="padding:20px;text-align:center;color:#94a3b8;font-size:12px;">Seleccione una tabla en el editor</div>';
            return;
        }

        let html = '';
        tables.forEach(table => {
            const tableIcon = `
                <svg viewBox="0 0 24 24" width="14" height="14" style="margin-right:8px;color:#3b82f6;">
                    <path fill="currentColor" d="M3 3h18v18H3V3zm16 16V5H5v14h14zM7 7h10v2H7V7zm0 4h10v2H7v-2zm0 4h10v2H7v-2z"/>
                </svg>
            `;

            html += `
                <details class="se-table" open>
                    <summary>
                        <span class="se-expander">
                            <svg viewBox="0 0 24 24" width="12" height="12"><path fill="currentColor" d="M8.59,16.59L13.17,12L8.59,7.41L10,6l6,6l-6,6L8.59,16.59z"/></svg>
                        </span>
                        ${tableIcon}
                        <span class="se-table-name">${window.escapeHtml(table.name)}</span>
                    </summary>
                    <ul>`;

            const columns = table.columns || [];
            columns.forEach(col => {
                html += `
                    <li draggable="true" data-col-full="${table.name}.${col.name}" class="se-column-item">
                        <label class="se-col-label">
                            <input type="checkbox" value="${table.name}.${col.name}">
                            ${col.primary ? '<span class="se-pk" title="Primary Key">🔑</span>' : ''}
                            <span class="se-name">${window.escapeHtml(col.name)}</span>
                            <span class="se-type">${window.escapeHtml(col.type)}</span>
                        </label>
                        <select class="se-sort-select">
                            <option value="NONE">↕</option>
                            <option value="ASC">ASC</option>
                            <option value="DESC">DESC</option>
                        </select>
                    </li>`;
            });

            html += `</ul></details>`;
        });
        
        tree.innerHTML = html;
    }

    setupEvents() {
        this.container.querySelector('.se-close').onclick = () => this.toggle();

        this.container.querySelector('#se-filter').oninput = (e) => {
            const val = e.target.value.toLowerCase();
            this.container.querySelectorAll('.se-column-item').forEach(li => {
                const matches = li.dataset.colFull.toLowerCase().includes(val);
                li.style.display = matches ? 'flex' : 'none';
            });
        };

        this.container.addEventListener('change', (e) => {
            if (e.target.type === 'checkbox' || e.target.classList.contains('se-sort-select')) {
                this.handleColumnSelection();
            }
        });

        this.container.addEventListener('dragstart', (e) => {
            const li = e.target.closest('li');
            if (li) {
                e.dataTransfer.setData('text/plain', li.dataset.colFull);
                e.dataTransfer.effectAllowed = 'copy';
                li.style.opacity = "0.4";
            }
        });

        this.container.addEventListener('dragend', (e) => {
            const li = e.target.closest('li');
            if (li) li.style.opacity = "1";
        });
    }

    handleColumnSelection() {
        const selected = [];
        this.container.querySelectorAll('.se-column-item').forEach(li => {
            const cb = li.querySelector('input[type="checkbox"]');
            if (cb.checked) {
                selected.push({
                    column: cb.value,
                    sort: li.querySelector('.se-sort-select').value
                });
            }
        });
        if (this.options.grid?.updateQuery) this.options.grid.updateQuery(selected);
    }

    toggle() {
        this.isOpen = !this.isOpen;
        this.container.classList.toggle('closed', !this.isOpen);
    }

    update(schemaData) {
        this.options.schemaData = schemaData;
        this.loadSchema();
    }
}