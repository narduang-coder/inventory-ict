<?php
require_once __DIR__ . '/../includes/config.php';
checkRole(['admin']);

$file = $_GET['file'] ?? '';

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
        header('Content-Length: ' . (int)$attachment['file_size']);
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'self';");
        echo $attachment['file_data'];
        exit;
    }

    $file = $attachment['file_path'];
}

if (empty($file)) {
    die('<div style="text-align:center; padding:50px; font-family:sans-serif;">ບໍ່ພົບຟາຍເອກະສານ</div>');
}

$filePath = assetPath($file);

if (!file_exists($filePath)) {
    die('<div style="text-align:center; padding:50px; font-family:sans-serif;">ບໍ່ພົບຟາຍໃນລະບົບ (File Not Found)</div>');
}

$ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

// ຟາຍທີ່ສາມາດສະແດງໃນ iframe ໄດ້ໂດຍກົງ
$inlineTypes = [
    'pdf'  => 'application/pdf',
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'txt'  => 'text/plain; charset=utf-8'
];

if (isset($inlineTypes[$ext])) {
    header('Content-Type: ' . $inlineTypes[$ext]);
    header('Content-Disposition: inline; filename="' . basename($filePath) . '"');
    header('Content-Length: ' . filesize($filePath));
    readfile($filePath);
    exit();
}
?>
<!DOCTYPE html>
<html lang="lo">
<head>
    <meta charset="UTF-8">
    <title>Preview Document</title>
    <style>
        body { font-family: sans-serif; text-align: center; padding: 40px; background-color: #f9fafb; color: #374151; }
        .card { background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); display: inline-block; max-width: 400px; }
        .btn { background-color: #2563eb; color: white; padding: 10px 20px; border-radius: 8px; text-decoration: none; font-weight: bold; display: inline-block; margin-top: 15px; }
    </style>
</head>
<body>
    <div class="card">
        <h3>📄 ຟາຍເອກະສານ (<?php echo strtoupper($ext); ?>)</h3>
        <p>ຟາຍປະເພດນີ້ບໍ່ສາມາດສະແດງຕົວຢ່າງໃນບຣາວເຊີໄດ້ໂດຍກົງ. ກະລຸນາດາວໂຫຼດເພື່ອເບິ່ງໃນເຄື່ອງ.</p>
        <a href="<?php echo htmlspecialchars(assetUrl($file)); ?>" download class="btn">
            📥 ດາວໂຫຼດຟາຍ
        </a>
    </div>
</body>
</html>