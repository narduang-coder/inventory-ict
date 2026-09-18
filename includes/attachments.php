<?php
/**
 * Item Attachments Handler
 * Handles secure file uploads, MIME validation, and attachment management
 * Location: includes/attachments.php
 */

if (!function_exists('getUploadDirectory')) {
    function getUploadDirectory() {
        $upload_base = UPLOAD_PATH . 'attachments' . DIRECTORY_SEPARATOR;
        if (!is_dir($upload_base)) {
            mkdir($upload_base, 0755, true);
        }
        return $upload_base;
    }
}

/**
 * Validate MIME type using file content inspection
 * Not just file extension
 */
if (!function_exists('validateMimeType')) {
    function validateMimeType($file_path, $expected_extension = null) {
        // Use finfo to detect MIME type from file content
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file_path);
        finfo_close($finfo);

        // Validate against database whitelist
        global $pdo;
        
        $stmt = $pdo->prepare("
            SELECT mime_type, file_category 
            FROM allowed_mime_types 
            WHERE mime_type = ? AND file_extension = ? AND is_allowed = 1
        ");
        $stmt->execute([$mime_type, strtolower((string)$expected_extension)]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ?: false;
    }
}

/**
 * Validate file extension
 */
if (!function_exists('validateFileExtension')) {
    function validateFileExtension($filename) {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        // Explicitly reject dangerous extensions
        $forbidden = ['php', 'php3', 'php4', 'php5', 'phtml', 'phar', 'cgi', 'exe', 'sh', 'js', 'html', 'htm', 'svg'];
        
        if (in_array($ext, $forbidden, true)) {
            return false;
        }

        // Validate against whitelist
        global $pdo;
        $stmt = $pdo->prepare("
            SELECT file_extension, file_category 
            FROM allowed_mime_types 
            WHERE file_extension = ? AND is_allowed = 1
            LIMIT 1
        ");
        $stmt->execute([$ext]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: false;
    }
}

/**
 * Get allowed MIME types by category
 */
if (!function_exists('getAllowedMimeTypes')) {
    function getAllowedMimeTypes($category = null) {
        global $pdo;
        
        $query = "SELECT mime_type, file_extension, file_category FROM allowed_mime_types WHERE is_allowed = 1";
        $params = [];
        
        if ($category) {
            $query .= " AND file_category = ?";
            $params[] = $category;
        }
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

/**
 * Generate random filename to prevent path traversal and filename attacks
 */
if (!function_exists('generateSecureFilename')) {
    function generateSecureFilename($original_filename) {
        $ext = strtolower(pathinfo($original_filename, PATHINFO_EXTENSION));
        // Use random bytes + timestamp + extension
        $random = bin2hex(random_bytes(8));
        $timestamp = time();
        return "attach_{$timestamp}_{$random}.{$ext}";
    }
}

/**
 * Upload and store file with full security validation
 * Returns array with success status and details
 */
if (!function_exists('uploadItemAttachment')) {
    function uploadItemAttachment($file_array, $item_id, $user_id) {
        global $pdo;
        
        $response = [
            'success' => false,
            'message' => '',
            'attachment_id' => null
        ];

        // Validate item exists
        $stmt = $pdo->prepare("SELECT id FROM items WHERE id = ?");
        $stmt->execute([$item_id]);
        if (!$stmt->fetch()) {
            $response['message'] = 'Item not found';
            return $response;
        }

        // Validate file upload
        if (!isset($file_array['error']) || $file_array['error'] !== UPLOAD_ERR_OK) {
            $response['message'] = 'File upload error: ' . ($file_array['error'] ?? 'unknown');
            return $response;
        }

        if (!isset($file_array['tmp_name']) || !is_uploaded_file($file_array['tmp_name'])) {
            $response['message'] = 'Invalid file upload';
            return $response;
        }

        // Validate file size (max 10MB)
        $max_size = 10 * 1024 * 1024;
        if ($file_array['size'] > $max_size) {
            $response['message'] = 'File size exceeds 10MB limit';
            return $response;
        }

        // Validate extension
        $ext_info = validateFileExtension($file_array['name']);
        if (!$ext_info) {
            $response['message'] = 'File extension not allowed: ' . pathinfo($file_array['name'], PATHINFO_EXTENSION);
            return $response;
        }

        // Validate MIME type
        $mime_info = validateMimeType($file_array['tmp_name'], $ext_info['file_extension']);
        if (!$mime_info) {
            $response['message'] = 'File type not allowed or MIME type mismatch';
            return $response;
        }

        // Generate secure filename
        $secure_filename = generateSecureFilename($file_array['name']);
        $upload_dir = getUploadDirectory();
        $file_path = $upload_dir . $secure_filename;

        // Move file
        if (!move_uploaded_file($file_array['tmp_name'], $file_path)) {
            $response['message'] = 'Failed to move uploaded file';
            return $response;
        }

        // Store in database
        try {
            $relative_path = 'assets/uploads/attachments/' . $secure_filename;
            
            $stmt = $pdo->prepare("
                INSERT INTO item_attachments 
                (item_id, original_filename, stored_filename, file_path, mime_type, file_size, file_type, uploaded_by, uploaded_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $item_id,
                $file_array['name'],
                $secure_filename,
                $relative_path,
                $mime_info['mime_type'],
                $file_array['size'],
                $mime_info['file_category'],
                $user_id
            ]);

            $attachment_id = $pdo->lastInsertId();
            
            $response['success'] = true;
            $response['message'] = 'File uploaded successfully';
            $response['attachment_id'] = $attachment_id;
            
            return $response;
            
        } catch (PDOException $e) {
            // Clean up uploaded file on error
            @unlink($file_path);
            $response['message'] = 'Database error';
            return $response;
        }
    }
}

/**
 * Get attachments for an item
 */
if (!function_exists('getItemAttachments')) {
    function getItemAttachments($item_id, $file_type = null) {
        global $pdo;
        
        $query = "
            SELECT 
                ia.*,
                u.fullname as uploaded_by_name
            FROM item_attachments ia
            LEFT JOIN users u ON ia.uploaded_by = u.id
            WHERE ia.item_id = ? AND ia.is_active = 1
        ";
        
        $params = [$item_id];
        
        if ($file_type) {
            $query .= " AND ia.file_type = ?";
            $params[] = $file_type;
        }
        
        $query .= " ORDER BY ia.uploaded_at DESC";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

/**
 * Get single attachment with authorization check
 */
if (!function_exists('getAttachment')) {
    function getAttachment($attachment_id, $user_id = null) {
        global $pdo;
        $user_id = (int)($user_id ?? 0);
        $role = $_SESSION['role'] ?? null;

        $sql = "
            SELECT ia.*, u.fullname AS uploaded_by_name, i.id AS item_id,
                   i.dept_id AS item_dept_id, owner.department AS owner_department,
                   d.dept_name AS item_department_name
            FROM item_attachments ia
            LEFT JOIN users u ON ia.uploaded_by = u.id
            JOIN items i ON ia.item_id = i.id
            LEFT JOIN departments d ON i.dept_id = d.id
            LEFT JOIN users owner ON owner.id = ?
            WHERE ia.id = ? AND ia.is_active = 1
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user_id, (int)$attachment_id]);
        $attachment = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$attachment) return null;

        if ($role === 'admin') return $attachment;
        if ($user_id > 0 && (int)$attachment['uploaded_by'] === $user_id) return $attachment;
        if ($user_id > 0 && $attachment['item_dept_id'] !== null &&
            ((string)$attachment['item_dept_id'] === (string)($_SESSION['dept_id'] ?? '') ||
             ((string)$attachment['item_department_name'] !== '' && (string)$attachment['item_department_name'] === (string)($attachment['owner_department'] ?? '')))) {
            return $attachment;
        }
        return null;
    }
}

/**
 * Delete attachment with authorization check
 */
if (!function_exists('deleteAttachment')) {
    function deleteAttachment($attachment_id, $user_id, $is_admin = false) {
        global $pdo;
        
        $attachment = getAttachment($attachment_id, $user_id);
        
        if (!$attachment) {
            return ['success' => false, 'message' => 'Attachment not found'];
        }

        // Authorization check: only admin or uploader can delete
        if (!$is_admin && $attachment['uploaded_by'] != $user_id) {
            return ['success' => false, 'message' => 'Unauthorized to delete this attachment'];
        }

        try {
            // Soft delete (mark as inactive)
            $stmt = $pdo->prepare("
                UPDATE item_attachments 
                SET is_active = 0 
                WHERE id = ?
            ");
            $stmt->execute([$attachment_id]);

            // Log access
            logAttachmentAccess($attachment_id, $user_id, 'delete');

            return ['success' => true, 'message' => 'Attachment deleted'];
            
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Database error'];
        }
    }
}

/**
 * Download attachment with authorization and logging
 */
if (!function_exists('downloadAttachment')) {
    function downloadAttachment($attachment_id, $user_id) {
        global $pdo;
        
        $attachment = getAttachment($attachment_id, $user_id);
        
        if (!$attachment) {
            http_response_code(404);
            die('Attachment not found');
        }

        $file_path = __DIR__ . '/../' . ltrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, (string)$attachment['file_path']), DIRECTORY_SEPARATOR);
        
        // Security: prevent path traversal
        $real_path = realpath($file_path);
        if (!$real_path || !file_exists($real_path)) {
            http_response_code(404);
            die('File not found');
        }

        // Verify file is in allowed directory
        $allowed_base = realpath(__DIR__ . '/../assets/uploads/attachments');
        if ($allowed_base === false || ($real_path !== $allowed_base && strpos($real_path, $allowed_base . DIRECTORY_SEPARATOR) !== 0)) {
            http_response_code(403);
            die('Access denied');
        }

        // Log access
        logAttachmentAccess($attachment_id, $user_id, 'download');

        // Send file
        header('Content-Type: ' . $attachment['mime_type']);
        $safeName = preg_replace('/[\r\n"]+/', '_', basename((string)$attachment['original_filename'])) ?: 'download';
        header('Content-Disposition: attachment; filename="' . $safeName . '"');
        header('Content-Length: ' . filesize($real_path));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // Prevent MIME confusion
        header('X-Content-Type-Options: nosniff');
        
        readfile($real_path);
        exit;
    }
}

/**
 * Log attachment access for audit
 */
if (!function_exists('logAttachmentAccess')) {
    function logAttachmentAccess($attachment_id, $user_id, $action = 'view') {
        global $pdo;
        
        try {
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            
            $stmt = $pdo->prepare("
                INSERT INTO attachment_access_logs 
                (attachment_id, accessed_by, action, accessed_at, ip_address)
                VALUES (?, ?, ?, NOW(), ?)
            ");
            
            $stmt->execute([$attachment_id, $user_id, $action, $ip_address]);
        } catch (PDOException $e) {
            error_log('Failed to log attachment access: ' . $e->getMessage());
        }
    }
}

/**
 * Get supported file types for UI display
 */
if (!function_exists('getSupportedFileTypes')) {
    function getSupportedFileTypes() {
        $images = ['jpg', 'jpeg', 'png', 'webp'];
        $documents = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv'];
        
        return [
            'images' => $images,
            'documents' => $documents,
            'all' => array_merge($images, $documents)
        ];
    }
}

/**
 * Get file icon/emoji based on type
 */
if (!function_exists('getFileIcon')) {
    function getFileIcon($filename) {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        $icons = [
            'jpg' => '🖼️',
            'jpeg' => '🖼️',
            'png' => '🖼️',
            'webp' => '🖼️',
            'pdf' => '📄',
            'doc' => '📝',
            'docx' => '📝',
            'xls' => '📊',
            'xlsx' => '📊',
            'csv' => '📊'
        ];
        
        return $icons[$ext] ?? '📎';
    }
}

/**
 * Format file size for display
 */
if (!function_exists('formatFileSize')) {
    function formatFileSize($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
?>
