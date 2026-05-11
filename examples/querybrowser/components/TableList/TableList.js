/**
 * TableList.js
 * Schema tree explorer — shows tables and columns for the active connection.
 *
 * Usage:
 *   app.tableList = new TableList('table-list-body-id');
 *
 * Called by ConnectionManager after a successful ping + list_tables:
 *   app.tableList.populate(connectionId, schemaData);
 *   // schemaData = { database, driver, schema_tables: [{name, columns:[{name,type,primary,nullable}]}] }
 */
class TableList {
    constructor(containerId, options = {}) {
        this.container = document.getElementById(containerId);
        this.connectionId = null;
        this.schemaData = null;
        this.openTables = new Set();   // tracks which table rows are expanded
        this.searchTerm = '';
        this.onTableClick = options.onTableClick || null;
        if (!this.container) return;
        this._renderEmpty();
    }

    // ─── Public API ───────────────────────────────────────────

    /** Called by ConnectionManager after list_tables succeeds */
    populate(connectionId, data) {
        this.connectionId = connectionId;
        this.schemaData   = data;
        this.openTables.clear();
        this.searchTerm = '';
        this._render();
    }

    /** Show loading state */
    setLoading() {
        if (!this.container) return;
        this.container.innerHTML = `
            <div class="tl-wrapper">
                <div class="tl-state">Cargando esquema…</div>
            </div>`;
    }

    /** Show error state */
    setError(msg) {
        if (!this.container) return;
        this.container.innerHTML = `
            <div class="tl-wrapper">
                <div class="tl-state error">⚠ ${window.escapeHtml(msg)}</div>
            </div>`;
    }

    // ─── Icons ────────────────────────────────────────────────

    static get ICON_DB() {
        return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/>
            <path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>
        </svg>`;
    }

    static get ICON_TABLE() {
        return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
            <rect x="3" y="3" width="18" height="18" rx="2"/>
            <path d="M3 9h18M3 15h18M9 3v18"/>
        </svg>`;
    }

    static _colIcon(col) {
        if (col.primary)                    return '🔑';
        if (/int|serial|bigint/i.test(col.type)) return '#';
        if (/char|text|varchar/i.test(col.type)) return 'A';
        if (/bool/i.test(col.type))         return '◉';
        if (/date|time/i.test(col.type))    return '📅';
        if (/float|double|decimal|real/i.test(col.type)) return '~';
        return '·';
    }

    // ─── Render ───────────────────────────────────────────────

    _renderEmpty() {
        this.container.innerHTML = `
            <div class="tl-wrapper">
                <div class="tl-state">Selecciona una conexión para ver el esquema</div>
            </div>`;
    }

    _render() {
        if (!this.container) return;

        const data   = this.schemaData;
        const tables = data?.schema_tables || [];
        const dbName = data?.database || '—';

        this.container.innerHTML = `
            <div class="tl-wrapper">

                <!-- Toolbar: search -->
                <div class="tl-toolbar">
                    <div class="tl-search-wrap">
                        <span class="tl-search-icon">🔍</span>
                        <input
                            type="text"
                            class="tl-search"
                            id="tl-search-input"
                            placeholder="Filtrar tablas y columnas..."
                            value="${window.escapeHtml(this.searchTerm)}"
                        >
                    </div>
                </div>

                <!-- DB name strip -->
                <div class="tl-db-name">
                    ${TableList.ICON_DB}
                    <span id="tl-db-label">${window.escapeHtml(dbName)}</span>
                </div>

                <!-- Tree -->
                <div class="tl-tree" id="tl-tree">
                    ${this._renderTree(tables, this.searchTerm)}
                </div>

            </div>`;

        this._bindEvents();
    }

