let currentConnectionId = null;
let tabs = new Map(); // tabId -> { database, table, sql, result }

// Cargar lista de bases de datos
async function loadDatabases() {
    const res = await fetch('api.php?action=list_databases');
    const data = await res.json();
    const dbDiv = document.getElementById('db-list');
    dbDiv.innerHTML = '<ul>' + data.databases.map(db => `<li data-db="${db}">📁 ${db}</li>`).join('') + '</ul>';
    document.querySelectorAll('[data-db]').forEach(el => {
        el.addEventListener('click', () => connectDatabase(el.dataset.db));
    });
}

// Conectar a una base de datos
async function connectDatabase(dbName) {
    const formData = new FormData();
    formData.append('db', dbName);
    const res = await fetch('api.php?action=connect', { method: 'POST', body: formData });
    const data = await res.json();
    if (data.status === 'ok') {
        currentConnectionId = data.connectionId;
        loadTables();
    }
}

// Cargar tablas y mostrarlas en el árbol
async function loadTables() {
    const res = await fetch(`api.php?action=list_tables&connectionId=${currentConnectionId}`);
    const data = await res.json();
    const treeDiv = document.getElementById('treeview');
    treeDiv.innerHTML = '<ul><li>📁 Tables<ul>' + data.tables.map(table => `<li draggable="true" data-table="${table}">📄 ${table}</li>`).join('') + '</ul></li></ul>';
    // Hacer draggable
    document.querySelectorAll('[data-table]').forEach(el => {
        el.addEventListener('dragstart', e => {
            e.dataTransfer.setData('text/plain', JSON.stringify({ type: 'table', name: el.dataset.table }));
            e.dataTransfer.effectAllowed = 'copy';
        });
    });
    // Al hacer clic en tabla, abrir pestaña
    document.querySelectorAll('[data-table]').forEach(el => {
        el.addEventListener('click', () => openTab(el.dataset.table));
    });
}

// Abrir pestaña para una tabla
function openTab(tableName) {
    const tabId = `tab_${Date.now()}_${Math.random()}`;
    // Crear pestaña en header
    const headerDiv = document.getElementById('tabs-header');
    const tabButton = document.createElement('div');
    tabButton.className = 'tab';
    tabButton.innerText = tableName;
    tabButton.dataset.tabId = tabId;
    tabButton.onclick = () => activateTab(tabId);
    headerDiv.appendChild(tabButton);
    
    // Crear contenido
    const contentDiv = document.getElementById('tab-content');
    const pane = document.createElement('div');
    pane.className = 'tab-pane';
    pane.id = `pane_${tabId}`;
    pane.innerHTML = `
        <div class="drop-zone" data-tab-id="${tabId}">🎯 Arrastra otra tabla aquí para auto-join</div>
        <textarea id="sql_${tabId}" class="sql-editor">SELECT * FROM ${tableName} LIMIT 100</textarea>
        <button onclick="executeQuery('${tabId}')">Ejecutar</button>
        <div id="grid_${tabId}" class="results-grid"></div>
    `;
    contentDiv.appendChild(pane);
    
    // Configurar drop zone
    const dropZone = pane.querySelector('.drop-zone');
    dropZone.addEventListener('dragover', e => e.preventDefault());
    dropZone.addEventListener('drop', async e => {
        e.preventDefault();
        const raw = e.dataTransfer.getData('text/plain');
        const dropped = JSON.parse(raw);
        if (dropped.type === 'table') {
            const currentTable = tableName;
            const tables = [currentTable, dropped.name];
            const formData = new FormData();
            formData.append('connectionId', currentConnectionId);
            formData.append('tables', JSON.stringify(tables));
            const resp = await fetch('api.php?action=auto_query', { method: 'POST', body: formData });
            const data = await resp.json();
            if (data.sql) {
                const textarea = pane.querySelector('.sql-editor');
                textarea.value = data.sql;
                executeQuery(tabId);
            }
        }
    });
    
    tabs.set(tabId, { table: tableName, sql: `SELECT * FROM ${tableName} LIMIT 100` });
    executeQuery(tabId);
    activateTab(tabId);
}

function activateTab(tabId) {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.querySelector(`.tab[data-tab-id="${tabId}"]`).classList.add('active');
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    document.getElementById(`pane_${tabId}`).classList.add('active');
}

async function executeQuery(tabId) {
    const pane = document.getElementById(`pane_${tabId}`);
    const sql = pane.querySelector('.sql-editor').value;
    const formData = new FormData();
    formData.append('connectionId', currentConnectionId);
    formData.append('sql', sql);
    const resp = await fetch('api.php?action=execute_query', { method: 'POST', body: formData });
    const data = await resp.json();
    const gridDiv = pane.querySelector('.results-grid');
    if (data.error) {
        gridDiv.innerHTML = `<pre style="color:red">${data.error}</pre>`;
        return;
    }
    if (!data.rows || data.rows.length === 0) {
        gridDiv.innerHTML = '<p>Sin resultados</p>';
        return;
    }
    // Construir tabla HTML
    let html = '<table><thead><tr>';
    data.columns.forEach(col => html += `<th>${escapeHtml(col)}</th>`);
    html += '</tr></thead><tbody>';
    data.rows.forEach(row => {
        html += '<tr>';
        data.columns.forEach(col => html += `<td>${escapeHtml(row[col] ?? 'NULL')}</td>`);
        html += '</tr>';
    });
    html += '</tbody></table>';
    gridDiv.innerHTML = html;
}

function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}

// Inicializar
loadDatabases();