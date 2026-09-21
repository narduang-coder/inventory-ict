<?php
function escapeHtml($string) {
    return ($string === null || $string === '') ? '' : htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

function formatNumber($number) {
    return number_format((float) $number, 0, '.', ',');
}

function formatCurrency($amount) {
    return '₭' . number_format((float) $amount, 2, '.', ',');
}

function formatDate($date) {
    return empty($date) ? '-' : date('d/m/Y', strtotime($date));
}

function formatDateTime($datetime) {
    return empty($datetime) ? '-' : date('d/m/Y H:i', strtotime($datetime));
}

function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && !empty($token) && hash_equals($_SESSION['csrf_token'], $token);
}

function showAlert($message, $type = 'success') {
    $_SESSION['alert'] = ['message' => $message, 'type' => $type];
}

function getAlert() {
    if (!isset($_SESSION['alert'])) {
        return null;
    }
    $alert = $_SESSION['alert'];
    unset($_SESSION['alert']);
    return $alert;
}

function logActivity($message) {
    if (!is_dir(LOG_PATH)) {
        mkdir(LOG_PATH, 0750, true);
    }
    file_put_contents(LOG_PATH . 'activity.log', date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function getMonthlyStats($pdo, $months = 6) {
    try {
        $stmt = $pdo->prepare("SELECT DATE_FORMAT(created_at, '%Y-%m') AS month, COUNT(*) AS total,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected
            FROM requests WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? MONTH)
            GROUP BY DATE_FORMAT(created_at, '%Y-%m') ORDER BY month ASC");
        $stmt->execute([(int) $months]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('getMonthlyStats error: ' . $e->getMessage());
        return [];
    }
}

function getPopularItems($pdo, $limit = 5) {
    try {
        $stmt = $pdo->prepare("SELECT i.name, i.item_code, SUM(ri.quantity) AS total_quantity,
            COUNT(DISTINCT r.id) AS request_count FROM request_items ri
            JOIN items i ON ri.item_id = i.id JOIN requests r ON ri.request_id = r.id
            WHERE r.status = 'approved' GROUP BY i.id ORDER BY total_quantity DESC LIMIT ?");
        $stmt->execute([(int) $limit]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('getPopularItems error: ' . $e->getMessage());
        return [];
    }
}

function getTopUsers($pdo, $limit = 5) {
    try {
        $stmt = $pdo->prepare("SELECT u.fullname, u.username, COUNT(r.id) AS request_count,
            SUM(ri.quantity) AS total_items FROM users u JOIN requests r ON u.id = r.user_id
            JOIN request_items ri ON r.id = ri.request_id WHERE r.status = 'approved'
            GROUP BY u.id ORDER BY request_count DESC LIMIT ?");
        $stmt->execute([(int) $limit]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('getTopUsers error: ' . $e->getMessage());
        return [];
    }
}

function getCategoryValue($pdo) {
    try {
        return $pdo->query("SELECT c.cate_name, SUM(i.price_per_unit * i.quantity) AS total_value,
            COUNT(i.id) AS item_count FROM items i LEFT JOIN category c ON i.cate_id = c.cate_id
            GROUP BY c.cate_id ORDER BY total_value DESC")->fetchAll();
    } catch (PDOException $e) {
        error_log('getCategoryValue error: ' . $e->getMessage());
        return [];
    }
}

function checkLowStock($pdo) {
    try {
        return $pdo->query('SELECT * FROM items WHERE quantity <= min_quantity ORDER BY CASE WHEN min_quantity > 0 THEN (quantity / min_quantity) ELSE 0 END ASC')->fetchAll();
    } catch (PDOException $e) {
        error_log('checkLowStock error: ' . $e->getMessage());
        return [];
    }
}

function getLowStockCount($pdo) {
    try {
        return (int) $pdo->query('SELECT COUNT(*) FROM items WHERE quantity <= min_quantity')->fetchColumn();
    } catch (PDOException $e) {
        error_log('getLowStockCount error: ' . $e->getMessage());
        return 0;
    }
}

function normalizeLogoAssetPath($path) {
    if (empty($path)) {
        return '';
    }

    $normalized = ltrim(str_replace('\\', '/', (string)$path), '/');
    if ($normalized === '') {
        return '';
    }

    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $normalized) || str_contains($normalized, '..')) {
        return '';
    }

    $basename = basename($normalized);
    if ($basename === '' || $basename === '.' || $basename === '..') {
        return '';
    }

    if (str_contains($normalized, 'uploads/') || str_contains($normalized, 'logos/') || preg_match('#^logo_[^/]+$#i', $basename) || preg_match('#\.(jpe?g|png|gif|webp)$#i', $basename)) {
        return 'assets/uploads/logos/' . $basename;
    }

    return $normalized;
}

function assetPath($path) {
    $normalized = normalizeLogoAssetPath($path);
    if ($normalized === '') {
        $normalized = ltrim(str_replace('\\', '/', (string) $path), '/');
        if ($normalized === '') {
            return '';
        }
        if (preg_match('/^[A-Za-z]:/', $normalized)) return '';
        if (str_contains($normalized, '../') || str_contains($normalized, '..\\')) return '';
    }

    if ($normalized === '') return '';
    if (preg_match('/^[A-Za-z]:/', $normalized)) return '';
    if (str_contains($normalized, '../') || str_contains($normalized, '..\\')) return '';

    $candidate = BASE_PATH . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
    $base = realpath(BASE_PATH);
    $dir = realpath(dirname($candidate));
    if ($base === false || $dir === false || !str_starts_with($dir, $base . DIRECTORY_SEPARATOR)) return '';
    return $candidate;
}

function assetUrl($path) {
    $normalized = normalizeLogoAssetPath($path);
    if ($normalized === '') {
        return '';
    }

    return rtrim(APP_BASE_URL, '/') . '/' . $normalized;
}

function safeErrorMessage(Throwable $e): string
{
    error_log($e->getMessage() . "\n" . $e->getTraceAsString());
    return 'ເກີດຂໍ້ຜິດພາດ ກະລຸນາລອງໃໝ່';
}

function generateReferenceCode(string $prefix): string
{
    return strtoupper($prefix) . '-' . date('Ym') . '-' . strtoupper(bin2hex(random_bytes(5)));
}


if (!function_exists('appUrl')) {
    /** Build an absolute application URL, proxy-aware and deployment-path aware. */
    function appUrl(string $path = ''): string {
        $forwardedProto = strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')[0] ?? ''));
        $https = $forwardedProto === 'https'
            || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
        $scheme = $https ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base = rtrim(APP_BASE_URL, '/');
        $path = '/' . ltrim($path, '/');
        return $scheme . '://' . $host . $base . $path;
    }
}
