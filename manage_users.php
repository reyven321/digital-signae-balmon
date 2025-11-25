<?php
require_once '../enhanced_config.php';
requireLogin();
requireRole('admin');

$conn = getConnection();
$success = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        $username = trim($_POST['username']);
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $nama = trim($_POST['nama']);
        $email = trim($_POST['email']);
        $role = $_POST['role'];
        
        // Check if username exists
        $checkStmt = $conn->prepare("SELECT id FROM admin WHERE username = ?");
        $checkStmt->bind_param("s", $username);
        $checkStmt->execute();
        
        if ($checkStmt->get_result()->num_rows > 0) {
            $error = "Username sudah digunakan!";
        } else {
            $stmt = $conn->prepare("INSERT INTO admin (username, password, nama, email, role) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sssss", $username, $password, $nama, $email, $role);
            
            if ($stmt->execute()) {
                logActivity('create', 'users', "Created user: $username");
                $success = "User berhasil ditambahkan!";
            } else {
                $error = "Gagal menambahkan user: " . $stmt->error;
            }
            $stmt->close();
        }
        $checkStmt->close();
    }
    
    elseif ($action === 'update') {
        $id = (int)$_POST['id'];
        $nama = trim($_POST['nama']);
        $email = trim($_POST['email']);
        $role = $_POST['role'];
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        $stmt = $conn->prepare("UPDATE admin SET nama = ?, email = ?, role = ?, is_active = ? WHERE id = ?");
        $stmt->bind_param("sssii", $nama, $email, $role, $is_active, $id);
        
        if ($stmt->execute()) {
            logActivity('update', 'users', "Updated user ID: $id");
            $success = "User berhasil diupdate!";
        } else {
            $error = "Gagal update user: " . $stmt->error;
        }
        $stmt->close();
    }
    
    elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        
        if ($id == $_SESSION['admin_id']) {
            $error = "Tidak bisa menghapus user yang sedang login!";
        } else {
            $stmt = $conn->prepare("DELETE FROM admin WHERE id = ?");
            $stmt->bind_param("i", $id);
            
            if ($stmt->execute()) {
                logActivity('delete', 'users', "Deleted user ID: $id");
                $success = "User berhasil dihapus!";
            } else {
                $error = "Gagal menghapus user: " . $stmt->error;
            }
            $stmt->close();
        }
    }
    
    elseif ($action === 'reset_password') {
        $id = (int)$_POST['id'];
        $newPassword = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
        
        $stmt = $conn->prepare("UPDATE admin SET password = ? WHERE id = ?");
        $stmt->bind_param("si", $newPassword, $id);
        
        if ($stmt->execute()) {
            logActivity('update', 'users', "Reset password for user ID: $id");
            $success = "Password berhasil direset!";
        } else {
            $error = "Gagal reset password: " . $stmt->error;
        }
        $stmt->close();
    }
}

