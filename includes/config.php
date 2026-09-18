<?php
// Central application bootstrap.
$envFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
if (is_file($envFile) && is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $value = trim($value, " \t\n\r\0\x0B\"'");
        if ($key !== '' && getenv($key) === false) putenv($key . '=' . $value);
    }
}

// Baseline browser security headers.
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('X-Permitted-Cross-Domain-Policies: none');
    header('Cross-Origin-Opener-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'self'; form-action 'self'; script-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com https://cdnjs.cloudflare.com https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data: blob: https://api.qrserver.com; connect-src 'self';");
    if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? null) == 443) || strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')[0] ?? '')) === 'https') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

if (session_status() === PHP_SESSION_NONE) {
    $forwardedProto = strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')[0] ?? ''));
    $secure = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? null) == 443) || $forwardedProto === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Asia/Bangkok');

define('BASE_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('INCLUDES_PATH', BASE_PATH . 'includes' . DIRECTORY_SEPARATOR);
define('ADMIN_PATH', BASE_PATH . 'admin' . DIRECTORY_SEPARATOR);
define('USER_PATH', BASE_PATH . 'user' . DIRECTORY_SEPARATOR);
define('API_PATH', BASE_PATH . 'api' . DIRECTORY_SEPARATOR);
define('ASSETS_PATH', BASE_PATH . 'assets' . DIRECTORY_SEPARATOR);
define('UPLOAD_PATH', ASSETS_PATH . 'uploads' . DIRECTORY_SEPARATOR);
define('BACKUP_PATH', dirname(BASE_PATH) . DIRECTORY_SEPARATOR . 'edl-inven-backups' . DIRECTORY_SEPARATOR);
define('LOG_PATH', dirname(BASE_PATH) . DIRECTORY_SEPARATOR . 'edl-inven-logs' . DIRECTORY_SEPARATOR);

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: '');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: '');
define('APP_ENV', strtolower(getenv('APP_ENV') ?: 'production'));
define('APP_DEBUG', filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN));

$configuredBaseUrl = getenv('APP_BASE_URL');
if ($configuredBaseUrl === false || trim($configuredBaseUrl) === '') {
    $configuredBaseUrl = (APP_ENV === 'local' || APP_ENV === 'development') ? '/edl-inven' : '';
}
define('APP_BASE_URL', rtrim('/' . ltrim(trim($configuredBaseUrl), '/'), '/'));


if (APP_ENV === 'production') {
    // Never expose verbose errors in production, regardless of APP_DEBUG.
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
} elseif (APP_ENV === 'local' || APP_ENV === 'development') {
    // XAMPP/local development may opt into visible errors for debugging.
    ini_set('display_errors', APP_DEBUG ? '1' : '0');
    ini_set('display_startup_errors', APP_DEBUG ? '1' : '0');
    ini_set('log_errors', '1');
}

// Empty DB passwords are allowed ONLY for local/development XAMPP installs.
// Production always requires a non-empty dedicated DB password.
$dbConfigMissing = (DB_USER === '' || DB_NAME === '' || (APP_ENV === 'production' && DB_PASS === ''));
if ($dbConfigMissing) {
    error_log('Missing database configuration.');
    http_response_code(500);
    exit('Application configuration error');
}

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
    );
} catch (PDOException $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Database connection failed');
}

require_once INCLUDES_PATH . 'functions.php';
require_once INCLUDES_PATH . 'auth.php';
require_once INCLUDES_PATH . 'inventory.php';
require_once INCLUDES_PATH . 'sct_workflow.php';