    _renderTree(tables, term) {
        if (!tables.length) {
            return `<div class="tl-state">No se encontraron tablas</div>`;
        }

        const lterm = term.toLowerCase();

        // Filter: a table matches if its name or any column matches
        const filtered = tables.map(t => {
            if (!lterm) return { ...t, matchedCols: null };
            const nameMatch = t.name.toLowerCase().includes(lterm);
            const matchedCols = t.columns.filter(c =>
                c.name.toLowerCase().includes(lterm) || c.type.toLowerCase().includes(lterm)
            );
            if (!nameMatch && !matchedCols.length) return null;
            return { ...t, matchedCols: nameMatch ? null : matchedCols };
        }).filter(Boolean);

        if (!filtered.length) {
            return `<div class="tl-state">Sin resultados para "<b>${window.escapeHtml(term)}</b>"</div>`;
        }

        let html = `
            <div class="tl-group-row open" data-group="tables">
                <span class="tl-chevron">▶</span>
                <span>Tablas</span>
                <span class="tl-col-count">${filtered.length}</span>
            </div>`;

        filtered.forEach(table => {
            const isOpen  = this.openTables.has(table.name) || (lterm && table.matchedCols !== null);
            const colsHtml = table.columns.map(col => {
                const isPk   = col.primary;
                const icon   = TableList._colIcon(col);
                const typeShort = col.type.replace(/\(.*\)/, '').toLowerCase();
                return `
                    <div class="tl-col-row ${isPk ? 'is-pk' : ''}" data-col="${window.escapeHtml(table.name + '.' + col.name)}">
                        <span class="tl-col-icon">${icon}</span>
                        <span class="tl-col-name">${window.escapeHtml(col.name)}</span>
                        <span class="tl-col-type">${window.escapeHtml(typeShort)}</span>
                    </div>`;
            }).join('');

            html += `
                <div class="tl-table-row ${isOpen ? 'open' : ''}" data-table="${window.escapeHtml(table.name)}">
                    <span class="tl-table-chevron">▶</span>
                    <span class="tl-table-icon">${TableList.ICON_TABLE}</span>
                    <span class="tl-table-name">${window.escapeHtml(table.name)}</span>
                    <span class="tl-col-count">${table.columns.length}</span>
                </div>
                <div class="tl-col-list ${isOpen ? 'open' : ''}" data-cols-for="${window.escapeHtml(table.name)}">
                    ${colsHtml}
                </div>`;
        });

        return html;
    }

    // ─── Events ───────────────────────────────────────────────

    _bindEvents() {
        if (!this.container) return;

        // Search
        const searchInput = this.container.querySelector('#tl-search-input');
        if (searchInput) {
            searchInput.addEventListener('input', () => {
                this.searchTerm = searchInput.value;
                const tree = this.container.querySelector('#tl-tree');
                if (tree) tree.innerHTML = this._renderTree(
                    this.schemaData?.schema_tables || [],
                    this.searchTerm
                );
                this._bindTreeEvents();
            });
        }

        this._bindTreeEvents();
    }

    _bindTreeEvents() {
        const tree = this.container?.querySelector('#tl-tree');
        if (!tree) return;

        // Group toggle (Tables / Views header)
        tree.querySelectorAll('.tl-group-row').forEach(row => {
            row.onclick = () => row.classList.toggle('open');
        });

        // Table row toggle → expand/collapse columns
        tree.querySelectorAll('.tl-table-row').forEach(row => {
            row.onclick = () => {
                const tableName = row.dataset.table;
                const colList   = tree.querySelector(`[data-cols-for="${tableName}"]`);

                const isNowOpen = !row.classList.contains('open');
                row.classList.toggle('open', isNowOpen);
                if (colList) colList.classList.toggle('open', isNowOpen);

                if (isNowOpen) this.openTables.add(tableName);
                else           this.openTables.delete(tableName);

                if (this.onTableClick) {
                    this.onTableClick(tableName);
                }

                // Mark row as active
                tree.querySelectorAll('.tl-table-row').forEach(r => r.classList.remove('active'));
                row.classList.add('active');
            };
        });

        // Column row click — emit to app
        tree.querySelectorAll('.tl-col-row').forEach(row => {
            row.onclick = (e) => {
                e.stopPropagation();
                const fullName = row.dataset.col; // "table.column"
                if (window.app?.onColumnClick) {
                    app.onColumnClick(this.connectionId, fullName);
                }
            };
        });
    }
}