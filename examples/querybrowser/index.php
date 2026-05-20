<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>RapidBase – Unified Layout</title>
    
    <link rel="stylesheet" href="components/ConnectionFooter/ConnectionFooter.css">
    <link rel="stylesheet" href="components/ConnectionManager/ConnectionManager.css">
    <link rel="stylesheet" href="components/ConnectionDialog/ConnectionDialog.css">
    <link rel="stylesheet" href="components/TableList/TableList.css?v=2">
    <link rel="stylesheet" href="components/TabManager/TabManager.css">
    <link rel="stylesheet" href="components/GraphViewer/GraphViewer.css?v=2">
    <link rel="stylesheet" href="components/QueryBuilder/QueryBuilder.css">
    <link rel="stylesheet" href="components/SchemaExplorer/SchemaExplorer.css">
    <link rel="stylesheet" href="components/SQLEditor/SQLEditor.css">
    <link rel="stylesheet" href="grid/Grid.css">
    <link rel="stylesheet" href="assets/css/glass.css">
    
    <!-- vis‑network -->
    <script src="assets/vis/vis.min.js"></script>
    <link rel="stylesheet" href="assets/vis/vis.min.css">
    
    <!-- Los CDN de CodeMirror se cargan al final del body con defer -->

    <style>
        :root {
            --accent-color: #3182ce;
            --border-color: rgba(255, 255, 255, 0.15);
            --footer-height: 28px;
            --panel-header-bg: rgba(255, 255, 255, 0.05);
        }

        html, body {
            margin: 0; padding: 0; height: 100vh; width: 100vw;
            display: flex; flex-direction: column;
            font-family: 'Segoe UI', system-ui, sans-serif;
            background-image: url('assets/img/background.jpg');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            background-color: #0f172a;
            overflow: hidden;
        }
        
        /* ── Overlay de carga / desconexión ── */
        .app-overlay {
            position: fixed;
            top: 0; left: 0; width: 100vw; height: 100vh;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(12px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            transition: opacity 0.3s ease;
        }
        .app-overlay.hidden {
            opacity: 0;
            pointer-events: none;
        }
        .app-overlay-content {
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.25);
            border-radius: 20px;
            padding: 48px 64px;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            color: #1e293b;
            max-width: 420px;
        }
        .app-overlay-icon { font-size: 48px; margin-bottom: 16px; }
        .app-overlay-title { font-size: 24px; font-weight: 700; margin-bottom: 24px; }
        .app-spinner {
            width: 28px; height: 28px;
            border: 3px solid rgba(255,255,255,0.3);
            border-top: 3px solid #3b82f6;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin: 0 auto 12px;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .app-overlay-status span {
            display: block;
            margin-top: 12px;
            font-size: 14px;
            color: #475569;
        }
        .app-overlay-error p {
            font-size: 14px;
            color: #b91c1c;
            margin-bottom: 20px;
        }
        .app-btn {
            padding: 10px 24px;
            background: #3b82f6;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }
        .app-btn:hover { background: #2563eb; }

        .main-wrapper { 
            display: flex; flex: 1; overflow: hidden; 
            height: calc(100vh - var(--footer-height)); 
            background: transparent;
        }

        #left-sidebar { width: 280px; display: flex; flex-direction: column; }
        #right-sidebar { width: 280px; display: flex; flex-direction: column; }
        #conn-manager-box { display: flex; flex-direction: column; height: 250px; min-height: 100px; flex-shrink: 0; }
        #table-list-box { flex: 1; display: flex; flex-direction: column; min-height: 0; }

        .rb-panel {
            display: flex; flex-direction: column;
            background: rgba(255, 255, 255, 0.06); 
            backdrop-filter: blur(4px) saturate(110%);
            -webkit-backdrop-filter: blur(4px) saturate(110%);
            height: 100%; width: 100%;
            overflow: hidden; 
            border: 1px solid var(--border-color);
            box-shadow: 0 4px 20px 0 rgba(0, 0, 0, 0.05);
        }
        .rb-panel-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 6px 12px; background: var(--panel-header-bg);
            border-bottom: 1px solid var(--border-color); user-select: none;
            flex-shrink: 0;
            backdrop-filter: blur(2px);
        }
        .rb-panel-title {
            font-size: 11px; font-weight: 700; color: #1e293b;
            text-transform: uppercase; letter-spacing: 0.5px;
            display: flex; align-items: center; gap: 8px;
        }
        .rb-panel-body { 
            flex: 1; display: flex; flex-direction: column; overflow: hidden; 
            background: transparent; position: relative; 
        }

        .resizer-h { height: 4px; background: rgba(255, 255, 255, 0.1); cursor: row-resize; z-index: 5; flex-shrink: 0; }
        .resizer-v { width: 4px; background: rgba(255, 255, 255, 0.1); cursor: col-resize; z-index: 10; flex-shrink: 0; }
        .resizer-h:hover, .resizer-v:hover { background: var(--accent-color); }
        body.is-resizing * { pointer-events: none !important; user-select: none !important; }

        .editor-area { 
            flex: 1; display: flex; flex-direction: column; 
            background: transparent; min-width: 0; 
        }
    </style>
</head>
<body>

    <!-- Splash Screen -->
    <div id="app-overlay" class="app-overlay">
        <div class="app-overlay-content">
            <div class="app-overlay-icon">🔌</div>
            <h2 class="app-overlay-title">RapidBase</h2>
            <div class="app-overlay-status" id="app-overlay-status">
                <div class="app-spinner"></div>
                <span>Conectando con el servidor...</span>
            </div>
            <div class="app-overlay-error" id="app-overlay-error" style="display:none;">
                <p>⚠️ No se pudo establecer conexión con el API.</p>
                <button id="app-overlay-retry" class="app-btn">Reintentar</button>
            </div>
        </div>
    </div>

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
            <div id="schema-explorer-box" style="flex: 1; display: flex; flex-direction: column; min-height: 0;"></div>
        </aside>
    </div>

    <footer id="footer-container" class="tab-footer"></footer>
    
    <script src="assets/js/RapidBaseClient.js"></script>
    <script src="components/Icons/DBIcons.js"></script>
    <script src="components/ConnectionFooter/ConnectionFooter.js"></script>
    <script src="components/ConnectionManager/ConnectionManager.js"></script>
    <script src="components/ConnectionDialog/ConnectionDialog.js"></script>
    <script src="components/TableList/TableList.js?v=2"></script>
    <script src="components/TabManager/TabManager.js"></script>
    <script src="components/GraphViewer/GraphViewer.js?v=2"></script>
    <script src="components/QueryBuilder/QueryBuilder.js"></script>
    <script src="components/GridViewer/GridViewer.js"></script>
    <script src="components/SchemaExplorer/SchemaExplorer.js"></script>
    <script src="components/SQLEditor/SQLEditor.js"></script>
    <script src="grid/GridBuilder.js"></script>
    <script src="grid/APIDataGrid.js"></script>

    <!-- CDN de CodeMirror con defer para no bloquear el splash -->
    <script defer src="assets/js/codemirror/codemirror.js"></script>
    <script defer src="assets/js/codemirror/mode/sql.js"></script>
    <script defer src="assets/js/codemirror/show-hint.js"></script>
    <script defer src="assets/js/codemirror/sql-hint.min.js"></script>
    <!-- CSS de CodeMirror (sin defer, no bloquea) -->
    <link rel="stylesheet" href="assets/css/codemirror/codemirror.css">
    <link rel="stylesheet" href="assets/css/codemirror/show-hint.min.css">

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
                document.body.classList.add('is-resizing');
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
                            if (sidebarElement) sidebarElement.style.width = newWidth + 'px';
                        }
                    } else if (direction === 'v-right') {
                        const newWidth = window.innerWidth - event.clientX;
                        if (newWidth > 150 && newWidth < 600) {
                            target.style.width = newWidth + 'px';
                            if (sidebarElement) sidebarElement.style.width = newWidth + 'px';
                        }
                    }
                };
                const onMouseUp = () => {
                    document.body.classList.remove('is-resizing');
                    document.removeEventListener('mousemove', onMouseMove);
                };
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
            
            openSQLEditor: (connId = null) => {
                const editor = new SQLEditor(connId);
                app.tabs.addTab(`sql-editor-${connId || 'default'}`, 'SQL Editor', editor, { icon: '📝' });
            },
            
            connectSaved: async (id) => {
                if (!id || id === 'undefined') return;
                
                const connectionKey = `saved_${id}`;
                app.activeConnectionId = connectionKey;

                if (window.RapidBaseClient) {
                    const api = new RapidBaseClient('api/v1/index.php');
                    const res = await api.connectionManager.activate({ connectionId: id });
                    if (!res.success) {
                        console.error('Error activando conexión:', res.error);
                        return;
                    }
                } else {
                    const form = new FormData();
                    form.append('connId', id);
                    const resp = await fetch('api.php?action=connect_saved', { method: 'POST', body: form });
                    const data = await resp.json();
                    if (data.status !== 'ok') {
                        console.error('Error activando conexión', data);
                        return;
                    }
                }

                console.log('Conexión activada:', connectionKey);
                app.footer = new ConnectionFooter('footer-container', connectionKey);
                app.openGraph(connectionKey);
                app.openSQLEditor(connectionKey);
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
                        if (app.schemaExplorer && app.activeSchemaData) {
                            app.schemaExplorer.update(app.activeSchemaData, instance.tables);
                        }
                    },
                    schemaExplorer: app.schemaExplorer
                });
                app.tabs.addTab(`grid-${connId}-${tableName}`, tableName, qb, { icon: '📋' });
            },

            showNewConnection: () => {
                if (!app.connDialog) app.connDialog = new ConnectionDialog();
                app.connDialog.open();
            }
        };

        // --- SPLASH SCREEN LOGIC (optimizado) ---
        async function checkApiConnection(retries = 1) {
            try {
                const resp = await fetch('api/v1/index.php?ep=SystemInfo&action=version');
                if (!resp.ok) throw new Error('API no disponible');
                const data = await resp.json();
                if (data && data.version) return data;
                throw new Error('Respuesta inesperada del API');
            } catch (e) {
                if (retries > 0) {
                    await new Promise(r => setTimeout(r, 1000));
                    return checkApiConnection(retries - 1);
                }
                return null;
            }
        }

        function showOverlay(error = false) {
            const overlay = document.getElementById('app-overlay');
            const statusEl = document.getElementById('app-overlay-status');
            const errorEl = document.getElementById('app-overlay-error');
            if (error) {
                statusEl.style.display = 'none';
                errorEl.style.display = 'block';
            } else {
                statusEl.style.display = 'block';
                errorEl.style.display = 'none';
            }
            overlay.classList.remove('hidden');
        }

        function hideOverlay() {
            const overlay = document.getElementById('app-overlay');
            overlay.classList.add('hidden');
            setTimeout(() => {
                if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
            }, 400);
        }

        function getPageLoadMetrics() {
            if (!window.performance || !performance.getEntriesByType) {
                return { count: 0, totalKB: 0, timeMs: 0 };
            }
            const resources = performance.getEntriesByType('resource');
            let totalBytes = 0, count = 0;
            resources.forEach(r => {
                if (r.transferSize) totalBytes += r.transferSize;
                else if (r.encodedBodySize) totalBytes += r.encodedBodySize;
                count++;
            });
            const timeMs = performance.timing ? 
                (performance.timing.loadEventEnd - performance.timing.navigationStart) : 0;
            return { count, totalKB: (totalBytes / 1024).toFixed(1), timeMs };
        }

        window.addEventListener('DOMContentLoaded', () => {
            const statusSpan = document.querySelector('#app-overlay-status span');
            
            if (statusSpan) statusSpan.textContent = 'Cargando página...';
            showOverlay(false);

            const apiPromise = checkApiConnection();

            const timeoutPromise = new Promise((resolve) => {
                setTimeout(() => resolve('timeout'), 5000);
            });

            Promise.race([apiPromise, timeoutPromise]).then(async (apiInfo) => {
                if (document.readyState === 'complete') {
                    const metrics = getPageLoadMetrics();
                    if (statusSpan && metrics.count > 0) {
                        statusSpan.innerHTML = `📦 ${metrics.count} recursos (${metrics.totalKB} KB) en ${(metrics.timeMs/1000).toFixed(1)}s<br>⏳ Verificando API...`;
                    }
                } else {
                    if (statusSpan) statusSpan.innerHTML = '⏳ Verificando API...';
                }

                if (apiInfo && apiInfo !== 'timeout') {
                    if (statusSpan) {
                        statusSpan.innerHTML = `✅ API v${apiInfo.version} · ${apiInfo.name}<br>🔌 ${apiInfo.endpoints_count} endpoints disponibles`;
                    }
                    setTimeout(() => {
                        hideOverlay();
                        initApp();
                    }, 1200);
                } else {
                    showOverlay(true);
                    document.getElementById('app-overlay-retry').onclick = async () => {
                        showOverlay(false);
                        if (statusSpan) statusSpan.textContent = 'Reintentando...';
                        const retryOk = await checkApiConnection();
                        if (retryOk) {
                            hideOverlay();
                            initApp();
                        } else {
                            showOverlay(true);
                        }
                    };
                }
            });
        });

        // --- INICIALIZACIÓN DE LA APLICACIÓN ---
        function initApp() {
            const pConn = new Panel('conn-manager-box', 'Connection', { icon: '🔌' });
            const pTables = new Panel('table-list-box', 'Tables', { icon: '📂' });

            app.connManager = new ConnectionManager(pConn.getBodyId());
            app.tableList   = new TableList(pTables.getBodyId(), {
                onTableClick: (tableName) => app.openGrid(app.activeConnectionId, tableName)
            });
            
            app.tabs = new TabManager('tabs-header', 'tabs-content', {
                onTabActivate: (tabId, tab) => {
                    if (tab && tab.component && tab.component.constructor.name === 'QueryBuilder') {
                        // el QueryBuilder se encarga del SchemaExplorer
                    } else {
                        if (app.schemaExplorer) app.schemaExplorer.close();
                    }
                },
                onTabClose: (tabId, nextTab) => {
                    if (!nextTab && app.schemaExplorer) app.schemaExplorer.close();
                }
            });

            app.schemaExplorer = new SchemaExplorer({ 
                containerId: 'schema-explorer-box',
                autoOpen: false,
                grid: {
                    updateQuery: (selected) => {
                        console.log("Columnas seleccionadas para query:", selected);
                    }
                },
                onSelectionChange: (selected) => {
                    if (!app.tabs || !app.tabs.activeTabId) return;
                    const activeTab = app.tabs.tabs.get(app.tabs.activeTabId);
                    if (activeTab && activeTab.component instanceof QueryBuilder) {
                        activeTab.component.setSelectedColumns(selected);
                    }
                },
                onClose: () => {
                    document.getElementById('right-sidebar').style.display = 'none';
                    document.getElementById('resizer-v-right').style.display = 'none';
                },
                onOpen: () => {
                    document.getElementById('right-sidebar').style.display = '';
                    document.getElementById('resizer-v-right').style.display = '';
                }
            });

            const origPopulate = app.tableList.populate.bind(app.tableList);
            app.tableList.populate = (connId, data) => {
                app.activeSchemaData = data;
                origPopulate(connId, data);
            };

            initResizable(document.getElementById('resizer-h-left'), document.getElementById('conn-manager-box'), 'h');
            initResizable(document.getElementById('resizer-v-left'), document.getElementById('left-sidebar'), 'v-left', document.getElementById('left-sidebar'));
            initResizable(document.getElementById('resizer-v-right'), document.getElementById('right-sidebar'), 'v-right', document.getElementById('right-sidebar'));
        }
    </script>
</body>
</html>