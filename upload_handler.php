<?php
/**
 * UPLOAD HANDLER - FIXED VERSION
 * Path: manage_display/upload_handler.php
 * Handles both image and video uploads with enhanced security
 */

// Disable all output before JSON
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Clear any existing output buffers
while (ob_get_level()) {
    ob_end_clean();
}

// Start fresh buffer
ob_start();

// Set JSON header immediately
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

// Simple response function
function sendJSON($success, $message, $data = null) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    $response = [
        'success' => $success,
        'message' => $message,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    if ($data !== null) {
        $response['data'] = $data;
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Start session
    session_start();
    
    // Check if user logged in (simple check)
    if (!isset($_SESSION['admin_id'])) {
        sendJSON(false, 'Anda harus login terlebih dahulu');
    }
    
    // Check request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJSON(false, 'Method tidak valid. Gunakan POST');
    }
    
    // Include config
    require_once '../config.php';
    $conn = getConnection();
    
    // Validate POST data
    $requiredFields = ['judul', 'tipe_layar', 'nomor_layar', 'durasi'];
    $errors = [];
    
    foreach ($requiredFields as $field) {
        if (!isset($_POST[$field]) || trim($_POST[$field]) === '') {
            $errors[] = "Field '$field' wajib diisi";
        }
    }
    
    if (!empty($errors)) {
        sendJSON(false, 'Data tidak lengkap: ' . implode(', ', $errors));
    }
    
    // Get and sanitize POST data
    $judul = trim($_POST['judul']);
    $deskripsi = isset($_POST['deskripsi']) ? trim($_POST['deskripsi']) : '';
    $tipeLayar = trim($_POST['tipe_layar']);
    $nomorLayar = (int)$_POST['nomor_layar'];
    $durasi = (int)$_POST['durasi'];
    $urutan = isset($_POST['urutan']) ? (int)$_POST['urutan'] : 0;
    
    // Validate tipe_layar
    if (!in_array($tipeLayar, ['external', 'internal'])) {
        sendJSON(false, 'Tipe layar tidak valid');
    }
    
    // Validate nomor_layar
    $maxLayar = ($tipeLayar === 'external') ? 4 : 3;
    if ($nomorLayar < 1 || $nomorLayar > $maxLayar) {
        sendJSON(false, "Nomor layar harus antara 1-$maxLayar untuk $tipeLayar");
    }
    
    // Check file upload
    if (!isset($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) {
        sendJSON(false, 'File tidak ada yang dipilih');
    }
    
    $file = $_FILES['file'];
    
    // Check upload error
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errorMsg = 'Upload error: ';
        switch ($file['error']) {
            case UPLOAD_ERR_INI_SIZE:
                $errorMsg .= 'File terlalu besar (limit: ' . ini_get('upload_max_filesize') . ')';
                break;
            case UPLOAD_ERR_FORM_SIZE:
                $errorMsg .= 'File terlalu besar';
                break;
            case UPLOAD_ERR_PARTIAL:
                $errorMsg .= 'Upload tidak lengkap';
                break;
            default:
                $errorMsg .= 'Code ' . $file['error'];
        }
        sendJSON(false, $errorMsg);
    }
    
    // Get file info
    $originalName = basename($file['name']);
    $tmpPath = $file['tmp_name'];
    $fileSize = $file['size'];
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    
    // Validate file exists in temp
    if (!file_exists($tmpPath)) {
        sendJSON(false, 'File temporary tidak ditemukan');
    }
    
    // Validate file size
    if ($fileSize == 0) {
        sendJSON(false, 'File kosong (0 bytes)');
    }
    
    // Max 200MB
    $maxSize = 200 * 1024 * 1024;
    if ($fileSize > $maxSize) {
        sendJSON(false, 'File terlalu besar. Maksimal 200MB');
    }
    
    // Allowed extensions
    $allowedImages = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $allowedVideos = ['mp4', 'webm', 'ogg'];
    $allowedExt = array_merge($allowedImages, $allowedVideos);
    
    if (!in_array($ext, $allowedExt)) {
        sendJSON(false, 'Format file tidak didukung. Gunakan: ' . implode(', ', $allowedExt));
    }
    
    // Validate MIME type (CRITICAL SECURITY)
    if (!function_exists('finfo_open')) {
        sendJSON(false, 'Extension fileinfo tidak aktif di PHP');
    }
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $tmpPath);
    finfo_close($finfo);
    
    // Check MIME type
    $isImage = in_array($ext, $allowedImages);
    $isVideo = in_array($ext, $allowedVideos);
    
    $validImageMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $validVideoMimes = ['video/mp4', 'video/webm', 'video/ogg'];
    
    if ($isImage && !in_array($mimeType, $validImageMimes)) {
        sendJSON(false, 'File bukan gambar valid. MIME: ' . $mimeType);
    }
    
    if ($isVideo && !in_array($mimeType, $validVideoMimes)) {
        sendJSON(false, 'File bukan video valid. MIME: ' . $mimeType);
    }
    
    // Create upload directory
    $uploadDir = '../uploads/';
    if (!file_exists($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            sendJSON(false, 'Gagal membuat folder uploads');
        }
    }
    
    // Check writable
    if (!is_writable($uploadDir)) {
        sendJSON(false, 'Folder uploads tidak writable. Ubah permission ke 755');
    }
    
    // Generate secure filename
    $newFilename = bin2hex(random_bytes(16)) . '_' . time() . '.' . $ext;
    $uploadPath = $uploadDir . $newFilename;
    
    // Move uploaded file
    if (!move_uploaded_file($tmpPath, $uploadPath)) {
        sendJSON(false, 'Gagal memindahkan file ke uploads/');
    }
    
    // Verify file moved successfully
    if (!file_exists($uploadPath)) {
        sendJSON(false, 'File tidak ditemukan setelah upload');
    }
    
    // Prepare SQL
    $createdBy = $_SESSION['admin_id'];
    
    if ($isImage) {
        $stmt = $conn->prepare(
            "INSERT INTO konten_layar 
            (tipe_layar, nomor_layar, judul, deskripsi, gambar, durasi, urutan, status, created_by, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, 'aktif', ?, NOW())"
        );
        $stmt->bind_param("sissiii", $tipeLayar, $nomorLayar, $judul, $deskripsi, $newFilename, $durasi, $urutan, $createdBy);
    } else {
        // Check if video column exists
        $checkCol = $conn->query("SHOW COLUMNS FROM konten_layar LIKE 'video'");
        if ($checkCol && $checkCol->num_rows > 0) {
            $stmt = $conn->prepare(
                "INSERT INTO konten_layar 
                (tipe_layar, nomor_layar, judul, deskripsi, video, durasi, urutan, status, created_by, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 'aktif', ?, NOW())"
            );
            $stmt->bind_param("sissiiii", $tipeLayar, $nomorLayar, $judul, $deskripsi, $newFilename, $durasi, $urutan, $createdBy);
        } else {
            // Fallback: use gambar column
            $stmt = $conn->prepare(
                "INSERT INTO konten_layar 
                (tipe_layar, nomor_layar, judul, deskripsi, gambar, durasi, urutan, status, created_by, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 'aktif', ?, NOW())"
            );
            $stmt->bind_param("sissiii", $tipeLayar, $nomorLayar, $judul, $deskripsi, $newFilename, $durasi, $urutan, $createdBy);
        }
    }
    
    // Execute
    if (!$stmt->execute()) {
        // Delete uploaded file on failure
        @unlink($uploadPath);
        sendJSON(false, 'Gagal menyimpan ke database: ' . $stmt->error);
    }
    
    $insertId = $stmt->insert_id;
    $stmt->close();
    $conn->close();
    
    // Format file size
    $fileSizeFormatted = $fileSize >= 1048576 
        ? number_format($fileSize / 1048576, 2) . ' MB' 
        : number_format($fileSize / 1024, 2) . ' KB';
    
    // Success response
    sendJSON(true, 'Upload berhasil!', [
        'id' => $insertId,
        'judul' => $judul,
        'filename' => $newFilename,
        'type' => $isImage ? 'image' : 'video',
        'size' => $fileSizeFormatted,
        'path' => 'uploads/' . $newFilename
    ]);
    
} catch (Exception $e) {
    sendJSON(false, 'Exception: ' . $e->getMessage());
}
?>