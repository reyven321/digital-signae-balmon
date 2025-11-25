<?php
require_once '../enhanced_config.php';
requireLogin();

$conn = getConnection();
$currentUser = getCurrentUser();

// Get date range (default: last 7 days)
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
$displayType = $_GET['display_type'] ?? 'all';

// Overall statistics
$stats = [];
$stats['total_displays'] = $conn->query("SELECT COALESCE(SUM(display_count), 0) as c FROM content_analytics WHERE display_date BETWEEN '$startDate' AND '$endDate'")->fetch_assoc()['c'];
$stats['total_duration'] = $conn->query("SELECT COALESCE(SUM(total_duration), 0) as c FROM content_analytics WHERE display_date BETWEEN '$startDate' AND '$endDate'")->fetch_assoc()['c'];
$stats['active_content'] = $conn->query("SELECT COUNT(*) as c FROM konten_layar WHERE status = 'aktif'")->fetch_assoc()['c'];
$stats['total_content'] = $conn->query("SELECT COUNT(*) as c FROM konten_layar")->fetch_assoc()['c'];

// Top content by displays
$topContentQuery = "SELECT k.id, k.judul, k.tipe_layar, k.nomor_layar, 
                    COALESCE(SUM(ca.display_count), 0) as total_displays,
                    COALESCE(SUM(ca.total_duration), 0) as total_duration
                    FROM konten_layar k
                    LEFT JOIN content_analytics ca ON k.id = ca.konten_id 
                        AND ca.display_date BETWEEN '$startDate' AND '$endDate'
                    WHERE 1=1";

if ($displayType !== 'all') {
    $topContentQuery .= " AND k.tipe_layar = '$displayType'";
}

$topContentQuery .= " GROUP BY k.id ORDER BY total_displays DESC LIMIT 10";
$topContent = $conn->query($topContentQuery)->fetch_all(MYSQLI_ASSOC);

