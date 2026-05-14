/**
 * QueryBuilder.js – Modernized
 * Advanced data explorer using the new API v1 and RapidBaseClient.
 */
class QueryBuilder {
    constructor(connectionId, tableName, options = {}) {
        this.connectionId = connectionId;           // "saved_6"
        this.tableName = tableName;
        this.tables = [tableName];
        this.mode = 'navigate';
        this.searchTerm = '';
        this.grid = null;
        this.onActivateTab = options.onActivateTab || null;
        this.schemaExplorer = options.schemaExplorer || null;

        // Cliente unificado (nuevo router v1)
        this.api = window.RapidBaseClient
            ? new RapidBaseClient('api/v1/index.php')
            : null;

        // Usar el viejo api.php si el router no está disponible
        this.useLegacyApi = !this.api;
    }

    init(parentElement) {
        this.parentElement = parentElement;
        this.parentElement.innerHTML = `
            <div class="qb-wrapper">
                <div class="qb-toolbar">
                    <div class="qb-mode-toggle">
                        <label class="qb-switch">
                            <input type="checkbox" id="qb-mode-chk">
                            <span class="qb-slider"></span>
                        </label>
                        <span class="qb-mode-label" id="qb-mode-text">Navegación</span>
                    </div>
                    
                    <div class="qb-chips-container" id="qb-chips"></div>
                    
                    <div class="qb-relations" id="qb-rels">
                        <div class="qb-rel-group" id="qb-rel-to">
                            <span class="qb-rel-label">belongsTo:</span>
                            <div class="qb-rel-list"></div>
                        </div>
                        <div class="qb-rel-group" id="qb-rel-from">
                            <span class="qb-rel-label">hasMany:</span>
                            <div class="qb-rel-list"></div>
                        </div>
                    </div>
                </div>

                <div class="qb-editor-pane" id="qb-editor-container">
                    <textarea class="qb-sql-editor" id="qb-sql" readonly rows="3"></textarea>
                    <div class="qb-editor-actions">
                        <button class="qb-btn qb-run-btn" id="qb-run" style="display:none;">▶ Ejecutar</button>
                        <label class="qb-debug-toggle">
                            <input type="checkbox" id="qb-debug-chk">
                            <span>🐞 Debug</span>
                        </label>
                    </div>
                    <div class="qb-error" id="qb-error" style="display:none;"></div>
                </div>

                <div class="qb-resizer-h" id="qb-resizer"></div>

                <div class="qb-grid-pane">
                    <div class="grid-container" id="qb-grid">
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
                    <div class="qb-debug-output" id="qb-debug" style="display:none; padding: 12px; background: #fefcbf; color: #975a16; font-family: monospace; font-size: 0.75rem; border-top: 1px solid #faf089; max-height: 150px; overflow-y: auto;"></div>
                </div>
            </div>
        `;

        this._initGrid();
        this._bindEvents();
        this._updateUI();
        this._loadRelations();
    }

    _initGrid() {
        const apiUrl = this.useLegacyApi
            ? `api.php?action=grid_data&connectionId=${this.connectionId}`
            : `api/v1/index.php?ep=QueryExecutor&action=grid`;   // Ajustar cuando exista QueryRunner

        this.grid = new APIDataGrid('#qb-grid', apiUrl, { mode: 'infinite', pageSize: 50 });
        
        const origBuildParams = this.grid.buildParams.bind(this.grid);
        this.grid.buildParams = () => {
            const p = origBuildParams();
            p.set('connectionId', this.connectionId);
            p.set('table', JSON.stringify(this.tables));
            if (this.searchTerm) p.set('search', this.searchTerm);
            return p;
        };

        const origFetchData = this.grid.fetchData.bind(this.grid);
        this.grid.fetchData = async () => {
            await origFetchData();
            if (this.mode === 'navigate') this._updateSQL();
        };

        this.grid.load();
    }

    _bindEvents() {
        const modeChk = this.parentElement.querySelector('#qb-mode-chk');
        modeChk.onchange = () => {
            this.mode = modeChk.checked ? 'edit' : 'navigate';
            this._updateUI();
        };

        const debugChk = this.parentElement.querySelector('#qb-debug-chk');
        debugChk.onchange = () => {
            this.parentElement.querySelector('#qb-debug').style.display = debugChk.checked ? 'block' : 'none';
        };

        const runBtn = this.parentElement.querySelector('#qb-run');
        runBtn.onclick = () => this._executeSQL();

        const sqlEditor = this.parentElement.querySelector('#qb-sql');
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

        const resizer = this.parentElement.querySelector('#qb-resizer');
        const editorPane = this.parentElement.querySelector('#qb-editor-container');
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
    }

