<?php
require_once '../enhanced_config.php';
requireLogin();
requireRole('admin');

$conn = getConnection();
$success = '';
$error = '';

// Check if tables exist, create if not
$rssTableCheck = $conn->query("SHOW TABLES LIKE 'rss_feeds'");
if (!$rssTableCheck || $rssTableCheck->num_rows === 0) {
    // Create tables
    $conn->query("CREATE TABLE IF NOT EXISTS `rss_feeds` (
        `id` INT PRIMARY KEY AUTO_INCREMENT,
        `name` VARCHAR(100) NOT NULL,
        `url` VARCHAR(255) NOT NULL,
        `category` VARCHAR(50) DEFAULT 'news',
        `is_active` BOOLEAN DEFAULT TRUE,
        `refresh_interval` INT DEFAULT 3600,
        `last_fetch` DATETIME,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX `idx_active` (`is_active`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    $conn->query("CREATE TABLE IF NOT EXISTS `rss_items` (
        `id` INT PRIMARY KEY AUTO_INCREMENT,
        `feed_id` INT NOT NULL,
        `title` VARCHAR(255) NOT NULL,
        `description` TEXT,
        `link` VARCHAR(255),
        `pub_date` DATETIME,
        `guid` VARCHAR(255) UNIQUE,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_feed` (`feed_id`),
        INDEX `idx_date` (`pub_date`),
        FOREIGN KEY (`feed_id`) REFERENCES `rss_feeds`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    // Insert default feeds
    $conn->query("INSERT INTO rss_feeds (name, url, category) VALUES 
        ('Kominfo News', 'https://www.kominfo.go.id/rss', 'news'),
        ('Antara News', 'https://www.antaranews.com/rss/terkini.xml', 'news')");
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_feed') {
        $name = trim($_POST['name']);
        $url = trim($_POST['url']);
        $category = trim($_POST['category']);
        $interval = (int)$_POST['refresh_interval'];
        
        $stmt = $conn->prepare("INSERT INTO rss_feeds (name, url, category, refresh_interval) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("sssi", $name, $url, $category, $interval);
        
        if ($stmt->execute()) {
            logActivity('create', 'rss', "Added RSS feed: $name");
            $success = "Feed berhasil ditambahkan!";
        } else {
            $error = "Gagal menambahkan feed: " . $stmt->error;
        }
        $stmt->close();
    }
    
    elseif ($action === 'delete_feed') {
        $id = (int)$_POST['id'];
        $stmt = $conn->prepare("DELETE FROM rss_feeds WHERE id = ?");
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            logActivity('delete', 'rss', "Deleted RSS feed ID: $id");
            $success = "Feed berhasil dihapus!";
        } else {
            $error = "Gagal menghapus feed: " . $stmt->error;
        }
        $stmt->close();
    }
    
    elseif ($action === 'toggle_status') {
        $id = (int)$_POST['id'];
        $status = (int)$_POST['status'];
        
        $stmt = $conn->prepare("UPDATE rss_feeds SET is_active = ? WHERE id = ?");
        $stmt->bind_param("ii", $status, $id);
        
        if ($stmt->execute()) {
            logActivity('update', 'rss', "Toggled RSS feed status ID: $id");
            $success = "Status feed diupdate!";
        } else {
            $error = "Gagal update status: " . $stmt->error;
        }
        $stmt->close();
    }
    
    elseif ($action === 'refresh_feed') {
        $id = (int)$_POST['id'];
        
        // Get feed details
        $feedStmt = $conn->prepare("SELECT * FROM rss_feeds WHERE id = ?");
        $feedStmt->bind_param("i", $id);
        $feedStmt->execute();
        $feed = $feedStmt->get_result()->fetch_assoc();
        $feedStmt->close();
        
        if ($feed) {
            $result = fetchRSSFeed($feed, $conn);
            if ($result['success']) {
                $success = "Feed berhasil direfresh! {$result['new_items']} item baru.";
            } else {
                $error = "Gagal refresh feed: " . $result['error'];
            }
        }
    }
    
    elseif ($action === 'refresh_all') {
        $feeds = $conn->query("SELECT * FROM rss_feeds WHERE is_active = 1")->fetch_all(MYSQLI_ASSOC);
        $totalNew = 0;
        
        foreach ($feeds as $feed) {
            $result = fetchRSSFeed($feed, $conn);
            if ($result['success']) {
                $totalNew += $result['new_items'];
            }
        }
        
        $success = "Semua feed direfresh! Total $totalNew item baru.";
    }
}

// Function to fetch RSS feed
function fetchRSSFeed($feed, $conn) {
    try {
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'user_agent' => 'Digital Signage BMFR/1.0'
            ]
        ]);
        
        $xmlContent = @file_get_contents($feed['url'], false, $context);
        
        if ($xmlContent === false) {
            return ['success' => false, 'error' => 'Failed to fetch feed'];
        }
        
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlContent);
        
        if ($xml === false) {
            return ['success' => false, 'error' => 'Failed to parse XML'];
        }
        
        $newItems = 0;
        
        // RSS 2.0
        if (isset($xml->channel->item)) {
            foreach ($xml->channel->item as $item) {
                $title = (string)$item->title;
                $description = (string)$item->description;
                $link = (string)$item->link;
                $pubDate = (string)$item->pubDate;
                $guid = (string)($item->guid ?? $link);
                
                $pubDateTime = null;
                if ($pubDate) {
                    $timestamp = strtotime($pubDate);
                    if ($timestamp !== false) {
                        $pubDateTime = date('Y-m-d H:i:s', $timestamp);
                    }
                }
                
                // Check if exists
                $checkStmt = $conn->prepare("SELECT id FROM rss_items WHERE guid = ?");
                $checkStmt->bind_param("s", $guid);
                $checkStmt->execute();
                $exists = $checkStmt->get_result()->num_rows > 0;
                $checkStmt->close();
                
                if (!$exists) {
                    $insertStmt = $conn->prepare(
                        "INSERT INTO rss_items (feed_id, title, description, link, pub_date, guid) VALUES (?, ?, ?, ?, ?, ?)"
                    );
                    $insertStmt->bind_param("isssss", $feed['id'], $title, $description, $link, $pubDateTime, $guid);
                    $insertStmt->execute();
                    $insertStmt->close();
                    $newItems++;
                }
            }
        }
        
        // Update last_fetch
        $updateStmt = $conn->prepare("UPDATE rss_feeds SET last_fetch = NOW() WHERE id = ?");
        $updateStmt->bind_param("i", $feed['id']);
        $updateStmt->execute();
        $updateStmt->close();
        
        return ['success' => true, 'new_items' => $newItems];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Get all feeds
$feeds = $conn->query("SELECT * FROM rss_feeds ORDER BY created_at DESC")->fetch_all(MYSQLI_ASSOC);

// Get recent items
$recentItems = $conn->query("
    SELECT ri.*, rf.name as feed_name 
    FROM rss_items ri 
    JOIN rss_feeds rf ON ri.feed_id = rf.id 
    ORDER BY ri.pub_date DESC 
    LIMIT 20
")->fetch_all(MYSQLI_ASSOC);

$conn->close();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RSS Feed Manager - Digital Signage</title>
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
            display: flex;
            gap: 10px;
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
        .btn-warning { background: #ffc107; color: #333; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-sm { padding: 5px 10px; font-size: 14px; }
        
        .card {
            background: white;
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .card h2 {
            color: #667eea;
            margin-bottom: 20px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 12px;
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
        }
        
        .form-group {
            margin-bottom: 15px;
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
        
        .news-item {
            padding: 15px;
            border-bottom: 1px solid #eee;
        }
        .news-item:last-child { border-bottom: none; }
        .news-item h4 {
            color: #333;
            margin-bottom: 5px;
        }
        .news-item .meta {
            font-size: 12px;
            color: #999;
            margin-bottom: 10px;
        }
        .news-item .desc {
            color: #666;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-content">
            <h1>📰 RSS Feed Manager</h1>
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
            <button class="btn btn-primary" onclick="openAddModal()">➕ Tambah Feed</button>
            <form method="POST" style="display: inline;">
                <input type="hidden" name="action" value="refresh_all">
                <button type="submit" class="btn btn-success">🔄 Refresh Semua</button>
            </form>
        </div>
        
        <!-- RSS Feeds Table -->
        <div class="card">
            <h2>📡 Daftar RSS Feeds</h2>
            <table>
                <thead>
                    <tr>
                        <th>Nama</th>
                        <th>URL</th>
                        <th>Kategori</th>
                        <th>Interval</th>
                        <th>Last Fetch</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($feeds as $feed): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($feed['name']) ?></strong></td>
                        <td><small><?= htmlspecialchars($feed['url']) ?></small></td>
                        <td><?= htmlspecialchars($feed['category']) ?></td>
                        <td><?= $feed['refresh_interval'] / 60 ?> menit</td>
                        <td><?= $feed['last_fetch'] ? timeAgo($feed['last_fetch']) : 'Belum pernah' ?></td>
                        <td>
                            <span class="badge badge-<?= $feed['is_active'] ? 'active' : 'inactive' ?>">
                                <?= $feed['is_active'] ? 'Aktif' : 'Nonaktif' ?>
                            </span>
                        </td>
                        <td>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="toggle_status">
                                <input type="hidden" name="id" value="<?= $feed['id'] ?>">
                                <input type="hidden" name="status" value="<?= $feed['is_active'] ? 0 : 1 ?>">
                                <button type="submit" class="btn btn-warning btn-sm">
                                    <?= $feed['is_active'] ? '⏸️' : '▶️' ?>
                                </button>
                            </form>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="refresh_feed">
                                <input type="hidden" name="id" value="<?= $feed['id'] ?>">
                                <button type="submit" class="btn btn-success btn-sm">🔄</button>
                            </form>
                            <form method="POST" style="display: inline;" onsubmit="return confirm('Hapus feed ini?')">
                                <input type="hidden" name="action" value="delete_feed">
                                <input type="hidden" name="id" value="<?= $feed['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm">🗑️</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Recent News Items -->
        <div class="card">
            <h2>📄 Berita Terbaru (20 Items)</h2>
            <?php foreach ($recentItems as $item): ?>
            <div class="news-item">
                <h4><?= htmlspecialchars($item['title']) ?></h4>
                <div class="meta">
                    <strong><?= htmlspecialchars($item['feed_name']) ?></strong> • 
                    <?= formatDateTime($item['pub_date']) ?>
                </div>
                <div class="desc"><?= htmlspecialchars(substr($item['description'] ?? '', 0, 200)) ?>...</div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- Add Feed Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <h2>Tambah RSS Feed</h2>
            <form method="POST">
                <input type="hidden" name="action" value="add_feed">
                <div class="form-group">
                    <label>Nama Feed *</label>
                    <input type="text" name="name" required>
                </div>
                <div class="form-group">
                    <label>URL Feed *</label>
                    <input type="url" name="url" required placeholder="https://example.com/rss">
                </div>
                <div class="form-group">
                    <label>Kategori</label>
                    <input type="text" name="category" value="news">
                </div>
                <div class="form-group">
                    <label>Interval Refresh (detik)</label>
                    <input type="number" name="refresh_interval" value="3600" min="300">
                </div>
                <button type="submit" class="btn btn-primary">Simpan</button>
                <button type="button" class="btn" onclick="closeModal()">Batal</button>
            </form>
        </div>
    </div>
    
    <script>
        function openAddModal() {
            document.getElementById('addModal').classList.add('active');
        }
        
        function closeModal() {
            document.getElementById('addModal').classList.remove('active');
        }
        
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                closeModal();
            }
        }
    </script>
</body>
</html>