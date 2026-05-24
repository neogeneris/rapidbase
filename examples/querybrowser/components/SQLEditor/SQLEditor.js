/**
 * SQLEditor.js
 * Editor SQL clásico con grid de resultados y selector de conexiones.
 */
class SQLEditor {
    constructor(connectionId = null) {
        this.connectionId = connectionId;          // puede ser null al inicio
        this.connections = [];                     // lista de conexiones disponibles
        this.grid = null;
        this.cmEditor = null;
        this.useAdvancedEditor = true;
    }

    async init(parentElement) {
        this.parentElement = parentElement;

        // Obtener la lista de conexiones para el selector
        await this._loadConnections();

        this.instanceId = `sqleditor-${Date.now()}`;
        const id = this.instanceId;
        
        // Generar opciones del selector después de cargar las conexiones
        const connectionOptions = this.connections.map(c => {
            const normalized = this._normalizeConnectionName(c.name);
            const isSelected = normalized === this.connectionId ? 'selected' : '';
            return `<option value="${normalized}" ${isSelected}>${this._escapeHtml(c.name)} (${c.driver})</option>`;
        }).join('');
        
        this.parentElement.innerHTML = `
            <div class="se-wrapper">
                <div class="se-toolbar">
                    <select class="se-connection-select" id="${id}-conn-select">
                        ${connectionOptions}
                    </select>
                    <div class="se-actions">
                        <button class="se-btn se-run-btn" title="Ejecutar (F5)">▶ Ejecutar</button>
                        <button class="se-btn se-clear-btn" title="Limpiar">🗑️</button>
                    </div>
                    <span class="se-info" id="${id}-info"></span>
                </div>
                <div class="se-editor-pane">
                    <textarea class="se-textarea" id="${id}-textarea" placeholder="Escribe tu consulta SQL aquí..."></textarea>
                </div>
                <!-- El contenedor de resultados ahora fuerza el scroll interno -->
                <div class="se-results-pane" style="flex:1; min-height:0; overflow:hidden;">
                    <div class="grid-container" id="${id}-grid" style="flex:1; min-height:0; overflow:hidden;">
                        <div class="grid-controls"></div>
                        <div class="grid-loading" style="display:none;">Cargando...</div>
                        <div class="grid-error" style="display:none;"></div>
                        <div class="grid-scroll-wrapper">
                            <table class="grid-table">
                                <thead class="grid-head">
                                    <tr>
                                        <th class="grid-header" style="display:none;">\${header}</th>
                                    </tr>
                                </thead>
                                <tbody class="grid-body">
                                    <tr class="grid-row" style="display:none;">
                                        <td class="grid-item">\${value}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="grid-footer"></div>
                    </div>
                </div>
            </div>
        `;

        this._initEditor();
        this._initGrid();
        this._bindEvents();
    }

    async _loadConnections() {
        try {
            if (window.RapidBaseClient) {
                const api = new RapidBaseClient('api/v1/index.php');
                const result = await api.connectionManager.list();
                this.connections = result.connections || [];
                if (!this.connectionId && this.connections.length > 0) {
                    this.connectionId = this._normalizeConnectionName(this.connections[0].name);
                }
            } else {
                const resp = await fetch('api.php?action=list_connections');
                const data = await resp.json();
                this.connections = (data.connections || []).map(c => ({ id: c[0], name: c[1], driver: c[2] }));
                if (!this.connectionId && this.connections.length > 0) {
                    this.connectionId = this._normalizeConnectionName(this.connections[0].name);
                }
            }
        } catch (e) {
            console.error('Error cargando conexiones para el editor SQL:', e);
        }
    }

    _initEditor() {
        const textarea = this.parentElement.querySelector('.se-textarea');
        if (typeof CodeMirror !== 'undefined' && this.useAdvancedEditor) {
            this.cmEditor = CodeMirror.fromTextArea(textarea, {
                mode: 'text/x-sql',
                theme: 'default',
                lineNumbers: true,
                extraKeys: {
                    "Ctrl-Enter": () => this._executeSQL(),
                    "F5": () => { this._executeSQL(); return false; }
                },
                hintOptions: { completeSingle: false }
            });
        }
    }

