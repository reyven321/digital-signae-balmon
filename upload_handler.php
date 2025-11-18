<?php
/**
 * UPLOAD HANDLER - Digital Signage BMFR
 * Menangani upload gambar dan video
 * Path: upload_handler.php
 */

header('Content-Type: application/json');

require_once 'config.php';
requireLogin();

// Aktifkan error reporting untuk debugging
error_reporting(E_ALL);
ini_set('display_errors', 0); // Jangan tampilkan error di output
ini_set('log_errors', 1);

// Function untuk mengirim response JSON
function sendResponse($success, $message, $data = null, $errors = null) {
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
        'errors' => $errors
    ]);
    exit;
}

// Validasi request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method tidak diizinkan');
}

// Validasi required fields
$requiredFields = ['tipe_layar', 'nomor_layar', 'judul', 'durasi'];
$errors = [];

foreach ($requiredFields as $field) {
    if (empty($_POST[$field])) {
        $errors[] = "Field '$field' wajib diisi";
    }
}

// Validasi file upload
if (!isset($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) {
    $errors[] = "File wajib diupload";
}

if (!empty($errors)) {
    sendResponse(false, 'Validasi gagal', null, $errors);
}

// Ambil data dari POST
$tipe_layar = $_POST['tipe_layar'];
$nomor_layar = (int)$_POST['nomor_layar'];
$judul = trim($_POST['judul']);
$deskripsi = trim($_POST['deskripsi'] ?? '');
$durasi = (int)$_POST['durasi'];
$urutan = (int)($_POST['urutan'] ?? 0);

// Validasi tipe layar
if (!in_array($tipe_layar, ['external', 'internal'])) {
    sendResponse(false, 'Tipe layar tidak valid');
}

// Validasi nomor layar
$maxLayar = $tipe_layar === 'external' ? 4 : 2;
if ($nomor_layar < 1 || $nomor_layar > $maxLayar) {
    sendResponse(false, "Nomor layar harus antara 1-$maxLayar");
}

// Validasi durasi
if ($durasi < 1 || $durasi > 60) {
    sendResponse(false, 'Durasi harus antara 1-60 detik');
}

// Proses file upload
$file = $_FILES['file'];

// Cek error upload
$uploadErrors = [
    UPLOAD_ERR_INI_SIZE => 'File terlalu besar (melebihi upload_max_filesize di php.ini)',
    UPLOAD_ERR_FORM_SIZE => 'File terlalu besar (melebihi MAX_FILE_SIZE di form)',
    UPLOAD_ERR_PARTIAL => 'File hanya terupload sebagian',
    UPLOAD_ERR_NO_FILE => 'Tidak ada file yang diupload',
    UPLOAD_ERR_NO_TMP_DIR => 'Folder temporary tidak ditemukan',
    UPLOAD_ERR_CANT_WRITE => 'Gagal menulis file ke disk',
    UPLOAD_ERR_EXTENSION => 'Upload dihentikan oleh extension PHP'
];

if ($file['error'] !== UPLOAD_ERR_OK) {
    $errorMsg = $uploadErrors[$file['error']] ?? 'Error upload tidak diketahui';
    sendResponse(false, $errorMsg);
}

// Validasi ukuran file (50MB)
$maxSize = 50 * 1024 * 1024; // 50MB dalam bytes
if ($file['size'] > $maxSize) {
    sendResponse(false, 'File terlalu besar. Maksimal 50MB');
}

// Deteksi tipe file
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

// Allowed MIME types
$allowedImages = [
    'image/jpeg',
    'image/png',
    'image/gif',
    'image/webp',
    'image/jpg'
];

$allowedVideos = [
    'video/mp4',
    'video/webm',
    'video/ogg',
    'video/quicktime',
    'video/x-msvideo'
];

$isImage = in_array($mimeType, $allowedImages);
$isVideo = in_array($mimeType, $allowedVideos);

if (!$isImage && !$isVideo) {
    sendResponse(false, "Tipe file tidak didukung: $mimeType. Hanya gambar (JPG, PNG, GIF, WebP) dan video (MP4, WebM, OGG) yang diperbolehkan");
}

// Generate unique filename
$extension = pathinfo($file['name'], PATHINFO_EXTENSION);
$filename = uniqid() . '_' . time() . '.' . strtolower($extension);

// Tentukan folder upload
$uploadDir = __DIR__ . '/uploads/';

// Buat folder jika belum ada
if (!file_exists($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) {
        sendResponse(false, 'Gagal membuat folder uploads');
    }
}

// Cek permission folder
if (!is_writable($uploadDir)) {
    sendResponse(false, 'Folder uploads tidak memiliki permission untuk menulis. Jalankan: chmod 755 uploads/');
}

// Upload file
$uploadPath = $uploadDir . $filename;

if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
    sendResponse(false, 'Gagal memindahkan file ke folder uploads');
}

// Insert ke database
try {
    $conn = getConnection();
    
    $stmt = $conn->prepare(
        "INSERT INTO konten_layar 
        (tipe_layar, nomor_layar, judul, deskripsi, gambar, video, durasi, urutan, status, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'aktif', NOW())"
    );
    
    $gambar = $isImage ? $filename : null;
    $video = $isVideo ? $filename : null;
    
    $stmt->bind_param(
        "sissssii",
        $tipe_layar,
        $nomor_layar,
        $judul,
        $deskripsi,
        $gambar,
        $video,
        $durasi,
        $urutan
    );
    
    if ($stmt->execute()) {
        $insertId = $stmt->insert_id;
        $stmt->close();
        $conn->close();
        
        sendResponse(true, 'Konten berhasil diupload', [
            'id' => $insertId,
            'filename' => $filename,
            'type' => $isImage ? 'image' : 'video'
        ]);
    } else {
        // Hapus file jika insert gagal
        unlink($uploadPath);
        sendResponse(false, 'Gagal menyimpan ke database: ' . $stmt->error);
    }
    
} catch (Exception $e) {
    // Hapus file jika terjadi error
    if (file_exists($uploadPath)) {
        unlink($uploadPath);
    }
    sendResponse(false, 'Terjadi kesalahan: ' . $e->getMessage());
}