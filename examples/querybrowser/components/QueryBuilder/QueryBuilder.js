/**
 * QueryBuilder.js – Sincronizado con SchemaExplorer y Grid (API v1)
 * IDs únicos por pestaña para evitar conflictos.
 */
class QueryBuilder {
    constructor(connectionId, tableName, options = {}) {
        this.connectionId = connectionId;
        this.tableName = tableName;
        this.tables = [tableName];
        this.mode = 'navigate';
        this.grid = null;
        this.onActivateTab = options.onActivateTab || null;
        this.schemaExplorer = options.schemaExplorer || null;

        this.api = window.RapidBaseClient ? new RapidBaseClient('api/v1/index.php') : null;

        // ID único para esta instancia
        this.instanceId = `qb_${connectionId}_${tableName}_${Date.now()}_${Math.random().toString(36).substr(2, 5)}`;

        this.state = {
            columns: null,
            sort: [],
            search: '',
            strategy: 'auto'
        };
    }

    init(parentElement) {
        this.parentElement = parentElement;
        const id = this.instanceId;

        this.parentElement.innerHTML = `
            <div class="qb-wrapper">
                <div class="qb-toolbar">
                    <div class="qb-mode-toggle">
                        <label class="qb-switch">
                            <input type="checkbox" id="${id}-mode-chk" name="qb-mode">
                            <span class="qb-slider"></span>
                        </label>
                        <span class="qb-mode-label" id="${id}-mode-text">Navegación</span>
                    </div>
                    <div class="qb-chips-container" id="${id}-chips"></div>
                    <div class="qb-relations" id="${id}-rels">
                        <div class="qb-rel-group" id="${id}-rel-to">
                            <span class="qb-rel-label">belongsTo:</span>
                            <div class="qb-rel-list"></div>
                        </div>
                        <div class="qb-rel-group" id="${id}-rel-from">
                            <span class="qb-rel-label">hasMany:</span>
                            <div class="qb-rel-list"></div>
                        </div>
                    </div>
                </div>
                <div class="qb-editor-pane" id="${id}-editor-container">
                    <textarea class="qb-sql-editor" id="${id}-sql" name="qb-sql" readonly rows="3"></textarea>
                    <div class="qb-editor-actions">
                        <button class="qb-btn qb-run-btn" id="${id}-run" style="display:none;">▶ Ejecutar</button>
                        <label class="qb-debug-toggle">
                            <input type="checkbox" id="${id}-debug-chk" name="qb-debug">
                            <span>🐞 Debug</span>
                        </label>
                    </div>
                    <div class="qb-error" id="${id}-error" style="display:none;"></div>
                </div>
                <div class="qb-resizer-h" id="${id}-resizer"></div>
                <div class="qb-grid-pane">
                    <div class="grid-container" id="${id}-grid">
                        <div class="grid-controls"></div>
                        <div class="grid-loading" style="display:none;">Cargando...</div>
                        <div class="grid-error" style="display:none;"></div>
                        <div class="grid-scroll-wrapper">
                            <table class="grid-table">
                                <thead class="grid-head"></thead>
                                <tbody class="grid-body"></tbody>
                            </table>
                        </div>
                        <div class="grid-footer"></div>
                    </div>
                    <div class="qb-debug-output" id="${id}-debug" style="display:none; padding: 12px; background: #fefcbf; color: #975a16; font-family: monospace; font-size: 0.75rem; border-top: 1px solid #faf089; max-height: 150px; overflow-y: auto;"></div>
                </div>
            </div>
        `;

        this._initGrid();
        this._bindEvents();
        this._updateUI();
        this._loadRelations();
    }

