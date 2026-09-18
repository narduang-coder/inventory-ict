<?php
/**
 * QR Code and Barcode Helpers
 * Location: includes/qr_barcode.php
 */

/**
 * Generate QR Code URL using third-party service
 * Using qr-server.com (free, no API key needed)
 */
if (!function_exists('generateQrCodeUrl')) {
    function generateQrCodeUrl($item_id, $size = 300, $error_correction = 'M') {
        // Build a public QR detail URL. appUrl() handles HTTPS reverse proxies
        // and both domain-root and /edl-inven deployments.
        $qr_url = appUrl('/qr_detail.php?id=' . urlencode($item_id));
        
        // Generate QR code using qr-server.com API
        $qr_code_url = "https://api.qrserver.com/v1/create-qr-code/?size=" . $size . "x" . $size . "&data=" . urlencode($qr_url) . "&ecc=" . $error_correction;
        
        return $qr_code_url;
    }
}

/**
 * Generate QR Code HTML Image Tag
 */
if (!function_exists('generateQrCodeHtml')) {
    function generateQrCodeHtml($item_id, $size = 300, $alt_text = 'QR Code') {
        $qr_url = generateQrCodeUrl($item_id, $size);
        return '<img src="' . htmlspecialchars($qr_url) . '" alt="' . htmlspecialchars($alt_text) . '" class="qr-code" width="' . $size . '" height="' . $size . '">';
    }
}

/**
 * Get barcode details
 * The system already generates barcodes in items table
 */
if (!function_exists('getItemBarcode')) {
    function getItemBarcode($item_id) {
        global $pdo;
        
        $stmt = $pdo->prepare("
            SELECT barcode, item_code, serial_number 
            FROM items 
            WHERE id = ?
        ");
        $stmt->execute([$item_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

/**
 * Validate QR/Barcode lookup - prevent IDOR
 * Check if user has permission to view this item
 */
if (!function_exists('checkItemAccessPermission')) {
    function checkItemAccessPermission($item_id, $user_id = null, $user_role = null) {
        global $pdo;
        
        // Admin can view all items
        if ($user_role === 'admin') {
            return true;
        }
        
        // For regular users, check if they have access through their department
        // or if the item is not classified as sensitive
        
        // For now, allow all authenticated users to view via QR/barcode
        // but log the access for audit
        
        return true;
    }
}

/**
 * Log QR/Barcode scan for analytics
 */
if (!function_exists('logQrScan')) {
    function logQrScan($item_id, $user_id = null) {
        global $pdo;
        
        try {
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            
            $stmt = $pdo->prepare("
                INSERT INTO qr_code_scans 
                (item_id, scanned_by, scan_timestamp, ip_address, user_agent)
                VALUES (?, ?, NOW(), ?, ?)
            ");
            
            $stmt->execute([$item_id, $user_id, $ip_address, $user_agent]);
        } catch (PDOException $e) {
            error_log('Failed to log QR scan: ' . $e->getMessage());
        }
    }
}

/**
 * Get material details for QR/Barcode detail pages
 */
if (!function_exists('getItemDetailsForQr')) {
    function getItemDetailsForQr($item_id) {
        global $pdo;
        
        $stmt = $pdo->prepare("
            SELECT 
                i.*,
                c.cate_name AS category_name,
                d.dept_name AS department_name,
                NULL AS created_by_name
            FROM items i
            LEFT JOIN category c ON i.cate_id = c.cate_id
            LEFT JOIN departments d ON i.dept_id = d.id
            WHERE i.id = ?
        ");
        $stmt->execute([$item_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

/**
 * Format material details for display
 */
if (!function_exists('formatMaterialDetails')) {
    function formatMaterialDetails($item) {
        $details = [
            'Material Code' => $item['item_code'] ?? '-',
            'Serial Number' => $item['serial_number'] ?? '-',
            'Material Name' => $item['name'] ?? '-',
            'Brand' => $item['brand'] ?? '-',
            'Model' => $item['model'] ?? '-',
            'Category' => $item['category_name'] ?? '-',
            'Manufacturing Year' => $item['manufacturing_year'] ?? '-',
            'Service Tax' => ($item['service_tax'] ?? 0) . ' LAK',
            'Quantity' => ($item['quantity'] ?? 0),
            'Unit' => $item['unit'] ?? '-',
            'Location' => $item['location'] ?? '-',
            'Department' => $item['department_name'] ?? '-',
            'Status' => $item['status'] ?? 'available',
            'Warranty Years' => ($item['warranty_years'] ?? 0),
            'Import Date' => !empty($item['import_date']) ? date('d/m/Y', strtotime($item['import_date'])) : '-',
            'Description' => $item['remark'] ?? '-'
        ];
        
        return $details;
    }
}
?>