// Display performance by screen
$screenPerformance = [];
if ($displayType === 'all' || $displayType === 'external') {
    for ($i = 1; $i <= 4; $i++) {
        $result = $conn->query("SELECT COALESCE(SUM(display_count), 0) as displays 
                               FROM content_analytics 
                               WHERE display_type = 'external' 
                               AND nomor_layar = $i 
                               AND display_date BETWEEN '$startDate' AND '$endDate'")->fetch_assoc();
        $screenPerformance['external'][$i] = $result['displays'];
    }
}

if ($displayType === 'all' || $displayType === 'internal') {
    for ($i = 1; $i <= 3; $i++) {
        $result = $conn->query("SELECT COALESCE(SUM(display_count), 0) as displays 
                               FROM content_analytics 
                               WHERE display_type = 'internal' 
                               AND nomor_layar = $i 
                               AND display_date BETWEEN '$startDate' AND '$endDate'")->fetch_assoc();
        $screenPerformance['internal'][$i] = $result['displays'];
    }
}

// Daily statistics for chart
$dailyStats = $conn->query("SELECT display_date, SUM(display_count) as displays 
                           FROM content_analytics 
                           WHERE display_date BETWEEN '$startDate' AND '$endDate'
                           GROUP BY display_date 
                           ORDER BY display_date")->fetch_all(MYSQLI_ASSOC);

// Peak hours
$peakHours = $conn->query("SELECT display_hour, SUM(display_count) as displays 
                          FROM content_analytics 
                          WHERE display_date BETWEEN '$startDate' AND '$endDate'
                          GROUP BY display_hour 
                          ORDER BY displays DESC 
                          LIMIT 5")->fetch_all(MYSQLI_ASSOC);

$conn->close();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics & Reports - Digital Signage</title>
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
            text-decoration: none;
            border-radius: 5px;
        }
        
        .container {
            max-width: 1400px;
            margin: 30px auto;
            padding: 0 20px;
        }
        
        .filter-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
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
        }
        .filter-form input, .filter-form select {
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            transition: transform 0.3s;
        }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-card .icon {
            font-size: 40px;
            margin-bottom: 10px;
        }
        .stat-card h3 {
            font-size: 14px;
            color: #666;
            margin-bottom: 10px;
        }
        .stat-card .value {
            font-size: 36px;
            font-weight: bold;
            color: #667eea;
        }
        .stat-card .subtext {
            font-size: 12px;
            color: #999;
            margin-top: 5px;
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
        }
        tr:hover {
            background: #f8f9fa;
        }
        
        .badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-external { background: #e3f2fd; color: #1976d2; }
        .badge-internal { background: #f3e5f5; color: #7b1fa2; }
        
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
        
        .chart-container {
            margin: 20px 0;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        .bar {
            display: flex;
            align-items: center;
            margin: 10px 0;
        }
        .bar-label {
            width: 120px;
            font-size: 14px;
            font-weight: 600;
        }
        .bar-track {
            flex: 1;
            height: 30px;
            background: #e0e0e0;
            border-radius: 5px;
            position: relative;
            overflow: hidden;
        }
        .bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #667eea, #764ba2);
            border-radius: 5px;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            padding-right: 10px;
            color: white;
            font-size: 12px;
            font-weight: 600;
            transition: width 1s ease;
        }
        
        .content-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 20px;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-content">
            <div>
                <h1>📊 Analytics & Reports</h1>
                <p>Statistik dan analisis performa konten digital signage</p>
            </div>
            <a href="../dashboard.php" class="back-btn">← Kembali ke Dashboard</a>
        </div>
    </div>
    
    <div class="container">
        <!-- Filter -->
        <div class="filter-card">
            <form method="GET" class="filter-form">
                <div>
                    <label>Tanggal Mulai:</label>
                    <input type="date" name="start_date" value="<?= $startDate ?>" required>
                </div>
                <div>
                    <label>Tanggal Akhir:</label>
                    <input type="date" name="end_date" value="<?= $endDate ?>" required>
                </div>
                <div>
                    <label>Tipe Display:</label>
                    <select name="display_type">
                        <option value="all" <?= $displayType === 'all' ? 'selected' : '' ?>>Semua</option>
                        <option value="external" <?= $displayType === 'external' ? 'selected' : '' ?>>External</option>
                        <option value="internal" <?= $displayType === 'internal' ? 'selected' : '' ?>>Internal</option>
                    </select>
                </div>
                <div>
                    <label>&nbsp;</label>
                    <button type="submit" class="btn">🔍 Filter</button>
                </div>
            </form>
        </div>
        
        <!-- Statistics Overview -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="icon">📺</div>
                <h3>Total Tampilan</h3>
                <div class="value"><?= number_format($stats['total_displays']) ?></div>
                <div class="subtext">Dalam periode yang dipilih</div>
            </div>
            
            <div class="stat-card">
                <div class="icon">⏱️</div>
                <h3>Total Durasi</h3>
                <div class="value"><?= number_format($stats['total_duration'] / 3600, 1) ?></div>
                <div class="subtext">Jam total konten ditampilkan</div>
            </div>
            
            <div class="stat-card">
                <div class="icon">✅</div>
                <h3>Konten Aktif</h3>
                <div class="value"><?= $stats['active_content'] ?></div>
                <div class="subtext">Dari <?= $stats['total_content'] ?> total konten</div>
            </div>
            
            <div class="stat-card">
                <div class="icon">📈</div>
                <h3>Rata-rata/Hari</h3>
                <div class="value"><?= $stats['total_displays'] > 0 ? number_format($stats['total_displays'] / max(1, count($dailyStats))) : 0 ?></div>
                <div class="subtext">Tampilan per hari</div>
            </div>
        </div>
        
        <!-- Content Grid -->
        <div class="content-grid">
            <!-- Top Content -->
            <div class="card">
                <h2>🏆 Top 10 Konten</h2>
                <?php if (empty($topContent)): ?>
                    <p style="color: #999; text-align: center; padding: 20px;">Belum ada data</p>
                <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Konten</th>
                            <th>Tampilan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topContent as $index => $content): ?>
                        <tr>
                            <td><strong><?= $index + 1 ?></strong></td>
                            <td>
                                <strong><?= htmlspecialchars($content['judul']) ?></strong><br>
                                <span class="badge badge-<?= $content['tipe_layar'] ?>">
                                    <?= ucfirst($content['tipe_layar']) ?> <?= $content['nomor_layar'] ?>
                                </span>
                            </td>
                            <td><strong><?= number_format($content['total_displays']) ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
            
            <!-- Peak Hours -->
            <div class="card">
                <h2>⏰ Jam Tersibuk</h2>
                <?php if (empty($peakHours)): ?>
                    <p style="color: #999; text-align: center; padding: 20px;">Belum ada data</p>
                <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Jam</th>
                            <th>Tampilan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($peakHours as $hour): ?>
                        <tr>
                            <td><strong><?= sprintf('%02d:00 - %02d:00', $hour['display_hour'], $hour['display_hour'] + 1) ?></strong></td>
                            <td><strong><?= number_format($hour['displays']) ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Screen Performance -->
        <?php if (!empty($screenPerformance)): ?>
        <div class="card">
            <h2>📊 Performa Per Layar</h2>
            <div class="chart-container">
                <?php if (isset($screenPerformance['external'])): ?>
                <h3 style="margin-bottom: 15px; color: #333;">External Displays</h3>
                <?php 
                $maxExternal = max($screenPerformance['external']);
                foreach ($screenPerformance['external'] as $num => $displays): 
                    $percentage = $maxExternal > 0 ? ($displays / $maxExternal) * 100 : 0;
                ?>
                <div class="bar">
                    <div class="bar-label">Layar <?= $num ?></div>
                    <div class="bar-track">
                        <div class="bar-fill" style="width: <?= $percentage ?>%">
                            <?= number_format($displays) ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
                
                <?php if (isset($screenPerformance['internal'])): ?>
                <h3 style="margin: 25px 0 15px 0; color: #333;">Internal Displays</h3>
                <?php 
                $maxInternal = max($screenPerformance['internal']);
                foreach ($screenPerformance['internal'] as $num => $displays): 
                    $percentage = $maxInternal > 0 ? ($displays / $maxInternal) * 100 : 0;
                ?>
                <div class="bar">
                    <div class="bar-label">Layar <?= $num ?></div>
                    <div class="bar-track">
                        <div class="bar-fill" style="width: <?= $percentage ?>%">
                            <?= number_format($displays) ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Daily Trend -->
        <?php if (!empty($dailyStats)): ?>
        <div class="card">
            <h2>📈 Trend Harian</h2>
            <div class="chart-container">
                <?php 
                $maxDaily = max(array_column($dailyStats, 'displays'));
                foreach ($dailyStats as $day): 
                    $percentage = $maxDaily > 0 ? ($day['displays'] / $maxDaily) * 100 : 0;
                ?>
                <div class="bar">
                    <div class="bar-label"><?= date('d M', strtotime($day['display_date'])) ?></div>
                    <div class="bar-track">
                        <div class="bar-fill" style="width: <?= $percentage ?>%">
                            <?= number_format($day['displays']) ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>