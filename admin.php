<?php
require_once __DIR__ . '/includes/config.php';
checkRole(['admin']);

$page = isset($_GET['admin']) ? $_GET['admin'] : 'dashboard';

// -------------------------------------------------------------
// 1. ຈັດການ Export ທັງໝົດກ່ອນເລີ່ມ Render HTML Output
// -------------------------------------------------------------

// Export CSV ສໍາລັບ items
if (isset($_GET['export']) && $_GET['export'] == 'csv' && $page == 'items') {
    $token = $_GET['token'] ?? '';
    if (!verifyCSRFToken($token)) {
        die('Unauthorized access');
    }
    exportItemsToCSV($pdo);
    exit();
}

// Export CSV / Excel / PDF ສໍາລັບ issuance
if (isset($_GET['export']) && $page === 'issuance') {
    $exportType = $_GET['export'] ?? '';
    if (in_array($exportType, ['csv', 'excel', 'pdf'], true)) {
        $token = $_GET['token'] ?? '';
        if (!verifyCSRFToken($token)) {
            http_response_code(403);
            exit('Unauthorized access');
        }

        $search = trim($_GET['search'] ?? '');
        $params = [];

        $sqlIssuances = "
            SELECT i.*, 
                   itm.name as item_name, 
                   itm.item_code, 
                   itm.serial_number,
                   itm.unit,
                   itm.quantity as stock_qty,
                   u.fullname as approver_name,
                   COALESCE(da.dept_asset_code, i.asset_code) AS display_asset_code
            FROM item_issuances i 
            LEFT JOIN items itm ON i.item_id = itm.id 
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

        $sqlIssuances .= " ORDER BY i.id DESC";

        $exportStmt = $pdo->prepare($sqlIssuances);
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
            fputcsv($output, ['Issue Code', 'Asset Code', 'Item Name', 'Item Code', 'Serial Number', 'Issued To', 'Department', 'Issue Date', 'Expected Return Date', 'Status', 'Quantity', 'Unit', 'Created By', 'Remark']);

            foreach ($rows as $row) {
                fputcsv($output, [
                    $row['issue_code'] ?? '',
                    $row['display_asset_code'] ?? $row['asset_code'] ?? '',
                    $row['item_name'] ?? '',
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
            echo "<tr><th>Issue Code</th><th>Asset Code</th><th>Item Name</th><th>Item Code</th><th>Serial Number</th><th>Issued To</th><th>Department</th><th>Issue Date</th><th>Expected Return Date</th><th>Status</th><th>Quantity</th><th>Unit</th><th>Created By</th><th>Remark</th></tr>\n";

            foreach ($rows as $row) {
                echo '<tr>';
                foreach ([
                    $row['issue_code'] ?? '',
                    $row['display_asset_code'] ?? $row['asset_code'] ?? '',
                    $row['item_name'] ?? '',
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

        if ($exportType === 'pdf') {
            header('Content-Type: text/html; charset=utf-8');
            header('Content-Disposition: inline; filename=' . $filename . '.pdf');
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Issuance Export</title><style>body{font-family:Arial,sans-serif;margin:24px;color:#111}table{border-collapse:collapse;width:100%;font-size:12px}th,td{border:1px solid #d1d5db;padding:8px;text-align:left;vertical-align:top}th{background:#f3f4f6}h2{margin-bottom:16px}.print-actions{display:none}@media print{body{margin:0}.print-actions{display:none}}</style></head><body>';
            echo '<h2>Issuance Report</h2>';
            echo '<table><thead><tr><th>Issue Code</th><th>Asset Code</th><th>Item Name</th><th>Issued To</th><th>Department</th><th>Issue Date</th><th>Status</th><th>Qty</th></tr></thead><tbody>';
            foreach ($rows as $row) {
                echo '<tr>';
                echo '<td>' . htmlspecialchars($row['issue_code'] ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
                echo '<td>' . htmlspecialchars($row['display_asset_code'] ?? $row['asset_code'] ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
                echo '<td>' . htmlspecialchars($row['item_name'] ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
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
    }
}

// ດຶງຂໍ້ມູນຝ່າຍ
$stmt = $pdo->prepare("SELECT * FROM settings LIMIT 1");
$stmt->execute();
$school = $stmt->fetch();

// -------------------------------------------------------------
// ດຶງຂໍ້ມູນຈຳນວນແຈ້ງເຕືອນ (Notification Counters)
// -------------------------------------------------------------
$total_items = $pdo->query("SELECT COUNT(*) FROM items")->fetchColumn();
$total_users = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();

// 1. ຈຳນວນຄຳຂໍເບີກ ທີ່ລໍຖ້າອະນຸມັດ
$pending_requests = $pdo->query("SELECT COUNT(*) FROM requests WHERE status = 'pending'")->fetchColumn();

// 2. ຈຳນວນຄຳຂໍສົ່ງຄືນ ທີ່ລໍຖ້າອະນຸມັດ
$pending_returns = $pdo->query("SELECT COUNT(*) FROM return_requests WHERE status = 'pending'")->fetchColumn();

// 3. ຜົນລວມຄຳຂໍເບີກ + ສົ່ງຄືນ ທີ່ Admin ຕ້ອງອະນຸມັດ
$total_pending = (int)$pending_requests + (int)$pending_returns;

// 4. ຈຳນວນອຸປະກອນຊຳຣຸດ/ເປ່ເພ ທີ່ລໍຖ້າການຊຳລະ & ຕັດສະຕັອກ (ໜ້າ categories)
$pending_damaged = $pdo->query("SELECT COUNT(*) FROM return_requests WHERE item_condition IN ('damaged', 'broken') AND status = 'approved'")->fetchColumn();

$total_value = $pdo->query("SELECT SUM(price_per_unit * quantity) FROM items")->fetchColumn();

$pages = [
    'dashboard' => ADMIN_PATH . 'dashboard.php',
    'items' => ADMIN_PATH . 'items.php',
    'item_detail' => ADMIN_PATH . 'item_detail.php',
    'categories' => ADMIN_PATH . 'categories.php',
    'requests' => ADMIN_PATH . 'requests.php',
    'settings' => ADMIN_PATH . 'settings.php',
    'purchase_report' => ADMIN_PATH . 'purchase_report.php',
    'stock_movement' => ADMIN_PATH . 'stock_movement.php',
    'locations' => ADMIN_PATH . 'locations.php',
    'users' => ADMIN_PATH . 'users.php',
    'issuance' => ADMIN_PATH . 'issuance.php',
];
$page_file = isset($pages[$page]) ? $pages[$page] : $pages['dashboard'];

// ຟັງຊັນສົ່ງອອກ CSV
function exportItemsToCSV($pdo) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=items_' . date('Y-m-d') . '.csv');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ['item_code', 'name', 'cate_name', 'unit', 'price_per_unit', 'quantity', 'min_quantity', 'barcode']);
    
    $stmt = $pdo->prepare("
        SELECT i.item_code, i.name, c.cate_name, i.unit, i.price_per_unit, i.quantity, i.min_quantity, i.barcode 
        FROM items i 
        LEFT JOIN category c ON i.cate_id = c.cate_id 
        ORDER BY i.id
    ");
    $stmt->execute();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit();
}

// Stream item BLOBs before the admin layout writes any response body.
if ($page === 'items' && isset($_GET['get_blob'])) {
    $id = (int)$_GET['get_blob'];
    $type = $_GET['type'] ?? 'image';

    $stmt = $pdo->prepare("SELECT image_data, image_mime, doc_data, doc_mime, doc_name FROM items WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $itemBlob = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($itemBlob && $type === 'image' && !empty($itemBlob['image_data'])) {
        header('Content-Type: ' . $itemBlob['image_mime']);
        echo $itemBlob['image_data'];
        exit();
    }

    if ($itemBlob && $type === 'doc' && !empty($itemBlob['doc_data'])) {
        $disposition = (isset($_GET['download']) && $_GET['download'] === '1') ? 'attachment' : 'inline';
        header('Content-Type: ' . $itemBlob['doc_mime']);
        header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($itemBlob['doc_name']) . '"');
        echo $itemBlob['doc_data'];
        exit();
    }

    http_response_code(404);
    exit('File Not Found');
}
?>
<!DOCTYPE html>
<html lang="lo">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ລະບົບຄຸ້ມຄອງສາງ ICT - ຜູ້ຄຸ້ມຄອງລະບົບ</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Lao:wght@100..900&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body {
            font-family: 'Noto Sans Lao', sans-serif;
            background: #f0f4f5;
        }
        .sidebar {
            background: #283593;
            height: 100vh;
            max-height: 100vh;
            width: 260px;
            position: fixed;
            top: 0;
            left: 0;
            overflow-y: auto;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 1000;
            box-shadow: 4px 0 20px rgba(0, 43, 102, 0.15);
            display: flex;
            flex-direction: column;
        }
        .sidebar::-webkit-scrollbar {
            width: 5px;
        }
        .sidebar::-webkit-scrollbar-track {
            background: rgba(0, 0, 0, 0.1);
        }
        .sidebar::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 4px;
        }
        .sidebar::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.4);
        }
        .sidebar-item {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            color: #bae6fd;
            text-decoration: none;
            transition: all 0.2s ease;
            border-left: 4px solid transparent;
            font-weight: 500;
        }
        .sidebar-item:hover {
            background: rgba(255, 255, 255, 0.12);
            color: #ffffff;
        }
        .sidebar-item.active {
            background: rgba(255, 255, 255, 0.18);
            color: #ffffff;
            border-left-color: #f59e0b;
            font-weight: 600;
        }
        .sidebar-item.active i {
            color: #fbbf24;
        }
        .sidebar-item i {
            width: 24px;
            margin-right: 12px;
            font-size: 1.1rem;
            color: #7dd3fc;
        }
        .main-content {
            margin-left: 260px;
            padding: 92px 28px 28px;
            min-height: 100vh;
        }
        .card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            padding: 20px;
            transition: all 0.3s;
        }
        .card:hover {
            box-shadow: 0 8px 30px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border-left: 4px solid #0284c7;
            transition: all 0.3s;
        }
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.1);
        }
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        .btn-primary {
            background: linear-gradient(135deg, #002B66 0%, #0284c7 100%);
            color: white;
            padding: 10px 24px;
            border-radius: 12px;
            border: none;
            cursor: pointer;
            transition: all 0.3s;
            font-family: 'Noto Sans Lao', sans-serif;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(2, 132, 199, 0.35);
        }
        .form-input {
            width: 100%;
            padding: 10px 14px;
            border: 2px solid #101010;
            border-radius: 10px;
            transition: all 0.3s;
            font-family: 'Noto Sans Lao', sans-serif;
            font-size: 15px;
        }
        .form-input:focus {
            border-color: #0284c7;
            outline: none;
            box-shadow: 0 0 0 4px rgba(145, 223, 239, 0.92);
        }
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            .sidebar.open {
                transform: translateX(0);
            }
            .main-content {
                margin-left: 0;
            }
        }
    </style>
</head>
<body>
    <div class="sidebar" id="sidebar">
        <div class="p-6 text-center border-b border-white/10 shrink-0">
            <?php if ($school && !empty($school['logo_path']) && file_exists(assetPath($school['logo_path']))): ?>
                <img src="<?php echo htmlspecialchars(assetUrl($school['logo_path'])); ?>" alt="Logo" class="h-16 mx-auto mb-3 object-contain">
            <?php endif; ?>
            <h1 class="text-xl font-bold text-white"><?php echo $school ? htmlspecialchars($school['school_name']) : 'ຝ່າຍ ICT'; ?></h1>
            <p class="text-sm text-white/60">ລະບົບຄຸ້ມຄອງສາງ</p>
        </div>
        
        <div class="p-4 border-b border-white/10 shrink-0">
            <div class="flex items-center text-white">
                <div class="w-10 h-10 rounded-full bg-gradient-to-r from-[#002B66] to-[#0284c7] flex items-center justify-center font-bold">
                    <?php echo strtoupper(substr($_SESSION['fullname'] ?? 'U', 0, 1)); ?>
                </div>
                <div class="ml-3">
                    <p class="font-medium text-sm"><?php echo htmlspecialchars($_SESSION['fullname'] ?? 'ຜູ້ໃຊ້'); ?></p>
                    <p class="text-xs text-white/60">ຜູ້ຄຸ້ມຄອງລະບົບ</p>
                </div>
            </div>
        </div>
        
        <nav class="p-4 flex-1 overflow-y-auto">
            <a href="?admin=dashboard" class="sidebar-item <?php echo $page == 'dashboard' ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt"></i> ໜ້າຫຼັກ
            </a>
            <a href="?admin=items" class="sidebar-item <?php echo $page == 'items' ? 'active' : ''; ?>">
                <i class="fas fa-boxes"></i> ອຸປະກອນທັງໝົດ
            </a>
            <a href="?admin=categories" class="sidebar-item flex items-center justify-between <?php echo $page == 'categories' ? 'active' : ''; ?>">
                <div class="flex items-center">
                    <i class="fas fa-layer-group"></i>
                    <span>ການຊຳລະ/ສະສາງອຸປະກອນ</span>
                </div>
                <?php if ($pending_damaged > 0): ?>
                    <span class="bg-amber-500 text-white text-xs font-bold px-2 py-0.5 rounded-full animate-pulse shadow-sm" title="ອຸປະກອນຊຳຣຸດລໍຖ້າຊຳລະ: <?php echo $pending_damaged; ?>">
                        <?php echo $pending_damaged; ?>
                    </span>
                <?php endif; ?>
            </a>
            <a href="?admin=requests" class="sidebar-item flex items-center justify-between <?php echo $page == 'requests' ? 'active' : ''; ?>">
                <div class="flex items-center">
                    <i class="fas fa-clipboard-list"></i>
                    <span>ຄຳຂໍເບີກ / ສົ່ງຄືນ</span>
                </div>
                <?php if ($total_pending > 0): ?>
                    <span class="bg-red-500 text-white text-xs font-bold px-2 py-0.5 rounded-full animate-pulse shadow-sm" title="ຄຳຂໍເບີກ: <?php echo $pending_requests; ?> | ຄຳຂໍສົ່ງຄືນ: <?php echo $pending_returns; ?>">
                        <?php echo $total_pending; ?>
                    </span>
                <?php endif; ?>
            </a>
            <a href="?admin=issuance" class="sidebar-item <?php echo $page == 'issuance' ? 'active' : ''; ?>">
                <i class="fas fa-hand-holding"></i> ຕິດຕາມການເບີກ/ສົ່ງຄືນ/ຍົກຍ້າຍອຸປະກອນ
            </a>
            <a href="?admin=stock_movement" class="sidebar-item <?php echo $page == 'stock_movement' ? 'active' : ''; ?>">
                <i class="fas fa-arrows-spin"></i> ປະຫວັດການເຄື່ອນໄຫວຂອງອຸປະກອນທັງໝົດ
            </a>
            <a href="?admin=locations" class="sidebar-item <?php echo $page == 'locations' ? 'active' : ''; ?>">
                <i class="fas fa-map-pin"></i> ເພີ່ມຂໍ້ມູນພະແນກ/ໝວດໝູ່
            </a>
            <a href="?admin=users" class="sidebar-item <?php echo $page == 'users' ? 'active' : ''; ?>">
                <i class="fas fa-users"></i> ຈັດການຜູ້ໃຊ້
            </a>
            <a href="?admin=settings" class="sidebar-item <?php echo $page == 'settings' ? 'active' : ''; ?>">
                <i class="fas fa-cog"></i> ຕັ້ງຄ່າລະບົບ
            </a>
            <a href="logout.php" class="sidebar-item mt-4 border-t border-white/10 pt-4 text-red-400 hover:text-red-300">
                <i class="fas fa-sign-out-alt"></i> ອອກຈາກລະບົບ
            </a>
        </nav>
    </div>
    
    <button onclick="toggleSidebar()" class="fixed top-4 left-4 z-50 lg:hidden bg-white p-3 rounded-xl shadow-lg">
        <i class="fas fa-bars text-gray-700"></i>
    </button>
    
    <div class="main-content">
        <?php
        if (file_exists($page_file)) {
            include $page_file;
        } else {
            echo '<div class="p-4 bg-red-100 text-red-700 rounded-lg">ໄຟລ໌ບໍ່ພົບ: ' . htmlspecialchars($page_file) . '</div>';
            include ADMIN_PATH . 'dashboard.php';
        }
        ?>
    </div>
    
    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
        }
        document.addEventListener('click', function(e) {
            const sidebar = document.getElementById('sidebar');
            const toggle = document.querySelector('[onclick="toggleSidebar()"]');
            if (window.innerWidth <= 768 && sidebar.classList.contains('open') && 
                !sidebar.contains(e.target) && !toggle.contains(e.target)) {
                sidebar.classList.remove('open');
            }
        });
    </script>
</body>
</html>