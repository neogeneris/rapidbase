/**
 * ConnectionDialog.js
 * Two‑step wizard for creating new database connections.
 * Refactored for deep esmerilated glassmorphism design.
 * Now uses the new API v1 (ConnectionManager.test / create).
 */
class ConnectionDialog {
    constructor() {
        this.currentStep = 1;
        this.selectedDriver = 'mysql';
        this.selectedEnvironment = 'dev';
        this.overlay = null;
        this.errorModal = null;
        this._build();
    }

    static get DRIVERS() {
        return [
            { 
                id: 'mysql', 
                label: 'MySQL', 
                port: 3306, 
                svg: `<img src="assets/icon/driver/mysql.svg" alt="MySQL" class="cd-driver-svg">`,
                svgStep2: `<img src="assets/icon/driver/mysql.svg" alt="MySQL" class="cd-driver-svg-step2">`
            },
            { 
                id: 'mariadb', 
                label: 'MariaDB', 
                port: 3306, 
                svg: `<img src="assets/icon/driver/mariadb.svg" alt="MariaDB" class="cd-driver-svg">`,
                svgStep2: `<img src="assets/icon/driver/mariadb.svg" alt="MariaDB" class="cd-driver-svg-step2">`
            },
            { 
                id: 'sqlite', 
                label: 'SQLite', 
                port: null, 
                svg: `<img src="assets/icon/driver/sqlite.svg" alt="SQLite" class="cd-driver-svg">`,
                svgStep2: `<img src="assets/icon/driver/sqlite.svg" alt="SQLite" class="cd-driver-svg-step2">`
            },
            { 
                id: 'pgsql', 
                label: 'PostgreSQL', 
                port: 5432, 
                svg: `<img src="assets/icon/driver/postgresql.svg" alt="PostgreSQL" class="cd-driver-svg">`,
                svgStep2: `<img src="assets/icon/driver/postgresql.svg" alt="PostgreSQL" class="cd-driver-svg-step2">`
            },
            { 
                id: 'sqlsrv', 
                label: 'SQL Server', 
                port: 1433, 
                svg: `<img src="assets/icon/driver/sqlserver.svg" alt="SQL Server" class="cd-driver-svg">`,
                svgStep2: `<img src="assets/icon/driver/sqlserver.svg" alt="SQL Server" class="cd-driver-svg-step2">`
            }
        ];
    }

