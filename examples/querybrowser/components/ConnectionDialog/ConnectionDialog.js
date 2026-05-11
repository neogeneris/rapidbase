/**
 * ConnectionDialog.js
 * Two-step wizard for creating new database connections.
 *
 * Step 1 — Driver grid, connection name, environment, description
 * Step 2 — Connection details (host, port, database, user, password)
 */
class ConnectionDialog {
    constructor() {
        this.currentStep = 1;
        this.selectedDriver = 'mysql';
        this.selectedStatus = 'dev';
        this.overlay = null;
        this._build();
    }

    static get DRIVERS() {
        return [
            { id: 'mysql',      label: 'MySQL',       port: 3306,  icon: 'mysql' },
            { id: 'mariadb',    label: 'MariaDB',     port: 3306,  icon: 'mariadb' },
            { id: 'sqlite',     label: 'SQLite',      port: null,  icon: 'sqlite' },
            { id: 'pgsql',      label: 'PostgreSQL',  port: 5432,  icon: 'postgresql' },
        ];
    }

    // ─── Build & Inject ──────────────────────────────────────
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

                <!-- HEADER -->
                <div class="cd-header">
                    <div>
                        <div class="cd-title">🔌 Nueva Conexión</div>
                        <div class="cd-subtitle" id="cd-subtitle">Paso 1 — Seleccionar driver</div>
                    </div>
                    <div class="cd-header-right">
                        <div class="cd-pills">
                            <div class="cd-pill active" data-step="1"></div>
                            <div class="cd-pill" data-step="2"></div>
                        </div>
                        <button class="cd-x" id="cd-close">&times;</button>
                    </div>
                </div>

                <!-- STEP 1 -->
                <div class="cd-body" id="cd-step-1">

                    <div class="cd-section">Seleccione el tipo de base de datos</div>
                    <div class="cd-driver-grid" id="cd-driver-grid">
                        ${ConnectionDialog.DRIVERS.map(d => `
                            <div class="cd-driver-card ${d.id === this.selectedDriver ? 'active' : ''}" data-driver="${d.id}">
                                <div class="cd-driver-icon">${window.DBIcons?.[d.icon] || ''}</div>
                                <span class="cd-driver-name">${d.label}</span>
                            </div>
                        `).join('')}
                    </div>

                    <div class="cd-field">
                        <label class="cd-label">Nombre de la conexión <span class="cd-req">*</span></label>
                        <input type="text" class="cd-input" id="cd-name" placeholder="Ej: Mi Proyecto Local" autocomplete="off">
                    </div>

                    <div class="cd-field" style="margin-top:12px;">
                        <label class="cd-label">Entorno</label>
                        <div class="cd-env-group">
                            <div class="cd-env active" data-status="dev"><span class="cd-dot dev"></span>Desarrollo</div>
                            <div class="cd-env" data-status="qa"><span class="cd-dot qa"></span>QA</div>
                            <div class="cd-env" data-status="production"><span class="cd-dot prod"></span>Producción</div>
                        </div>
                    </div>

                    <div class="cd-field" style="margin-top:12px;">
                        <label class="cd-label">Descripción</label>
                        <textarea class="cd-textarea" id="cd-description" placeholder="Notas opcionales sobre esta conexión..."></textarea>
                    </div>

                </div>

                <!-- STEP 2 -->
                <div class="cd-body" id="cd-step-2" style="display:none;">

                    <div class="cd-driver-badge" id="cd-step2-badge"></div>

                    <div class="cd-row" id="cd-network-row">
                        <div class="cd-field" style="flex:2">
                            <label class="cd-label">Host</label>
                            <input type="text" class="cd-input" id="cd-host" value="localhost">
                        </div>
                        <div class="cd-field" style="flex:1">
                            <label class="cd-label">Puerto</label>
                            <input type="number" class="cd-input" id="cd-port" value="3306">
                        </div>
                    </div>

                    <div class="cd-field" id="cd-db-field" style="margin-bottom:12px;">
                        <label class="cd-label" id="cd-db-label">Base de datos <span class="cd-req">*</span></label>
                        <input type="text" class="cd-input" id="cd-database" placeholder="nombre_base_datos">
                    </div>

                    <div class="cd-row" id="cd-auth-row">
                        <div class="cd-field">
                            <label class="cd-label">Usuario</label>
                            <input type="text" class="cd-input" id="cd-username" placeholder="root">
                        </div>
                        <div class="cd-field">
                            <label class="cd-label">Contraseña</label>
                            <input type="password" class="cd-input" id="cd-password" placeholder="••••••">
                        </div>
                    </div>

                </div>

                <!-- FOOTER -->
                <div class="cd-footer">
                    <div class="cd-footer-left">
                        <button class="cd-btn cd-ghost" id="cd-test-btn" style="display:none;">
                            <span class="cd-spinner"></span>
                            <span class="cd-btn-text">⚡ Probar conexión</span>
                        </button>
                        <span class="cd-result" id="cd-result"></span>
                    </div>
                    <div class="cd-footer-right">
                        <button class="cd-btn cd-ghost" id="cd-cancel-btn">Cancelar</button>
                        <button class="cd-btn cd-ghost" id="cd-prev-btn" style="display:none;">← Anterior</button>
                        <button class="cd-btn cd-primary" id="cd-next-btn">Siguiente →</button>
                        <button class="cd-btn cd-success" id="cd-save-btn" style="display:none;">
                            <span class="cd-spinner"></span>
                            <span class="cd-btn-text">✔ Finalizar</span>
                        </button>
                    </div>
                </div>

