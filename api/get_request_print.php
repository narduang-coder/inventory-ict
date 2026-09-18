<?php
require_once __DIR__ . '/../includes/config.php';
requireAuthenticated();

if (!isset($_SESSION['user_id']) || !isset($_GET['id'])) {
    http_response_code(403);
    exit('Unauthorized access');
}

$req_id = intval($_GET['id']);
$user_id = $_SESSION['user_id'];

// ດຶງຂໍ້ມູນ Request
$stmt = $pdo->prepare("
    SELECT r.*, u.fullname, u.department 
    FROM requests r 
    JOIN users u ON r.user_id = u.id 
    WHERE r.id = ? AND (r.user_id = ? OR ? = 'admin')
");
$stmt->execute([$req_id, $user_id, $_SESSION['role'] ?? 'user']);
$req = $stmt->fetch();

if (!$req) {
    http_response_code(404);
    exit('Request not found');
}

// ດຶງລາຍການອຸປະກອນ
$stmtItems = $pdo->prepare("
    SELECT ri.*, i.name as item_name, i.unit 
    FROM request_items ri 
    JOIN items i ON ri.item_id = i.id 
    WHERE ri.request_id = ?
");
$stmtItems->execute([$req_id]);
$items = $stmtItems->fetchAll();

// ດຶງຂໍ້ມູນການຕັ້ງຄ່າ
$school_name = 'ລະບົບຈັດການສາງອຸປະກອນ';
try {
    $stmtSet = $pdo->query("SELECT * FROM settings LIMIT 1");
    if ($stmtSet) {
        $setting = $stmtSet->fetch();
        if ($setting && !empty($setting['school_name'])) {
            $school_name = $setting['school_name'];
        }
    }
} catch (Exception $e) {
    // ຂ້າມຖ້າບໍ່ມີ table settings
}
?>
<!DOCTYPE html>
<html lang="lo">
<head>
    <meta charset="UTF-8">
    <title>ໃບເບີກອຸປະກອນ #<?php echo str_pad($req['id'], 5, '0', STR_PAD_LEFT); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Lao:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Noto Sans Lao', sans-serif; padding: 30px; color: #1e293b; background: #fff; }
        .header { text-align: center; margin-bottom: 25px; border-bottom: 2px solid #334155; padding-bottom: 15px; }
        .header h2 { margin: 0; font-size: 20px; text-transform: uppercase; }
        .header p { margin: 5px 0 0; font-size: 13px; color: #64748b; }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px; font-size: 14px; }
        .info-grid p { margin: 4px 0; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 30px; font-size: 14px; }
        th, td { border: 1px solid #cbd5e1; padding: 10px 12px; text-align: left; }
        th { background-color: #f8fafc; font-weight: 600; }
        .signatures { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; text-align: center; margin-top: 50px; font-size: 13px; }
        .sign-line { margin-top: 50px; border-top: 1px dashed #94a3b8; width: 80%; margin-left: auto; margin-right: auto; }
        @media print { body { padding: 0; } }
    </style>
</head>
<body>
    <div class="header">
        <h2><?php echo htmlspecialchars($school_name); ?></h2>
        <p>ໃບເບີກອຸປະກອນ / ໃບຮ້ອງຂໍອຸປະກອນ</p>
    </div>

    <div class="info-grid">
        <div>
            <p><strong>ເລກທີໃບເບີກ:</strong> #REQ-<?php echo str_pad($req['id'], 5, '0', STR_PAD_LEFT); ?></p>
            <p><strong>ຜູ້ຂໍເບີກ:</strong> <?php echo htmlspecialchars($req['fullname']); ?></p>
            <p><strong>ພາກສ່ວນ / ພະແນກ:</strong> <?php echo htmlspecialchars($req['department']); ?></p>
        </div>
        <div>
            <p><strong>ວັນທີຂໍເບີກ:</strong> <?php echo date('d/m/Y', strtotime($req['request_date'])); ?></p>
            <p><strong>ຈຸດປະສົງ:</strong> <?php echo htmlspecialchars($req['purpose']); ?></p>
            <p><strong>ສະຖານະ:</strong> <?php echo strtoupper($req['status']); ?></p>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 50px; text-align: center;">ລຳດັບ</th>
                <th>ລາຍການອຸປະກອນ</th>
                <th style="width: 100px; text-align: center;">ຈຳນວນ</th>
                <th style="width: 100px; text-align: center;">ໜ່ວຍນັບ</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $idx => $item): ?>
                <tr>
                    <td style="text-align: center;"><?php echo $idx + 1; ?></td>
                    <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                    <td style="text-align: center; font-weight: bold;"><?php echo number_format($item['quantity']); ?></td>
                    <td style="text-align: center;"><?php echo htmlspecialchars($item['unit']); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="signatures">
        <div>
            <p><strong>ຜູ້ຂໍເບີກ</strong></p>
            <div class="sign-line"></div>
            <p style="margin-top: 5px;"><?php echo htmlspecialchars($req['fullname']); ?></p>
        </div>
        <div>
            <p><strong>ຜູ້ກວດສອບ / ຄຸ້ມຄອງສາງ</strong></p>
            <div class="sign-line"></div>
        </div>
        <div>
            <p><strong>ຜູ້ອະນຸມັດ</strong></p>
            <div class="sign-line"></div>
        </div>
    </div>
</body>
</html>
