<?php
/**
 * manage_backup.php
 * Backup Management Interface
 */

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is logged in
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit();
}

// Include required files
require_once '../config.php';
require_once './BackupManager.php';

function formatFileSize($bytes) {
    if ($bytes < 1024) return $bytes . ' B';
    elseif ($bytes < 1048576) return round($bytes / 1024, 2) . ' KB';
    elseif ($bytes < 1073741824) return round($bytes / 1048576, 2) . ' MB';
    else return round($bytes / 1073741824, 2) . ' GB';
}


// Initialize BackupManager
$backupManager = new BackupManager();

// Handle actions
$action = $_GET['action'] ?? '';
$message = '';
$error = '';

// Process actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    switch ($action) {
        case 'create':
            $result = $backupManager->createBackup('manual');
            if ($result['success']) {
                $message = 'Backup created successfully: ' . $result['filename'];
            } else {
                $error = 'Backup failed: ' . $result['error'];
            }
            break;
            
        case 'restore':
            if (isset($_POST['filename'])) {
                $result = $backupManager->restoreBackup($_POST['filename']);
                if ($result['success']) {
                    $message = $result['message'];
                } else {
                    $error = 'Restore failed: ' . $result['error'];
                }
            }
            break;
            
        case 'delete':
            if (isset($_POST['filename'])) {
                $result = $backupManager->deleteBackup($_POST['filename']);
                if ($result['success']) {
                    $message = $result['message'];
                } else {
                    $error = 'Delete failed: ' . $result['error'];
                }
            }
            break;
    }
}

// Download backup
if ($action == 'download' && isset($_GET['file'])) {
    $backupManager->downloadBackup($_GET['file']);
    exit;
}

// Get backup list
$backups = $backupManager->getBackupList();

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backup Management - Digital Signage BMFR</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
        }
        
        .header h1 {
            font-size: 28px;
            margin-bottom: 10px;
        }
        
        .header p {
            opacity: 0.9;
        }
        
        .content {
            padding: 30px;
        }
        
        .alert {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .actions {
            display: flex;
            gap: 15px;
            margin-bottom: 30px;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 5px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }
        
        .btn-primary {
            background: #667eea;
            color: white;
        }
        
        .btn-primary:hover {
            background: #5a67d8;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102,126,234,0.4);
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        
        .btn-danger:hover {
            background: #c82333;
        }
        
        .btn-success {
            background: #28a745;
            color: white;
        }
        
        .btn-success:hover {
            background: #218838;
        }
        
        .table-container {
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th {
            background: #f8f9fa;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: #495057;
            border-bottom: 2px solid #dee2e6;
        }
        
        td {
            padding: 15px;
            border-bottom: 1px solid #dee2e6;
        }
        
        tr:hover {
            background: #f8f9fa;
        }
        
        .badge {
            padding: 5px 10px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .badge-success {
            background: #d4edda;
            color: #155724;
        }
        
        .badge-danger {
            background: #f8d7da;
            color: #721c24;
        }
        
        .badge-info {
            background: #d1ecf1;
            color: #0c5460;
        }
        
        .badge-warning {
            background: #fff3cd;
            color: #856404;
        }
        
        .action-buttons {
            display: flex;
            gap: 5px;
        }
        
        .btn-sm {
            padding: 5px 10px;
            font-size: 12px;
        }
        
        .icon {
            width: 20px;
            height: 20px;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }
        
        .empty-state h3 {
            color: #6c757d;
            margin-bottom: 10px;
        }
        
        .empty-state p {
            color: #adb5bd;
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        
        .modal-content {
            background-color: white;
            margin: 15% auto;
            padding: 20px;
            border-radius: 10px;
            width: 80%;
            max-width: 500px;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .modal-body {
            margin-bottom: 20px;
        }
        
        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        
        .close {
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            color: #adb5bd;
        }
        
        .close:hover {
            color: #000;
        }
        
        @media (max-width: 768px) {
            .actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn-sm {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🗄️ Backup Management</h1>
            <p>Manage database backups and restore points</p>
        </div>
        
        <div class="content">
            <?php if ($message): ?>
                <div class="alert alert-success">
                    ✅ <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-error">
                    ❌ <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <div class="actions">
                <form method="POST" action="?action=create" style="display: inline;">
                    <button type="submit" class="btn btn-primary">
                        ➕ Create New Backup
                    </button>
                </form>
                
                <a href="dashboard.php" class="btn btn-secondary">
                    ⬅️ Back to Dashboard
                </a>
            </div>
            
            <div class="table-container">
                <?php if (empty($backups)): ?>
                    <div class="empty-state">
                        <h3>No backups found</h3>
                        <p>Create your first backup to get started</p>
                    </div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Filename</th>
                                <th>Size</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Created By</th>
                                <th>Created At</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($backups as $backup): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($backup['filename']); ?></td>
                                    <td><?php echo formatFileSize($backup['file_size']); ?></td>
                                    <td>
                                        <?php if ($backup['backup_type'] == 'auto'): ?>
                                            <span class="badge badge-info">AUTO</span>
                                        <?php else: ?>
                                            <span class="badge badge-warning">MANUAL</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($backup['status'] == 'success'): ?>
                                            <span class="badge badge-success">SUCCESS</span>
                                        <?php else: ?>
                                            <span class="badge badge-danger">FAILED</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($backup['created_by_name'] ?? 'System'); ?></td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($backup['created_at'])); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <?php if ($backup['status'] == 'success' && file_exists(BACKUP_DIR . $backup['filename'])): ?>
                                                <a href="?action=download&file=<?php echo urlencode($backup['filename']); ?>" 
                                                   class="btn btn-sm btn-success" title="Download">
                                                    ⬇️
                                                </a>
                                                <button onclick="confirmRestore('<?php echo htmlspecialchars($backup['filename']); ?>')" 
                                                        class="btn btn-sm btn-primary" title="Restore">
                                                    🔄
                                                </button>
                                                <button onclick="confirmDelete('<?php echo htmlspecialchars($backup['filename']); ?>')" 
                                                        class="btn btn-sm btn-danger" title="Delete">
                                                    🗑️
                                                </button>
                                            <?php else: ?>
                                                <span class="badge badge-danger">File Missing</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Restore Modal -->
    <div id="restoreModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Confirm Restore</h2>
                <span class="close" onclick="closeModal('restoreModal')">&times;</span>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to restore from this backup?</p>
                <p><strong>Warning:</strong> This will replace all current data!</p>
            </div>
            <div class="modal-footer">
                <form id="restoreForm" method="POST" action="?action=restore">
                    <input type="hidden" name="filename" id="restoreFilename">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('restoreModal')">Cancel</button>
                    <button type="submit" class="btn btn-danger">Restore</button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Delete Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Confirm Delete</h2>
                <span class="close" onclick="closeModal('deleteModal')">&times;</span>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete this backup?</p>
                <p>This action cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <form id="deleteForm" method="POST" action="?action=delete">
                    <input type="hidden" name="filename" id="deleteFilename">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('deleteModal')">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete</button>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        function confirmRestore(filename) {
            document.getElementById('restoreFilename').value = filename;
            document.getElementById('restoreModal').style.display = 'block';
        }
        
        function confirmDelete(filename) {
            document.getElementById('deleteFilename').value = filename;
            document.getElementById('deleteModal').style.display = 'block';
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.className === 'modal') {
                event.target.style.display = 'none';
            }
        }
    </script>
</body>
</html>