    // ─── Build & Inject Semántico ────────────────────────────
    _build() {
        // Modal de error (oculto por defecto)
        this._buildErrorModal();

        if (document.getElementById('cd-overlay')) {
            this.overlay = document.getElementById('cd-overlay');
            return;
        }

        const ov = document.createElement('div');
        ov.id = 'cd-overlay';
        ov.className = 'cd-overlay';

        ov.innerHTML = `
            <div class="cd-dialog">

                <header class="cd-header">
                    <div class="cd-header-meta">
                        <h3 class="cd-title">Nueva Conexión</h3>
                        <p class="cd-subtitle" id="cd-subtitle">Paso 1 — Seleccionar driver</p>
                    </div>
                    <div class="cd-header-actions">
                        <div class="cd-pills">
                            <span class="cd-pill active" data-step="1"></span>
                            <span class="cd-pill" data-step="2"></span>
                        </div>
                        <button class="cd-close-btn" id="cd-close" aria-label="Cerrar">&times;</button>
                    </div>
                </header>

                <main class="cd-body" id="cd-step-1">
                    <section class="cd-section">
                        <h4 class="cd-section-title">Select Database Engine</h4>
                        <div class="cd-driver-grid" id="cd-driver-grid">
                            ${ConnectionDialog.DRIVERS.map(d => `
                                <div class="cd-driver-card ${d.id === this.selectedDriver ? 'active' : ''}" data-driver="${d.id}" role="radio">
                                    <div class="cd-driver-icon">${d.svg}</div>
                                    <span class="cd-driver-name">${d.label}</span>
                                </div>
                            `).join('')}
                        </div>
                    </section>

                    <section class="cd-section fields-gap">
                        <div class="cd-field">
                            <label class="cd-label">Connection Name <span class="cd-req">*</span></label>
                            <input type="text" class="cd-input" id="cd-name" placeholder="e.g. Production Main" autocomplete="off">
                        </div>

                        <div class="cd-field">
                            <label class="cd-label">Environment</label>
                            <div class="cd-env-group" role="radiogroup">
                                <button class="cd-env-pill active" data-status="dev">
                                    <span class="cd-env-dot dev"></span> Development
                                </button>
                                <button class="cd-env-pill" data-status="qa">
                                    <span class="cd-env-dot qa"></span> QA
                                </button>
                                <button class="cd-env-pill" data-status="production">
                                    <span class="cd-env-dot prod"></span> Production
                                </button>
                            </div>
                        </div>

                        <div class="cd-field">
                            <label class="cd-label">Description</label>
                            <textarea class="cd-textarea" id="cd-description" placeholder="Contextual notes about this infrastructure..."></textarea>
                        </div>
                    </section>
                </main>

                <main class="cd-body" id="cd-step-2" style="display:none;">
                    <div class="cd-driver-badge" id="cd-step2-badge"></div>

                    <section class="cd-section fields-gap">
                        <div class="cd-row" id="cd-network-row">
                            <div class="cd-field" style="flex: 2.5">
                                <label class="cd-label">Network Host</label>
                                <input type="text" class="cd-input" id="cd-host" value="localhost">
                            </div>
                            <div class="cd-field" style="flex: 1">
                                <label class="cd-label">Port</label>
                                <input type="number" class="cd-input" id="cd-port" value="3306">
                            </div>
                        </div>

                        <div class="cd-field" id="cd-db-field">
                            <label class="cd-label" id="cd-db-label">Database <span class="cd-req">*</span></label>
                            <input type="text" class="cd-input" id="cd-database" placeholder="schema_name">
                        </div>

                        <div class="cd-row" id="cd-auth-row">
                            <div class="cd-field">
                                <label class="cd-label">Username</label>
                                <input type="text" class="cd-input" id="cd-username" placeholder="root">
                            </div>
                            <div class="cd-field">
                                <label class="cd-label">Password</label>
                                <input type="password" class="cd-input" id="cd-password" placeholder="••••••••">
                            </div>
                        </div>
                    </section>
                </main>

                <footer class="cd-footer">
                    <div class="cd-footer-status">
                        <button class="cd-btn cd-btn-ghost" id="cd-test-btn" style="display:none;">
                            <span class="cd-spinner"></span>
                            <img src="assets/icon/reloj.gif" alt="Testing..." class="cd-test-loading-gif" style="display:none;">
                            <span class="cd-btn-text">⚡ Probar conexión</span>
                        </button>
                        <!-- Indicador visual circular -->
                        <div class="cd-status-indicator" id="cd-status-indicator" style="display:none;">
                            <div class="cd-status-circle" id="cd-status-circle">
                                <div class="cd-status-spinner"></div>
                            </div>
                            <span class="cd-status-text" id="cd-status-text"></span>
                        </div>
                    </div>
                    <div class="cd-footer-buttons">
                        <button class="cd-btn cd-btn-ghost" id="cd-cancel-btn">Cancel</button>
                        <button class="cd-btn cd-btn-ghost" id="cd-prev-btn" style="display:none;">← Previous</button>
                        <button class="cd-btn cd-btn-primary" id="cd-next-btn">Next →</button>
                        <button class="cd-btn cd-btn-success" id="cd-save-btn" style="display:none;">
                            <span class="cd-spinner"></span>
                            <span class="cd-btn-text">✔ Finish</span>
                        </button>
                    </div>
                </footer>

            </div>
        `;

        document.body.appendChild(ov);
        this.overlay = ov;
        this._events();
    }

    // ─── Modal de error ─────────────────────────────────────
    _buildErrorModal() {
        if (document.getElementById('cd-error-modal')) return;
        const modal = document.createElement('div');
        modal.id = 'cd-error-modal';
        modal.className = 'cd-error-modal';
        modal.innerHTML = `
            <div class="cd-error-modal-content">
                <div class="cd-error-icon">✗</div>
                <h4 class="cd-error-title">Connection Error</h4>
                <p class="cd-error-message" id="cd-error-message"></p>
                <button class="cd-btn cd-btn-primary" id="cd-error-close">Close</button>
            </div>
        `;
        document.body.appendChild(modal);
        this.errorModal = modal;
        modal.querySelector('#cd-error-close').onclick = () => this._hideErrorModal();
        modal.addEventListener('click', (e) => { if (e.target === modal) this._hideErrorModal(); });
    }

    _showErrorModal(message) {
        this.errorModal.querySelector('#cd-error-message').textContent = message;
        this.errorModal.classList.add('is-open');
    }

    _hideErrorModal() {
        this.errorModal.classList.remove('is-open');
    }