            </div>
        `;

        document.body.appendChild(ov);
        this.overlay = ov;
        this._events();
    }

    // ─── Events ──────────────────────────────────────────────
    _events() {
        const ov = this.overlay;

        ov.querySelector('#cd-close').onclick    = () => this.close();
        ov.querySelector('#cd-cancel-btn').onclick = () => this.close();
        ov.addEventListener('click', e => { if (e.target === ov) this.close(); });
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape' && ov.classList.contains('is-open')) this.close();
        });

        // Driver cards
        ov.querySelectorAll('.cd-driver-card').forEach(c =>
            c.onclick = () => this._pickDriver(c.dataset.driver)
        );

        // Env badges
        ov.querySelectorAll('.cd-env').forEach(b =>
            b.onclick = () => this._pickEnv(b.dataset.status)
        );

        ov.querySelector('#cd-next-btn').onclick = () => this._goTo(2);
        ov.querySelector('#cd-prev-btn').onclick = () => this._goTo(1);
        ov.querySelector('#cd-test-btn').onclick = () => this._test();
        ov.querySelector('#cd-save-btn').onclick = () => this._save();
    }

    // ─── Navigation ──────────────────────────────────────────
    _goTo(step) {
        const ov = this.overlay;

        if (step === 2) {
            const name = ov.querySelector('#cd-name').value.trim();
            if (!name) {
                const inp = ov.querySelector('#cd-name');
                inp.style.borderColor = '#ef4444';
                inp.focus();
                setTimeout(() => inp.style.borderColor = '', 1500);
                return;
            }
            this._setupStep2();
        }

        this.currentStep = step;

        ov.querySelector('#cd-step-1').style.display = step === 1 ? 'block' : 'none';
        ov.querySelector('#cd-step-2').style.display = step === 2 ? 'block' : 'none';

        // Pills
        ov.querySelectorAll('.cd-pill').forEach(p => {
            const s = +p.dataset.step;
            p.className = 'cd-pill' + (s < step ? ' done' : s === step ? ' active' : '');
        });

        // Subtitle
        ov.querySelector('#cd-subtitle').textContent = step === 1
            ? 'Paso 1 — Seleccionar driver'
            : 'Paso 2 — Datos de conexión';

        // Footer buttons
        ov.querySelector('#cd-next-btn').style.display  = step === 1 ? '' : 'none';
        ov.querySelector('#cd-prev-btn').style.display  = step === 2 ? '' : 'none';
        ov.querySelector('#cd-save-btn').style.display  = step === 2 ? '' : 'none';
        ov.querySelector('#cd-test-btn').style.display  = step === 2 ? '' : 'none';

        // Clear result
        const r = ov.querySelector('#cd-result');
        r.textContent = '';
        r.className = 'cd-result';
    }

    // ─── Setup step 2 based on selected driver ────────────────
    _setupStep2() {
        const ov = this.overlay;
        const d = ConnectionDialog.DRIVERS.find(x => x.id === this.selectedDriver);
        const svg = window.DBIcons?.[d.icon] || '';

        ov.querySelector('#cd-step2-badge').innerHTML = `${svg}<span>${d.label}</span>`;

        const isSqlite = this.selectedDriver === 'sqlite';

        ov.querySelector('#cd-network-row').style.display = isSqlite ? 'none' : 'flex';
        ov.querySelector('#cd-auth-row').style.display    = isSqlite ? 'none' : 'flex';

        const dbLabel = ov.querySelector('#cd-db-label');
        const dbInput = ov.querySelector('#cd-database');

        if (isSqlite) {
            dbLabel.innerHTML = 'Ruta del archivo SQLite <span class="cd-req">*</span>';
            dbInput.placeholder = '/ruta/al/archivo.sqlite';
        } else {
            dbLabel.innerHTML = 'Base de datos <span class="cd-req">*</span>';
            dbInput.placeholder = 'nombre_base_datos';
            ov.querySelector('#cd-port').value = d.port;
        }
    }

    // ─── Driver selection ─────────────────────────────────────
    _pickDriver(id) {
        this.selectedDriver = id;
        this.overlay.querySelectorAll('.cd-driver-card').forEach(c =>
            c.classList.toggle('active', c.dataset.driver === id)
        );
    }

    // ─── Environment selection ────────────────────────────────
    _pickEnv(status) {
        this.selectedStatus = status;
        this.overlay.querySelectorAll('.cd-env').forEach(b =>
            b.classList.toggle('active', b.dataset.status === status)
        );
    }

    // ─── Collect data ─────────────────────────────────────────
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
            status:      this.selectedStatus,
        };
    }

    _validate(d) {
        if (!d.name) return 'El nombre es obligatorio.';
        if (!d.database) return this.selectedDriver === 'sqlite'
            ? 'La ruta del archivo es obligatoria.'
            : 'El nombre de la base de datos es obligatorio.';
        return null;
    }

    // ─── Test connection ──────────────────────────────────────
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
                resultEl.textContent = `✗ ${res.error || 'Error'}`;
                resultEl.className = 'cd-result error';
            }
        } catch { resultEl.textContent = '✗ Error de red'; resultEl.className = 'cd-result error'; }
        finally { btn.classList.remove('loading'); }
    }

    // ─── Save connection ──────────────────────────────────────
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
                resultEl.textContent = `✗ ${res.error || 'Error al guardar'}`;
                resultEl.className = 'cd-result error';
            }
        } catch { resultEl.textContent = '✗ Error de red'; resultEl.className = 'cd-result error'; }
        finally { btn.classList.remove('loading'); btn.disabled = false; }
    }

    // ─── Open / Close ─────────────────────────────────────────
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
        ov.querySelector('#cd-name').style.borderColor = '';
        this._pickDriver('mysql');
        this._pickEnv('dev');
        this._goTo(1);
    }
}