// Get all users
$users = $conn->query("SELECT * FROM admin ORDER BY created_at DESC")->fetch_all(MYSQLI_ASSOC);
$conn->close();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - Digital Signage</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; background: #f5f5f5; }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .header-content {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .back-btn {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 8px 20px;
            border-radius: 5px;
            text-decoration: none;
        }
        
        .container {
            max-width: 1400px;
            margin: 30px auto;
            padding: 0 20px;
        }
        
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 8px;
        }
        .alert-success { background: #d4edda; color: #155724; }
        .alert-error { background: #f8d7da; color: #721c24; }
        
        .toolbar {
            background: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary { background: #667eea; color: white; }
        .btn-success { background: #28a745; color: white; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-warning { background: #ffc107; color: #333; }
        .btn-sm { padding: 5px 10px; font-size: 14px; }
        
        .card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        th {
            background: #f8f9fa;
            font-weight: 600;
        }
        tr:hover { background: #f8f9fa; }
        
        .badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-superadmin { background: #e3f2fd; color: #1976d2; }
        .badge-admin { background: #f3e5f5; color: #7b1fa2; }
        .badge-editor { background: #fff3e0; color: #f57c00; }
        .badge-viewer { background: #e8f5e9; color: #388e3c; }
        .badge-active { background: #d4edda; color: #155724; }
        .badge-inactive { background: #f8d7da; color: #721c24; }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
        }
        .modal.active { display: flex; align-items: center; justify-content: center; }
        .modal-content {
            background: white;
            padding: 30px;
            border-radius: 10px;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }
        .modal-header {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #eee;
        }
        .modal-header h2 { color: #667eea; }
        
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
        }
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .checkbox-group input[type="checkbox"] {
            width: auto;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-content">
            <h1>👥 User Management</h1>
            <a href="../dashboard.php" class="back-btn">← Dashboard</a>
        </div>
    </div>
    
    <div class="container">
        <?php if ($success): ?>
            <div class="alert alert-success"><?= $success ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?= $error ?></div>
        <?php endif; ?>
        
        <div class="toolbar">
            <button class="btn btn-primary" onclick="openAddModal()">➕ Tambah User Baru</button>
        </div>
        
        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Username</th>
                        <th>Nama</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Last Login</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?= $user['id'] ?></td>
                        <td><strong><?= htmlspecialchars($user['username']) ?></strong></td>
                        <td><?= htmlspecialchars($user['nama']) ?></td>
                        <td><?= htmlspecialchars($user['email'] ?? '-') ?></td>
                        <td>
                            <span class="badge badge-<?= $user['role'] ?>">
                                <?= ROLES[$user['role']] ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge badge-<?= $user['is_active'] ? 'active' : 'inactive' ?>">
                                <?= $user['is_active'] ? 'Aktif' : 'Nonaktif' ?>
                            </span>
                        </td>
                        <td><?= $user['last_login'] ? timeAgo($user['last_login']) : 'Belum login' ?></td>
                        <td>
                            <button class="btn btn-warning btn-sm" onclick='openEditModal(<?= json_encode($user) ?>)'>✏️ Edit</button>
                            <button class="btn btn-success btn-sm" onclick='openResetModal(<?= $user['id'] ?>)'>🔑 Reset PW</button>
                            <?php if ($user['id'] != $_SESSION['admin_id']): ?>
                            <button class="btn btn-danger btn-sm" onclick='deleteUser(<?= $user['id'] ?>, "<?= htmlspecialchars($user['username']) ?>")'>🗑️</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Add User Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Tambah User Baru</h2>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="create">
                <div class="form-group">
                    <label>Username *</label>
                    <input type="text" name="username" required>
                </div>
                <div class="form-group">
                    <label>Password *</label>
                    <input type="password" name="password" required minlength="6">
                </div>
                <div class="form-group">
                    <label>Nama Lengkap *</label>
                    <input type="text" name="nama" required>
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email">
                </div>
                <div class="form-group">
                    <label>Role *</label>
                    <select name="role" required>
                        <?php foreach (ROLES as $key => $value): ?>
                        <option value="<?= $key ?>"><?= $value ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">Simpan</button>
                <button type="button" class="btn" onclick="closeModal('addModal')">Batal</button>
            </form>
        </div>
    </div>
    
    <!-- Edit User Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Edit User</h2>
            </div>
            <form method="POST" id="editForm">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" id="edit_id">
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" id="edit_username" disabled>
                </div>
                <div class="form-group">
                    <label>Nama Lengkap *</label>
                    <input type="text" name="nama" id="edit_nama" required>
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" id="edit_email">
                </div>
                <div class="form-group">
                    <label>Role *</label>
                    <select name="role" id="edit_role" required>
                        <?php foreach (ROLES as $key => $value): ?>
                        <option value="<?= $key ?>"><?= $value ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group checkbox-group">
                    <input type="checkbox" name="is_active" id="edit_is_active" value="1">
                    <label>User Aktif</label>
                </div>
                <button type="submit" class="btn btn-primary">Update</button>
                <button type="button" class="btn" onclick="closeModal('editModal')">Batal</button>
            </form>
        </div>
    </div>
    
    <!-- Reset Password Modal -->
    <div id="resetModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Reset Password</h2>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="id" id="reset_id">
                <div class="form-group">
                    <label>Password Baru *</label>
                    <input type="password" name="new_password" required minlength="6">
                </div>
                <button type="submit" class="btn btn-success">Reset Password</button>
                <button type="button" class="btn" onclick="closeModal('resetModal')">Batal</button>
            </form>
        </div>
    </div>
    
    <script>
        function openAddModal() {
            document.getElementById('addModal').classList.add('active');
        }
        
        function openEditModal(user) {
            document.getElementById('edit_id').value = user.id;
            document.getElementById('edit_username').value = user.username;
            document.getElementById('edit_nama').value = user.nama;
            document.getElementById('edit_email').value = user.email || '';
            document.getElementById('edit_role').value = user.role;
            document.getElementById('edit_is_active').checked = user.is_active == 1;
            document.getElementById('editModal').classList.add('active');
        }
        
        function openResetModal(userId) {
            document.getElementById('reset_id').value = userId;
            document.getElementById('resetModal').classList.add('active');
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }
        
        function deleteUser(id, username) {
            if (confirm(`Yakin ingin menghapus user "${username}"?`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="${id}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('active');
            }
        }
    </script>
</body>
</html>