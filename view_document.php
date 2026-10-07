<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/config.php';
checkRole(['admin']);


$uploadRoot = __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'uploads';
$requestedFile = $_GET['file'] ?? '';

if (isset($_GET['attachment_id'])) {
    $stmt = $pdo->prepare("SELECT original_filename, file_path, mime_type, file_size, file_data FROM request_attachments WHERE id = ? LIMIT 1");
    $stmt->execute([(int)$_GET['attachment_id']]);
    $attachment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$attachment) {
        http_response_code(404);
        exit('Attachment not found.');
    }

    if ($attachment['file_data'] !== null && $attachment['file_data'] !== '') {
        $filename = preg_replace('/[\r\n"]+/', '_', basename((string)$attachment['original_filename'])) ?: 'attachment';
        $inlineTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
        $disposition = in_array($attachment['mime_type'], $inlineTypes, true) ? 'inline' : 'attachment';
        header('Content-Type: ' . $attachment['mime_type']);
        header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"');
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'self';");
        header('Content-Length: ' . (int)$attachment['file_size']);
        header('Cache-Control: private, no-cache, no-store, must-revalidate, max-age=0');
        echo $attachment['file_data'];
        exit;
    }

    $requestedFile = (string)$attachment['file_path'];
}

if ($requestedFile === '') {
    http_response_code(400);
    echo 'Missing file parameter.';
    exit;
}

$normalized = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, trim($requestedFile));
$normalized = ltrim($normalized, DIRECTORY_SEPARATOR);

if (stripos($normalized, 'assets' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR) === 0) {
    $normalized = substr($normalized, strlen('assets' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR));
}

if ($normalized === '' || preg_match('/(?:^|[\\/])\.\.(?:[\\/]|$)/', $normalized)) {
    http_response_code(400);
    echo 'Invalid file path.';
    exit;
}

$absolutePath = realpath($uploadRoot . DIRECTORY_SEPARATOR . $normalized);
if ($absolutePath === false || !is_file($absolutePath)) {
    http_response_code(404);
    echo 'File not found.';
    exit;
}

$rootReal = realpath($uploadRoot);
if ($rootReal === false || ($absolutePath !== $rootReal && strpos($absolutePath, $rootReal . DIRECTORY_SEPARATOR) !== 0)) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $absolutePath) ?: 'application/octet-stream';
finfo_close($finfo);

$allowedMime = [
    'application/pdf',
    'image/jpeg',
    'image/png',
    'image/webp',
    'image/gif',
];

if (!in_array($mimeType, $allowedMime, true)) {
    http_response_code(415);
    echo 'Unsupported file type for preview.';
    exit;
}

$fileSize = filesize($absolutePath);
if ($fileSize === false) {
    http_response_code(500);
    echo 'Unable to read file size.';
    exit;
}

header('Content-Type: ' . $mimeType);
header('Content-Disposition: inline; filename="' . basename($absolutePath) . '"');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'self';");
header('Content-Length: ' . $fileSize);
header('Cache-Control: private, no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

readfile($absolutePath);
exit;
