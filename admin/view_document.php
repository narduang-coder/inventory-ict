<?php
require_once __DIR__ . '/../includes/config.php';
checkRole(['admin']);

$file = $_GET['file'] ?? '';

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