    _initGrid() {
        this.grid = new APIDataGrid(`#${this.instanceId}-grid`, 'api/v1/index.php?ep=QueryExecutor&action=execute', {
            mode: 'infinite',
            pageSize: 50
        });
        this.grid.hasMore = false; // Prevent premature infinite scroll triggers
        this.grid.buildParams = () => {
            const p = new URLSearchParams();
            p.set('connectionId', this.connectionId);
            p.set('sql', this._getSQL());
            return p;
        };

        // Client-side sorting implementation for raw SQL results
        this.grid.sortBy = (field) => {
            if (!this.originalData || !this.originalData.length) return;

            if (this.grid.sortField !== field) {
                this.grid.sortField = field;
                this.grid.sortOrder = 'asc';
            } else {
                if (this.grid.sortOrder === 'asc') this.grid.sortOrder = 'desc';
                else if (this.grid.sortOrder === 'desc') {
                    this.grid.sortOrder = null;
                    this.grid.sortField = null;
                } else this.grid.sortOrder = 'asc';
            }

            this.grid.updateSortIndicator();

            if (!this.grid.sortField || !this.grid.sortOrder) {
                this.grid.render(this.originalData, {
                    columns: this.currentColumns,
                    titles: this.currentTitles
                });
                return;
            }

            const colIdx = this.currentColumns.indexOf(field);
            if (colIdx === -1) return;

            const sorted = [...this.originalData].sort((a, b) => {
                let valA = a[colIdx];
                let valB = b[colIdx];

                const numA = Number(valA);
                const numB = Number(valB);
                if (valA !== '' && valB !== '' && !isNaN(numA) && !isNaN(numB)) {
                    valA = numA;
                    valB = numB;
                } else {
                    valA = valA !== null && valA !== undefined ? String(valA).toLowerCase() : '';
                    valB = valB !== null && valB !== undefined ? String(valB).toLowerCase() : '';
                }

                if (valA < valB) return this.grid.sortOrder === 'asc' ? -1 : 1;
                if (valA > valB) return this.grid.sortOrder === 'asc' ? 1 : -1;
                return 0;
            });

            this.grid.render(sorted, {
                columns: this.currentColumns,
                titles: this.currentTitles
            });
        };
    }

    _bindEvents() {
        this.parentElement.querySelector('.se-run-btn').onclick = () => this._executeSQL();
        this.parentElement.querySelector('.se-clear-btn').onclick = () => this._clearEditor();

        const connSelect = this.parentElement.querySelector('.se-connection-select');
        connSelect.onchange = () => {
            this.connectionId = connSelect.value;
        };
    }

    _getSQL() {
        return this.cmEditor ? this.cmEditor.getValue() :
               this.parentElement.querySelector('.se-textarea').value;
    }

    async _executeSQL() {
        const sql = this._getSQL().trim();
        if (!sql || !this.connectionId) return;

        const infoEl = this.parentElement.querySelector('.se-info');
        infoEl.textContent = 'Ejecutando...';

        try {
            const url = `api/v1/index.php?ep=QueryExecutor&action=execute&connectionId=${this.connectionId}&sql=${encodeURIComponent(sql)}`;
            const resp = await fetch(url);
            const result = await resp.json();

            if (result.error) throw new Error(result.error);

            if (result.columns) {
                this.grid._hideError();
                
                // Clear any prior sort indicators for new queries
                this.grid.sortField = null;
                this.grid.sortOrder = null;
                this.grid.updateSortIndicator();

                // Save data and metadata locally for client-side sorting
                this.originalData = result.data;
                this.currentColumns = result.columns;
                this.currentTitles = result.titles || result.columns.map(c => c.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()));

                this.grid.render(result.data, {
                    columns: this.currentColumns,
                    titles: this.currentTitles
                });
                this.grid.hasMore = false;
                infoEl.textContent = `${result.data.length} filas | ${result.durationMs || '0'}ms`;
                if (this.grid.footerContainer) {
                    this.grid.footerContainer.textContent = `Total: ${result.data.length} registros | Duración: ${result.durationMs || 'N/A'}ms`;
                }
            } else {
                infoEl.textContent = `Filas afectadas: ${result.affected_rows || 0}`;
            }
        } catch (e) {
            infoEl.textContent = 'Error: ' + e.message;
        }
    }

    _clearEditor() {
        if (this.cmEditor) {
            this.cmEditor.setValue('');
        } else {
            this.parentElement.querySelector('.se-textarea').value = '';
        }
    }

    _escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    /**
     * Normalize connection name to match the server-side normalization.
     * Converts to lowercase, replaces special characters with underscores.
     */
    _normalizeConnectionName(name) {
        let normalized = name.trim().toLowerCase();
        normalized = normalized.replace(/[^a-z0-9_\-]/g, '_');
        normalized = normalized.replace(/_+/g, '_'); // Avoid multiple consecutive underscores
        return 'conn_' + normalized;
    }

    onActivate() {}
}