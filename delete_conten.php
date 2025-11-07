<?php
/**
 * DELETE CONTENT HANDLER
 * Handle penghapusan konten (gambar & video) dari database dan file system
 * Path: manage_display/delete_content.php
 */

require_once '../config.php';
requireLogin();

header('Content-Type: application/json');

// Cek method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

// Get JSON input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data || !isset($data['id'])) {
    echo json_encode(['success' => false, 'error' => 'ID konten tidak ditemukan']);
    exit;
}

$id = (int)$data['id'];

if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'ID tidak valid']);
    exit;
}

try {
    $conn = getConnection();
    
    // Get content data before deletion
    $stmt = $conn->prepare("SELECT * FROM konten_layar WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $content = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$content) {
        throw new Exception('Konten tidak ditemukan');
    }
    
    // Delete from database first
    $deleteStmt = $conn->prepare("DELETE FROM konten_layar WHERE id = ?");
    $deleteStmt->bind_param("i", $id);
    
    if (!$deleteStmt->execute()) {
        throw new Exception('Gagal menghapus dari database: ' . $deleteStmt->error);
    }
    $deleteStmt->close();
    
    // Delete physical files
    $filesDeleted = [];
    
    // Delete image file
    if (!empty($content['gambar'])) {
        $imagePath = UPLOAD_DIR . $content['gambar'];
        if (file_exists($imagePath)) {
            if (@unlink($imagePath)) {
                $filesDeleted[] = $content['gambar'];
            }
        }
    }
    
    // Delete video file and thumbnail
    if (!empty($content['video'])) {
        $videoPath = UPLOAD_DIR . $content['video'];
        if (file_exists($videoPath)) {
            if (@unlink($videoPath)) {
                $filesDeleted[] = $content['video'];
            }
        }
        
        // Delete video thumbnail
        $thumbnailName = 'thumb_' . pathinfo($content['video'], PATHINFO_FILENAME) . '.jpg';
        $thumbnailPath = UPLOAD_DIR . $thumbnailName;
        if (file_exists($thumbnailPath)) {
            if (@unlink($thumbnailPath)) {
                $filesDeleted[] = $thumbnailName;
            }
        }
    }
    
    // Log activity
    if (function_exists('logActivity')) {
        $contentType = !empty($content['video']) ? 'video' : 'gambar';
        logActivity(
            'delete',
            'konten_layar',
            "Hapus konten {$contentType}: {$content['judul']} dari {$content['tipe_layar']} layar {$content['nomor_layar']}",
            $content,
            null
        );
    }
    
    $conn->close();
    
    echo json_encode([
        'success' => true,
        'message' => 'Konten berhasil dihapus',
        'files_deleted' => count($filesDeleted)
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>