    // ─── Indicador visual de estado ─────────────────────────
    _setStatus(state, text = '') {
        const indicator = this.overlay.querySelector('#cd-status-indicator');
        const circle = this.overlay.querySelector('#cd-status-circle');
        const statusText = this.overlay.querySelector('#cd-status-text');
        const testBtn = this.overlay.querySelector('#cd-test-btn');
        const spinner = testBtn.querySelector('.cd-spinner');
        const loadingGif = testBtn.querySelector('.cd-test-loading-gif');
        const btnText = testBtn.querySelector('.cd-btn-text');

        // No ocultar el botón, solo mostrar animación interna
        if (state === 'loading') {
            // Cambiar background a blanco para coincidir con el GIF
            testBtn.style.background = '#ffffff';
            testBtn.style.borderColor = '#e2e8f0';
            spinner.style.display = 'none';
            loadingGif.style.display = 'inline-block';
            btnText.style.display = 'none';
            
            // Ocultar indicador circular cuando usamos el botón con animación
            indicator.style.display = 'none';
        } else if (state === 'success') {
            // Borde verde para éxito - botón permanece visible para reintentar
            testBtn.style.border = '2px solid #22c55e';
            testBtn.style.background = ''; // restaurar color original
            spinner.style.display = 'none';
            loadingGif.style.display = 'none';
            btnText.style.display = 'inline';
            btnText.textContent = '✓ Connection successful';
            
            indicator.style.display = 'none';
            
            // Resetear después de 2 segundos para permitir nueva prueba
            setTimeout(() => this._resetStatus(), 2000);
        } else if (state === 'error') {
            // Borde rojo para error - botón permanece visible para reintentar
            testBtn.style.border = '2px solid #ef4444';
            testBtn.style.background = ''; // restaurar color original
            spinner.style.display = 'none';
            loadingGif.style.display = 'none';
            btnText.style.display = 'inline';
            btnText.textContent = '✗ Connection error';
            
            indicator.style.display = 'none';
            
            // Resetear después de 2 segundos para permitir nueva prueba
            setTimeout(() => this._resetStatus(), 2000);
        }
    }

    _resetStatus() {
        const indicator = this.overlay.querySelector('#cd-status-indicator');
        const testBtn = this.overlay.querySelector('#cd-test-btn');
        const spinner = testBtn.querySelector('.cd-spinner');
        const loadingGif = testBtn.querySelector('.cd-test-loading-gif');
        const btnText = testBtn.querySelector('.cd-btn-text');
        
        indicator.style.display = 'none';
        // Restaurar estado original del botón
        testBtn.style.border = '';
        testBtn.style.background = '';
        spinner.style.display = 'none';
        loadingGif.style.display = 'none';
        btnText.style.display = 'inline';
        btnText.textContent = '⚡ Test connection';
    }

