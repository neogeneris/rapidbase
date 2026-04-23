<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users CRUD - RapidBase + Grid.js</title>
    
    <!-- Grid.js CSS -->
    <link href="https://cdn.jsdelivr.net/npm/gridjs/dist/theme/mermaid.min.css" rel="stylesheet" />
    
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            margin: 0;
            background: #f1f5f9;
            padding: 20px;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            border-radius: 24px;
            box-shadow: 0 12px 30px rgba(0,0,0,0.05);
            overflow: hidden;
            padding: 24px 28px;
        }
        h1 {
            font-size: 1.8rem;
            font-weight: 600;
            color: #0f172a;
            margin: 0 0 8px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .sub {
            color: #475569;
            border-left: 3px solid #3b82f6;
            padding-left: 12px;
            margin-bottom: 28px;
            font-size: 0.9rem;
        }
        .header-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 24px;
        }
        .btn {
            padding: 8px 18px;
            border: none;
            border-radius: 40px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-primary { background: #3b82f6; color: white; }
        .btn-success { background: #10b981; color: white; }
        .btn-danger { background: #ef4444; color: white; }
        .btn-warning { background: #f59e0b; color: #1e293b; }
        .btn-sm { padding: 5px 12px; font-size: 12px; }
        .btn:hover { opacity: 0.85; transform: translateY(-1px); }
        
        /* Modal */
        #userModal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            backdrop-filter: blur(4px);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .modal-content {
            background: white;
            padding: 28px;
            border-radius: 28px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 25px 40px rgba(0,0,0,0.2);
            animation: fadeUp 0.2s ease;
        }
        @keyframes fadeUp {
            from { opacity: 0; transform: scale(0.96); }
            to { opacity: 1; transform: scale(1); }
        }
        .modal-content h2 {
            margin-top: 0;
            margin-bottom: 20px;
            font-weight: 600;
        }
        .close {
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            color: #94a3b8;
            line-height: 0.8;
        }
        .close:hover { color: #1e293b; }
        .form-group {
            margin-bottom: 18px;
        }
        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            color: #1e293b;
        }
        .form-group input, .form-group select {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 16px;
            font-size: 14px;
            transition: 0.2s;
        }
        .form-group input:focus, .form-group select:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59,130,246,0.2);
        }
        .modal-buttons {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            margin-top: 24px;
        }
        .action-buttons {
            display: flex;
            gap: 8px;
            justify-content: center;
        }
        .loader {
            text-align: center;
            padding: 40px;
            color: #475569;
        }
        /* Grid.js custom */
        .gridjs-wrapper {
            border-radius: 18px;
            overflow: auto;
        }
        .gridjs-th {
            background: #f8fafc;
            font-weight: 600;
        }
        .gridjs-td {
            vertical-align: middle;
        }
        @media (max-width: 700px) {
            .container { padding: 16px; }
            .btn-sm { padding: 4px 8px; }
        }
    </style>
</head>
<body>
<div class="container">
    <h1>👥 Users Management</h1>
    <div class="sub">Powered by Grid.js · CRUD completo</div>
    
    <div class="header-actions">
        <button class="btn btn-success" id="newUserBtn">+ New User</button>
        <div style="font-size: 13px; background: #eef2ff; padding: 6px 12px; border-radius: 40px;">✨ Búsqueda y paginación instantánea</div>
    </div>
    
    <div id="usersGridContainer">
        <div class="loader">Cargando usuarios...</div>
    </div>
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
    // --- Global variables ---
    let userGrid = null;
    let allUsers = [];          // store latest user list locally
    
    // DOM elements
    const modal = document.getElementById('userModal');
    const modalTitle = document.getElementById('modalTitle');
    const userForm = document.getElementById('userForm');
    const userIdInput = document.getElementById('userId');
    const nameInput = document.getElementById('name');
    const emailInput = document.getElementById('email');
    const roleSelect = document.getElementById('role');
    
    // --- API helpers ---
    async function fetchAllUsers() {
        try {
            // Request all records (adjust limit if needed)
            const res = await fetch('api.php?action=list&limit=1000');
            const json = await res.json();
            if (json && Array.isArray(json.data)) {
                return json.data; // each element: [id, name, email, role, created_at]
            }
            throw new Error('Invalid response structure');
        } catch (err) {
            console.error(err);
            alert('Error loading users. Check API connection.');
            return [];
        }
    }
    
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
    
    async function deleteUserApi(id) {
        const formBody = new URLSearchParams();
        formBody.append('id', id);
        const res = await fetch('api.php?action=delete', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formBody.toString()
        });
        return await res.json();
    }
    
    // --- Grid rendering ---
    function renderGrid(usersData) {
        const container = document.getElementById('usersGridContainer');
        if (!container) return;
        
        const columns = [
            { name: 'ID', width: '80px', sort: true },
            { name: 'Name', sort: true },
            { name: 'Email', sort: true },
            { name: 'Role', sort: true },
            { name: 'Created At', width: '170px', sort: true },
            {
                name: 'Actions',
                width: '150px',
                formatter: (_, row) => {
                    const userId = row.cells[0].data; // ID from first column
                    return `
                        <div class="action-buttons">
                            <button class="btn btn-warning btn-sm" data-action="edit" data-id="${userId}">✏️ Edit</button>
                            <button class="btn btn-danger btn-sm" data-action="delete" data-id="${userId}">🗑️ Delete</button>
                        </div>
                    `;
                }
            }
        ];
        
        if (userGrid) {
            userGrid.updateConfig({ data: usersData }).forceRender();
        } else {
            userGrid = new gridjs.Grid({
                columns,
                data: usersData,
                pagination: { enabled: true, limit: 10, summary: true },
                search: { enabled: true, placeholder: '🔍 Search by name, email or role...' },
                sort: true,
                language: {
                    search: '🔎',
                    pagination: { previous: '⬅', next: '➡', showing: 'Showing', of: 'of', results: 'users' }
                },
                resizable: true
            });
            userGrid.render(container);
        }
        
        // Attach event listeners after grid is fully rendered (delegation)
        setTimeout(() => attachGridEvents(), 50);
    }
    
    // Event delegation for dynamic buttons (edit/delete)
    function attachGridEvents() {
        const container = document.getElementById('usersGridContainer');
        if (!container) return;
        
        // Remove previous listener to avoid duplicates
        container.removeEventListener('click', gridClickHandler);
        container.addEventListener('click', gridClickHandler);
    }
    
    async function gridClickHandler(e) {
        const btn = e.target.closest('[data-action]');
        if (!btn) return;
        
        const action = btn.getAttribute('data-action');
        const id = parseInt(btn.getAttribute('data-id'));
        
        if (action === 'edit') {
            e.preventDefault();
            const user = allUsers.find(u => u[0] === id);
            if (user) {
                openModalForEdit({
                    id: user[0],
                    name: user[1],
                    email: user[2],
                    role: user[3]
                });
            } else {
                alert('User not found locally');
            }
        } else if (action === 'delete') {
            e.preventDefault();
            if (confirm('⚠️ Delete this user permanently?')) {
                const result = await deleteUserApi(id);
                if (result.success === true) {
                    alert('✅ User deleted');
                    await refreshData();
                } else {
                    alert('❌ Error: ' + (result.error || result.message));
                }
            }
        }
    }
    
    // --- Modal logic ---
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
    
    // --- Refresh grid from API ---
    async function refreshData() {
        const users = await fetchAllUsers();
        allUsers = users;
        renderGrid(users);
    }
    
    // --- Form submit (create/update) ---
    userForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const id = userIdInput.value;
        const name = nameInput.value.trim();
        const email = emailInput.value.trim();
        const role = roleSelect.value;
        
        if (!name || !email) {
            alert('Name and email are required');
            return;
        }
        
        const result = await saveUser({ id: id || null, name, email, role });
        if (result.success === true) {
            alert(`✨ User ${id ? 'updated' : 'created'} successfully`);
            closeModal();
            await refreshData();
        } else {
            alert('❌ Operation failed: ' + (result.error || result.message));
        }
    });
    
    // --- Event listeners for modal controls ---
    document.getElementById('newUserBtn').addEventListener('click', () => openModalForEdit(null));
    document.getElementById('cancelModalBtn').addEventListener('click', closeModal);
    document.getElementById('closeModalBtn').addEventListener('click', closeModal);
    window.addEventListener('click', (e) => {
        if (e.target === modal) closeModal();
    });
    
    // --- Initial load ---
    (async () => {
        allUsers = await fetchAllUsers();
        renderGrid(allUsers);
    })();
</script>
</body>
</html>