    _initGrid() {
        const apiUrl = `api/v1/index.php?ep=Grid&action=data`;
        this.grid = new APIDataGrid(`#${this.instanceId}-grid`, apiUrl, { mode: 'infinite', pageSize: 50 });
        this.grid.sortBy = () => {};

        const origBuildParams = this.grid.buildParams.bind(this.grid);
        this.grid.buildParams = () => {
            const p = origBuildParams();
            p.set('connectionId', this.connectionId);
            p.set('table', JSON.stringify(this.tables));
            if (this.state.search) p.set('search', this.state.search);

            if (this.state.sort.length > 0) {
                const sortString = this.state.sort.map(s => {
                    const q = this._qualifySortField(s.field);
                    return `${s.order === 'desc' ? '-' : ''}${q}`;
                }).join(',');
                p.set('sort', sortString);
            }

            if (this.state.columns && this.state.columns.length > 0) {
                p.set('columns', JSON.stringify(this.state.columns));
            }

            return p;
        };

        const origFetchData = this.grid.fetchData.bind(this.grid);
        this.grid.fetchData = async () => {
            await origFetchData();
            if (this.grid.lastResponse && this.grid.lastResponse.sql) {
                const sqlEl = this.parentElement.querySelector(`#${this.instanceId}-sql`);
                if (sqlEl) sqlEl.value = this.grid.lastResponse.sql;
            }
            this._debugLog();
        };

        this.grid.load();
    }

    _bindEvents() {
        const id = this.instanceId;

        const modeChk = this.parentElement.querySelector(`#${id}-mode-chk`);
        modeChk.onchange = () => {
            this.mode = modeChk.checked ? 'edit' : 'navigate';
            this._updateUI();
        };

        const debugChk = this.parentElement.querySelector(`#${id}-debug-chk`);
        debugChk.onchange = () => {
            const debugDiv = this.parentElement.querySelector(`#${id}-debug`);
            debugDiv.style.display = debugChk.checked ? 'block' : 'none';
            if (debugChk.checked) this._debugLog();
        };

        const runBtn = this.parentElement.querySelector(`#${id}-run`);
        runBtn.onclick = () => this._executeSQL();

        const sqlEditor = this.parentElement.querySelector(`#${id}-sql`);
        sqlEditor.onkeydown = (e) => {
            if (e.ctrlKey && e.key === 'Enter' && this.mode === 'edit') {
                this._executeSQL();
            }
        };

        sqlEditor.ondragover = (e) => e.preventDefault();
        sqlEditor.ondrop = (e) => {
            e.preventDefault();
            const data = e.dataTransfer.getData("text/plain");
            if (data) {
                const text = sqlEditor.value;
                const start = sqlEditor.selectionStart;
                const end = sqlEditor.selectionEnd;
                sqlEditor.value = text.substring(0, start) + data + text.substring(end);
                sqlEditor.focus();
            }
        };

        const resizer = this.parentElement.querySelector(`#${id}-resizer`);
        const editorPane = this.parentElement.querySelector(`#${id}-editor-container`);
        resizer.onmousedown = (e) => {
            e.preventDefault();
            const startY = e.clientY;
            const startH = editorPane.offsetHeight;
            const onMouseMove = (moveE) => {
                const newH = startH + (moveE.clientY - startY);
                if (newH > 50 && newH < 400) {
                    editorPane.style.height = newH + 'px';
                }
            };
            const onMouseUp = () => {
                document.removeEventListener('mousemove', onMouseMove);
                document.removeEventListener('mouseup', onMouseUp);
            };
            document.addEventListener('mousemove', onMouseMove);
            document.addEventListener('mouseup', onMouseUp);
        };

        // Ordenación por cabeceras
        this.grid.container.addEventListener('click', (e) => {
            const th = e.target.closest('th');
            if (!th || !th.dataset.column) return;
            const col = th.dataset.column;
            // Usar el nombre tal cual si ya está calificado, si no, calificar
            const qualified = col.includes('.') ? col : this._qualifyColumn(col);

            const current = this.state.sort.length > 0 && this.state.sort[0].field === qualified
                ? this.state.sort[0]
                : { field: null, order: null };

            if (current.field === qualified) {
                if (current.order === 'asc') {
                    this.state.sort = [{ field: qualified, order: 'desc' }];
                } else {
                    this.state.sort = [];
                }
            } else {
                this.state.sort = [{ field: qualified, order: 'asc' }];
            }
            this._updateQuery();
        });

        // Búsqueda
        const searchInput = this.grid.container.querySelector('.grid-search');
        if (searchInput) {
            let timeout;
            searchInput.addEventListener('input', (e) => {
                clearTimeout(timeout);
                timeout = setTimeout(() => {
                    this.state.search = e.target.value;
                    this._updateQuery();
                }, 300);
            });
        }
    }

