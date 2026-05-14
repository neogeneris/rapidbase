class SchemaExplorer {
    constructor(options = {}) {
        this.container = null;
        this.options = options;
        this.isOpen = false;
        this.relationsData = null;   // Almacena las relaciones
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
        if (this.options.containerId) this.open();
    }

    /**
     * Updates the schema explorer with given schema data and optional active tables.
     * @param {Object} schemaData - Schema map (list_tables format or description format)
     * @param {Array|string} activeTables - Tables to show. If undefined or empty array, the panel is cleared.
     */
    update(schemaData, activeTables) {
        this.options.schemaData = schemaData;

        // Si no se especifican tablas activas → estado vacío
        if (activeTables === undefined || activeTables === null) {
            this.options.activeTables = undefined;
            this.renderTree([]);
            this.close();
            return;
        }

        // Normalizar a array
        if (typeof activeTables === 'string') {
            try {
                const parsed = JSON.parse(activeTables);
                this.options.activeTables = Array.isArray(parsed) ? parsed : [parsed];
            } catch (e) {
                this.options.activeTables = [activeTables];
            }
        } else if (Array.isArray(activeTables)) {
            this.options.activeTables = activeTables;
        } else {
            this.options.activeTables = [activeTables];
        }

        // Si el array está vacío, también mostramos panel vacío (sin cerrar, para mantener feedback visual)
        if (this.options.activeTables.length === 0) {
            this.renderTree([]);
            this.open();  // mostramos el panel con el mensaje de "seleccione una tabla"
            return;
        }

        this.loadSchema();
        this.open();
    }

    loadSchema() {
        const data = this.options.schemaData;
        if (!data) return;

        let tablesArray = [];
        this.relationsData = null;

        // Nuevo formato: { tables: [...], views: [], relations: [...] }
        if (data.tables && Array.isArray(data.tables)) {
            tablesArray = data.tables.map(t => ({
                name: t.name,
                columns: t.columns ? Object.entries(t.columns).map(([col, type]) => ({
                    name: col,
                    type: type,
                    primary: (t.primaryKeys || []).includes(col)
                })) : [],
                pks: t.primaryKeys || []
            }));
            if (data.relations) {
                this.relationsData = data.relations;
            }
        }
        // Formato list_tables antiguo: { schema_tables: [...] }
        else if (data.schema_tables) {
            tablesArray = data.schema_tables.map(t => ({
                name: t.name,
                columns: (t.columns || []).map(c => ({
                    name: c.name,
                    type: c.type,
                    primary: c.primary || false
                })),
                pks: (t.columns || []).filter(c => c.primary).map(c => c.name)
            }));
        }
        // X::description() antiguo o array
        else if (typeof data === 'object' && !Array.isArray(data)) {
            tablesArray = Object.keys(data).map(tableName => {
                const tableData = data[tableName];
                const cols = tableData.columns || {};
                return {
                    name: tableName,
                    columns: Object.entries(cols).map(([col, type]) => ({
                        name: col,
                        type: type,
                        primary: (tableData.pks || []).includes(col)
                    })),
                    pks: tableData.pks || []
                };
            });
        }

        // Filtrar por tablas activas
        if (this.options.activeTables && this.options.activeTables.length > 0) {
            tablesArray = tablesArray.filter(t => this.options.activeTables.includes(t.name));
        }

        this.renderTree(tablesArray);
    }

    renderTree(tables) {
        const tree = this.container.querySelector('#se-tree');
        let html = '';

        if (!tables || tables.length === 0) {
            html += '<div style="padding:20px;text-align:center;color:#94a3b8;font-size:12px;">Seleccione una tabla en el editor</div>';
        } else {
            // --- Tablas ---
            tables.forEach(table => {
                const tableIcon = `<svg viewBox="0 0 24 24" width="14" height="14" style="margin-right:8px;color:#3b82f6;">
                    <path fill="currentColor" d="M3 3h18v18H3V3zm16 16V5H5v14h14zM7 7h10v2H7V7zm0 4h10v2H7v-2zm0 4h10v2H7v-2z"/>
                </svg>`;

                html += `<details class="se-table" open>
                    <summary>
                        <span class="se-expander">▶</span>
                        ${tableIcon}
                        <span class="se-table-name">${window.escapeHtml(table.name)}</span>
                    </summary>
                    <ul>`;

                table.columns.forEach(col => {
                    html += `<li draggable="true" data-col-full="${table.name}.${col.name}" class="se-column-item">
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
        }

        // --- Relaciones (si existen) ---
        if (this.relationsData && this.relationsData.length > 0) {
            html += `<details class="se-relations" open>
                <summary style="padding:6px 12px; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:6px;">
                    <span class="se-expander">▶</span>
                    <svg viewBox="0 0 24 24" width="14" height="14" style="color:#f59e0b;"><path fill="currentColor" d="M3 3h18v18H3V3zm16 16V5H5v14h14zM7 7h10v2H7V7zm0 4h10v2H7v-2zm0 4h10v2H7v-2z"/></svg>
                    Relations
                </summary>
                <div style="padding-left:24px;">`;

            this.relationsData.forEach(rel => {
                html += `<div class="se-rel-item" style="padding:2px 0; font-size:11px;">
                    <span style="color:#3b82f6;">${window.escapeHtml(rel.sourceTable)}</span>.
                    <span style="color:#1e293b;">${window.escapeHtml(rel.sourceColumn)}</span>
                    → 
                    <span style="color:#3b82f6;">${window.escapeHtml(rel.targetTable)}</span>.
                    <span style="color:#1e293b;">${window.escapeHtml(rel.targetColumn)}</span>
                    <span style="color:#94a3b8; margin-left:4px;">(${rel.type})</span>
                </div>`;
            });

            html += `</div></details>`;
        }

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

    open() {
        this.isOpen = true;
        this.container.classList.remove('closed');
    }

    close() {
        this.isOpen = false;
        this.container.classList.add('closed');
    }

    toggle() {
        this.isOpen ? this.close() : this.open();
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

		// 👇 Emitir a cualquier suscriptor (nuevo)
		if (typeof this.options.onSelectionChange === 'function') {
			this.options.onSelectionChange(selected);
		}
	}	
	
	
}