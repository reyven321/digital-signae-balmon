<?php
/**
 * BackupManager.php
 * Complete backup and restore functionality
 * Path: BackupManager.php
 */
require_once __DIR__ . '/../config.php';

class BackupManager {
    private $conn;
    private $backupDir;
    private $retentionDays;
    
    public function __construct($retentionDays = 30) {
        $this->conn = getConnection();
        $this->backupDir = BACKUP_DIR ?? __DIR__ . '/backups/';
        $this->retentionDays = $retentionDays;
        
        if (!file_exists($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }
    }
    
    /**
     * Create database backup
     */
    public function createBackup($type = 'manual') {
        $timestamp = date('Y-m-d_His');
        $filename = "backup_{$timestamp}.sql";
        $filepath = $this->backupDir . $filename;
        
        try {
            // Get database credentials
            $host = DB_HOST;
            $user = DB_USER;
            $pass = DB_PASS;
            $dbname = DB_NAME;
            
            // Build mysqldump command
            $mysqldump = '"C:/xampp/mysql/bin/mysqldump.exe"';

    $command = sprintf(
        '%s --user=%s --password=%s --host=%s --port=%s %s > %s 2>&1',
        $mysqldump,
        escapeshellarg(DB_USER),
        escapeshellarg(DB_PASS),
        escapeshellarg(DB_HOST),
        DB_PORT,
        escapeshellarg(DB_NAME),
        escapeshellarg($filepath)
    );

            
            // Execute backup
            exec($command, $output, $returnCode);
            
            if ($returnCode !== 0 || !file_exists($filepath)) {
                throw new Exception('Backup failed: ' . implode("\n", $output));
            }
            
            $filesize = filesize($filepath);
            
            // Compress backup
            $zipFilename = "backup_{$timestamp}.zip";
            $zipPath = $this->backupDir . $zipFilename;
            
            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE) === true) {
                $zip->addFile($filepath, $filename);
                
                // Add uploads folder if exists
                if (file_exists(UPLOAD_DIR)) {
                    $this->addFolderToZip($zip, UPLOAD_DIR, 'uploads');
                }
                
                $zip->close();
                
                // Remove uncompressed SQL file
                unlink($filepath);
                
                $filepath = $zipPath;
                $filename = $zipFilename;
                $filesize = filesize($zipPath);
            }
            
            // Log to database
            $this->logBackup($filename, $filepath, $filesize, $type, 'success');
            
            // Clean old backups
            $this->cleanOldBackups();
            
            return [
                'success' => true,
                'filename' => $filename,
                'filepath' => $filepath,
                'size' => $filesize
            ];
            
        } catch (Exception $e) {
            $this->logBackup($filename ?? 'unknown', null, 0, $type, 'failed', $e->getMessage());
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Add folder recursively to ZIP
     */
    private function addFolderToZip($zip, $folder, $zipPath) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($folder),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        
        foreach ($files as $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = $zipPath . '/' . substr($filePath, strlen($folder) + 1);
                $zip->addFile($filePath, $relativePath);
            }
        }
    }
    
    /**
     * Restore from backup
     */
    public function restoreBackup($filename) {
        $filepath = $this->backupDir . $filename;
        
        if (!file_exists($filepath)) {
            return [
                'success' => false,
                'error' => 'Backup file not found'
            ];
        }
        
        try {
            $tempDir = $this->backupDir . 'temp_' . time() . '/';
            mkdir($tempDir, 0755, true);
            
            // Extract ZIP
            $zip = new ZipArchive();
            if ($zip->open($filepath) !== true) {
                throw new Exception('Failed to open backup file');
            }
            
            $zip->extractTo($tempDir);
            $zip->close();
            
            // Find SQL file
            $sqlFile = null;
            $files = scandir($tempDir);
            foreach ($files as $file) {
                if (pathinfo($file, PATHINFO_EXTENSION) === 'sql') {
                    $sqlFile = $tempDir . $file;
                    break;
                }
            }
            
            if (!$sqlFile) {
                throw new Exception('SQL file not found in backup');
            }
            
            // Restore database
            $host = DB_HOST;
            $user = DB_USER;
            $pass = DB_PASS;
            $dbname = DB_NAME;
            
            $command = sprintf(
                'mysql --user=%s --password=%s --host=%s %s < %s 2>&1',
                escapeshellarg($user),
                escapeshellarg($pass),
                escapeshellarg($host),
                escapeshellarg($dbname),
                escapeshellarg($sqlFile)
            );
            
            exec($command, $output, $returnCode);
            
            if ($returnCode !== 0) {
                throw new Exception('Database restore failed: ' . implode("\n", $output));
            }
            
            // Restore uploads if exists
            $uploadsPath = $tempDir . 'uploads/';
            if (file_exists($uploadsPath)) {
                $this->copyFolder($uploadsPath, UPLOAD_DIR);
            }
            
            // Clean temp directory
            $this->deleteFolder($tempDir);
            
            // Log restore
            if (function_exists('logActivity')) {
                logActivity('restore', 'backup', "Restored from backup: $filename");
            }
            
            return [
                'success' => true,
                'message' => 'Backup restored successfully'
            ];
            
        } catch (Exception $e) {
            // Clean temp directory on error
            if (isset($tempDir) && file_exists($tempDir)) {
                $this->deleteFolder($tempDir);
            }
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Copy folder recursively
     */
    private function copyFolder($src, $dst) {
        if (!file_exists($dst)) {
            mkdir($dst, 0755, true);
        }
        
        $files = scandir($src);
        foreach ($files as $file) {
            if ($file !== '.' && $file !== '..') {
                $srcPath = $src . '/' . $file;
                $dstPath = $dst . '/' . $file;
                
                if (is_dir($srcPath)) {
                    $this->copyFolder($srcPath, $dstPath);
                } else {
                    copy($srcPath, $dstPath);
                }
            }
        }
    }
    
    /**
     * Delete folder recursively
     */
    private function deleteFolder($dir) {
        if (!file_exists($dir)) return;
        
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->deleteFolder($path) : unlink($path);
        }
        rmdir($dir);
    }
    
    /**
     * Log backup to database
     */

     /**
 * Check if table exists in database
 */
private function tableExists($tableName) {
  $tableName = $this->conn->real_escape_string($tableName);
  $query = "SHOW TABLES LIKE '$tableName'";
  $result = $this->conn->query($query);
  return $result && $result->num_rows > 0;
}


    private function logBackup($filename, $filepath, $filesize, $type, $status, $errorMsg = null) {
      if (!$this->tableExists('backup_log')) {
        return;
    }
        
        $createdBy = $_SESSION['admin_id'] ?? null;
        
        $stmt = $this->conn->prepare(
            "INSERT INTO backup_log (filename, file_path, file_size, backup_type, status, error_message, created_by) 
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        
        $stmt->bind_param("ssisssi", $filename, $filepath, $filesize, $type, $status, $errorMsg, $createdBy);
        $stmt->execute();
        $stmt->close();
    }
    
    /**
     * Clean old backups
     */
    private function cleanOldBackups() {
        $files = glob($this->backupDir . 'backup_*.{sql,zip}', GLOB_BRACE);
        $cutoffTime = time() - ($this->retentionDays * 86400);
        
        foreach ($files as $file) {
            if (filemtime($file) < $cutoffTime) {
                unlink($file);
                
                // Remove from database log
                $filename = basename($file);
                $stmt = $this->conn->prepare("DELETE FROM backup_log WHERE filename = ?");
                $stmt->bind_param("s", $filename);
                $stmt->execute();
                $stmt->close();
            }
        }
    }
    
    /**
     * Get backup list
     */
    public function getBackupList($limit = 50) {
      if (!$this->tableExists('backup_log')) {
        return [];
    }
        
        $query = "SELECT bl.*, a.nama as created_by_name 
                  FROM backup_log bl 
                  LEFT JOIN admin a ON bl.created_by = a.id 
                  ORDER BY bl.created_at DESC 
                  LIMIT ?";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $limit);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        return $result;
    }
    
    /**
     * Download backup file
     */
    public function downloadBackup($filename) {
        $filepath = $this->backupDir . $filename;
        
        if (!file_exists($filepath)) {
            return false;
        }
        
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        exit;
    }
    
    /**
     * Delete backup
     */
    public function deleteBackup($filename) {
        $filepath = $this->backupDir . $filename;
        
        if (!file_exists($filepath)) {
            return [
                'success' => false,
                'error' => 'Backup file not found'
            ];
        }
        
        try {
            unlink($filepath);
            
            // Remove from database
            $stmt = $this->conn->prepare("DELETE FROM backup_log WHERE filename = ?");
            $stmt->bind_param("s", $filename);
            $stmt->execute();
            $stmt->close();
            
            return [
                'success' => true,
                'message' => 'Backup deleted successfully'
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    public function __destruct() {
        if ($this->conn) {
            $this->conn->close();
        }
    }
}