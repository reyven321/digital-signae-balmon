<?php
/**
 * UPLOAD HANDLER - Support Image & Video
 * Handle upload untuk gambar dan video dengan validation lengkap
 * Path: manage_display/upload_handler.php
 */

require_once '../config.php';
require_once '../ImageProcessor.php';
requireLogin();

header('Content-Type: application/json');

// Cek method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

// Get form data
$tipeLayar = $_POST['tipe_layar'] ?? '';
$nomorLayar = (int)($_POST['nomor_layar'] ?? 0);
$judul = trim($_POST['judul'] ?? '');
$deskripsi = trim($_POST['deskripsi'] ?? '');
$durasi = (int)($_POST['durasi'] ?? 5);
$urutan = (int)($_POST['urutan'] ?? 0);
$contentType = $_POST['content_type'] ?? 'image'; // 'image' or 'video'

// Validation
$errors = [];

if (empty($judul)) {
    $errors[] = 'Judul wajib diisi';
}

if (!in_array($tipeLayar, ['external', 'internal'])) {
    $errors[] = 'Tipe layar tidak valid';
}

if ($tipeLayar === 'external' && ($nomorLayar < 1 || $nomorLayar > 4)) {
    $errors[] = 'Nomor layar external harus 1-4';
}

if ($tipeLayar === 'internal' && ($nomorLayar < 1 || $nomorLayar > 3)) {
    $errors[] = 'Nomor layar internal harus 1-3';
}

if ($durasi < 1 || $durasi > 60) {
    $errors[] = 'Durasi harus antara 1-60 detik';
}

// Cek apakah ada file yang diupload
$hasFile = isset($_FILES['file']) && $_FILES['file']['error'] !== UPLOAD_ERR_NO_FILE;

if (!$hasFile) {
    $errors[] = 'File harus diupload';
}

if (!empty($errors)) {
    echo json_encode(['success' => false, 'errors' => $errors]);
    exit;
}

// Process file upload
$file = $_FILES['file'];
$uploadedFile = null;
$uploadedType = null;
$videoDuration = 0;

try {
    // Validate file
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Upload error: ' . $file['error']);
    }
    
    // Get file info
    $originalName = basename($file['name']);
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $tmpPath = $file['tmp_name'];
    
    // Determine if file is image or video
    $isImage = in_array($ext, ALLOWED_IMAGE_EXT);
    $isVideo = in_array($ext, ALLOWED_VIDEO_EXT);
    
    if (!$isImage && !$isVideo) {
        throw new Exception('Format file tidak didukung. Upload gambar (jpg, png, gif, webp) atau video (mp4, webm, ogg)');
    }
    
    // Validate file size
    if ($file['size'] > MAX_FILE_SIZE) {
        $maxSizeMB = MAX_FILE_SIZE / (1024 * 1024);
        throw new Exception("Ukuran file maksimal {$maxSizeMB}MB");
    }
    
    // Validate MIME type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $tmpPath);
    finfo_close($finfo);
    
    if ($isImage) {
        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($mimeType, $allowedMimes)) {
            throw new Exception('MIME type gambar tidak valid: ' . $mimeType);
        }
    } elseif ($isVideo) {
        $allowedMimes = ['video/mp4', 'video/webm', 'video/ogg'];
        if (!in_array($mimeType, $allowedMimes)) {
            throw new Exception('MIME type video tidak valid: ' . $mimeType);
        }
    }
    
    // Generate secure filename
    $secureFilename = uniqid() . '_' . time() . '.' . $ext;
    $uploadPath = UPLOAD_DIR . $secureFilename;
    
    // Process based on type
    if ($isImage) {
        // Process image (resize to 1920x1080)
        $imageProcessor = new ImageProcessor();
        
        if (!$imageProcessor->processImage($tmpPath, $uploadPath)) {
            throw new Exception('Gagal memproses gambar');
        }
        
        $uploadedFile = $secureFilename;
        $uploadedType = 'image';
        
    } elseif ($isVideo) {
        // Move video file
        if (!move_uploaded_file($tmpPath, $uploadPath)) {
            throw new Exception('Gagal mengupload video');
        }
        
        $uploadedFile = $secureFilename;
        $uploadedType = 'video';
        
        // Get video duration
        $imageProcessor = new ImageProcessor();
        $videoDuration = $imageProcessor->getVideoDuration($uploadPath);
        
        // Generate thumbnail for video
        $thumbnailName = 'thumb_' . pathinfo($secureFilename, PATHINFO_FILENAME) . '.jpg';
        $thumbnailPath = UPLOAD_DIR . $thumbnailName;
        $imageProcessor->generateVideoThumbnail($uploadPath, $thumbnailPath);
    }
    
    // Insert to database
    $conn = getConnection();
    
    if ($uploadedType === 'image') {
        $stmt = $conn->prepare(
            "INSERT INTO konten_layar 
            (tipe_layar, nomor_layar, judul, deskripsi, gambar, durasi, urutan, status, created_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, 'aktif', ?)"
        );
        $createdBy = $_SESSION['admin_id'];
        $stmt->bind_param("sissiii", $tipeLayar, $nomorLayar, $judul, $deskripsi, $uploadedFile, $durasi, $urutan, $createdBy);
        
    } elseif ($uploadedType === 'video') {
        $stmt = $conn->prepare(
            "INSERT INTO konten_layar 
            (tipe_layar, nomor_layar, judul, deskripsi, video, video_duration, durasi, urutan, status, created_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'aktif', ?)"
        );
        $createdBy = $_SESSION['admin_id'];
        $stmt->bind_param("sissiiii", $tipeLayar, $nomorLayar, $judul, $deskripsi, $uploadedFile, $videoDuration, $durasi, $urutan, $createdBy);
    }
    
    if (!$stmt->execute()) {
        // Delete uploaded file if database insert fails
        @unlink($uploadPath);
        if ($uploadedType === 'video' && isset($thumbnailPath)) {
            @unlink($thumbnailPath);
        }
        throw new Exception('Gagal menyimpan ke database: ' . $stmt->error);
    }
    
    $insertId = $stmt->insert_id;
    $stmt->close();
    
    // Log activity
    if (function_exists('logActivity')) {
        logActivity(
            'create', 
            'konten_layar', 
            "Upload {$uploadedType}: {$judul} untuk {$tipeLayar} layar {$nomorLayar}"
        );
    }
    
    $conn->close();
    
    // Success response
    echo json_encode([
        'success' => true,
        'message' => ucfirst($uploadedType) . ' berhasil diupload dan disimpan',
        'data' => [
            'id' => $insertId,
            'filename' => $uploadedFile,
            'type' => $uploadedType,
            'duration' => $videoDuration
        ]
    ]);
    
} catch (Exception $e) {
    // Clean up any uploaded files on error
    if (isset($uploadPath) && file_exists($uploadPath)) {
        @unlink($uploadPath);
    }
    if (isset($thumbnailPath) && file_exists($thumbnailPath)) {
        @unlink($thumbnailPath);
    }
    
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>