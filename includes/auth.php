<?php
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function checkRole($allowed_roles = ['admin', 'user']) {
    if (!isLoggedIn()) {
        header('Location: ' . APP_BASE_URL . '/index.php');
        exit();
    }
    if (!in_array($_SESSION['role'] ?? null, $allowed_roles, true)) {
        $destination = ($_SESSION['role'] ?? null) === 'admin'
            ? APP_BASE_URL . '/admin.php?admin=dashboard'
            : APP_BASE_URL . '/user.php?user=dashboard';
        header('Location: ' . $destination);
        exit();
    }
}

function checkApiAuth() {
    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit();
    }
}

function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function isUser() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'user';
}

function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

function getCurrentUserRole() {
    return $_SESSION['role'] ?? null;
}

function getCurrentDepartment() {
    return $_SESSION['department'] ?? null;
}

function requireAdmin(): void {
    checkRole(['admin']);
}

function requireAuthenticated(): void {
    checkRole(['admin', 'user']);
}

function requirePostCsrf(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Invalid request');
    }
}

function enforceLoginRateLimit(string $username): bool {
    $key = hash('sha256', strtolower(trim($username)) . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $dir = LOG_PATH . 'security' . DIRECTORY_SEPARATOR;
    if (!is_dir($dir)) { @mkdir($dir, 0750, true); }
    $file = $dir . $key . '.json';
    $now = time();
    $window = 900; // 15 minutes
    $maxAttempts = 8;
    $data = ['attempts' => [], 'blocked_until' => 0];
    if (is_file($file)) {
        $decoded = json_decode((string)@file_get_contents($file), true);
        if (is_array($decoded)) $data = array_merge($data, $decoded);
    }
    $data['attempts'] = array_values(array_filter((array)$data['attempts'], static fn($t) => is_int($t) && $t >= $now - $window));
    if ((int)$data['blocked_until'] > $now) return false;
    return count($data['attempts']) < $maxAttempts;
}

function recordFailedLogin(string $username): void {
    $key = hash('sha256', strtolower(trim($username)) . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $dir = LOG_PATH . 'security' . DIRECTORY_SEPARATOR;
    if (!is_dir($dir)) { @mkdir($dir, 0750, true); }
    $file = $dir . $key . '.json';
    $now = time();
    $data = ['attempts' => [], 'blocked_until' => 0];
    if (is_file($file)) {
        $decoded = json_decode((string)@file_get_contents($file), true);
        if (is_array($decoded)) $data = array_merge($data, $decoded);
    }
    $data['attempts'] = array_values(array_filter((array)$data['attempts'], static fn($t) => is_int($t) && $t >= $now - 900));
    $data['attempts'][] = $now;
    if (count($data['attempts']) >= 8) $data['blocked_until'] = $now + 900;
    @file_put_contents($file, json_encode($data, JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function clearFailedLogins(string $username): void {
    $key = hash('sha256', strtolower(trim($username)) . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $file = LOG_PATH . 'security' . DIRECTORY_SEPARATOR . $key . '.json';
    if (is_file($file)) @unlink($file);
}
