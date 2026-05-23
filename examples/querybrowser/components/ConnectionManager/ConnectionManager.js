class ConnectionManager {
    constructor(containerId) {
        this.container = document.getElementById(containerId);
        this.allConnections = [];
        this.filterActive = false;
        this.pingedConnections = {};
        this.activeConnectionId = null;

        // Cliente unificado (nuevo router v1)
        this.apiClient = window.RapidBaseClient
            ? new RapidBaseClient('api/v1/index.php')
            : null;

        if (!this.container) return;
        this.init();
    }

    async init() {
        this.container.innerHTML = '<div class="p-3 text-muted" style="font-size:12px;">Cargando...</div>';
        try {
            this.allConnections = await this._fetchConnections();
            this.render();
        } catch (e) {
            this.container.innerHTML = '<div class="p-3 text-danger">Error de API</div>';
        }
    }

    async _fetchConnections() {
        const result = await this.apiClient.connectionManager.list();
        return result.connections || [];
    }

    async _pingConnection(id) {
        return await this.apiClient.connectionManager.ping({ connectionId: `saved_${id}` });
    }

    async _loadSchema(connectionId) {
        const raw = await this.apiClient.schemaExplorer.getSchema({ connectionId: `saved_${connectionId}` });
        return {
            success: true,
            tables: raw.tables || [],
            views: raw.views || [],
            relations: raw.relations || [],
            database: raw.database || '',
        };
    }

    async selectConnection(id, element) {
        if (element.classList.contains('is-testing')) return;

        this.container.querySelectorAll('.conn-item').forEach(el => el.classList.remove('active'));
        element.classList.add('active');

        // Si ya estaba online, solo recargamos esquema y activamos
        if (this.pingedConnections[id]?.success) {
            this.activeConnectionId = id;
            this.loadSchema(id);
            if (window.app?.connectSaved) app.connectSaved(id);
            return;
        }

        element.classList.remove('is-online', 'is-offline');
        element.classList.add('is-testing');

        const latencyEl = element.querySelector('.latency-tag');
        const dbNameEl = element.querySelector('.conn-real-db');
        const hostPortEl = element.querySelector('.conn-host-port');

        latencyEl.textContent = '...';
        if (dbNameEl) dbNameEl.innerHTML = '<span class="meta-loading">...</span>';

        try {
            const result = await this._pingConnection(id);
            element.classList.remove('is-testing');

            if (result.success) {
                element.classList.add('is-online');
                latencyEl.textContent = `${Math.round(result.latency)}ms`;

                if (dbNameEl) dbNameEl.textContent = result.database_name || '';
                if (hostPortEl) {
                    let hostStr = result.host || '';
                    if (result.port) hostStr += ':' + result.port;
                    hostPortEl.textContent = hostStr ? `· ${hostStr}` : '';
                }

                this.pingedConnections[id] = {
                    success: true,
                    latency: result.latency,
                    host: result.host,
                    port: result.port,
                    database_name: result.database_name,
                    driver: result.driver
                };

                this.activeConnectionId = id;
                this.loadSchema(id);
                // Activar la conexión y cargar el esquema en el resto de la app
                if (window.app?.connectSaved) app.connectSaved(id);
            } else {
                element.classList.add('is-offline');
                latencyEl.textContent = 'ERR';
                if (dbNameEl) dbNameEl.innerHTML = '<span class="meta-error">!</span>';
                this.pingedConnections[id] = { success: false };
            }
        } catch (err) {
            element.classList.remove('is-testing');
            element.classList.add('is-offline');
            latencyEl.textContent = 'OFF';
            this.pingedConnections[id] = { success: false };
        }
    }

    async loadSchema(connectionId) {
        if (window.app?.tableList) app.tableList.setLoading();

        try {
            const result = await this._loadSchema(connectionId);

            if (result.success && window.app?.tableList) {
                // Añadir nombre de la BD si no viene en la respuesta
                if (!result.database) {
                    const connInfo = this.allConnections.find(c => c.id === connectionId);
                    result.database = connInfo?.database || '';
                }
                app.tableList.populate(connectionId, result);
            } else if (!result.success && window.app?.tableList) {
                app.tableList.setError(result.error || 'No se pudo cargar el esquema');
            }
        } catch (e) {
            console.error('Error cargando esquema:', e);
            if (window.app?.tableList) {
                app.tableList.setError('Error de red al cargar el esquema');
            }
        }
    }

    // ── Render (con iconos SVG modernos) ──
    render() {
        this.container.innerHTML = '';
        this.container.className = 'cm-wrapper';

        const launcher = document.createElement('div');
        launcher.className = 'cm-launcher';
        launcher.innerHTML = `
            <button class="l-btn ${this.filterActive ? 'active' : ''}" id="cm-filter-btn">
                <svg viewBox="0 0 24 24" width="14" height="14"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="12" cy="12" r="4" fill="currentColor"/></svg>
            </button>
            <div class="l-search">
                <input type="text" class="launcher-input" name="cm-search" placeholder="Buscar...">
            </div>
            <button class="l-btn l-add" onclick="app.showNewConnection()"><span>+</span></button>
        `;

        const list = document.createElement('div');
        list.className = 'cm-list';

        this.allConnections.forEach(conn => {
            const id = conn.id;
            const name = conn.name || 'Unnamed';
            const driver = (conn.driver || '').toLowerCase();
            // Usar los SVG modernos de DBIcons (archivos locales)
            const iconSvg = window.DBIcons?.[driver] 
                ? `<img src="assets/icon/driver/${driver}.svg" class="cm-driver-icon" alt="${driver}">` 
                : '🗄️';

            const cached = this.pingedConnections[id];
            let statusClass = '';
            let latencyText = '';
            let dbNameText = conn.database || '';
            let hostPortText = '';

            if (cached) {
                if (cached.success) {
                    statusClass = 'is-online';
                    latencyText = `${Math.round(cached.latency)}ms`;
                    dbNameText = cached.database_name || dbNameText;
                    if (cached.host) {
                        hostPortText = cached.host;
                        if (cached.port) hostPortText += ':' + cached.port;
                    }
                } else {
                    statusClass = 'is-offline';
                    latencyText = 'ERR';
                }
            } else {
                if (conn.host) {
                    hostPortText = conn.host;
                    if (conn.port) hostPortText += ':' + conn.port;
                }
            }

            const item = document.createElement('div');
            item.className = `conn-item ${statusClass}`;
            item.setAttribute('data-id', id);
            item.setAttribute('data-name', name);
            item.onclick = () => this.selectConnection(id, item);

            item.innerHTML = `
                <div class="conn-icon-box">
                    ${iconSvg}
                    <div class="status-dot"></div>
                </div>
                <div class="conn-body">
                    <div class="conn-header-row">
                        <div class="conn-name-wrap">
                            <span class="conn-name">${window.escapeHtml(name)}</span>
                            <span class="conn-real-db">${window.escapeHtml(dbNameText)}</span>
                        </div>
                        <span class="latency-tag">${latencyText}</span>
                    </div>
                    <div class="conn-details-row">
                        <span class="conn-driver">${driver}</span>
                        <span class="conn-host-port">${hostPortText ? '· ' + window.escapeHtml(hostPortText) : ''}</span>
                    </div>
                </div>
            `;
            list.appendChild(item);
        });

        this.container.appendChild(launcher);
        this.container.appendChild(list);

        launcher.querySelector('#cm-filter-btn').onclick = (e) => {
            this.filterActive = !this.filterActive;
            e.currentTarget.classList.toggle('active', this.filterActive);
            this.applyFilters();
        };
        launcher.querySelector('.launcher-input').oninput = () => this.applyFilters();
    }

    applyFilters() {
        const term = this.container.querySelector('.launcher-input').value.toLowerCase();
        const items = this.container.querySelectorAll('.conn-item');
        items.forEach(item => {
            const name = item.getAttribute('data-name').toLowerCase();
            const isOnline = item.classList.contains('is-online');
            const matchesSearch = name.includes(term);
            const matchesStatus = !this.filterActive || isOnline;
            item.style.display = (matchesSearch && matchesStatus) ? 'flex' : 'none';
        });
    }
}