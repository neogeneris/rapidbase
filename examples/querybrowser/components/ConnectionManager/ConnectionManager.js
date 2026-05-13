class ConnectionManager {
    constructor(containerId) {
        this.container = document.getElementById(containerId);
        this.allConnections = [];
        this.filterActive = false;
        this.pingedConnections = {}; // Cache: { id: { success, latency, host, port, database_name, driver } }
        this.activeConnectionId = null;
        if (!this.container) return;
        this.init();
    }

    async init() {
        this.container.innerHTML = '<div class="p-3 text-muted" style="font-size:12px;">Cargando...</div>';
        try {
            // Nuevo endpoint unificado: ConnectionManager::list
            const response = await fetch('api/v1/index.php?ep=ConnectionManager&action=list');
            const data = await response.json();
            this.allConnections = data.connections || [];
            this.render();
        } catch (e) {
            this.container.innerHTML = '<div class="p-3 text-danger">Error de API</div>';
        }
    }

    async selectConnection(id, element) {
        if (element.classList.contains('is-testing')) return;

        // Mark active
        this.container.querySelectorAll('.conn-item').forEach(el => el.classList.remove('active'));
        element.classList.add('active');

        // If already pinged and online → go straight to schema
        if (this.pingedConnections[id]?.success) {
            this.activeConnectionId = `saved_${id}`;
            this.loadSchema(this.activeConnectionId);
            app.connectSaved(this.activeConnectionId);
            return;
        }

        // First click → ping
        element.classList.remove('is-online', 'is-offline');
        element.classList.add('is-testing');

        const latencyEl = element.querySelector('.latency-tag');
        const dbNameEl = element.querySelector('.conn-real-db');
        const hostPortEl = element.querySelector('.conn-host-port');
        
        latencyEl.textContent = '...';
        if (dbNameEl) dbNameEl.innerHTML = '<span class="meta-loading">...</span>';

        try {
            const response = await fetch('api/v1/index.php?ep=HealthService&action=ping', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ connectionId: id })
            });

            const result = await response.json();
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

                // Cache the ping result
                this.pingedConnections[id] = {
                    success: true,
                    latency: result.latency,
                    host: result.host,
                    port: result.port,
                    database_name: result.database_name,
                    driver: result.driver
                };

                this.activeConnectionId = `saved_${id}`;
                // Now load schema
                this.loadSchema(this.activeConnectionId);
                app.connectSaved(this.activeConnectionId);
            } else {
                element.classList.add('is-offline');
                latencyEl.textContent = 'ERR';
                if (dbNameEl) dbNameEl.innerHTML = `<span class="meta-error">!</span>`;

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
        // Delegate rendering to the TableList component
        if (window.app?.tableList) {
            app.tableList.setLoading();
        }

        try {
            const response = await fetch(`api/v1/index.php?ep=SchemaExplorer&action=getSchema&connectionId=${connectionId}`);
            const result   = await response.json();

            if (result.success && window.app?.tableList) {
                // Adaptar el formato de SchemaExplorer al que espera TableList
                const adaptedResult = {
                    success: true,
                    tables: result.tables || [],
                    views: result.views || [],
                    relations: result.relations || []
                };
                app.tableList.populate(connectionId, adaptedResult);
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
                <input type="text" class="launcher-input" placeholder="Buscar...">
            </div>
            <button class="l-btn l-add" onclick="app.showNewConnection()"><span>+</span></button>
        `;

        const list = document.createElement('div');
        list.className = 'cm-list';

        this.allConnections.forEach(conn => {
            const id = conn[0];
            const name = conn[1] || 'Unnamed';
            const driver = (conn[2] || '').toLowerCase();
            const iconSvg = window.DBIcons ? (DBIcons[driver] || DBIcons.sqlite) : '🗄️';

            // Check if this connection was already pinged
            const cached = this.pingedConnections[id];
            let statusClass = '';
            let latencyText = '';
            let metaContent = '';

            if (cached) {
                if (cached.success) {
                    statusClass = 'is-online';
                    latencyText = `${Math.round(cached.latency)}ms`;
                    const parts = [];
                    if (cached.database_name) parts.push(cached.database_name);
                    if (cached.host) {
                        let hostStr = cached.host;
                        if (cached.port) hostStr += ':' + cached.port;
                        parts.push(hostStr);
                    }
                    metaContent = parts.map(p => `<span class="meta-chip">${window.escapeHtml(p)}</span>`).join('');
                } else {
                    statusClass = 'is-offline';
                    latencyText = 'ERR';
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
                            <span class="conn-name">${name}</span>
                            <span class="conn-real-db">${cached?.database_name ? window.escapeHtml(cached.database_name) : ''}</span>
                        </div>
                        <span class="latency-tag">${latencyText}</span>
                    </div>
                    <div class="conn-details-row">
                        <span class="conn-driver">${driver}</span>
                        <span class="conn-host-port">${cached?.host ? '· ' + window.escapeHtml(cached.host + (cached.port ? ':' + cached.port : '')) : ''}</span>
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