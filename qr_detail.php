<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/qr_barcode.php';

$itemId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$itemId) {
    http_response_code(400);
    $pageError = 'ລະຫັດອຸປະກອນບໍ່ຖືກຕ້ອງ';
} else {
    $item = getItemDetailsForQr($itemId);
    if (!$item) {
        http_response_code(404);
        $pageError = 'ບໍ່ພົບອຸປະກອນ';
    } else {
        if (isset($_GET['image']) && $_GET['image'] === '1') {
            if (empty($item['image_data']) || empty($item['image_mime'])) {
                http_response_code(404);
                exit('Image not found');
            }
            header('Content-Type: ' . $item['image_mime']);
            header('Cache-Control: public, max-age=3600');
            echo $item['image_data'];
            exit;
        }
        if (function_exists('logQrScan')) {
            logQrScan($itemId, $_SESSION['user_id'] ?? null);
        }
    }
}

$school = null;
try {
    $school = $pdo->query('SELECT school_name, logo_path FROM settings LIMIT 1')->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('QR detail settings lookup failed: ' . $e->getMessage());
}

function qrDetailValue(array $item, string $key, string $fallback = '-'): string
{
    $value = trim((string)($item[$key] ?? ''));
    return $value !== '' ? $value : $fallback;
}
?>
<!DOCTYPE html>
<html lang="lo">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($item) ? htmlspecialchars(qrDetailValue($item, 'name')) : 'QR Detail'; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Lao:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Noto Sans Lao', sans-serif; background: #eef2f7; }
    </style>
</head>
<body class="min-h-screen p-4 sm:p-8">
    <main class="mx-auto max-w-2xl">
        <header class="mb-6 flex items-center gap-3">
            <?php $logoAsset = resolveLogoAssetPath($school['logo_path'] ?? ''); ?>
            <?php if ($logoAsset !== ''): ?>
                <img src="<?php echo htmlspecialchars(assetUrl($logoAsset)); ?>" alt="Logo" class="h-12 w-12 rounded-xl object-contain bg-white p-1 shadow-sm">
            <?php endif; ?>
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600">QR Inventory Detail</p>
                <h1 class="text-xl font-bold text-slate-800"><?php echo htmlspecialchars($school['school_name'] ?? 'ລະບົບຄຸ້ມຄອງສາງ ICT'); ?></h1>
            </div>
        </header>

        <?php if (isset($pageError)): ?>
            <section class="rounded-2xl border border-rose-200 bg-white p-8 text-center shadow-sm">
                <i class="fas fa-circle-exclamation mb-3 text-4xl text-rose-500"></i>
                <h2 class="text-lg font-bold text-slate-800"><?php echo htmlspecialchars($pageError); ?></h2>
            </section>
        <?php else: ?>
            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="bg-indigo-600 px-6 py-5 text-white">
                    <p class="text-sm text-indigo-100">ລາຍລະອຽດອຸປະກອນ</p>
                    <h2 class="mt-1 text-2xl font-bold"><?php echo htmlspecialchars(qrDetailValue($item, 'name')); ?></h2>
                </div>

                <?php if (!empty($item['image_data'])): ?>
                    <div class="flex justify-center border-b border-slate-100 bg-slate-50 p-5">
                        <img src="<?php echo htmlspecialchars(appUrl('/qr_detail.php?id=' . (int)$item['id'] . '&image=1')); ?>" alt="<?php echo htmlspecialchars(qrDetailValue($item, 'name')); ?>" class="max-h-64 max-w-full rounded-xl object-contain shadow-sm">
                    </div>
                <?php endif; ?>

                <dl class="grid grid-cols-1 divide-y divide-slate-100 sm:grid-cols-2 sm:divide-x sm:divide-y-0">
                    <?php
                    $details = [
                        'item_code' => 'ລະຫັດອຸປະກອນ',
                        'barcode' => 'Barcode',
                        'serial_number' => 'Serial Number',
                        'category_name' => 'ໝວດໝູ່',
                        'brand' => 'ຍີ່ຫໍ້',
                        'model' => 'ລຸ້ນ',
                        'quantity' => 'ຈຳນວນຄົງເຫຼືອ',
                        'unit' => 'ຫົວໜ່ວຍ',
                        'department_name' => 'ພະແນກ',
                        'import_date' => 'ວັນທີນຳເຂົ້າ',
                        'origin' => 'ທີ່ມາ',
                        'remark' => 'ໝາຍເຫດ',
                    ];
                    foreach ($details as $key => $label):
                        $value = qrDetailValue($item, $key);
                        if ($key === 'import_date' && $value !== '-') {
                            $value = date('d/m/Y', strtotime($value));
                        }
                    ?>
                        <div class="p-4">
                            <dt class="text-xs font-semibold text-slate-400"><?php echo htmlspecialchars($label); ?></dt>
                            <dd class="mt-1 break-words font-semibold text-slate-700"><?php echo htmlspecialchars($value); ?></dd>
                        </div>
                    <?php endforeach; ?>
                </dl>
            </section>
            <p class="mt-4 text-center text-xs text-slate-400">ຂໍ້ມູນຈາກລະບົບຄຸ້ມຄອງສາງ ICT</p>
        <?php endif; ?>
    </main>
</body>
</html>