    // ─── Control de Eventos Actualizado ──────────────────────
    _events() {
        const ov = this.overlay;

        ov.querySelector('#cd-close').onclick    = () => this.close();
        ov.querySelector('#cd-cancel-btn').onclick = () => this.close();
        ov.addEventListener('click', e => { if (e.target === ov) this.close(); });
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape' && ov.classList.contains('is-open')) this.close();
        });

        ov.querySelectorAll('.cd-driver-card').forEach(c =>
            c.onclick = () => this._pickDriver(c.dataset.driver)
        );

        ov.querySelectorAll('.cd-env-pill').forEach(b =>
            b.onclick = () => this._pickEnv(b.dataset.status)
        );

        ov.querySelector('#cd-next-btn').onclick = () => this._goTo(2);
        ov.querySelector('#cd-prev-btn').onclick = () => this._goTo(1);
        ov.querySelector('#cd-test-btn').onclick = () => this._test();
        ov.querySelector('#cd-save-btn').onclick = () => this._save();
    }

    // ─── Navegación Funcional Estricta ───────────────────────
    _goTo(step) {
        const ov = this.overlay;

        if (step === 2) {
            const name = ov.querySelector('#cd-name').value.trim();
            if (!name) {
                const inp = ov.querySelector('#cd-name');
                inp.style.boxShadow = '0 0 0 3px rgba(239, 68, 68, 0.35)';
                inp.focus();
                setTimeout(() => inp.style.boxShadow = '', 1500);
                return;
            }
            this._setupStep2();
        }

        this.currentStep = step;

        ov.querySelector('#cd-step-1').style.display = step === 1 ? 'block' : 'none';
        ov.querySelector('#cd-step-2').style.display = step === 2 ? 'block' : 'none';

        ov.querySelectorAll('.cd-pill').forEach(p => {
            const s = +p.dataset.step;
            p.className = 'cd-pill' + (s < step ? ' done' : s === step ? ' active' : '');
        });

        ov.querySelector('#cd-subtitle').textContent = step === 1
            ? 'Step 1 — Select Driver'
            : 'Step 2 — Connection Data';

        ov.querySelector('#cd-next-btn').style.display  = step === 1 ? '' : 'none';
        ov.querySelector('#cd-prev-btn').style.display  = step === 2 ? '' : 'none';
        ov.querySelector('#cd-save-btn').style.display  = step === 2 ? '' : 'none';
        // El botón de test solo aparece en el paso 2
        ov.querySelector('#cd-test-btn').style.display  = step === 2 ? '' : 'none';

        this._resetStatus();
    }

    _setupStep2() {
        const ov = this.overlay;
        const d = ConnectionDialog.DRIVERS.find(x => x.id === this.selectedDriver);
        ov.querySelector('#cd-step2-badge').innerHTML = `${d.svgStep2}<span>Selected Driver: <strong>${d.label}</strong></span>`;

        const isSqlite = this.selectedDriver === 'sqlite';

        ov.querySelector('#cd-network-row').style.display = isSqlite ? 'none' : 'flex';
        ov.querySelector('#cd-auth-row').style.display    = isSqlite ? 'none' : 'flex';

        const dbLabel = ov.querySelector('#cd-db-label');
        const dbInput = ov.querySelector('#cd-database');

        if (isSqlite) {
            dbLabel.innerHTML = 'SQLite file path <span class="cd-req">*</span>';
            dbInput.placeholder = '/absolute/path/infrastructure/file.sqlite';
        } else {
            dbLabel.innerHTML = 'Database <span class="cd-req">*</span>';
            dbInput.placeholder = 'database_schema_name';
            ov.querySelector('#cd-port').value = d.port;
        }
    }

    _pickDriver(id) {
        this.selectedDriver = id;
        this.overlay.querySelectorAll('.cd-driver-card').forEach(c =>
            c.classList.toggle('active', c.dataset.driver === id)
        );
    }

    _pickEnv(status) {
        this.selectedEnvironment = status;
        this.overlay.querySelectorAll('.cd-env-pill').forEach(b =>
            b.classList.toggle('active', b.dataset.status === status)
        );
    }

    _data() {
        const ov = this.overlay;
        return {
            name:        ov.querySelector('#cd-name').value.trim(),
            driver:      this.selectedDriver === 'mariadb' ? 'mysql' : this.selectedDriver,
            host:        ov.querySelector('#cd-host').value.trim() || null,
            port:        parseInt(ov.querySelector('#cd-port').value) || null,
            database:    ov.querySelector('#cd-database').value.trim(),
            username:    ov.querySelector('#cd-username').value.trim() || null,
            password:    ov.querySelector('#cd-password').value || null,
            description: ov.querySelector('#cd-description').value.trim() || null,
            environment: this.selectedEnvironment,
            status:      'active',
        };
    }

    _validate(d) {
        if (!d.name) return 'Name is required.';
        if (!d.database) return this.selectedDriver === 'sqlite'
            ? 'SQLite file path is required.'
            : 'Database name is required.';
        return null;
    }

    // ─── Nuevo endpoint ConnectionManager.test ───────────────
    async _test() {
        const data = this._data();
        const err = this._validate(data);
        if (err) {
            this._showErrorModal(err);
            return;
        }

        this._setStatus('loading');

        try {
            let res;
            if (window.RapidBaseClient) {
                const api = new RapidBaseClient('api/v1/index.php');
                res = await api.connectionManager.test(data);
            } else {
                const r = await fetch('api/v1/index.php?ep=ConnectionManager&action=test', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                res = await r.json();
            }
            if (res.success) {
                this._setStatus('success', `Connected (${res.latency})`);
            } else {
                this._setStatus('error');
                this._showErrorModal(res.error || 'Negotiation error');
            }
        } catch (networkError) {
            this._setStatus('error');
            this._showErrorModal('Could not contact the server. Check your network.');
        }
    }

    // ─── Nuevo endpoint ConnectionManager.create ─────────────
    async _save() {
        const data = this._data();
        const err = this._validate(data);
        if (err) {
            this._showErrorModal(err);
            return;
        }

        const saveBtn = this.overlay.querySelector('#cd-save-btn');
        saveBtn.classList.add('loading');
        saveBtn.disabled = true;

        try {
            if (window.RapidBaseClient) {
                const api = new RapidBaseClient('api/v1/index.php');
                await api.connectionManager.create(data);
            } else {
                const r = await fetch('api/v1/index.php?ep=ConnectionManager&action=create', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                const res = await r.json();
                if (!res.success) throw new Error(res.error);
            }
            this.close();
            window.app?.connManager?.init();
        } catch (e) {
            this._showErrorModal(e.message);
        } finally {
            saveBtn.classList.remove('loading');
            saveBtn.disabled = false;
        }
    }

    open() {
        this._reset();
        this.overlay.classList.add('is-open');
    }

    close() {
        this.overlay.classList.remove('is-open');
    }

    _reset() {
        const ov = this.overlay;
        ov.querySelector('#cd-name').value = '';
        ov.querySelector('#cd-host').value = 'localhost';
        ov.querySelector('#cd-port').value = '3306';
        ov.querySelector('#cd-database').value = '';
        ov.querySelector('#cd-username').value = '';
        ov.querySelector('#cd-password').value = '';
        ov.querySelector('#cd-description').value = '';
        this._pickDriver('mysql');
        this._pickEnv('dev');
        this._goTo(1);
    }
}