    _updateUI() {
        const isEdit = this.mode === 'edit';
        this.parentElement.querySelector('#qb-mode-text').textContent = isEdit ? 'Edición SQL' : 'Navegación';
        const sqlEditor = this.parentElement.querySelector('#qb-sql');
        sqlEditor.readOnly = !isEdit;
        sqlEditor.classList.toggle('editable', isEdit);
        this.parentElement.querySelector('#qb-run').style.display = isEdit ? 'block' : 'none';
        
        this.parentElement.querySelector('#qb-chips').style.display = isEdit ? 'none' : 'flex';
        this.parentElement.querySelector('#qb-rels').style.display = isEdit ? 'none' : 'flex';

        if (!isEdit) {
            this._renderChips();
            this.grid.resetAndFetch();
        }
    }

    _renderChips() {
        const container = this.parentElement.querySelector('#qb-chips');
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
                this.grid.resetAndFetch();
            };
        });
        
        if (window.app?.schemaExplorer && window.app?.activeSchemaData) {
            window.app.schemaExplorer.update(window.app.activeSchemaData, this.tables);
        }
    }

    async _loadRelations() {
        if (this.mode === 'edit') return;
        try {
            let data;
            if (this.useLegacyApi) {
                const resp = await fetch(`api.php?action=related_tables&connectionId=${this.connectionId}&tables=${encodeURIComponent(JSON.stringify(this.tables))}`);
                data = await resp.json();
            } else {
                data = await this.api.schemaExplorer.getRelatedTables({ connectionId: this.connectionId, tables: JSON.stringify(this.tables) });
            }
            
            this._renderRelList('qb-rel-to', data.to || []);
            this._renderRelList('qb-rel-from', data.from || []);
            this._loadTableDescription();
        } catch (e) { console.error("Error loading relations", e); }
    }

    async _loadTableDescription() {
        try {
            let result;
            if (this.useLegacyApi) {
                const resp = await fetch(`api.php?action=table_description&connectionId=${this.connectionId}&tables=${encodeURIComponent(JSON.stringify(this.tables))}`);
                result = await resp.json();
            } else {
                // Obtener descripción de cada tabla y combinarlas
                const descriptions = {};
                for (const tbl of this.tables) {
                    const resp = await this.api.schemaExplorer.describeTable({ connectionId: this.connectionId, table: tbl });
                    if (resp.success && resp.structure) {
                        descriptions[tbl] = resp.structure;
                    }
                }
                result = { success: true, description: descriptions };
            }

            if (result.success && result.description) {
                if (window.app) window.app.activeSchemaData = result.description;
                if (this.schemaExplorer) {
                    this.schemaExplorer.update(result.description, this.tables);
                } else if (window.app?.schemaExplorer) {
                    window.app.schemaExplorer.update(result.description, this.tables);
                }
            }
        } catch (e) { console.error("Error loading table description", e); }
    }

    _renderRelList(containerId, list) {
        const container = this.parentElement.querySelector(`#${containerId} .qb-rel-list`);
        container.innerHTML = list.map(t => `<span class="qb-rel-item" data-table="${t}">${window.escapeHtml(t)}</span>`).join('');
        container.querySelectorAll('.qb-rel-item').forEach(item => {
            item.onclick = () => {
                const tableName = item.dataset.table;
                if (!this.tables.includes(tableName)) {
                    this.tables.push(tableName);
                    this._renderChips();
                    this._loadRelations();
                    this.grid.resetAndFetch();
                }
            };
        });
        
        if (window.app?.schemaExplorer && window.app?.activeSchemaData) {
            window.app.schemaExplorer.update(window.app.activeSchemaData, this.tables);
        }
    }

    async _updateSQL() {
        if (this.mode !== 'navigate') return;
        try {
            let sql;
            if (this.useLegacyApi) {
                const f = new FormData();
                f.append('connectionId', this.connectionId);
                f.append('tables', JSON.stringify(this.tables));
                const resp = await fetch(`api.php?action=auto_query`, { method: 'POST', body: f });
                const data = await resp.json();
                sql = data.sql || '';
            } else {
                const result = await this.api.queryBuilder.autoQuery({ connectionId: this.connectionId, tables: JSON.stringify(this.tables) });
                sql = result.sql || '';
            }
            this.parentElement.querySelector('#qb-sql').value = sql;
        } catch (e) {}
    }

    _executeSQL() {
        const sql = this.parentElement.querySelector('#qb-sql').value.trim();
        if (!sql) return;

        const errorEl = this.parentElement.querySelector('#qb-error');
        errorEl.style.display = 'none';

        const btn = this.parentElement.querySelector('#qb-run');
        const origBtnHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<img src="assets/icon/reloj.gif" style="height:16px;vertical-align:middle;margin-right:8px;"> Ejecutando...';

        const execute = async () => {
            try {
                let result;
                if (this.useLegacyApi) {
                    const f = new FormData();
                    f.append('connectionId', this.connectionId);
                    f.append('sql', sql);
                    const resp = await fetch(`api.php?action=execute_query`, { method: 'POST', body: f });
                    result = await resp.json();
                } else {
                    // Petición directa al router para obtener todos los metadatos
                    const url = `api/v1/index.php?ep=QueryExecutor&action=execute&connectionId=${encodeURIComponent(this.connectionId)}&sql=${encodeURIComponent(sql)}`;
                    const resp = await fetch(url);
                    result = await resp.json();
                }

                if (result.error) throw new Error(result.error);

                if (result.columns) {
                    this.grid.render(result.data, { 
                        columns: result.columns, 
                        titles: result.titles || result.columns.map(c => c.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())) 
                    });
                    this.grid.hasMore = false;
                    this.grid.footerContainer.textContent = `Total: ${result.data.length} registros | Duración: ${result.durationMs || 'N/A'}ms`;
                } else {
                    alert(`Filas afectadas: ${result.affected_rows || result.affected || 0}`);
                }
            } catch (e) {
                errorEl.textContent = e.message;
                errorEl.style.display = 'block';
            } finally {
                btn.innerHTML = origBtnHtml;
                btn.disabled = false;
            }
        };
        execute();
    }

    onActivate() {
        if (this.onActivateTab) this.onActivateTab(this);
    }
	
	setSelectedColumns(selected) {
    // selected = [ { column: "tabla.columna", sort: "ASC" }, ... ]
    this.selectedColumns = selected;

    if (this.mode === 'edit') return;   // Solo en navegación

    if (!selected || selected.length === 0) {
        // Si no hay selección, usar todas las columnas (comportamiento por defecto)
        this._updateSQLAndGrid();
        return;
    }

    // Extraer solo los nombres de columna completos (tabla.columna)
    const columns = selected.map(s => s.column);

    // Regenerar SQL con esas columnas y recargar la grilla
    this._updateSQLAndGrid(columns);
}

