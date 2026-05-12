/**
 * QueryBuilder.js
 * Advanced data explorer with SQL editor, auto-join chips, and data grid.
 */
class QueryBuilder {
    constructor(connectionId, tableName, options = {}) {
        this.connectionId = connectionId;
        this.tableName = tableName;
        this.tables = [tableName];
        this.mode = 'navigate'; // 'navigate' or 'edit'
        this.searchTerm = '';
        this.grid = null;
        this.onActivateTab = options.onActivateTab || null;
        this.schemaExplorer = options.schemaExplorer || null;
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
        const apiUrl = `api.php?action=grid_data&connectionId=${this.connectionId}`;
        this.grid = new APIDataGrid('#qb-grid', apiUrl, { mode: 'infinite', pageSize: 50 });
        
        // Override grid parameters
        const origBuildParams = this.grid.buildParams.bind(this.grid);
        this.grid.buildParams = () => {
            const p = origBuildParams();
            p.set('connectionId', this.connectionId);
            p.set('table', JSON.stringify(this.tables));
            if (this.searchTerm) p.set('search', this.searchTerm);
            return p;
        };

        // Hook into grid fetch to update SQL debug
        const origFetchData = this.grid.fetchData.bind(this.grid);
        this.grid.fetchData = async () => {
            await origFetchData();
            // After fetch, if there's debug info in the last response (we need to capture it)
            // For now, we'll manually fetch the SQL if in navigate mode
            if (this.mode === 'navigate') {
                this._updateSQL();
            }
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

        // Drag & Drop columns from SchemaExplorer
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

        // Horizontal Resizer
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
        const modeText = this.parentElement.querySelector('#qb-mode-text');
        const sqlEditor = this.parentElement.querySelector('#qb-sql');
        const runBtn = this.parentElement.querySelector('#qb-run');
        const chips = this.parentElement.querySelector('#qb-chips');
        const rels = this.parentElement.querySelector('#qb-rels');

        modeText.textContent = isEdit ? 'Edición SQL' : 'Navegación';
        sqlEditor.readOnly = !isEdit;
        sqlEditor.classList.toggle('editable', isEdit);
        runBtn.style.display = isEdit ? 'block' : 'none';
        
        // Chips & Relations only in navigate mode
        chips.style.display = isEdit ? 'none' : 'flex';
        rels.style.display = isEdit ? 'none' : 'flex';

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
        
        // Update SchemaExplorer with active tables when chips change
        if (window.app?.schemaExplorer && window.app?.activeSchemaData) {
            window.app.schemaExplorer.update(window.app.activeSchemaData, this.tables);
        }
    }

    async _loadRelations() {
        if (this.mode === 'edit') return;
        try {
            const resp = await fetch(`api.php?action=related_tables&connectionId=${this.connectionId}&tables=${encodeURIComponent(JSON.stringify(this.tables))}`);
            const data = await resp.json();
            
            this._renderRelList('qb-rel-to', data.to || []);
            this._renderRelList('qb-rel-from', data.from || []);
            
            // Cargar descripción de las tablas para SchemaExplorer
            this._loadTableDescription();
        } catch (e) { console.error("Error loading relations", e); }
    }

    async _loadTableDescription() {
        try {
            const resp = await fetch(`api.php?action=table_description&connectionId=${this.connectionId}&tables=${encodeURIComponent(JSON.stringify(this.tables))}`);
            const data = await resp.json();
            
            console.log("SchemaExplorer - Table Description Response:", data);
            
            if (data.success && data.description) {
                // Guardar en app.activeSchemaData si existe el contexto global
                if (window.app) {
                    window.app.activeSchemaData = data.description;
                    console.log("SchemaExplorer - Updated app.activeSchemaData with tables:", Object.keys(data.description));
                }
                
                // Actualizar SchemaExplorer directamente si tenemos referencia
                if (this.schemaExplorer) {
                    console.log("SchemaExplorer - Updating via direct reference with tables:", this.tables);
                    this.schemaExplorer.update(data.description, this.tables);
                } else if (window.app && window.app.schemaExplorer) {
                    console.log("SchemaExplorer - Updating via window.app.schemaExplorer with tables:", this.tables);
                    window.app.schemaExplorer.update(data.description, this.tables);
                } else {
                    console.warn("SchemaExplorer - No schemaExplorer instance found");
                }
            } else if (data.error) {
                console.error("SchemaExplorer - API error:", data.error);
            } else {
                console.warn("SchemaExplorer - No description in response");
            }
        } catch (e) { 
            console.error("SchemaExplorer - Error loading table description", e); 
        }
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
        
        // Update SchemaExplorer with active tables when relations change
        if (window.app?.schemaExplorer && window.app?.activeSchemaData) {
            window.app.schemaExplorer.update(window.app.activeSchemaData, this.tables);
        }
    }

    async _updateSQL() {
        if (this.mode !== 'navigate') return;
        try {
            const f = new FormData();
            f.append('connectionId', this.connectionId);
            f.append('tables', JSON.stringify(this.tables));
            const resp = await fetch(`api.php?action=auto_query`, { method: 'POST', body: f });
            const data = await resp.json();
            this.parentElement.querySelector('#qb-sql').value = data.sql || '';
        } catch (e) {}
    }

    _executeSQL() {
        const sql = this.parentElement.querySelector('#qb-sql').value.trim();
        if (!sql) return;

        const errorEl = this.parentElement.querySelector('#qb-error');
        errorEl.style.display = 'none';

        const btn = this.parentElement.querySelector('#qb-run');
        const origBtnHtml = btn.innerHTML;
        const origBtnDisabled = btn.disabled;
        btn.disabled = true;
        // Use correct path to clock.gif
        btn.innerHTML = '<img src="assets/icon/reloj.gif" style="height:16px;vertical-align:middle;margin-right:8px;"> Ejecutando...';

        try {
            const f = new FormData();
            f.append('connectionId', this.connectionId);
            f.append('sql', sql);
            fetch(`api.php?action=execute_query`, { method: 'POST', body: f })
                .then(resp => resp.json())
                .then(data => {
                    if (data.error) throw new Error(data.error);

                    if (data.columns) {
                        // If it's a SELECT, update grid
                        this.grid.render(data.data, { 
                            columns: data.columns, 
                            titles: data.columns.map(c => c.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())) 
                        });
                        this.grid.hasMore = false;
                        this.grid.footerContainer.textContent = `Total: ${data.data.length} registros | Duración: ${data.durationMs || 'N/A'}ms`;
                    } else {
                        alert(`Filas afectadas: ${data.affected || 0}`);
                    }
                })
                .catch(e => {
                    errorEl.textContent = e.message;
                    errorEl.style.display = 'block';
                })
                .finally(() => {
                    btn.innerHTML = origBtnHtml;
                    btn.disabled = origBtnDisabled;
                });
        } catch (e) {
            errorEl.textContent = e.message;
            errorEl.style.display = 'block';
            btn.innerHTML = origBtnHtml;
            btn.disabled = origBtnDisabled;
        }
    }

    onActivate() {
        if (this.onActivateTab) {
            this.onActivateTab(this);
        }
        if (this.grid) {
            // Need to fix potential column alignment issues when tab becomes visible
        }
    }
}
