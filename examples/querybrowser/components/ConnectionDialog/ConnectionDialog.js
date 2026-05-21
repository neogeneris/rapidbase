/**
 * ConnectionDialog.js
 * Two‑step wizard for creating new database connections.
 * Refactored for deep esmerilated glassmorphism design.
 */
class ConnectionDialog {
    constructor() {
        this.currentStep = 1;
        this.selectedDriver = 'mysql';
        this.selectedEnvironment = 'dev';
        this.overlay = null;
        this._build();
    }

static get DRIVERS() {
        return [
            { 
                id: 'mysql', 
                label: 'MySQL', 
                port: 3306, 
                svg: `<img src="assets/icon/driver/mysql.svg" alt="MySQL" class="cd-driver-svg">` 
            },
            { 
                id: 'mariadb', 
                label: 'MariaDB', 
                port: 3306, 
                svg: `<img src="assets/icon/driver/mariadb.svg" alt="MariaDB" class="cd-driver-svg">` 
            },
            { 
                id: 'sqlite', 
                label: 'SQLite', 
                port: null, 
                svg: `<img src="assets/icon/driver/sqlite.svg" alt="SQLite" class="cd-driver-svg">` 
            },
            { 
                id: 'pgsql', 
                label: 'PostgreSQL', 
                port: 5432, 
                svg: `<img src="assets/icon/driver/postgresql.svg" alt="PostgreSQL" class="cd-driver-svg">` 
            },
            { 
                id: 'sqlsrv', 
                label: 'SQL Server', 
                port: 1433, 
                svg: `<img src="assets/icon/driver/sqlserver.svg" alt="SQL Server" class="cd-driver-svg">` 
            }
        ];
    }
    // ─── Build & Inject Semántico ────────────────────────────
    _build() {
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
                        <h4 class="cd-section-title">Seleccione el motor de base de datos</h4>
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
                            <label class="cd-label">Nombre de la conexión <span class="cd-req">*</span></label>
                            <input type="text" class="cd-input" id="cd-name" placeholder="Ej: Producción Main" autocomplete="off">
                        </div>

                        <div class="cd-field">
                            <label class="cd-label">Entorno</label>
                            <div class="cd-env-group" role="radiogroup">
                                <button class="cd-env-pill active" data-status="dev">
                                    <span class="cd-env-dot dev"></span> Desarrollo
                                </button>
                                <button class="cd-env-pill" data-status="qa">
                                    <span class="cd-env-dot qa"></span> QA
                                </button>
                                <button class="cd-env-pill" data-status="production">
                                    <span class="cd-env-dot prod"></span> Producción
                                </button>
                            </div>
                        </div>

                        <div class="cd-field">
                            <label class="cd-label">Descripción</label>
                            <textarea class="cd-textarea" id="cd-description" placeholder="Notas contextuales sobre esta infraestructura..."></textarea>
                        </div>
                    </section>
                </main>

                <main class="cd-body" id="cd-step-2" style="display:none;">
                    <div class="cd-driver-badge" id="cd-step2-badge"></div>

                    <section class="cd-section fields-gap">
                        <div class="cd-row" id="cd-network-row">
                            <div class="cd-field" style="flex: 2.5">
                                <label class="cd-label">Host de red</label>
                                <input type="text" class="cd-input" id="cd-host" value="localhost">
                            </div>
                            <div class="cd-field" style="flex: 1">
                                <label class="cd-label">Puerto</label>
                                <input type="number" class="cd-input" id="cd-port" value="3306">
                            </div>
                        </div>

                        <div class="cd-field" id="cd-db-field">
                            <label class="cd-label" id="cd-db-label">Base de datos <span class="cd-req">*</span></label>
                            <input type="text" class="cd-input" id="cd-database" placeholder="nombre_esquema">
                        </div>

                        <div class="cd-row" id="cd-auth-row">
                            <div class="cd-field">
                                <label class="cd-label">Usuario</label>
                                <input type="text" class="cd-input" id="cd-username" placeholder="root">
                            </div>
                            <div class="cd-field">
                                <label class="cd-label">Contraseña</label>
                                <input type="password" class="cd-input" id="cd-password" placeholder="••••••••">
                            </div>
                        </div>
                    </section>
                </main>

                <footer class="cd-footer">
                    <div class="cd-footer-status">
                        <button class="cd-btn cd-btn-ghost" id="cd-test-btn" style="display:none;">
                            <span class="cd-spinner"></span>
                            <span class="cd-btn-text">⚡ Probar conexión</span>
                        </button>
                        <span class="cd-result" id="cd-result"></span>
                    </div>
                    <div class="cd-footer-buttons">
                        <button class="cd-btn cd-btn-ghost" id="cd-cancel-btn">Cancelar</button>
                        <button class="cd-btn cd-btn-ghost" id="cd-prev-btn" style="display:none;">← Anterior</button>
                        <button class="cd-btn cd-btn-primary" id="cd-next-btn">Siguiente →</button>
                        <button class="cd-btn cd-btn-success" id="cd-save-btn" style="display:none;">
                            <span class="cd-spinner"></span>
                            <span class="cd-btn-text">✔ Finalizar</span>
                        </button>
                    </div>
                </footer>

            </div>
        `;

        document.body.appendChild(ov);
        this.overlay = ov;
        this._events();
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
            ? 'Paso 1 — Seleccionar driver'
            : 'Paso 2 — Datos de conexión';

        ov.querySelector('#cd-next-btn').style.display  = step === 1 ? '' : 'none';
        ov.querySelector('#cd-prev-btn').style.display  = step === 2 ? '' : 'none';
        ov.querySelector('#cd-save-btn').style.display  = step === 2 ? '' : 'none';
        ov.querySelector('#cd-test-btn').style.display  = step === 2 ? '' : 'none';

        const r = ov.querySelector('#cd-result');
        r.textContent = '';
        r.className = 'cd-result';
    }

    _setupStep2() {
        const ov = this.overlay;
        const d = ConnectionDialog.DRIVERS.find(x => x.id === this.selectedDriver);
        ov.querySelector('#cd-step2-badge').innerHTML = `${d.svg}<span>Motor Seleccionado: <strong>${d.label}</strong></span>`;

        const isSqlite = this.selectedDriver === 'sqlite';

        ov.querySelector('#cd-network-row').style.display = isSqlite ? 'none' : 'flex';
        ov.querySelector('#cd-auth-row').style.display    = isSqlite ? 'none' : 'flex';

        const dbLabel = ov.querySelector('#cd-db-label');
        const dbInput = ov.querySelector('#cd-database');

        if (isSqlite) {
            dbLabel.innerHTML = 'Ruta del archivo SQLite <span class="cd-req">*</span>';
            dbInput.placeholder = '/absoluta/infraestructura/archivo.sqlite';
        } else {
            dbLabel.innerHTML = 'Base de datos <span class="cd-req">*</span>';
            dbInput.placeholder = 'nombre_esquema_db';
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
        if (!d.name) return 'El nombre es obligatorio.';
        if (!d.database) return this.selectedDriver === 'sqlite'
            ? 'La ruta del archivo SQLite es obligatoria.'
            : 'El nombre de la base de datos es obligatorio.';
        return null;
    }

    async _test() {
        const data = this._data();
        const err = this._validate(data);
        const resultEl = this.overlay.querySelector('#cd-result');
        const btn = this.overlay.querySelector('#cd-test-btn');
        if (err) { resultEl.textContent = err; resultEl.className = 'cd-result error'; return; }

        btn.classList.add('loading');
        resultEl.textContent = '';
        try {
            const r = await fetch('api.php?action=test_connection', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });
            const res = await r.json();
            if (res.success) {
                resultEl.textContent = `✓ Conectado (${Math.round(res.latency)}ms)`;
                resultEl.className = 'cd-result ok';
            } else {
                resultEl.textContent = `✗ ${res.error || 'Error de negociación'}`;
                resultEl.className = 'cd-result error';
            }
        } catch { resultEl.textContent = '✗ Error de enlace de red'; resultEl.className = 'cd-result error'; }
        finally { btn.classList.remove('loading'); }
    }

    async _save() {
        const data = this._data();
        const err = this._validate(data);
        const resultEl = this.overlay.querySelector('#cd-result');
        const btn = this.overlay.querySelector('#cd-save-btn');
        if (err) { resultEl.textContent = err; resultEl.className = 'cd-result error'; return; }

        btn.classList.add('loading'); btn.disabled = true;
        try {
            const r = await fetch('api.php?action=add_connection', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });
            const res = await r.json();
            if (res.success) {
                this.close();
                window.app?.connManager?.init();
            } else {
                resultEl.textContent = `✗ ${res.error || 'Fallo de persistencia'}`;
                resultEl.className = 'cd-result error';
            }
        } catch { resultEl.textContent = '✗ Fallo crítico de red'; resultEl.className = 'cd-result error'; }
        finally { btn.classList.remove('loading'); btn.disabled = false; }
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