<?php
require_once '../enhanced_config.php';
requireLogin();

$conn = getConnection();

// Pagination
$page = (int)($_GET['page'] ?? 1);
$limit = 50;
$offset = ($page - 1) * $limit;

// Filter
$filterUser = $_GET['user'] ?? '';
$filterAction = $_GET['action'] ?? '';
$filterModule = $_GET['module'] ?? '';
$filterDate = $_GET['date'] ?? '';

// Build query
$whereConditions = [];
$params = [];
$types = '';

if ($filterUser) {
    $whereConditions[] = "a.username LIKE ?";
    $params[] = "%$filterUser%";
    $types .= 's';
}
if ($filterAction) {
    $whereConditions[] = "al.action = ?";
    $params[] = $filterAction;
    $types .= 's';
}
if ($filterModule) {
    $whereConditions[] = "al.module = ?";
    $params[] = $filterModule;
    $types .= 's';
}
if ($filterDate) {
    $whereConditions[] = "DATE(al.created_at) = ?";
    $params[] = $filterDate;
    $types .= 's';
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Get total records
$countQuery = "SELECT COUNT(*) as total 
               FROM activity_log al 
               JOIN admin a ON al.user_id = a.id 
               $whereClause";

if (!empty($params)) {
    $countStmt = $conn->prepare($countQuery);
    $countStmt->bind_param($types, ...$params);
    $countStmt->execute();
    $totalRecords = $countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();
} else {
    $totalRecords = $conn->query($countQuery)->fetch_assoc()['total'];
}

$totalPages = ceil($totalRecords / $limit);

// Get activity logs
$query = "SELECT al.*, a.nama, a.username 
          FROM activity_log al 
          JOIN admin a ON al.user_id = a.id 
          $whereClause
          ORDER BY al.created_at DESC 
          LIMIT ? OFFSET ?";

if (!empty($params)) {
    $stmt = $conn->prepare($query);
    $params[] = $limit;
    $params[] = $offset;
    $types .= 'ii';
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $activities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $stmt = $conn->prepare($query);
    $stmt->bind_param('ii', $limit, $offset);
    $stmt->execute();
    $activities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Get filter options
$actions = $conn->query("SELECT DISTINCT action FROM activity_log ORDER BY action")->fetch_all(MYSQLI_ASSOC);
$modules = $conn->query("SELECT DISTINCT module FROM activity_log ORDER BY module")->fetch_all(MYSQLI_ASSOC);

$conn->close();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Log - Digital Signage</title>
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
            max-width: 1600px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .back-btn {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 8px 20px;
            text-decoration: none;
            border-radius: 5px;
        }
        
        .container {
            max-width: 1600px;
            margin: 30px auto;
            padding: 0 20px;
        }
        
        .filter-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .filter-form {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        .filter-form label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #333;
            font-size: 14px;
        }
        .filter-form input, .filter-form select {
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
            min-width: 150px;
        }
        
        .card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .card h2 {
            color: #667eea;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
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
            font-size: 14px;
        }
        tr:hover {
            background: #f8f9fa;
        }
        
        .badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        .badge-create { background: #d4edda; color: #155724; }
        .badge-update { background: #fff3cd; color: #856404; }
        .badge-delete { background: #f8d7da; color: #721c24; }
        .badge-login { background: #e3f2fd; color: #1976d2; }
        .badge-logout { background: #f3e5f5; color: #7b1fa2; }
        .badge-view { background: #e8f5e9; color: #2e7d32; }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            background: #667eea;
            color: white;
            text-decoration: none;
            display: inline-block;
        }
        .btn:hover {
            background: #5568d3;
        }
        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }
        
        .pagination {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-top: 20px;
        }
        .pagination a {
            padding: 8px 15px;
            background: white;
            color: #667eea;
            text-decoration: none;
            border-radius: 5px;
            border: 1px solid #667eea;
        }
        .pagination a:hover {
            background: #667eea;
            color: white;
        }
        .pagination .active {
            background: #667eea;
            color: white;
        }
        
        .detail-popup {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .detail-content {
            background: white;
            padding: 30px;
            border-radius: 10px;
            max-width: 600px;
            max-height: 80vh;
            overflow-y: auto;
        }
        .close-btn {
            float: right;
            cursor: pointer;
            font-size: 24px;
            color: #999;
        }
        
        .info-text {
            color: #666;
            font-size: 14px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-content">
            <div>
                <h1>📝 Activity Log</h1>
                <p>Riwayat aktivitas sistem digital signage</p>
            </div>
            <a href="../dashboard.php" class="back-btn">← Kembali ke Dashboard</a>
        </div>
    </div>
    
    <div class="container">
        <div class="filter-card">
            <form method="GET" class="filter-form">
                <div>
                    <label>User:</label>
                    <input type="text" name="user" placeholder="Username..." value="<?= htmlspecialchars($filterUser) ?>">
                </div>
                <div>
                    <label>Action:</label>
                    <select name="action">
                        <option value="">Semua Action</option>
                        <?php foreach ($actions as $act): ?>
                        <option value="<?= htmlspecialchars($act['action']) ?>" <?= $filterAction === $act['action'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($act['action']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Module:</label>
                    <select name="module">
                        <option value="">Semua Module</option>
                        <?php foreach ($modules as $mod): ?>
                        <option value="<?= htmlspecialchars($mod['module']) ?>" <?= $filterModule === $mod['module'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($mod['module']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Tanggal:</label>
                    <input type="date" name="date" value="<?= htmlspecialchars($filterDate) ?>">
                </div>
                <div>
                    <label>&nbsp;</label>
                    <button type="submit" class="btn">🔍 Filter</button>
                </div>
                <?php if ($filterUser || $filterAction || $filterModule || $filterDate): ?>
                <div>
                    <label>&nbsp;</label>
                    <a href="activity_log.php" class="btn" style="background: #6c757d;">Clear Filter</a>
                </div>
                <?php endif; ?>
            </form>
        </div>
        
        <div class="card">
            <h2>📋 Log Aktivitas (<?= number_format($totalRecords) ?> records)</h2>
            <p class="info-text">Menampilkan <?= $limit ?> dari <?= number_format($totalRecords) ?> total aktivitas</p>
            
            <?php if (empty($activities)): ?>
                <p style="color: #999; text-align: center; padding: 40px 0;">Tidak ada aktivitas yang ditemukan</p>
            <?php else: ?>
            
            <table>
                <thead>
                    <tr>
                        <th>Waktu</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Module</th>
                        <th>Deskripsi</th>
                        <th>IP Address</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($activities as $activity): ?>
                    <tr>
                        <td><?= formatDateTime($activity['created_at'], 'd M Y H:i:s') ?></td>
                        <td>
                            <strong><?= htmlspecialchars($activity['nama']) ?></strong><br>
                            <small style="color: #999;"><?= htmlspecialchars($activity['username']) ?></small>
                        </td>
                        <td>
                            <span class="badge badge-<?= $activity['action'] ?>">
                                <?= htmlspecialchars($activity['action']) ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($activity['module']) ?></td>
                        <td><?= htmlspecialchars($activity['description']) ?></td>
                        <td><?= htmlspecialchars($activity['ip_address']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                <a href="?page=<?= $page - 1 ?><?= $filterUser ? '&user=' . urlencode($filterUser) : '' ?><?= $filterAction ? '&action=' . urlencode($filterAction) : '' ?><?= $filterModule ? '&module=' . urlencode($filterModule) : '' ?><?= $filterDate ? '&date=' . $filterDate : '' ?>">
                    ← Previous
                </a>
                <?php endif; ?>
                
                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                <a href="?page=<?= $i ?><?= $filterUser ? '&user=' . urlencode($filterUser) : '' ?><?= $filterAction ? '&action=' . urlencode($filterAction) : '' ?><?= $filterModule ? '&module=' . urlencode($filterModule) : '' ?><?= $filterDate ? '&date=' . $filterDate : '' ?>" 
                   class="<?= $i === $page ? 'active' : '' ?>">
                    <?= $i ?>
                </a>
                <?php endfor; ?>
                
                <?php if ($page < $totalPages): ?>
                <a href="?page=<?= $page + 1 ?><?= $filterUser ? '&user=' . urlencode($filterUser) : '' ?><?= $filterAction ? '&action=' . urlencode($filterAction) : '' ?><?= $filterModule ? '&module=' . urlencode($filterModule) : '' ?><?= $filterDate ? '&date=' . $filterDate : '' ?>">
                    Next →
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <?php endif; ?>
        </div>
    </div>
</body>
</html>