    _updateUI() {
        const id = this.instanceId;
        const isEdit = this.mode === 'edit';
        this.parentElement.querySelector(`#${id}-mode-text`).textContent = isEdit ? 'Edición SQL' : 'Navegación';
        const sqlEditor = this.parentElement.querySelector(`#${id}-sql`);
        sqlEditor.readOnly = !isEdit;
        sqlEditor.classList.toggle('editable', isEdit);
        this.parentElement.querySelector(`#${id}-run`).style.display = isEdit ? 'block' : 'none';

        this.parentElement.querySelector(`#${id}-chips`).style.display = isEdit ? 'none' : 'flex';
        this.parentElement.querySelector(`#${id}-rels`).style.display = isEdit ? 'none' : 'flex';

        if (!isEdit) {
            this._renderChips();
            this.grid.resetAndFetch();
        }
    }

    _renderChips() {
        const id = this.instanceId;
        const container = this.parentElement.querySelector(`#${id}-chips`);
        container.innerHTML = this.tables.map((t, i) => `
            <div class="qb-chip">
                <span>${window.escapeHtml(t)}</span>
                ${this.tables.length > 1 ? `<span class="qb-chip-remove" data-idx="${i}">×</span>` : ''}
            </div>
        `).join('');

        if (this.onActivateTab) this.onActivateTab(this);

        container.querySelectorAll('.qb-chip-remove').forEach(btn => {
            btn.onclick = (e) => {
                e.stopPropagation();
                const idx = parseInt(btn.dataset.idx);
                this.tables.splice(idx, 1);
                this._renderChips();
                this._loadRelations();
                this._updateQuery();
            };
        });

        if (window.app?.schemaExplorer && window.app?.activeSchemaData) {
            window.app.schemaExplorer.update(window.app.activeSchemaData, this.tables);
            window.app.schemaExplorer.setSelection(this.state.columns, this.state.sort);
        }
    }

    async _loadRelations() {
        if (this.mode === 'edit') return;
        try {
            const data = await this.api.schemaExplorer.getRelatedTables({
                connectionId: this.connectionId,
                tables: JSON.stringify(this.tables)
            });
            this._renderRelList('qb-rel-to', data.to || []);
            this._renderRelList('qb-rel-from', data.from || []);
            this._loadTableDescription();
        } catch (e) { console.error("Error loading relations", e); }
    }

    async _loadTableDescription() {
        try {
            const descriptions = {};
            for (const tbl of this.tables) {
                const resp = await this.api.schemaExplorer.describeTable({
                    connectionId: this.connectionId,
                    table: tbl
                });
                if (resp.success && resp.structure) {
                    descriptions[tbl] = resp.structure;
                }
            }
            const result = { success: true, description: descriptions };

            if (result.success && result.description) {
                if (window.app) window.app.activeSchemaData = result.description;
                if (this.schemaExplorer) {
                    this.schemaExplorer.update(result.description, this.tables);
                    this.schemaExplorer.setSelection(this.state.columns, this.state.sort);
                } else if (window.app?.schemaExplorer) {
                    window.app.schemaExplorer.update(result.description, this.tables);
                    window.app.schemaExplorer.setSelection(this.state.columns, this.state.sort);
                }
            }
        } catch (e) { console.error("Error loading table description", e); }
    }

    _renderRelList(containerId, list) {
        const container = this.parentElement.querySelector(`#${containerId} .qb-rel-list`);
        if (!container) return;
        container.innerHTML = list.map(t => `<span class="qb-rel-item" data-table="${t}">${window.escapeHtml(t)}</span>`).join('');
        container.querySelectorAll('.qb-rel-item').forEach(item => {
            item.onclick = () => {
                const tableName = item.dataset.table;
                if (!this.tables.includes(tableName)) {
                    this.tables.push(tableName);
                    this._renderChips();
                    this._loadRelations();
                    this._updateQuery();
                }
            };
        });

        if (window.app?.schemaExplorer && window.app?.activeSchemaData) {
            window.app.schemaExplorer.update(window.app.activeSchemaData, this.tables);
            window.app.schemaExplorer.setSelection(this.state.columns, this.state.sort);
        }
    }