setSelectedColumns(selected) {
    this.selectedColumns = selected;

    if (this.mode !== 'navigate') return;

    if (!selected || selected.length === 0) {
        this._updateSQL();
        this.grid.resetAndFetch();
        return;
    }

    const columns = selected.map(s => s.column);

    if (this.useLegacyApi) {
        this._updateSQL();
        this.grid.resetAndFetch();
        return;
    }

    this._updateSQLAndGrid(columns);
}

async _updateSQLAndGrid(columns) {
    try {
        const params = {
            connectionId: this.connectionId,
            tables: JSON.stringify(this.tables),
            columns: JSON.stringify(columns)
        };
        const result = await this.api.queryBuilder.autoQuery(params);
        if (!result.sql) return;

        this.parentElement.querySelector('#qb-sql').value = result.sql;

        const url = `api/v1/index.php?ep=QueryExecutor&action=execute&connectionId=${encodeURIComponent(this.connectionId)}&sql=${encodeURIComponent(result.sql)}`;
        const resp = await fetch(url);
        const execData = await resp.json();

        if (execData.columns) {
            this.grid.render(execData.data, {
                columns: execData.columns,
                titles: execData.titles || execData.columns.map(c => c.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()))
            });
            this.grid.hasMore = false;
            this.grid.footerContainer.textContent = `Total: ${execData.data.length} registros | Duración: ${execData.durationMs || 'N/A'}ms`;
        }
    } catch (e) {
        console.error('Error actualizando SQL/Grid:', e);
    }
}


}