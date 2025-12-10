<?php
/**
 * UPLOAD HANDLER - Digital Signage BMFR Kelas II Manado
 * Handler untuk upload konten (gambar/video) ke database dan folder uploads
 * Path: upload_handler.php (di root folder)
 */

// Prevent any output before JSON
ob_start();

// Set header JSON
header('Content-Type: application/json; charset=utf-8');

// Error handling
error_reporting(E_ALL);
ini_set('display_errors', 0); // Jangan tampilkan error di output

try {
    // Include config
    require_once 'config.php';
    
    // Check if user logged in
    requireLogin();
    
    // Validasi request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Method tidak valid. Harus POST.');
    }
    
    // Validasi required fields
    $required_fields = ['judul', 'tipe_layar', 'nomor_layar', 'durasi'];
    $errors = [];
    
    foreach ($required_fields as $field) {
        if (!isset($_POST[$field]) || empty(trim($_POST[$field]))) {
            $errors[] = "Field '$field' wajib diisi";
        }
    }
    
    if (!empty($errors)) {
        ob_end_clean();
        echo json_encode([
            'success' => false,
            'error' => 'Data tidak lengkap',
            'errors' => $errors
        ]);
        exit;
    }
    
    // Validasi file upload
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $error_msg = 'File tidak ada atau gagal diupload';
        
        if (isset($_FILES['file']['error'])) {
            switch ($_FILES['file']['error']) {
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    $error_msg = 'Ukuran file terlalu besar';
                    break;
                case UPLOAD_ERR_PARTIAL:
                    $error_msg = 'File hanya terupload sebagian';
                    break;
                case UPLOAD_ERR_NO_FILE:
                    $error_msg = 'Tidak ada file yang diupload';
                    break;
                default:
                    $error_msg = 'Error upload: ' . $_FILES['file']['error'];
            }
        }
        
        ob_end_clean();
        echo json_encode([
            'success' => false,
            'error' => $error_msg
        ]);
        exit;
    }
    
    // Get data dari POST
    $judul = trim($_POST['judul']);
    $tipe_layar = trim($_POST['tipe_layar']);
    $nomor_layar = (int)$_POST['nomor_layar'];
    $durasi = (int)$_POST['durasi'];
    $urutan = isset($_POST['urutan']) ? (int)$_POST['urutan'] : 0;
    
    // Validasi tipe layar
    if (!in_array($tipe_layar, ['external', 'internal'])) {
        ob_end_clean();
        echo json_encode([
            'success' => false,
            'error' => 'Tipe layar tidak valid. Harus "external" atau "internal"'
        ]);
        exit;
    }
    
    // Validasi nomor layar
    $max_layar = ($tipe_layar === 'external') ? 4 : 1;
    if ($nomor_layar < 1 || $nomor_layar > $max_layar) {
        ob_end_clean();
        echo json_encode([
            'success' => false,
            'error' => "Nomor layar tidak valid. Harus 1-$max_layar untuk $tipe_layar"
        ]);
        exit;
    }
    
    // Get file info
    $file = $_FILES['file'];
    $file_name = $file['name'];
    $file_tmp = $file['tmp_name'];
    $file_size = $file['size'];
    $file_error = $file['error'];
    
    // Get file extension
    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
    
    // Allowed extensions
    $allowed_images = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $allowed_videos = ['mp4', 'webm', 'ogg'];
    $allowed_extensions = array_merge($allowed_images, $allowed_videos);
    
    // Validasi extension
    if (!in_array($file_ext, $allowed_extensions)) {
        ob_end_clean();
        echo json_encode([
            'success' => false,
            'error' => 'Format file tidak didukung. Format yang didukung: ' . implode(', ', $allowed_extensions)
        ]);
        exit;
    }
    
    // Determine media type
    $is_image = in_array($file_ext, $allowed_images);
    $is_video = in_array($file_ext, $allowed_videos);
    
    // Validasi file size (max 200MB)
    $max_size = 200 * 1024 * 1024; // 200MB in bytes
    if ($file_size > $max_size) {
        ob_end_clean();
        echo json_encode([
            'success' => false,
            'error' => 'Ukuran file terlalu besar. Maksimal 200MB'
        ]);
        exit;
    }
    
    // Create uploads directory if not exists
    $upload_dir = __DIR__ . '/uploads/';
    if (!file_exists($upload_dir)) {
        if (!mkdir($upload_dir, 0755, true)) {
            ob_end_clean();
            echo json_encode([
                'success' => false,
                'error' => 'Gagal membuat folder uploads. Periksa permission folder.'
            ]);
            exit;
        }
    }
    
    // Generate unique filename
    $new_filename = uniqid() . '_' . time() . '.' . $file_ext;
    $upload_path = $upload_dir . $new_filename;
    
    // Move uploaded file
    if (!move_uploaded_file($file_tmp, $upload_path)) {
        ob_end_clean();
        echo json_encode([
            'success' => false,
            'error' => 'Gagal menyimpan file. Periksa permission folder uploads/'
        ]);
        exit;
    }
    
    // Insert to database
    $conn = getConnection();
    
    // Prepare SQL
    $sql = "INSERT INTO konten_layar (judul, tipe_layar, nomor_layar, durasi, urutan, status";
    
    if ($is_image) {
        $sql .= ", gambar";
    } elseif ($is_video) {
        $sql .= ", video";
    }
    
    $sql .= ") VALUES (?, ?, ?, ?, ?, 'aktif'";
    
    if ($is_image || $is_video) {
        $sql .= ", ?";
    }
    
    $sql .= ")";
    
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        // Delete uploaded file if SQL prepare fails
        unlink($upload_path);
        ob_end_clean();
        echo json_encode([
            'success' => false,
            'error' => 'Gagal prepare SQL: ' . $conn->error
        ]);
        exit;
    }
    
    // Bind parameters
    if ($is_image || $is_video) {
        $stmt->bind_param("ssiiss", $judul, $tipe_layar, $nomor_layar, $durasi, $urutan, $new_filename);
    } else {
        $stmt->bind_param("ssiis", $judul, $tipe_layar, $nomor_layar, $durasi, $urutan);
    }
    
    // Execute
    if (!$stmt->execute()) {
        // Delete uploaded file if insert fails
        unlink($upload_path);
        ob_end_clean();
        echo json_encode([
            'success' => false,
            'error' => 'Gagal menyimpan ke database: ' . $stmt->error
        ]);
        exit;
    }
    
    $insert_id = $stmt->insert_id;
    $stmt->close();
    $conn->close();
    
    // Clear output buffer and return success
    ob_end_clean();
    echo json_encode([
        'success' => true,
        'message' => 'Konten berhasil diupload!',
        'data' => [
            'id' => $insert_id,
            'judul' => $judul,
            'filename' => $new_filename,
            'type' => $is_image ? 'image' : 'video',
            'size' => $file_size
        ]
    ]);
    
} catch (Exception $e) {
    // Clear output buffer and return error
    ob_end_clean();
    echo json_encode([
        'success' => false,
        'error' => 'Exception: ' . $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}
exit;