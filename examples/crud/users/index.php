<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users CRUD - RapidBase Example</title>
    
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.3.6/css/buttons.dataTables.min.css">
    
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h1 { color: #333; border-bottom: 2px solid #007bff; padding-bottom: 10px; }
        .btn { padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-block; margin: 2px; }
        .btn-primary { background: #007bff; color: white; }
        .btn-success { background: #28a745; color: white; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-warning { background: #ffc107; color: #212529; }
        .btn:hover { opacity: 0.9; }
        #userModal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; }
        .modal-content { background: white; margin: 10% auto; padding: 20px; border-radius: 8px; width: 90%; max-width: 500px; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
        .form-group input, .form-group select { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
        .close { float: right; font-size: 24px; cursor: pointer; }
        table.dataTable tbody tr:hover { background-color: #f1f1f1; }
        .loading { text-align: center; padding: 20px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <h1>👥 Users Management</h1>
        
        <button class="btn btn-success" onclick="openModal()">+ New User</button>
        
        <table id="usersTable" class="display" style="width:100%; margin-top: 20px;">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Created At</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <tr><td colspan="6" class="loading">Loading...</td></tr>
            </tbody>
        </table>
    </div>

    <!-- User Modal -->
    <div id="userModal">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <h2 id="modalTitle">Add User</h2>
            <form id="userForm">
                <input type="hidden" id="userId">
                <div class="form-group">
                    <label for="name">Name *</label>
                    <input type="text" id="name" required>
                </div>
                <div class="form-group">
                    <label for="email">Email *</label>
                    <input type="email" id="email" required>
                </div>
                <div class="form-group">
                    <label for="role">Role</label>
                    <select id="role">
                        <option value="user">User</option>
                        <option value="admin">Admin</option>
                        <option value="moderator">Moderator</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">Save</button>
                <button type="button" class="btn btn-warning" onclick="closeModal()">Cancel</button>
            </form>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    
    <script>
        let table;
        
        $(document).ready(function() {
            // Initialize DataTable
            table = $('#usersTable').DataTable({
                ajax: {
                    url: 'api.php?action=list',
                    dataSrc: 'data'  // Grid format uses 'data' key
                },
                columns: [
                    { data: 0 }, // ID (FETCH_NUM index 0)
                    { data: 1 }, // Name
                    { data: 2 }, // Email
                    { data: 3 }, // Role
                    { data: 4 }, // Created At
                    { 
                        data: 0,
                        render: function(data) {
                            return `
                                <button class="btn btn-warning btn-sm" onclick="editUser(${data})">Edit</button>
                                <button class="btn btn-danger btn-sm" onclick="deleteUser(${data})">Delete</button>
                            `;
                        }
                    }
                ],
                pageLength: 10,
                order: [[0, 'desc']]
            });
        });
        
        function openModal(userId = null) {
            $('#userModal').show();
            if (userId) {
                $('#modalTitle').text('Edit User');
                $.get(`api.php?action=get&id=${userId}`, function(res) {
                    if (res.success) {
                        $('#userId').val(res.data.id);
                        $('#name').val(res.data.name);
                        $('#email').val(res.data.email);
                        $('#role').val(res.data.role);
                    }
                });
            } else {
                $('#modalTitle').text('Add User');
                $('#userForm')[0].reset();
                $('#userId').val('');
            }
        }
        
        function closeModal() {
            $('#userModal').hide();
        }
        
        $('#userForm').submit(function(e) {
            e.preventDefault();
            const data = {
                id: $('#userId').val(),
                name: $('#name').val(),
                email: $('#email').val(),
                role: $('#role').val()
            };
            
            const action = data.id ? 'update' : 'create';
            $.post('api.php?action=' + action, data, function(res) {
                if (res.success) {
                    closeModal();
                    table.ajax.reload();
                    alert(res.message);
                } else {
                    alert('Error: ' + res.error);
                }
            }, 'json');
        });
        
        function editUser(id) {
            openModal(id);
        }
        
        function deleteUser(id) {
            if (confirm('Are you sure you want to delete this user?')) {
                $.post('api.php?action=delete', { id: id }, function(res) {
                    if (res.success) {
                        table.ajax.reload();
                        alert(res.message);
                    } else {
                        alert('Error: ' + res.error);
                    }
                }, 'json');
            }
        }
        
        // Close modal when clicking outside
        $(window).click(function(e) {
            if (e.target == document.getElementById('userModal')) {
                closeModal();
            }
        });
    </script>
</body>
</html>
