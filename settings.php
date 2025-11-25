<?php
require_once '../enhanced_config.php';
requireLogin();
requireRole('admin');

$conn = getConnection();
$success = '';
$error = '';

// Create settings table if not exists
$settingsTableCheck = $conn->query("SHOW TABLES LIKE 'settings'");
if (!$settingsTableCheck || $settingsTableCheck->num_rows === 0) {
    $conn->query("CREATE TABLE IF NOT EXISTS `settings` (
        `id` INT PRIMARY KEY AUTO_INCREMENT,
        `key_name` VARCHAR(100) UNIQUE NOT NULL,
        `key_value` TEXT,
        `description` VARCHAR(255),
        `updated_by` INT,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    // Insert default settings
    $defaultSettings = [
        ['site_name', 'Digital Signage BMFR Kelas II Manado', 'Nama website'],
        ['site_url', 'http://localhost/digital-signage', 'URL website'],
        ['auto_backup_enabled', '1', 'Enable automatic backup'],
        ['backup_retention_days', '30', 'Backup retention period in days'],
        ['analytics_enabled', '1', 'Enable content analytics'],
        ['rss_refresh_enabled', '1', 'Enable automatic RSS refresh'],
        ['rss_refresh_interval', '3600', 'RSS refresh interval in seconds'],
        ['display_transition', 'fade', 'Display transition effect (fade/slide/none)'],
        ['display_default_duration', '5', 'Default content duration in seconds'],
        ['max_upload_size', '50', 'Maximum upload size in MB'],
        ['maintenance_mode', '0', 'Enable maintenance mode'],
        ['email_notifications', '0', 'Enable email notifications'],
        ['admin_email', 'admin@bmfr.go.id', 'Admin email address']
    ];
    
    foreach ($defaultSettings as $setting) {
        $stmt = $conn->prepare("INSERT INTO settings (key_name, key_value, description) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $setting[0], $setting[1], $setting[2]);
        $stmt->execute();
        $stmt->close();
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_settings') {
        $userId = $_SESSION['admin_id'];
        $updated = 0;
        
        foreach ($_POST as $key => $value) {
            if ($key === 'action') continue;
            
            // Check if setting exists
            $checkStmt = $conn->prepare("SELECT id FROM settings WHERE key_name = ?");
            $checkStmt->bind_param("s", $key);
            $checkStmt->execute();
            $exists = $checkStmt->get_result()->num_rows > 0;
            $checkStmt->close();
            
            if ($exists) {
                $stmt = $conn->prepare("UPDATE settings SET key_value = ?, updated_by = ? WHERE key_name = ?");
                $stmt->bind_param("sis", $value, $userId, $key);
            } else {
                $stmt = $conn->prepare("INSERT INTO settings (key_name, key_value, updated_by) VALUES (?, ?, ?)");
                $stmt->bind_param("ssi", $key, $value, $userId);
            }
            
            if ($stmt->execute()) {
                $updated++;
            }
            $stmt->close();
        }
        
        logActivity('update', 'settings', "Updated $updated settings");
        $success = "Settings berhasil disimpan! ($updated items updated)";
    }
}

// Get all settings
$settingsResult = $conn->query("SELECT * FROM settings ORDER BY key_name");
$settings = [];
while ($row = $settingsResult->fetch_assoc()) {
    $settings[$row['key_name']] = $row;
}

$conn->close();

// Helper function - GUNAKAN NAMA BERBEDA untuk menghindari conflict
function getSettingValue($key, $default = '') {
    global $settings;
    return $settings[$key]['key_value'] ?? $default;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings - Digital Signage</title>
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
            max-width: 1200px;
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
            max-width: 1200px;
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
        
        .settings-grid {
            display: grid;
            gap: 20px;
        }
        
        .settings-section {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .settings-section h2 {
            color: #667eea;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #eee;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #333;
        }
        .form-group .description {
            font-size: 12px;
            color: #999;
            margin-bottom: 5px;
        }
        .form-group input[type="text"],
        .form-group input[type="number"],
        .form-group input[type="email"],
        .form-group input[type="url"],
        .form-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
        }
        .form-group input[type="checkbox"] {
            width: 20px;
            height: 20px;
            margin-right: 10px;
        }
        
        .checkbox-wrapper {
            display: flex;
            align-items: center;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 5px;
        }
        .checkbox-wrapper label {
            margin: 0;
            font-weight: normal;
        }
        
        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
        }
        .btn-primary { 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white; 
        }
        .btn-primary:hover { 
            opacity: 0.9;
        }
        
        .save-bar {
            position: sticky;
            bottom: 20px;
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.2);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .info-box {
            background: #e3f2fd;
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid #1976d2;
            margin-bottom: 20px;
        }
        .info-box strong {
            color: #1976d2;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-content">
            <h1>⚙️ System Settings</h1>
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
        
        <div class="info-box">
            <strong>⚠️ Perhatian:</strong> Perubahan pada pengaturan ini akan mempengaruhi seluruh sistem. 
            Pastikan Anda memahami setiap pengaturan sebelum mengubahnya.
        </div>
        
        <form method="POST">
            <input type="hidden" name="action" value="save_settings">
            
            <div class="settings-grid">
                <!-- General Settings -->
                <div class="settings-section">
                    <h2>🌐 General Settings</h2>
                    
                    <div class="form-group">
                        <label>Site Name</label>
                        <div class="description">Nama aplikasi yang akan ditampilkan</div>
                        <input type="text" name="site_name" value="<?= htmlspecialchars(getSettingValue('site_name')) ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Site URL</label>
                        <div class="description">URL lengkap aplikasi</div>
                        <input type="url" name="site_url" value="<?= htmlspecialchars(getSettingValue('site_url')) ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Admin Email</label>
                        <div class="description">Email administrator untuk notifikasi</div>
                        <input type="email" name="admin_email" value="<?= htmlspecialchars(getSettingValue('admin_email')) ?>">
                    </div>
                    
                    <div class="form-group">
                        <div class="checkbox-wrapper">
                            <input type="checkbox" name="maintenance_mode" value="1" 
                                   <?= getSettingValue('maintenance_mode') == '1' ? 'checked' : '' ?>>
                            <label>Enable Maintenance Mode</label>
                        </div>
                        <div class="description">Aktifkan mode maintenance untuk mencegah akses user</div>
                    </div>
                </div>
                
                <!-- Display Settings -->
                <div class="settings-section">
                    <h2>📺 Display Settings</h2>
                    
                    <div class="form-group">
                        <label>Transition Effect</label>
                        <div class="description">Efek transisi antar konten</div>
                        <select name="display_transition">
                            <option value="fade" <?= getSettingValue('display_transition') == 'fade' ? 'selected' : '' ?>>Fade</option>
                            <option value="slide" <?= getSettingValue('display_transition') == 'slide' ? 'selected' : '' ?>>Slide</option>
                            <option value="none" <?= getSettingValue('display_transition') == 'none' ? 'selected' : '' ?>>None</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Default Duration (seconds)</label>
                        <div class="description">Durasi default tampilan konten</div>
                        <input type="number" name="display_default_duration" 
                               value="<?= getSettingValue('display_default_duration', '5') ?>" 
                               min="1" max="60">
                    </div>
                </div>
                
                <!-- Backup Settings -->
                <div class="settings-section">
                    <h2>💾 Backup Settings</h2>
                    
                    <div class="form-group">
                        <div class="checkbox-wrapper">
                            <input type="checkbox" name="auto_backup_enabled" value="1" 
                                   <?= getSettingValue('auto_backup_enabled') == '1' ? 'checked' : '' ?>>
                            <label>Enable Automatic Backup</label>
                        </div>
                        <div class="description">Aktifkan backup otomatis harian (perlu CRON setup)</div>
                    </div>
                    
                    <div class="form-group">
                        <label>Backup Retention (days)</label>
                        <div class="description">Berapa hari backup otomatis disimpan</div>
                        <input type="number" name="backup_retention_days" 
                               value="<?= getSettingValue('backup_retention_days', '30') ?>" 
                               min="7" max="365">
                    </div>
                </div>
                
                <!-- Analytics Settings -->
                <div class="settings-section">
                    <h2>📊 Analytics Settings</h2>
                    
                    <div class="form-group">
                        <div class="checkbox-wrapper">
                            <input type="checkbox" name="analytics_enabled" value="1" 
                                   <?= getSettingValue('analytics_enabled') == '1' ? 'checked' : '' ?>>
                            <label>Enable Content Analytics</label>
                        </div>
                        <div class="description">Track tampilan dan durasi konten</div>
                    </div>
                </div>
                
                <!-- RSS Settings -->
                <div class="settings-section">
                    <h2>📰 RSS Feed Settings</h2>
                    
                    <div class="form-group">
                        <div class="checkbox-wrapper">
                            <input type="checkbox" name="rss_refresh_enabled" value="1" 
                                   <?= getSettingValue('rss_refresh_enabled') == '1' ? 'checked' : '' ?>>
                            <label>Enable Automatic RSS Refresh</label>
                        </div>
                        <div class="description">Refresh RSS feeds secara otomatis</div>
                    </div>
                    
                    <div class="form-group">
                        <label>RSS Refresh Interval (seconds)</label>
                        <div class="description">Interval refresh RSS (default: 3600 = 1 jam)</div>
                        <input type="number" name="rss_refresh_interval" 
                               value="<?= getSettingValue('rss_refresh_interval', '3600') ?>" 
                               min="300" max="86400">
                    </div>
                </div>
                
                <!-- Upload Settings -->
                <div class="settings-section">
                    <h2>📤 Upload Settings</h2>
                    
                    <div class="form-group">
                        <label>Maximum Upload Size (MB)</label>
                        <div class="description">Ukuran maksimal file yang bisa diupload</div>
                        <input type="number" name="max_upload_size" 
                               value="<?= getSettingValue('max_upload_size', '50') ?>" 
                               min="1" max="500">
                    </div>
                </div>
                
                <!-- Notification Settings -->
                <div class="settings-section">
                    <h2>🔔 Notification Settings</h2>
                    
                    <div class="form-group">
                        <div class="checkbox-wrapper">
                            <input type="checkbox" name="email_notifications" value="1" 
                                   <?= getSettingValue('email_notifications') == '1' ? 'checked' : '' ?>>
                            <label>Enable Email Notifications</label>
                        </div>
                        <div class="description">Kirim notifikasi via email</div>
                    </div>
                </div>
            </div>
            
            <div class="save-bar">
                <div>
                    <strong>💡 Tip:</strong> Scroll ke atas untuk melihat pesan konfirmasi setelah save
                </div>
                <button type="submit" class="btn btn-primary">💾 Save All Settings</button>
            </div>
        </form>
    </div>
    
    <script>
        // Auto-scroll to top after form submit
        <?php if ($success || $error): ?>
        window.scrollTo({ top: 0, behavior: 'smooth' });
        <?php endif; ?>
    </script>
</body>
</html>