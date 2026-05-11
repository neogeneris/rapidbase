<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>RapidBase - Unified Layout</title>
    <link rel="stylesheet" href="components/ConnectionFooter/ConnectionFooter.css">
    <link rel="stylesheet" href="components/ConnectionManager/ConnectionManager.css">
    <link rel="stylesheet" href="components/ConnectionDialog/ConnectionDialog.css">
    <link rel="stylesheet" href="components/TableList/TableList.css">
    <link rel="stylesheet" href="components/TabManager/TabManager.css">
    <link rel="stylesheet" href="components/GraphViewer/GraphViewer.css">
    <link rel="stylesheet" href="components/QueryBuilder/QueryBuilder.css">
    <link rel="stylesheet" href="components/SchemaExplorer/SchemaExplorer.css">
    <link rel="stylesheet" href="grid/Grid.css">
    <!-- vis‑network -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/vis/4.21.0/vis.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/vis/4.21.0/vis.min.css">
    
    <style>
        :root {
            --accent-color: #3182ce;
            --border-color: #cbd5e0;
            --footer-height: 28px;
            --panel-header-bg: #f1f5f9;
        }

        body {
            margin: 0; padding: 0; height: 100vh;
            display: flex; flex-direction: column;
            font-family: 'Segoe UI', system-ui, sans-serif;
            background-color: #fff; overflow: hidden;
        }

        .main-wrapper { 
            display: flex; 
            flex: 1; 
            overflow: hidden; 
            height: calc(100vh - var(--footer-height)); 
        }

        #left-sidebar { width: 280px; display: flex; flex-direction: column; }
        #right-sidebar { width: 280px; display: flex; flex-direction: column; }
        #conn-manager-box { height: 250px; min-height: 100px; flex-shrink: 0; }
        #table-list-box { flex: 1; }

        /* --- CLASE PANEL --- */
        .rb-panel {
            display: flex; flex-direction: column;
            background: #ffffff; height: 100%; width: 100%;
            overflow: hidden; border: 1px solid var(--border-color);
        }
        .rb-panel-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 6px 12px; background: var(--panel-header-bg);
            border-bottom: 1px solid var(--border-color); user-select: none;
            flex-shrink: 0;
        }
        .rb-panel-title {
            font-size: 11px; font-weight: 700; color: #475569;
            text-transform: uppercase; letter-spacing: 0.5px;
            display: flex; align-items: center; gap: 8px;
        }
        .rb-panel-body { 
            flex: 1; 
            display: flex;
            flex-direction: column;
            overflow: hidden; 
            background: #fff; 
            position: relative; 
        }

        /* --- SPLITTERS --- */
        .resizer-h { height: 4px; background: #e2e8f0; cursor: row-resize; z-index: 5; }
        .resizer-v { width: 4px; background: #e2e8f0; cursor: col-resize; z-index: 10; }
        .resizer-h:hover, .resizer-v:hover { background: var(--accent-color); }

        /* --- EDITOR --- */
        .editor-area { flex: 1; display: flex; flex-direction: column; padding: 10px; background: #f8fafc; }
        .sql-textarea { 
            flex: 1; border: 1px solid var(--border-color); border-radius: 4px;
            padding: 15px; font-family: 'Consolas', monospace; outline: none; resize: none;
        }
    </style>
</head>
<body>

    <div class="main-wrapper">
        <aside id="left-sidebar">
            <div id="conn-manager-box"></div>
            <div class="resizer-h" id="resizer-h-left"></div>
            <div id="table-list-box"></div>
        </aside>

        <div class="resizer-v" id="resizer-v-left"></div>

        <main class="editor-area" style="padding: 0;">
            <div id="tabs-header" class="tm-header"></div>
            <div id="tabs-content" class="tm-content"></div>
        </main>

        <div class="resizer-v" id="resizer-v-right"></div>

        <aside id="right-sidebar">
            <div id="schema-explorer-box" style="height: 100%;"></div>
        </aside>
    </div>

    <footer id="footer-container" class="tab-footer"></footer>
<script src="components/Icons/DBIcons.js"></script>
    <script src="components/ConnectionFooter/ConnectionFooter.js"></script>
	
    <script src="components/ConnectionManager/ConnectionManager.js"></script>
    <script src="components/ConnectionDialog/ConnectionDialog.js"></script>
<script src="components/TableList/TableList.js"></script>
<script src="components/TabManager/TabManager.js"></script>
<script src="components/GraphViewer/GraphViewer.js"></script>
<script src="components/QueryBuilder/QueryBuilder.js"></script>
<script src="components/GridViewer/GridViewer.js"></script>
<script src="components/SchemaExplorer/SchemaExplorer.js"></script>
<script src="grid/GridBuilder.js"></script>
<script src="grid/APIDataGrid.js"></script>

    <script>
        // --- UTILIDADES ---
        window.escapeHtml = (text) => {
            if (!text) return '';
            return String(text).replace(/[&<>"']/g, m => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
            }[m]));
        };

        // --- CLASE PANEL ---
        class Panel {
            constructor(containerId, title, options = {}) {
                this.container = document.getElementById(containerId);
                this.bodyId = `${containerId}-body`;
                this.render(title, options);
            }
            render(title, options) {
                this.container.innerHTML = `
                    <div class="rb-panel">
                        <div class="rb-panel-header">
                            <span class="rb-panel-title">${options.icon || ''} ${title}</span>
                        </div>
                        <div class="rb-panel-body" id="${this.bodyId}"></div>
                    </div>
                `;
            }
            getBodyId() { return this.bodyId; }
        }

        // --- SPLITTERS ---
        function initResizable(resizer, target, direction, sidebarElement = null) {
            resizer.addEventListener('mousedown', (e) => {
                e.preventDefault();
                const onMouseMove = (event) => {
                    if (direction === 'h') {
                        const newHeight = event.clientY;
                        if (newHeight > 100 && newHeight < (window.innerHeight - 150)) {
                            target.style.height = newHeight + 'px';
                        }
                    } else if (direction === 'v-left') {
                        const newWidth = event.clientX;
                        if (newWidth > 150 && newWidth < 600) {
                            target.style.width = newWidth + 'px';
                            // Fix: Force reflow for grid horizontal scroll
                            if (sidebarElement) {
                                sidebarElement.style.width = newWidth + 'px';
                            }
                        }
                    } else if (direction === 'v-right') {
                        const newWidth = window.innerWidth - event.clientX;
                        if (newWidth > 150 && newWidth < 600) {
                            target.style.width = newWidth + 'px';
                            if (sidebarElement) {
                                sidebarElement.style.width = newWidth + 'px';
                            }
                        }
                    }
                };
                const onMouseUp = () => document.removeEventListener('mousemove', onMouseMove);
                document.addEventListener('mousemove', onMouseMove);
                document.addEventListener('mouseup', onMouseUp, { once: true });
            });
        }

        // --- OBJETO APP GLOBAL ---
        window.app = {
            footer: null,
            connManager: null,
            connDialog: null,
            tableList: null,
            tabs: null,
            schemaExplorer: null,
            activeConnectionId: null,
            activeSchemaData: null,
            
            // Método para cambiar de conexión
            connectSaved: (id) => {
                if (!id || id === 'undefined') return;
                app.activeConnectionId = id;
                console.log("Cambiando a conexión ID:", id);
                
                // Reiniciar el footer con el nuevo ID
                app.footer = new ConnectionFooter('footer-container', id);

                // Abrir pestaña de grafo
                app.openGraph(id);
            },

            openGraph: (connId) => {
                const graph = new GraphViewer(connId, {
                    onTableClick: (tableName) => app.openGrid(connId, tableName)
                });
                app.tabs.addTab(`graph-${connId}`, `Graph: ${connId}`, graph, { icon: '🕸️' });
            },

            openGrid: (connId, tableName) => {
                const qb = new QueryBuilder(connId, tableName, {
                    onActivateTab: (instance) => {
                        // Update SchemaExplorer when this tab is selected
                        if (app.schemaExplorer && app.activeSchemaData) {
                            app.schemaExplorer.update(app.activeSchemaData, instance.tables);
                        }
                    }
                });
                app.tabs.addTab(`grid-${connId}-${tableName}`, tableName, qb, { icon: '📋' });
            },

            // Abrir diálogo de nueva conexión
            showNewConnection: () => {
                if (!app.connDialog) app.connDialog = new ConnectionDialog();
                app.connDialog.open();
            }
        };

        // --- INICIO ---
        window.onload = () => {
            const pConn = new Panel('conn-manager-box', 'Connection', { icon: '🔌' });
            const pTables = new Panel('table-list-box', 'Tables', { icon: '📂' });

            // Cargar Connection Manager y Table List
            app.connManager = new ConnectionManager(pConn.getBodyId());
            app.tableList   = new TableList(pTables.getBodyId(), {
                onTableClick: (tableName) => app.openGrid(app.activeConnectionId, tableName)
            });
            
            // Inicializar pestañas
            app.tabs = new TabManager('tabs-header', 'tabs-content');

            // Inicializar SchemaExplorer (Aside derecho)
            app.schemaExplorer = new SchemaExplorer({ 
                containerId: 'schema-explorer-box',
                grid: {
                    updateQuery: (selected) => {
                        console.log("Columnas seleccionadas para query:", selected);
                        // Aquí podrías actualizar el QueryBuilder si es necesario
                    }
                },
                onSelectionChange: (selected) => {
                    console.log("Columnas seleccionadas:", selected);
                }
            });

            // Interceptar la carga de tablas para guardar el schemaData global
            const origPopulate = app.tableList.populate.bind(app.tableList);
            app.tableList.populate = (connId, data) => {
                app.activeSchemaData = data;
                origPopulate(connId, data);
                // Actualizar SchemaExplorer con los datos del schema
                if (app.schemaExplorer && data) {
                    app.schemaExplorer.update(data, []);
                }
            };

            initResizable(document.getElementById('resizer-h-left'), document.getElementById('conn-manager-box'), 'h');
            initResizable(document.getElementById('resizer-v-left'), document.getElementById('left-sidebar'), 'v-left', document.getElementById('left-sidebar'));
            initResizable(document.getElementById('resizer-v-right'), document.getElementById('right-sidebar'), 'v-right', document.getElementById('right-sidebar'));

            // NOTA: No inicializamos el footer con ID 1 por defecto para evitar el error 400 
            // si la DB está vacía. Se cargará cuando hagas clic en una conexión de la lista.
        };
    </script>
</body>
</html>