    async _updateQuery() {
        if (this.mode !== 'navigate') return;

        if (this.grid && typeof this.grid.setSort === 'function') {
            const primarySort = this.state.sort.length > 0 ? this.state.sort[0] : { field: null, order: null };
            // Para una sola tabla, el data-column del grid es el nombre corto
const shortField = primarySort.field
    ? (primarySort.field.includes('.') ? primarySort.field.split('.').pop() : primarySort.field)
    : null;
            this.grid.setSort(shortField, primarySort.order);
        }

        if (this.schemaExplorer && typeof this.schemaExplorer.setSelection === 'function') {
            this.schemaExplorer.setSelection(this.state.columns, this.state.sort);
        }

        this.grid.resetAndFetch();
    }

    _debugLog() {
        const id = this.instanceId;
        const debugChk = this.parentElement?.querySelector(`#${id}-debug-chk`);
        const debugDiv = this.parentElement?.querySelector(`#${id}-debug`);
        if (!debugChk?.checked) {
            if (debugDiv) debugDiv.textContent = '';
            return;
        }

        const stateCopy = JSON.parse(JSON.stringify(this.state));
        const sqlEl = this.parentElement?.querySelector(`#${id}-sql`);
        const sql = sqlEl?.value || '';

        console.group('🐞 QueryBuilder Debug');
        console.log('State:', stateCopy);
        console.log('SQL:', sql);
        console.groupEnd();

        if (debugDiv) {
            debugDiv.textContent = `State:\n${JSON.stringify(stateCopy, null, 2)}\n\nSQL:\n${sql}`;
        }
    }

    _executeSQL() {
        const id = this.instanceId;
        const sql = this.parentElement.querySelector(`#${id}-sql`).value.trim();
        if (!sql) return;

        const errorEl = this.parentElement.querySelector(`#${id}-error`);
        errorEl.style.display = 'none';

        const btn = this.parentElement.querySelector(`#${id}-run`);
        const origBtnHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<img src="assets/icon/reloj.gif" style="height:16px;vertical-align:middle;margin-right:8px;"> Ejecutando...';

        (async () => {
            try {
                const url = `api/v1/index.php?ep=QueryExecutor&action=execute&connectionId=${encodeURIComponent(this.connectionId)}&sql=${encodeURIComponent(sql)}`;
                const resp = await fetch(url);
                const result = await resp.json();

                if (result.error) throw new Error(result.error);

                if (result.columns) {
                    this.grid.hideError();
                    this.grid.render(result.data, {
                        columns: result.columns,
                        titles: result.titles || result.columns.map(c => c.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()))
                    });
                    this.grid.hasMore = false;
                    this.grid.footerContainer.textContent = `Total: ${result.data.length} registros | Duración: ${result.durationMs || 'N/A'}ms`;
                } else {
                    alert(`Filas afectadas: ${result.affected_rows || 0}`);
                }
            } catch (e) {
                errorEl.textContent = e.message;
                errorEl.style.display = 'block';
            } finally {
                btn.innerHTML = origBtnHtml;
                btn.disabled = false;
            }
        })();
    }

    setSelectedColumns(selection) {
        this.state.columns = selection.projections && selection.projections.length > 0
            ? selection.projections
            : null;

        this.state.sort = (selection.orders || []).map(o => ({
            field: o.column,
            order: o.sort.toLowerCase()
        }));

        this._updateQuery();
    }

    onActivate() {
        if (this.onActivateTab) this.onActivateTab(this);
        if (this.schemaExplorer && window.app?.activeSchemaData) {
            this.schemaExplorer.update(window.app.activeSchemaData, this.tables);
            this.schemaExplorer.setSelection(this.state.columns, this.state.sort);
        }
        if (this.grid && typeof this.grid.setSort === 'function') {
            const primarySort = this.state.sort.length > 0 ? this.state.sort[0] : { field: null, order: null };
            const shortField = primarySort.field ? primarySort.field.split('.').pop() : null;
            this.grid.setSort(shortField, primarySort.order);
        }
    }

    _qualifyColumn(col) {	
        if (!this.tables.length) return col;
        const mainTable = this.tables[0];
        return `${mainTable}.${col}`;
    }

	   _qualifySortField(field) {
		if (this.tables.length <= 1) {
			return field.includes('.') ? field.split('.').pop() : field;
		}
		return field.includes('.') ? field : this._qualifyColumn(field);
	}
}