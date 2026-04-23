<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users CRUD - Grid.js Server-side</title>
    <link href="https://cdn.jsdelivr.net/npm/gridjs/dist/theme/mermaid.min.css" rel="stylesheet" />
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', system-ui, sans-serif; background: #f1f5f9; margin: 0; padding: 20px; }
        .container { max-width: 1400px; margin: 0 auto; background: white; border-radius: 24px; padding: 24px 28px; box-shadow: 0 12px 30px rgba(0,0,0,0.05); }
        h1 { font-size: 1.8rem; font-weight: 600; color: #0f172a; margin: 0 0 8px 0; }
        .sub { color: #475569; border-left: 3px solid #3b82f6; padding-left: 12px; margin-bottom: 28px; }
        .header-actions { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px; }
        .btn { padding: 8px 18px; border: none; border-radius: 40px; font-weight: 500; cursor: pointer; transition: all 0.2s; font-size: 14px; display: inline-flex; align-items: center; gap: 8px; }
        .btn-success { background: #10b981; color: white; }
        .btn-warning { background: #f59e0b; color: #1e293b; }
        .btn-danger { background: #ef4444; color: white; }
        .btn-primary { background: #3b82f6; color: white; }
        .btn-sm { padding: 5px 12px; font-size: 12px; }
        .btn:hover { opacity: 0.85; transform: translateY(-1px); }
        #userModal { display: none; position: fixed; top:0; left:0; width:100%; height:100%; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); z-index:1000; align-items: center; justify-content: center; }
        .modal-content { background: white; padding: 28px; border-radius: 28px; width: 90%; max-width: 500px; animation: fadeUp 0.2s; }
        @keyframes fadeUp { from { opacity: 0; transform: scale(0.96); } to { opacity: 1; transform: scale(1); } }
        .close { float: right; font-size: 28px; cursor: pointer; color: #94a3b8; }
        .form-group { margin-bottom: 18px; }
        .form-group label { font-weight: 600; display: block; margin-bottom: 6px; }
        .form-group input, .form-group select { width: 100%; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 16px; }
        .modal-buttons { display: flex; justify-content: flex-end; gap: 12px; margin-top: 24px; }
        .action-buttons { display: flex; gap: 8px; justify-content: center; }
        .gridjs-wrapper { border-radius: 18px; }
        .gridjs-th { background: #f8fafc; }
    </style>
</head>
<body>
<div class="container">
    <h1>👥 Users Management</h1>
    <div class="sub">Server-side pagination & sorting · RapidBase CRUD Example</div>
    <div class="header-actions">
        <button class="btn btn-success" id="newUserBtn">+ New User</button>
        <span style="font-size:13px; background:#eef2ff; padding:6px 12px; border-radius:40px;">⚡ Ordena y pagina en el servidor</span>
    </div>
    <div id="usersGridContainer"></div>
</div>

<!-- Modal -->
<div id="userModal">
    <div class="modal-content">
        <span class="close" id="closeModalBtn">&times;</span>
        <h2 id="modalTitle">Add User</h2>
        <form id="userForm">
            <input type="hidden" id="userId">
            <div class="form-group">
                <label>Full Name *</label>
                <input type="text" id="name" required placeholder="John Doe">
            </div>
            <div class="form-group">
                <label>Email *</label>
                <input type="email" id="email" required placeholder="john@example.com">
            </div>
            <div class="form-group">
                <label>Role</label>
                <select id="role">
                    <option value="user">User</option>
                    <option value="admin">Admin</option>
                    <option value="moderator">Moderator</option>
                </select>
            </div>
            <div class="modal-buttons">
                <button type="button" class="btn btn-warning" id="cancelModalBtn">Cancel</button>
                <button type="submit" class="btn btn-primary">💾 Save</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/gridjs/dist/gridjs.umd.js"></script>
<script>
    let userGrid = null;
    const modal = document.getElementById('userModal');
    const modalTitle = document.getElementById('modalTitle');
    const userForm = document.getElementById('userForm');
    const userIdInput = document.getElementById('userId');
    const nameInput = document.getElementById('name');
    const emailInput = document.getElementById('email');
    const roleSelect = document.getElementById('role');

    function initGrid() {
        const container = document.getElementById('usersGridContainer');
        if (!container) return;
        if (userGrid) {
            userGrid.forceRender();
            return;
        }
        container.innerHTML = '';

        const columns = [
            { name: 'ID', width: '80px', sort: true },
            { name: 'Name', sort: true },
            { name: 'Email', sort: true },
            { name: 'Role', sort: true },
            { name: 'Created At', width: '170px', sort: true },
            {
                name: 'Actions',
                width: '180px',
                sort: false,
                formatter: (_, row) => {
                    // row.cells[0].data es el ID del usuario
                    const userId = row.cells[0].data;
                    // Usar gridjs.html() para inyectar HTML raw con onclick inline
                    // Esto es la forma más confiable de que los botones funcionen en Grid.js
                    return gridjs.html(
                        `<div class="action-buttons">` +
                            `<button class="btn btn-warning btn-sm" onclick="handleEdit(${userId})">✏️ Edit</button>` +
                            `<button class="btn btn-danger btn-sm" onclick="handleDelete(${userId})">🗑️ Delete</button>` +
                        `</div>`
                    );
                }
            }
        ];

        userGrid = new gridjs.Grid({
            columns,
            server: {
                url: 'api.php?action=list',
                then: data => data.data,
                total: data => data.total
            },
            pagination: {
                enabled: true,
                limit: 10,
                summary: true,
                server: {
                    url: (prev, page, limit) => {
                        const offset = page * limit;
                        return `${prev}&limit=${limit}&offset=${offset}`;
                    }
                }
            },
            search: { 
                enabled: true, 
                placeholder: '🔍 Search...',
                server: {
                    url: (prev, keyword) => `${prev}&search=${encodeURIComponent(keyword)}`
                }
            },
            sort: {
                server: {
                    url: (prev, columns) => {
                        if (!columns || columns.length === 0) return prev;
                        const col = columns[0];
                        const dir = col.direction === 1 ? 'ASC' : 'DESC';
                        // Grid.js server-side sort usa col.index (numérico, 0-based)
                        // Mapear índice de columna al nombre real del campo en la DB
                        const indexMap = ['id', 'name', 'email', 'role', 'created_at'];
                        const apiSortField = indexMap[col.index] || 'id';
                        return `${prev}&sort=${apiSortField}&order=${dir}`;
                    }
                }
            },
            language: {
                search: '🔎',
                pagination: { previous: '⬅', next: '➡', showing: 'Showing', of: 'of', results: 'users' }
            },
            resizable: true
        });

        userGrid.render(container);
    }

    // ========== FUNCIONES GLOBALES para los botones de acción ==========
    // Estas funciones son invocadas por los onclick inline en el HTML generado por gridjs.html()
    
    async function handleEdit(id) {
        console.log(`[DEBUG] Edit clicked for user ID: ${id}`);
        try {
            const res = await fetch(`api.php?action=get&id=${id}`);
            const json = await res.json();
            if (json && json.success && json.data) {
                const user = json.data;
                openModalForEdit({
                    id: user.id,
                    name: user.name,
                    email: user.email,
                    role: user.role
                });
            } else {
                alert('Could not load user details: ' + (json.error || 'Unknown error'));
            }
        } catch (err) {
            console.error(err);
            alert('Error fetching user');
        }
    }

    async function handleDelete(id) {
        console.log(`[DEBUG] Delete clicked for user ID: ${id}`);
        if (!confirm('⚠️ Delete this user permanently?')) return;
        
        try {
            const formBody = new URLSearchParams();
            formBody.append('id', id);
            const res = await fetch('api.php?action=delete', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formBody.toString()
            });
            const result = await res.json();
            if (result.success === true) {
                alert('✅ User deleted');
                if (userGrid) userGrid.forceRender();
            } else {
                alert('❌ Error: ' + (result.error || result.message));
            }
        } catch (err) {
            console.error(err);
            alert('Error deleting user');
        }
    }

    // ========== FUNCIONES DE MODAL Y FORMULARIO ==========

    async function saveUser(userData) {
        const { id, name, email, role } = userData;
        const action = id ? 'update' : 'create';
        const formBody = new URLSearchParams();
        if (id) formBody.append('id', id);
        formBody.append('name', name);
        formBody.append('email', email);
        formBody.append('role', role);
        const res = await fetch(`api.php?action=${action}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formBody.toString()
        });
        return await res.json();
    }

    function openModalForEdit(user = null) {
        modal.style.display = 'flex';
        if (user) {
            modalTitle.innerText = '✏️ Edit User';
            userIdInput.value = user.id;
            nameInput.value = user.name;
            emailInput.value = user.email;
            roleSelect.value = user.role;
        } else {
            modalTitle.innerText = '➕ New User';
            userForm.reset();
            userIdInput.value = '';
            roleSelect.value = 'user';
        }
    }

    function closeModal() {
        modal.style.display = 'none';
        userForm.reset();
        userIdInput.value = '';
    }

    userForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const id = userIdInput.value;
        const name = nameInput.value.trim();
        const email = emailInput.value.trim();
        const role = roleSelect.value;
        if (!name || !email) return alert('Name and email are required');
        const result = await saveUser({ id: id || null, name, email, role });
        if (result.success === true) {
            alert(`✨ User ${id ? 'updated' : 'created'} successfully`);
            closeModal();
            if (userGrid) userGrid.forceRender();
        } else {
            alert('❌ Error: ' + (result.error || result.message));
        }
    });

    document.getElementById('newUserBtn').addEventListener('click', () => openModalForEdit(null));
    document.getElementById('cancelModalBtn').addEventListener('click', closeModal);
    document.getElementById('closeModalBtn').addEventListener('click', closeModal);
    window.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });

    initGrid();
</script>
</body>
</html>