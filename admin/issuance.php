<?php
require_once __DIR__ . '/../includes/config.php';
checkRole(['admin']);

/**
 * Item Issuance & Transfer Management Module
 */

$current_user_id = $_SESSION['user_id'] ?? 1;

// ------------------------------------------------------------
// 1. HANDLER: ເບີກອຸປະກອນ (Manual Issue)
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_create_issuance'])) {
    requirePostCsrf();
    $item_id     = (int)$_POST['item_id'];
    $asset_code  = trim($_POST['asset_code'] ?? ''); 
    $issued_to   = trim($_POST['issued_to'] ?? '');
    $department  = trim($_POST['department'] ?? '');
    $qty         = (int)($_POST['quantity'] ?? 1);
    $issue_date  = $_POST['issue_date'] ?? date('Y-m-d');
    $return_date = !empty($_POST['expected_return_date']) ? $_POST['expected_return_date'] : null;
    $remark      = trim($_POST['remark'] ?? '');

    if ($item_id > 0 && !empty($issued_to) && $qty > 0) {
        try {
            $pdo->beginTransaction();

            $stCheck = $pdo->prepare("SELECT name, quantity, dept_id FROM items WHERE id = ?");
            $stCheck->execute([$item_id]);
            $item = $stCheck->fetch(PDO::FETCH_ASSOC);

            if (!$item || $item['quantity'] < $qty) {
                $pdo->rollBack();
                echo "<script>Swal.fire('ຜິດພາດ!', 'ຈຳນວນອຸປະກອນໃນຄັງບໍ່ພໍ! (ມີຢູ່: ".($item['quantity'] ?? 0).")', 'error');</script>";
            } else {
                $issue_code = 'ISS-' . date('Ym') . '-' . sprintf("%04d", rand(1, 9999));

                $stIss = $pdo->prepare("
                    INSERT INTO item_issuances 
                    (issue_code, asset_code, item_id, issued_to, department, quantity, issue_date, expected_return_date, status, remark, created_by, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'issued', ?, ?, NOW())
                ");
                $stIss->execute([$issue_code, $asset_code, $item_id, $issued_to, $department, $qty, $issue_date, $return_date, $remark, $current_user_id]);

                $stUpdate = $pdo->prepare("UPDATE items SET quantity = quantity - ? WHERE id = ?");
                $stUpdate->execute([$qty, $item_id]);

                $stMove = $pdo->prepare("
                    INSERT INTO stock_movements 
                    (item_id, movement_type, quantity, reference_no, note, created_by, created_at)
                    VALUES (?, 'issue', ?, ?, ?, ?, NOW())
                ");
                $note_text = "ເບີກໃຫ້: {$issued_to} (ພະແນກ: {$department})" . ($asset_code ? " [ລະຫັດ ຊຄທ
                : {$asset_code}]" : "") . ($remark ? " - {$remark}" : "");
                $stMove->execute([$item_id, $qty, $issue_code, $note_text, $current_user_id]);

                $pdo->commit();
                echo "<script>
                    Swal.fire('ສຳເລັດ!', 'ເບີກອຸປະກອນສຳເລັດ', 'success').then(() => {
                        window.location.href = '?admin=issuance';
                    });
                </script>";
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log($e->getMessage());
            echo "<script>Swal.fire('Error!', 'ບໍ່ສາມາດດຳເນີນການໄດ້', 'error');</script>";
        }
    }
}

// ------------------------------------------------------------
// 2. HANDLER: ຍົກຍ້າຍອຸປະກອນລະຫວ່າງພະແນກ
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_transfer_item'])) {
    requirePostCsrf();
    $issuance_id     = (int)($_POST['issuance_id'] ?? 0);
    $item_id         = (int)$_POST['item_id'];
    $from_department = trim($_POST['from_department'] ?? '');
    $to_dept_id      = (int)($_POST['to_dept_id'] ?? 0);
    $qty             = (int)($_POST['transfer_quantity'] ?? 1);
    $remark          = trim($_POST['transfer_remark'] ?? '');

    $stDeptName = $pdo->prepare("SELECT dept_name FROM departments WHERE id = ?");
    $stDeptName->execute([$to_dept_id]);
    $toDept = $stDeptName->fetch(PDO::FETCH_ASSOC);
    $to_department = $toDept['dept_name'] ?? '';

    if ($to_dept_id > 0 && !empty($to_department) && $qty > 0) {
        try {
            $pdo->beginTransaction();

            $transfer_code = 'TRF-' . date('Ym') . '-' . sprintf("%04d", rand(1, 9999));

            $stMove = $pdo->prepare("
                INSERT INTO stock_movements 
                (item_id, movement_type, quantity, reference_no, note, created_by, created_at)
                VALUES (?, 'transfer', ?, ?, ?, ?, NOW())
            ");
            $note_text = "ຍົກຍ້າຍຈາກພະແນກ: [{$from_department}] ➔ ໄປພະແນກ: [{$to_department}]" . ($remark ? " | ໝາຍເຫດ: {$remark}" : "");
            $stMove->execute([$item_id, $qty, $transfer_code, $note_text, $current_user_id]);

            if ($issuance_id > 0) {
                $stUpIss = $pdo->prepare("
                    UPDATE item_issuances 
                    SET department = ?, status = 'transferred', remark = CONCAT(IFNULL(remark, ''), ' [ຍ້າຍໄປ: ', ?, ']') 
                    WHERE id = ?
                ");
                $stUpIss->execute([$to_department, $to_department, $issuance_id]);
            }

            $stUpItem = $pdo->prepare("UPDATE items SET dept_id = ? WHERE id = ?");
            $stUpItem->execute([$to_dept_id, $item_id]);

            $pdo->commit();
            echo "<script>
                Swal.fire('ສຳເລັດ!', 'ຍົກຍ້າຍອຸປະກອນ ແລະ ບັນທຶກປະຫວັດຮຽບຮ້ອຍແລ້ວ', 'success').then(() => {
                    window.location.href = '?admin=issuance';
                });
            </script>";
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log($e->getMessage());
            echo "<script>Swal.fire('Error!', 'ບໍ່ສາມາດດຳເນີນການໄດ້', 'error');</script>";
        }
    }
}

// ------------------------------------------------------------
// 3. FETCH DATA: ດຶງຂໍ້ມູນ Issuances ແລະ ດຶງ asset_code ຈາກ department_assets
// (ປັບປຸງ COLLATE ເພື່ອປ້ອງກັນ Illegal mix of collations error)
// ------------------------------------------------------------
$search = trim($_GET['search'] ?? '');
$categoryFilter = (int)($_GET['category'] ?? 0);
$params = [];

$sqlIssuances = "
    SELECT i.*, 
           itm.name as item_name, 
           itm.item_code, 
           itm.serial_number,
           itm.unit,
           itm.quantity as stock_qty,
           c.cate_name as category_name,
           u.fullname as approver_name,
           COALESCE(da.dept_asset_code, i.asset_code) AS display_asset_code
    FROM item_issuances i 
    LEFT JOIN items itm ON i.item_id = itm.id 
    LEFT JOIN category c ON itm.cate_id = c.cate_id
    LEFT JOIN users u ON i.created_by = u.id
    LEFT JOIN (
        SELECT 
            item_id, 
            department,
            GROUP_CONCAT(DISTINCT asset_code SEPARATOR ', ') AS dept_asset_code
        FROM department_assets
        GROUP BY item_id, department
    ) da ON i.item_id = da.item_id 
        AND (i.department COLLATE utf8mb4_unicode_ci) = (da.department COLLATE utf8mb4_unicode_ci)
";

if (!empty($search)) {
    $sqlIssuances .= " WHERE (i.issue_code COLLATE utf8mb4_unicode_ci LIKE ? 
                        OR i.asset_code COLLATE utf8mb4_unicode_ci LIKE ? 
                        OR da.dept_asset_code COLLATE utf8mb4_unicode_ci LIKE ?
                        OR itm.name COLLATE utf8mb4_unicode_ci LIKE ? 
                        OR itm.item_code COLLATE utf8mb4_unicode_ci LIKE ? 
                        OR itm.serial_number COLLATE utf8mb4_unicode_ci LIKE ? 
                        OR i.issued_to COLLATE utf8mb4_unicode_ci LIKE ? 
                        OR i.department COLLATE utf8mb4_unicode_ci LIKE ? 
                        OR u.fullname COLLATE utf8mb4_unicode_ci LIKE ?) ";
    $searchParam = "%{$search}%";
    $params = array_fill(0, 9, $searchParam);
}

if ($categoryFilter > 0) {
    $sqlIssuances .= empty($search) ? " WHERE itm.cate_id = ? " : " AND itm.cate_id = ? ";
    $params[] = $categoryFilter;
}

$sqlIssuances .= " ORDER BY i.id DESC";

$stmt = $pdo->prepare($sqlIssuances);
$stmt->execute($params);
$issuances = $stmt->fetchAll(PDO::FETCH_ASSOC);

$allItems = $pdo->query("SELECT i.id, i.name, i.item_code, i.serial_number, i.barcode, i.brand, i.model, i.quantity, i.unit, i.cate_id, c.cate_name
                         FROM items i
                         LEFT JOIN category c ON i.cate_id = c.cate_id
                         ORDER BY i.name ASC")->fetchAll(PDO::FETCH_ASSOC);
$itemCategories = $pdo->query("SELECT cate_id, cate_name FROM category ORDER BY cate_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$allDepartments = $pdo->query("SELECT id, dept_name FROM departments ORDER BY dept_name ASC")->fetchAll(PDO::FETCH_ASSOC);

if (isset($_GET['export']) && in_array($_GET['export'] ?? '', ['csv', 'excel', 'pdf'], true)) {
    $exportType = $_GET['export'];
    $token = $_GET['token'] ?? '';
    if (!verifyCSRFToken($token)) {
        http_response_code(403);
        exit('Unauthorized access');
    }

    $exportSql = $sqlIssuances;
    $exportStmt = $pdo->prepare($exportSql);
    $exportStmt->execute($params);
    $rows = $exportStmt->fetchAll(PDO::FETCH_ASSOC);

    $filename = 'issuance_export_' . date('Y-m-d');

    if ($exportType === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename . '.csv');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, ['Issue Code', 'Asset Code', 'Item Name', 'Category', 'Item Code', 'Serial Number', 'Issued To', 'Department', 'Issue Date', 'Expected Return Date', 'Status', 'Quantity', 'Unit', 'Created By', 'Remark']);

        foreach ($rows as $row) {
            fputcsv($output, [
                $row['issue_code'] ?? '',
                $row['display_asset_code'] ?? $row['asset_code'] ?? '',
                $row['item_name'] ?? '',
                $row['category_name'] ?? '',
                $row['item_code'] ?? '',
                $row['serial_number'] ?? '',
                $row['issued_to'] ?? '',
                $row['department'] ?? '',
                $row['issue_date'] ?? '',
                $row['expected_return_date'] ?? '',
                $row['status'] ?? '',
                $row['quantity'] ?? '',
                $row['unit'] ?? '',
                $row['approver_name'] ?? '',
                $row['remark'] ?? '',
            ]);
        }
        fclose($output);
        exit;
    }

    if ($exportType === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename . '.xls');
        echo "<html xmlns:o='urn:schemas-microsoft-com:office:office' xmlns:x='urn:schemas-microsoft-com:office:excel' xmlns='http://www.w3.org/TR/REC-html40'>\n";
        echo "<meta charset='UTF-8'>\n";
        echo "<table border='1'>\n";
        echo "<tr><th>Issue Code</th><th>Asset Code</th><th>Item Name</th><th>Category</th><th>Item Code</th><th>Serial Number</th><th>Issued To</th><th>Department</th><th>Issue Date</th><th>Expected Return Date</th><th>Status</th><th>Quantity</th><th>Unit</th><th>Created By</th><th>Remark</th></tr>\n";

        foreach ($rows as $row) {
            echo '<tr>';
            foreach ([
                $row['issue_code'] ?? '',
                $row['display_asset_code'] ?? $row['asset_code'] ?? '',
                $row['item_name'] ?? '',
                $row['category_name'] ?? '',
                $row['item_code'] ?? '',
                $row['serial_number'] ?? '',
                $row['issued_to'] ?? '',
                $row['department'] ?? '',
                $row['issue_date'] ?? '',
                $row['expected_return_date'] ?? '',
                $row['status'] ?? '',
                $row['quantity'] ?? '',
                $row['unit'] ?? '',
                $row['approver_name'] ?? '',
                $row['remark'] ?? '',
            ] as $cell) {
                echo '<td>' . htmlspecialchars((string) $cell, ENT_QUOTES, 'UTF-8') . '</td>';
            }
            echo '</tr>';
        }

        echo "</table></html>\n";
        exit;
    }

    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: inline; filename=' . $filename . '.pdf');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Issuance Export</title><style>body{font-family:Arial,sans-serif;margin:24px;color:#111}table{border-collapse:collapse;width:100%;font-size:12px}th,td{border:1px solid #d1d5db;padding:8px;text-align:left;vertical-align:top}th{background:#f3f4f6}h2{margin-bottom:16px}.print-actions{display:none}@media print{body{margin:0}.print-actions{display:none}}</style></head><body>';
    echo '<h2>Issuance Report</h2>';
    echo '<table><thead><tr><th>Issue Code</th><th>Asset Code</th><th>Item Name</th><th>Category</th><th>Issued To</th><th>Department</th><th>Issue Date</th><th>Status</th><th>Qty</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($row['issue_code'] ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars($row['display_asset_code'] ?? $row['asset_code'] ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars($row['item_name'] ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars($row['category_name'] ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars($row['issued_to'] ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars($row['department'] ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars($row['issue_date'] ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars($row['status'] ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars((string)($row['quantity'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '<script>window.print();</script>';
    echo '</body></html>';
    exit;
}
?>

<div class="space-y-8">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-800 flex items-center gap-2">
                <i class="fas fa-boxes-packing text-indigo-600"></i>
                ຕິດຕາມການເບີກ-ຈ່າຍ ແລະ ຍົກຍ້າຍອຸປະກອນ
            </h1>
            <p class="text-sm text-gray-500">ປະຫວັດການເບີກ-ຈ່າຍ, ຍົກຍ້າຍອຸປະກອນ ແລະ ສະຖານະລະຫັດ ຊຄທ
                 ຈາກພະແນກ</p>
        </div>
    </div>

    <div class="card overflow-hidden bg-white rounded-xl shadow-sm border">
        <div class="p-4 bg-gray-50 border-b flex flex-col md:flex-row md:items-center justify-between gap-4">
            <h3 class="font-bold text-gray-800 flex items-center gap-2">
                <i class="fas fa-list-check text-indigo-600"></i>
                ປະຫວັດການເບີກ ແລະ ຍົກຍ້າຍອຸປະກອນທັງໝົດ
            </h3>

            <form method="GET" action="" class="flex items-center gap-2 w-full md:w-auto flex-wrap justify-end">
                <input type="hidden" name="admin" value="issuance">
                <div class="relative w-full md:w-80">
                    <input type="text" 
                           name="search" 
                           value="<?php echo htmlspecialchars($search); ?>" 
                           placeholder="ຄົ້ນຫາ (ລະຫັດເບີກ, ລະຫັດ ຊຄທ
                           , ຊື່, SN, ພະແນກ)..." 
                           class="w-full pl-9 pr-4 py-2 text-sm border rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none">
                    <i class="fas fa-search absolute left-3 top-2.5 text-gray-400"></i>
                </div>
                <select name="category" onchange="this.form.submit()" class="px-3 py-2 text-sm border rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none">
                    <option value="0">-- ທຸກໝວດໝູ່ --</option>
                    <?php foreach ($itemCategories as $category): ?>
                        <option value="<?php echo (int)$category['cate_id']; ?>" <?php echo $categoryFilter === (int)$category['cate_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($category['cate_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-sm font-medium transition-colors">
                    ຄົ້ນຫາ
                </button>
                <?php if (!empty($search)): ?>
                    <a href="?admin=issuance" class="px-3 py-2 bg-gray-200 hover:bg-gray-300 text-gray-700 rounded-lg text-sm transition-colors" title="ລ້າງການຄົ້ນຫາ">
                        <i class="fas fa-times"></i>
                    </a>
                <?php endif; ?>
                <div class="flex items-center gap-2 ml-1">
                    <button type="button" onclick="exportIssuance('csv')" class="px-3 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-xs font-semibold transition-colors">
                        <i class="fas fa-file-csv"></i> CSV
                    </button>
                    <button type="button" onclick="exportIssuance('excel')" class="px-3 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg text-xs font-semibold transition-colors">
                        <i class="fas fa-file-excel"></i> Excel
                    </button>
                    <button type="button" onclick="exportIssuance('pdf')" class="px-3 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg text-xs font-semibold transition-colors">
                        <i class="fas fa-file-pdf"></i> PDF
                    </button>
                </div>
            </form>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-gray-50 text-xs text-gray-500 uppercase font-semibold border-b">
                        <th class="p-4">ລະຫັດອ້າງອີງ / ວັນທີ</th>
                        <th class="p-4">ລະຫັດ ຊຄທ

                        </th>
                        <th class="p-4">ອຸປະກອນ</th>
                        <th class="p-4">ໝວດໝູ່</th>
                        <th class="p-4">SERIAL NUMBER</th>
                        <th class="p-4">ຜູ້ຮັບເບີກ / ພະແນກ</th>
                        <th class="p-4">ວັນທີກົດ / ຜູ້ອະນຸມັດ</th>
                        <th class="p-4 text-center">ຈຳນວນເບີກ</th>
                        <th class="p-4 text-center">ສະຖານະ</th>
                        <th class="p-4 text-center">ຈັດການ</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 text-sm">
                    <?php if (!empty($issuances)): ?>
                        <?php foreach ($issuances as $row): 
                            $isOverdue = ($row['status'] === 'issued' && !empty($row['expected_return_date']) && $row['expected_return_date'] < date('Y-m-d'));
                            $assetCode = $row['display_asset_code'] ?? '';
                        ?>
                            <tr class="hover:bg-gray-50 <?php echo $row['status'] === 'returned' ? 'bg-green-50/40' : ($row['status'] === 'transferred' ? 'bg-purple-50/30' : ''); ?>">
                                <td class="p-4">
                                    <span class="font-mono font-bold text-blue-600 block">#<?php echo htmlspecialchars($row['issue_code']); ?></span>
                                    <span class="text-xs text-gray-500">📅 <?php echo date('d/m/Y', strtotime($row['issue_date'])); ?></span>
                                </td>
                                
                                <td class="p-4">
                                    <?php if (!empty($assetCode)): ?>
                                        <div class="flex flex-wrap gap-1">
                                            <?php 
                                            $codes = array_filter(array_map('trim', explode(',', $assetCode)));
                                            foreach ($codes as $code): 
                                            ?>
                                                <span class="inline-flex items-center gap-1 bg-emerald-50 text-emerald-700 border border-emerald-200 px-2 py-0.5 rounded font-mono text-xs font-bold shadow-xs">
                                                    <i class="fas fa-barcode"></i> <?php echo htmlspecialchars($code); ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-amber-600 text-xs font-semibold bg-amber-50 px-2 py-0.5 rounded border border-amber-200 inline-block">
                                            ⏳ ຍັງບໍ່ທັນສ້າງ ຊຄທ

                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td class="p-4">
                                    <div class="font-bold text-gray-800"><?php echo htmlspecialchars($row['item_name'] ?? 'ລຶບແລ້ວ'); ?></div>
                                    <div class="text-xs text-gray-500 font-mono"><?php echo htmlspecialchars($row['item_code'] ?? '-'); ?></div>
                                </td>
                                <td class="p-4 text-sm text-gray-600">
                                    <?php echo htmlspecialchars($row['category_name'] ?? '-'); ?>
                                </td>
                                <td class="p-4 font-mono text-xs text-gray-700">
                                    <?php if (!empty($row['serial_number'])): ?>
                                        <span class="bg-gray-100 px-2 py-1 rounded border text-gray-800 font-semibold">
                                            SN: <?php echo htmlspecialchars($row['serial_number']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-gray-400 italic">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-4">
                                    <div class="text-xs text-gray-700 font-medium">
                                        <i class="fas fa-user text-gray-400"></i> <?php echo htmlspecialchars($row['issued_to']); ?>
                                    </div>
                                    <div class="text-xs font-bold text-indigo-900 mt-1">
                                        <?php echo htmlspecialchars($row['department'] ?: 'ບໍ່ລະບຸພະແນກ'); ?>
                                    </div>
                                </td>
                                <td class="p-4 text-xs">
                                    <div class="text-gray-600 font-medium">
                                        <i class="fas fa-clock text-gray-400"></i> 
                                        <?php echo !empty($row['created_at']) ? date('d/m/Y H:i', strtotime($row['created_at'])) : '-'; ?>
                                    </div>
                                    <div class="text-gray-500 mt-1">
                                        <i class="fas fa-user-check text-green-600"></i> 
                                        <?php echo htmlspecialchars($row['approver_name'] ?? 'ລະບົບ / ບໍ່ລະບຸ'); ?>
                                    </div>
                                </td>
                                <td class="p-4 text-center font-bold text-gray-800">
                                    <?php echo number_format($row['quantity']); ?> <?php echo htmlspecialchars($row['unit'] ?? ''); ?>
                                </td>
                                <td class="p-4 text-center">
                                    <?php if ($row['status'] === 'transferred'): ?>
                                        <span class="px-2.5 py-1 text-xs rounded-full bg-purple-100 text-purple-700 font-bold border border-purple-300 inline-flex items-center gap-1">
                                            <i class="fas fa-right-left"></i> ຍົກຍ້າຍພະແນກ
                                        </span>
                                    <?php elseif ($row['status'] === 'returned'): ?>
                                        <span class="px-2.5 py-1 text-xs rounded-full bg-green-100 text-green-700 font-bold border border-green-300 inline-flex items-center gap-1">
                                            <i class="fas fa-check-circle"></i> ສົ່ງຄືນແລ້ວ
                                        </span>
                                    <?php elseif ($isOverdue): ?>
                                        <span class="px-2.5 py-1 text-xs rounded-full bg-red-100 text-red-600 font-bold animate-pulse">
                                            ⚠️ ເກີນກຳນົດ
                                        </span>
                                    <?php else: ?>
                                        <span class="px-2.5 py-1 text-xs rounded-full bg-blue-100 text-blue-700 font-medium">
                                            ກຳລັງໃຊ້
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-4 text-center">
                                    <?php if ($row['status'] !== 'returned'): ?>
                                        <button onclick="openRowTransferModal(<?php echo htmlspecialchars(json_encode($row)); ?>)" 
                                                class="px-2.5 py-1 bg-purple-50 hover:bg-purple-100 text-purple-700 rounded-lg text-xs font-semibold border border-purple-200 transition flex items-center gap-1 mx-auto">
                                            <i class="fas fa-right-left"></i> ຍົກຍ້າຍ
                                        </button>
                                    <?php else: ?>
                                        <span class="text-gray-300 text-xs">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="10" class="p-8 text-center text-gray-400">
                                <?php if (!empty($search)): ?>
                                    ບໍ່ພົບຂໍ້ມູນທີ່ກົງກັບຄຳຄົ້ນຫາ "<strong><?php echo htmlspecialchars($search); ?></strong>"
                                <?php else: ?>
                                    ບໍ່ມີຂໍ້ມູນການເບີກອຸປະກອນ
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal ຟອມເບີກອຸປະກອນ -->
<div id="issueModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center hidden p-4">
    <div class="bg-white rounded-2xl max-w-md w-full shadow-2xl overflow-hidden">
        <div class="p-5 bg-gradient-to-r from-indigo-600 to-purple-600 text-white flex justify-between items-center">
            <h3 class="text-lg font-bold flex items-center gap-2">
                <i class="fas fa-box-open"></i> ເບີກອຸປະກອນ
            </h3>
            <button onclick="closeIssueModal()" class="text-white/80 hover:text-white text-xl">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <form method="POST" action="" class="p-6 space-y-4">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCSRFToken()); ?>">
            <input type="hidden" name="action_create_issuance" value="1">

            <div>
                <label class="block text-xs font-bold text-gray-700 mb-1">ເລືອກອຸປະກອນ <span class="text-red-500">*</span></label>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 mb-2">
                    <input type="text" id="issueItemSearch" oninput="filterIssuanceItems('issue')" placeholder="ຄົ້ນຫາຊື່, ລະຫັດ, SN..." class="w-full border rounded-xl p-2.5 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
                    <select id="issueItemCategory" onchange="filterIssuanceItems('issue')" class="w-full border rounded-xl p-2.5 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
                        <option value="all">-- ທຸກໝວດໝູ່ --</option>
                        <?php foreach ($itemCategories as $category): ?>
                            <option value="<?php echo (int)$category['cate_id']; ?>"><?php echo htmlspecialchars($category['cate_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <select name="item_id" id="issueItemSelect" required class="w-full border rounded-xl p-2.5 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
                    <option value="">-- ເລືອກອຸປະກອນ --</option>
                    <?php foreach ($allItems as $itm): ?>
                        <option value="<?php echo $itm['id']; ?>"
                                data-search="<?php echo htmlspecialchars(strtolower(implode(' ', array_filter([$itm['name'], $itm['item_code'], $itm['serial_number'], $itm['barcode'], $itm['brand'], $itm['model']]))), ENT_QUOTES); ?>"
                                data-category="<?php echo (int)($itm['cate_id'] ?? 0); ?>">
                            <?php echo htmlspecialchars($itm['name']); ?> (ຄັງ: <?php echo $itm['quantity']; ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-xs font-bold text-gray-700 mb-1">ລະຫັດ ຊຄທ
                     (Asset Code)</label>
                <input type="text" name="asset_code" placeholder="ຕົວຢ່າງ: TSG-2026-0012" class="w-full border rounded-xl p-2.5 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">ຜູ້ຮັບເບີກ <span class="text-red-500">*</span></label>
                    <input type="text" name="issued_to" required placeholder="ຊື່ຜູ້ຮັບ..." class="w-full border rounded-xl p-2.5 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">ພະແນກ</label>
                    <input type="text" name="department" placeholder="ລະບຸພະແນກ..." class="w-full border rounded-xl p-2.5 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">ຈຳນວນ</label>
                    <input type="number" name="quantity" min="1" value="1" required class="w-full border rounded-xl p-2.5 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">ວັນທີເບີກ</label>
                    <input type="date" name="issue_date" value="<?php echo date('Y-m-d'); ?>" class="w-full border rounded-xl p-2.5 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-gray-700 mb-1">ໝາຍເຫດ</label>
                <textarea name="remark" rows="2" placeholder="ໝາຍເຫດເພີ່ມເຕີມ..." class="w-full border rounded-xl p-2.5 text-sm focus:ring-2 focus:ring-indigo-500 outline-none"></textarea>
            </div>

            <div class="pt-3 flex justify-end gap-2 border-t">
                <button type="button" onclick="closeIssueModal()" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-xl text-xs font-semibold">
                    ຍົກເລີກ
                </button>
                <button type="submit" class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold shadow-md transition">
                    <i class="fas fa-check"></i> ຢືນຢັນການເບີກ
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal ຍົກຍ້າຍ -->
<div id="transferModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center hidden p-4">
    <div class="bg-white rounded-2xl max-w-lg w-full shadow-2xl overflow-hidden">
        <div class="p-5 bg-gradient-to-r from-purple-600 to-indigo-600 text-white flex justify-between items-center">
            <h3 class="text-lg font-bold flex items-center gap-2">
                <i class="fas fa-right-left"></i> ຍົກຍ້າຍອຸປະກອນລະຫວ່າງພະແນກ
            </h3>
            <button onclick="closeTransferModal()" class="text-white/80 hover:text-white text-xl">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <form method="POST" action="" class="p-6 space-y-4">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCSRFToken()); ?>">
            <input type="hidden" name="action_transfer_item" value="1">
            <input type="hidden" name="issuance_id" id="modal_issuance_id" value="">

            <div>
                <label class="block text-xs font-bold text-gray-700 mb-1">ເລືອກອຸປະກອນ</label>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 mb-2">
                    <input type="text" id="transferItemSearch" oninput="filterIssuanceItems('transfer')" placeholder="ຄົ້ນຫາຊື່, ລະຫັດ, SN..." class="w-full border rounded-xl p-2.5 text-sm focus:ring-2 focus:ring-purple-500 outline-none">
                    <select id="transferItemCategory" onchange="filterIssuanceItems('transfer')" class="w-full border rounded-xl p-2.5 text-sm focus:ring-2 focus:ring-purple-500 outline-none">
                        <option value="all">-- ທຸກໝວດໝູ່ --</option>
                        <?php foreach ($itemCategories as $category): ?>
                            <option value="<?php echo (int)$category['cate_id']; ?>"><?php echo htmlspecialchars($category['cate_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <select name="item_id" id="modal_item_id" class="w-full border rounded-xl p-2.5 text-sm focus:ring-2 focus:ring-purple-500 outline-none">
                    <option value="">-- ເລືອກອຸປະກອນ --</option>
                    <?php foreach ($allItems as $itm): ?>
                        <option value="<?php echo $itm['id']; ?>"
                                data-search="<?php echo htmlspecialchars(strtolower(implode(' ', array_filter([$itm['name'], $itm['item_code'], $itm['serial_number'], $itm['barcode'], $itm['brand'], $itm['model']]))), ENT_QUOTES); ?>"
                                data-category="<?php echo (int)($itm['cate_id'] ?? 0); ?>">
                            <?php echo htmlspecialchars($itm['name']); ?> (<?php echo htmlspecialchars($itm['item_code']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">ພະແນກປັດຈຸບັນ</label>
                    <input type="text" name="from_department" id="modal_from_dept" class="w-full border rounded-xl p-2.5 text-sm bg-gray-50 outline-none" placeholder="ລະບຸພະແນກຕົ້ນທາງ...">
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">ຍ້າຍໄປພະແນກ <span class="text-red-500">*</span></label>
                    <select name="to_dept_id" id="modal_to_dept_id" required class="w-full border rounded-xl p-2.5 text-sm focus:ring-2 focus:ring-purple-500 outline-none">
                        <option value="">-- ເລືອກພະແນກ --</option>
                        <?php foreach ($allDepartments as $dept): ?>
                            <option value="<?php echo $dept['id']; ?>">
                                <?php echo htmlspecialchars($dept['dept_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-gray-700 mb-1">ຈຳນວນ</label>
                <input type="number" name="transfer_quantity" id="modal_quantity" min="1" value="1" readonly class="w-full border rounded-xl p-2.5 text-sm bg-gray-50 focus:ring-2 focus:ring-purple-500 outline-none">
            </div>

            <div>
                <label class="block text-xs font-bold text-gray-700 mb-1">ໝາຍເຫດ</label>
                <textarea name="transfer_remark" rows="2" placeholder="ເຫດຜົນການຍ້າຍ..." class="w-full border rounded-xl p-2.5 text-sm focus:ring-2 focus:ring-purple-500 outline-none"></textarea>
            </div>

            <div class="pt-3 flex justify-end gap-2 border-t">
                <button type="button" onclick="closeTransferModal()" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-xl text-xs font-semibold">
                    ຍົກເລີກ
                </button>
                <button type="submit" class="px-5 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-xl text-xs font-bold shadow-md transition">
                    <i class="fas fa-check"></i> ຢືນຢັນການຍົກຍ້າຍ
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openIssueModal() {
    document.getElementById('issueModal').classList.remove('hidden');
}

function closeIssueModal() {
    document.getElementById('issueModal').classList.add('hidden');
}

function openTransferModal() {
    document.getElementById('modal_issuance_id').value = '';
    document.getElementById('modal_from_dept').value = '';
    document.getElementById('modal_quantity').value = 1;
    document.getElementById('transferModal').classList.remove('hidden');
}

function openRowTransferModal(row) {
    document.getElementById('modal_issuance_id').value = row.id;
    document.getElementById('modal_item_id').value = row.item_id;
    document.getElementById('modal_from_dept').value = row.department || '';
    document.getElementById('modal_quantity').value = row.quantity || 1;
    document.getElementById('transferModal').classList.remove('hidden');
}

function filterIssuanceItems(type) {
    const prefix = type === 'transfer' ? 'transfer' : 'issue';
    const searchTerm = document.getElementById(`${prefix}ItemSearch`).value.toLowerCase().trim();
    const selectedCategory = document.getElementById(`${prefix}ItemCategory`).value;
    const itemSelect = document.getElementById(type === 'transfer' ? 'modal_item_id' : 'issueItemSelect');

    Array.from(itemSelect.options).forEach(option => {
        if (!option.value) {
            option.hidden = false;
            return;
        }

        const matchesSearch = (option.dataset.search || '').includes(searchTerm);
        const matchesCategory = selectedCategory === 'all' || option.dataset.category === selectedCategory;
        option.hidden = !(matchesSearch && matchesCategory);
    });
}

// function exportItems() {
//     const type = prompt("ກະລຸນາພິມປະເພດ 'csv' ເພື່ອສົ່ງອອກຂໍ້ມູນ:", "csv");
//     if (type) window.location.href = '?admin=items&export=' + type;
// }

function exportIssuance(type) {
    const searchInput = document.querySelector('input[name="search"]');
    const searchValue = searchInput ? searchInput.value.trim() : '';
    const token = <?php echo json_encode(generateCSRFToken()); ?>;
    const url = new URL(window.location.href);
    url.searchParams.set('admin', 'issuance');
    url.searchParams.set('export', type);
    url.searchParams.set('token', token);
    const categorySelect = document.querySelector('select[name="category"]');
    if (categorySelect && categorySelect.value !== '0') {
        url.searchParams.set('category', categorySelect.value);
    }
    if (searchValue) {
        url.searchParams.set('search', searchValue);
    }

    if (type === 'pdf') {
        window.open(url.toString(), '_blank');
        return;
    }

    window.location.href = url.toString();
}

function closeTransferModal() {
    document.getElementById('transferModal').classList.add('hidden');
}
</script>