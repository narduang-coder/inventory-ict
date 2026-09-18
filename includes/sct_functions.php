<?php

require_once __DIR__ . '/../includes/config.php';
checkRole(['user']);

$user_id = $_SESSION['user_id'] ?? 0;
$user_department = $_SESSION['department'] ?? '';

$message = '';
$message_type = '';

// ============================================================
// 1. PROCESS: ສ້າງຂໍ້ມູນ ທສກ (department_assets)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_tsg') {
    $request_id = (int)($_POST['request_id'] ?? 0);
    $item_id    = (int)($_POST['item_id'] ?? 0);
    $asset_code = trim($_POST['asset_code'] ?? '');
    $total_qty  = (int)($_POST['quantity'] ?? 0);

    if ($request_id <= 0 || $item_id <= 0) {
        $message = "ກະລຸນາເລືອກປະເພດອຸປະກອນ ແລະ ລາຍການອຸປະກອນໃຫ້ຄົບຖ້ວນ!";
        $message_type = "warning";
    } elseif (empty($asset_code) || $total_qty <= 0) {
        $message = "ກະລຸນາປ້ອນລະຫັດ ທສກ ແລະ ຈຳນວນໃຫ້ຄົບຖ້ວນ!";
        $message_type = "warning";
    } else {
        try {
            $pdo->beginTransaction();

            $chkCode = $pdo->prepare("SELECT COUNT(*) FROM department_assets WHERE asset_code = ?");
            $chkCode->execute([$asset_code]);
            
            if ($chkCode->fetchColumn() > 0) {
                $pdo->rollBack();
                $message = "ລະຫັດ ທສກ ({$asset_code}) ນີ້ມີໃນລະບົບແລ້ວ!";
                $message_type = "warning";
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO department_assets (request_id, item_id, department, asset_code, total_qty, current_qty) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$request_id, $item_id, $user_department, $asset_code, $total_qty, $total_qty]);
                
                $updIssuance = $pdo->prepare("
                    UPDATE item_issuances 
                    SET asset_code = ? 
                    WHERE item_id = ? AND department = ? AND (asset_code IS NULL OR asset_code = '')
                    ORDER BY id DESC LIMIT 1
                ");
                $updIssuance->execute([$asset_code, $item_id, $user_department]);

                $pdo->commit();
                $message = "ເພີ່ມຂໍ້ມູນ ທສກ ສຳເລັດແລ້ວ! (ລະຫັດ: {$asset_code})";
                $message_type = "success";
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $message = "ເກີດຂໍ້ຜິດພາດ ກະລຸນາລອງໃໝ່";
            $message_type = "error";
        }
    }
}

// ============================================================
// 2. PROCESS: ບັນທຶກ Movement & ຕັດ current_qty (ກວດສອບຈຳນວນຈາກພະແນກ)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'record_movement') {
    $asset_id    = (int)($_POST['asset_id'] ?? 0);
    $action_type = $_POST['action_type'] ?? 'use';
    $quantity    = (int)($_POST['quantity'] ?? 0);
    $taken_by    = trim($_POST['taken_by'] ?? '');
    $destination = trim($_POST['destination'] ?? '');
    $note        = trim($_POST['note'] ?? '');

    if ($asset_id <= 0) {
        $message = "ກະລຸນາເລືອກປະເພດ ແລະ ອຸປະກອນທີ່ຕ້ອງການດໍາເນີນການ!";
        $message_type = "warning";
    } elseif ($quantity <= 0 || empty($taken_by)) {
        $message = "ກະລຸນາປ້ອນຂໍ້ມູນຈຳນວນ ແລະ ຜູ້ເອົາໄປໃຫ້ຄົບຖ້ວນ!";
        $message_type = "warning";
    } else {
        try {
            $pdo->beginTransaction();

            // ດຶງຂໍ້ມູນ item_id ຈາກ asset
            $assetStmt = $pdo->prepare("SELECT item_id, current_qty FROM department_assets WHERE id = ? AND department = ? FOR UPDATE");
            $assetStmt->execute([$asset_id, $user_department]);
            $assetData = $assetStmt->fetch(PDO::FETCH_ASSOC);

            if (!$assetData) {
                $pdo->rollBack();
                $message = "ບໍ່ພົບຂໍ້ມູນ ທສກ ນີ້ໃນພະແນກ!";
                $message_type = "error";
            } else {
                $item_id = $assetData['item_id'];

                // ຕວດສອບຈຳນວນຄົງເຫຼືອຂອງພະແນກ (ອະນຸມັດເບີກ - ສົ່ງຄືນ)
                $chkDeptStmt = $pdo->prepare("
                    SELECT 
                        (
                            IFNULL((SELECT SUM(ri.quantity) FROM request_items ri JOIN requests r ON ri.request_id = r.id WHERE ri.item_id = ? AND r.department = ? AND r.status IN ('approved', 'issued', 'completed')), 0)
                            -
                            IFNULL((SELECT SUM(quantity) FROM return_requests WHERE item_id = ? AND department = ? AND status IN ('pending', 'approved', 'disposed', 'completed', 'cleared')), 0)
                        ) AS current_balance
                ");
                $chkDeptStmt->execute([$item_id, $user_department, $item_id, $user_department]);
                $dept_qty = (int)$chkDeptStmt->fetchColumn();

                if ($quantity > $dept_qty) {
                    $pdo->rollBack();
                    $message = "ຈຳນວນທີ່ລະບຸເກີນຈຳນວນຄົງເຫຼືອໃນພະແນກ (ຄົງເຫຼືອ: {$dept_qty})";
                    $message_type = "warning";
                } else {
                    // ບັນທຶກ Movement
                    $insStmt = $pdo->prepare("
                        INSERT INTO asset_movements (asset_id, action_type, quantity, taken_by, destination, note) 
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $insStmt->execute([$asset_id, $action_type, $quantity, $taken_by, $destination, $note]);

                    // ຕັດ current_qty ຈາກ department_assets
                    $updAsset = $pdo->prepare("UPDATE department_assets SET current_qty = current_qty - ? WHERE id = ?");
                    $updAsset->execute([$quantity, $asset_id]);

                    $pdo->commit();
                    $message = "ບັນທຶກ " . ($action_type === 'transfer' ? 'ການຍົກຍ້າຍ' : 'ການນຳເອົາໄປໃຊ້ງານ') . " ຮຽບຮ້ອຍ!";
                    $message_type = "success";
                }
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $message = "ເກີດຂໍ້ຜິດພາດ ກະລຸນາລອງໃໝ່";
            $message_type = "error";
        }
    }
}

// ============================================================
// 3. FETCH DATA (ຄິດໄລ່ຍອດຄົງເຫຼືອຂອງພະແນກແບບ Real-Time)
// ============================================================

// ດຶງຂໍ້ມູນຄຳຂໍເບີກ + Category Name ສຳລັບ ຟອມ 1
$approved_items = [];
if (!empty($user_department)) {
    $stmtApproved = $pdo->prepare("
        SELECT 
            r.id AS request_id, 
            ri.item_id, 
            i.name AS item_name, 
            i.item_code AS sys_item_code,
            c.cate_name AS category_name,
            ri.quantity,
            r.request_date
        FROM requests r
        JOIN request_items ri ON r.id = ri.request_id
        JOIN items i ON ri.item_id = i.id
        LEFT JOIN category c ON i.cate_id = c.cate_id
        WHERE r.department = ? 
          AND r.status IN ('approved', 'issued', 'completed')
        ORDER BY c.cate_name ASC, i.name ASC
    ");
    $stmtApproved->execute([$user_department]);
    $approved_items = $stmtApproved->fetchAll(PDO::FETCH_ASSOC);
}

// ດຶງຂໍ້ມູນ department_assets ໂດຍຄິດໄລ່ຈຳນວນຄົງເຫຼືອຂອງພະແນກ (แก้ไข Parameter แยกจุด)
$my_assets = [];
if (!empty($user_department)) {
    $stmtAssets = $pdo->prepare("
        SELECT 
            da.id AS asset_id,
            da.request_id,
            da.item_id,
            da.department,
            da.asset_code,
            da.total_qty,
            da.current_qty AS asset_current_qty,
            IFNULL(dept_balance.current_balance, 0) AS dept_items_qty,
            da.created_at,
            i.name AS item_name, 
            i.item_code AS sys_item_code, 
            i.unit,
            c.cate_name AS category_name
        FROM department_assets da
        JOIN items i ON da.item_id = i.id
        LEFT JOIN category c ON i.cate_id = c.cate_id
        LEFT JOIN (
            SELECT 
                approved_data.item_id,
                (IFNULL(approved_data.total_approved, 0) - IFNULL(returned_data.total_returned, 0)) AS current_balance
            FROM (
                SELECT ri.item_id, SUM(ri.quantity) AS total_approved
                FROM request_items ri
                JOIN requests r ON ri.request_id = r.id
                WHERE r.department = :dept1 AND r.status IN ('approved', 'issued', 'completed')
                GROUP BY ri.item_id
            ) approved_data
            LEFT JOIN (
                SELECT item_id, SUM(quantity) AS total_returned
                FROM return_requests
                WHERE department = :dept2 AND status IN ('pending', 'approved', 'disposed', 'completed', 'cleared')
                GROUP BY item_id
            ) returned_data ON approved_data.item_id = returned_data.item_id
        ) dept_balance ON da.item_id = dept_balance.item_id
        WHERE da.department = :dept3 AND IFNULL(dept_balance.current_balance, 0) > 0
        ORDER BY c.cate_name ASC, da.id DESC
    ");
    
    $stmtAssets->execute([
        'dept1' => $user_department,
        'dept2' => $user_department,
        'dept3' => $user_department
    ]);
    $my_assets = $stmtAssets->fetchAll(PDO::FETCH_ASSOC);
}

// ຈັດກຸ່ມ Category ສຳລັບ ຟອມ 1
$categories_approved = [];
foreach ($approved_items as $item) {
    $cat = $item['category_name'] ?: 'ອື່ນໆ';
    $categories_approved[$cat][] = $item;
}

// ຈັດກຸ່ມ Category ສຳລັບ ຟອມ 2
$categories_assets = [];
foreach ($my_assets as $asset) {
    $cat = $asset['category_name'] ?: 'ອື່ນໆ';
    $categories_assets[$cat][] = $asset;
}

// ດຶງຂໍ້ມູນ asset_movements
$movements_history = [];
if (!empty($user_department)) {
    $stmtHist = $pdo->prepare("
        SELECT 
            am.id AS movement_id,
            am.asset_id,
            am.action_type,
            am.quantity AS move_qty,
            am.taken_by,
            am.destination,
            am.note,
            am.created_at AS move_date,
            da.asset_code, 
            i.name AS item_name, 
            i.unit
        FROM asset_movements am
        JOIN department_assets da ON am.asset_id = da.id
        JOIN items i ON da.item_id = i.id
        WHERE da.department = ?
        ORDER BY am.id DESC
    ");
    $stmtHist->execute([$user_department]);
    $movements_history = $stmtHist->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!-- Header Banner -->
<div class="mb-6 flex flex-col md:flex-row md:items-center justify-between gap-4 bg-white p-5 rounded-2xl border border-slate-200/80 shadow-sm">
    <div class="flex items-center gap-3">
        <span class="p-3 bg-gradient-to-br from-[#002B66] to-[#0284c7] text-white rounded-xl text-xl shadow-md">
            <i class="fas fa-boxes-stacked"></i>
        </span>
        <div>
            <h1 class="text-xl font-bold text-slate-800">
                ຄຸ້ມຄອງ ທສກ ປະຈຳພະແນກ 
                <span class="text-sky-600 font-semibold">(<?php echo htmlspecialchars($user_department); ?>)</span>
            </h1>
            <p class="text-slate-500 text-xs mt-0.5">ເພີ່ມຂໍ້ມູນ ທສກ, ລາຍງານ Admin ພ້ອມບັນທຶກ Process ຍົກຍ້າຍ/ນຳໃຊ້</p>
        </div>
    </div>
</div>

<!-- SweetAlert Notification -->
<?php if (!empty($message)): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            Swal.fire({
                icon: '<?php echo htmlspecialchars($message_type); ?>',
                title: '<?php echo $message_type === "success" ? "ສຳເລັດ!" : ($message_type === "warning" ? "ແຈ້ງເຕືອນ" : "ເກີດຂໍ້ຜິດພາດ"); ?>',
                text: '<?php echo htmlspecialchars($message, ENT_QUOTES); ?>',
                confirmButtonColor: '#002B66',
                timer: 3000
            });
        });
    </script>
<?php endif; ?>

<!-- Process Forms Section -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
    
    <!-- ຟອມ 1: ເພີ່ມຂໍ້ມູນເຂົ້າ department_assets -->
    <div class="bg-white p-6 rounded-2xl border border-slate-200/80 shadow-sm flex flex-col justify-between">
        <div>
            <div class="flex items-center gap-2 pb-3 mb-4 border-b border-slate-100">
                <i class="fas fa-folder-plus text-[#002B66] text-lg"></i>
                <h3 class="text-base font-bold text-slate-800">1. ສ້າງຂໍ້ມູນ ທສກ (department_assets)</h3>
            </div>

            <form action="" method="POST" onsubmit="return validateCreateTsg()" class="space-y-4">
                <input type="hidden" name="action" value="create_tsg">

                <!-- 1. ເລືອກປະເພດອຸປະກອນ -->
                <div>
                    <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase">1. ເລືອກປະເພດອຸປະກອນ *</label>
                    <select id="catSelectForm1" onchange="onCategoryChangeForm1(this.value)" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:border-sky-500 transition-all">
                        <option value="">-- ເລືອກປະເພດອຸປະກອນ --</option>
                        <?php foreach (array_keys($categories_approved) as $catName): ?>
                            <option value="<?php echo htmlspecialchars($catName); ?>"><?php echo htmlspecialchars($catName); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- 2. ເລືອກລາຍການອຸປະກອນທີ່ອະນຸມັດ (ກັ່ນກອງຕາມປະເພດ) -->
                <div>
                    <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase">2. ເລືອກອຸປະກອນທີ່ອະນຸມັດເບີກ *</label>
                    <select id="selectApproved" disabled onchange="handleSelectApproved(this)" class="w-full bg-slate-100 border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:border-sky-500 transition-all disabled:opacity-60 disabled:cursor-not-allowed">
                        <option value="">-- ກະລຸນາເລືອກປະເພດອຸປະກອນກ່ອນ --</option>
                    </select>
                    <input type="hidden" name="request_id" id="tsg_request_id">
                    <input type="hidden" name="item_id" id="tsg_item_id">
                    <input type="hidden" name="quantity" id="tsg_quantity">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase">ກຳນົດ asset_code (ລະຫັດ ທສກ) *</label>
                    <input type="text" id="asset_code_input" name="asset_code" placeholder="ເຊັ່ນ: TSG-<?php echo htmlspecialchars(strtoupper($user_department ?: 'EDL')); ?>-001" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:border-sky-500 transition-all font-mono">
                </div>

                <button type="submit" class="w-full bg-gradient-to-r from-[#002B66] to-[#004B99] hover:from-[#001d45] hover:to-[#003875] text-white font-semibold py-2.5 px-4 rounded-xl transition-all shadow-md flex items-center justify-center gap-2 text-sm mt-2">
                    <i class="fas fa-plus-circle"></i> ບັນທຶກສ້າງ ທສກ
                </button>
            </form>
        </div>
    </div>

    <!-- ຟອມ 2: ບັນທຶກ Process ຍົກຍ້າຍ/ນຳໃຊ້ (ດຶງຂໍ້ມູນຈຳນວນຄົງເຫຼືອຂອງພະແນກ) -->
    <div class="bg-white p-6 rounded-2xl border border-slate-200/80 shadow-sm flex flex-col justify-between">
        <div>
            <div class="flex items-center gap-2 pb-3 mb-4 border-b border-slate-100">
                <i class="fas fa-people-carry-box text-amber-500 text-lg"></i>
                <h3 class="text-base font-bold text-slate-800">2. Process ຍົກຍ້າຍ / ນຳໃຊ້ ທສກ (asset_movements)</h3>
            </div>

            <form action="" method="POST" onsubmit="return validateMovement()" class="space-y-4">
                <input type="hidden" name="action" value="record_movement">

                <!-- 1. ເລືອກປະເພດອຸປະກອນ -->
                <div>
                    <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase">1. ເລືອກປະເພດອຸປະກອນ *</label>
                    <select id="catSelectForm2" onchange="onCategoryChangeForm2(this.value)" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:border-amber-500 transition-all">
                        <option value="">-- ເລືອກປະເພດອຸປະກອນ --</option>
                        <?php foreach (array_keys($categories_assets) as $catName): ?>
                            <option value="<?php echo htmlspecialchars($catName); ?>"><?php echo htmlspecialchars($catName); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- 2. ເລືອກອຸປະກອນ ທສກ ຂອງພະແນກ (ດຶງຈຳນວນຄົງເຫຼືອຂອງພະແນກ) -->
                <div>
                    <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase">2. ເລືອກອຸປະກອນ ທສກ ທີ່ພະແນກມີ *</label>
                    <select id="selectAssetId" name="asset_id" disabled class="w-full bg-slate-100 border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:border-amber-500 transition-all disabled:opacity-60 disabled:cursor-not-allowed">
                        <option value="">-- ກະລຸນາເລືອກປະເພດອຸປະກອນກ່ອນ --</option>
                    </select>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase">action_type *</label>
                        <select name="action_type" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:border-amber-500 transition-all">
                            <option value="use">ນຳໄປໃຊ້ງານ (use)</option>
                            <option value="transfer">ຍົກຍ້າຍ (transfer)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase">quantity (ຈຳນວນ) *</label>
                        <input type="number" id="moveQuantity" name="quantity" min="1" placeholder="0" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:border-amber-500 transition-all">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase">taken_by (ຜູ້ເອົາໄປ) *</label>
                    <input type="text" id="takenBy" name="taken_by" placeholder="ລະບຸຊື່ຜູ້ເອົາໄປໃຊ້ງານ/ຍົກຍ້າຍ" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:border-amber-500 transition-all">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase">destination (ຈຸດໝາຍ/ສະຖານທີ່)</label>
                    <input type="text" name="destination" placeholder="ລະບຸສະຖານທີ່ ຫຼື ໂຄງການ..." class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:border-amber-500 transition-all">
                </div>

                <button type="submit" class="w-full bg-amber-500 hover:bg-amber-600 text-white font-semibold py-2.5 px-4 rounded-xl transition-all shadow-md flex items-center justify-center gap-2 text-sm mt-2">
                    <i class="fas fa-arrow-right-from-bracket"></i> ຢືນຢັນການຕັດຈຳນວນ
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Data Tables Section -->
<div class="space-y-6">
    <!-- Table 1: department_assets (ສະແດງຈຳນວນຄົງເຫຼືອຂອງພະແນກ) -->
    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm overflow-hidden">
        <div class="p-5 border-b border-slate-100 flex items-center justify-between">
            <h3 class="text-base font-bold text-slate-800 flex items-center gap-2">
                <i class="fas fa-table text-[#002B66]"></i>
                ຕາຕະລາງ department_assets (ຈຳນວນຄົງເຫຼືອໃນພະແນກ)
            </h3>
            <span class="text-xs font-semibold bg-sky-50 text-sky-700 px-3 py-1 rounded-full border border-sky-200">
                <?php echo count($my_assets); ?> ລາຍການ
            </span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-slate-600">
                <thead class="bg-slate-50 text-slate-500 text-xs uppercase font-semibold border-b border-slate-200">
                    <tr>
                        <th class="px-5 py-3.5">asset_code</th>
                        <th class="px-5 py-3.5">ປະເພດ / ລາຍການອຸປະກອນ</th>
                        <th class="px-5 py-3.5 text-center">total_qty</th>
                        <th class="px-5 py-3.5 text-center">ຄົງເຫຼືອໃນພະແນກ</th>
                        <th class="px-5 py-3.5 text-center">created_at</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (count($my_assets) > 0): ?>
                        <?php foreach ($my_assets as $asset): ?>
                            <tr class="hover:bg-slate-50/80 transition-colors">
                                <td class="px-5 py-4 font-mono font-bold text-sky-700">
                                    <?php echo htmlspecialchars($asset['asset_code']); ?>
                                </td>
                                <td class="px-5 py-4">
                                    <div class="text-xs text-slate-400 font-medium"><?php echo htmlspecialchars($asset['category_name'] ?: 'ອື່ນໆ'); ?></div>
                                    <div class="font-semibold text-slate-800"><?php echo htmlspecialchars($asset['item_name']); ?></div>
                                </td>
                                <td class="px-5 py-4 text-center font-medium text-slate-600">
                                    <?php echo number_format($asset['total_qty']) . ' ' . htmlspecialchars($asset['unit'] ?? ''); ?>
                                </td>
                                <td class="px-5 py-4 text-center font-bold <?php echo ($asset['dept_items_qty'] ?? 0) > 0 ? 'text-emerald-600' : 'text-rose-500'; ?>">
                                    <?php echo number_format($asset['dept_items_qty'] ?? 0) . ' ' . htmlspecialchars($asset['unit'] ?? ''); ?>
                                </td>
                                <td class="px-5 py-4 text-center text-xs text-slate-400">
                                    <?php echo date('d/m/Y H:i', strtotime($asset['created_at'])); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="px-5 py-8 text-center text-slate-400">ບໍ່ມີຂໍ້ມູນໃນ department_assets</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Table 2: asset_movements -->
    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm overflow-hidden">
        <div class="p-5 border-b border-slate-100 flex items-center justify-between">
            <h3 class="text-base font-bold text-slate-800 flex items-center gap-2">
                <i class="fas fa-history text-amber-500"></i>
                ຕາຕະລາງ asset_movements (ປະຫວັດ Process)
            </h3>
            <span class="text-xs font-semibold bg-amber-50 text-amber-700 px-3 py-1 rounded-full border border-amber-200">
                <?php echo count($movements_history); ?> ປະຫວັດ
            </span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-slate-600">
                <thead class="bg-slate-50 text-slate-500 text-xs uppercase font-semibold border-b border-slate-200">
                    <tr>
                        <th class="px-5 py-3.5">move_date</th>
                        <th class="px-5 py-3.5">asset_code / item_name</th>
                        <th class="px-5 py-3.5 text-center">action_type</th>
                        <th class="px-5 py-3.5 text-center">move_qty</th>
                        <th class="px-5 py-3.5">taken_by</th>
                        <th class="px-5 py-3.5">destination</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (count($movements_history) > 0): ?>
                        <?php foreach ($movements_history as $hist): ?>
                            <tr class="hover:bg-slate-50/80 transition-colors">
                                <td class="px-5 py-4 text-xs font-medium text-slate-400">
                                    <?php echo date('d/m/Y H:i', strtotime($hist['move_date'])); ?>
                                </td>
                                <td class="px-5 py-4">
                                    <div class="font-bold font-mono text-sky-700 text-xs"><?php echo htmlspecialchars($hist['asset_code']); ?></div>
                                    <div class="font-semibold text-slate-800"><?php echo htmlspecialchars($hist['item_name']); ?></div>
                                </td>
                                <td class="px-5 py-4 text-center">
                                    <?php if ($hist['action_type'] === 'transfer'): ?>
                                        <span class="px-2.5 py-1 text-xs font-semibold bg-blue-50 text-blue-700 border border-blue-200 rounded-full">
                                            <i class="fas fa-right-left mr-1"></i> transfer
                                        </span>
                                    <?php else: ?>
                                        <span class="px-2.5 py-1 text-xs font-semibold bg-amber-50 text-amber-700 border border-amber-200 rounded-full">
                                            <i class="fas fa-hand-holding mr-1"></i> use
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-5 py-4 text-center font-bold text-rose-600">
                                    -<?php echo number_format($hist['move_qty']) . ' ' . htmlspecialchars($hist['unit'] ?? ''); ?>
                                </td>
                                <td class="px-5 py-4 font-semibold text-slate-700">
                                    <i class="fas fa-user-tag text-xs text-slate-400 mr-1"></i>
                                    <?php echo htmlspecialchars($hist['taken_by']); ?>
                                </td>
                                <td class="px-5 py-4 text-slate-500">
                                    <?php echo htmlspecialchars($hist['destination'] ?: '-'); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="px-5 py-8 text-center text-slate-400">ບໍ່ມີຂໍ້ມູນໃນ asset_movements</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
const rawApprovedData = <?php echo json_encode($categories_approved, JSON_UNESCAPED_UNICODE); ?>;
const rawAssetsData = <?php echo json_encode($categories_assets, JSON_UNESCAPED_UNICODE); ?>;
const userDept = <?php echo json_encode(strtoupper($user_department ?: "EDL"), JSON_UNESCAPED_UNICODE); ?>;

// Dynamic Filter ຟອມ 1
function onCategoryChangeForm1(catName) {
    const itemSelect = document.getElementById('selectApproved');
    itemSelect.innerHTML = '<option value="">-- ເລືອກອຸປະກອນ --</option>';
    
    document.getElementById('tsg_request_id').value = '';
    document.getElementById('tsg_item_id').value = '';
    document.getElementById('tsg_quantity').value = '';
    document.getElementById('asset_code_input').value = '';

    if (catName && rawApprovedData[catName]) {
        itemSelect.disabled = false;
        itemSelect.classList.remove('bg-slate-100', 'cursor-not-allowed');
        itemSelect.classList.add('bg-slate-50');

        rawApprovedData[catName].forEach(item => {
            const opt = document.createElement('option');
            opt.value = item.request_id;
            opt.setAttribute('data-item-id', item.item_id);
            opt.setAttribute('data-qty', item.quantity);
            opt.setAttribute('data-code', item.sys_item_code);
            opt.textContent = `#REQ-${String(item.request_id).padStart(5, '0')} | ${item.item_name} (ຈຳນວນ: ${item.quantity})`;
            itemSelect.appendChild(opt);
        });
    } else {
        itemSelect.disabled = true;
        itemSelect.classList.add('bg-slate-100', 'cursor-not-allowed');
        itemSelect.classList.remove('bg-slate-50');
    }
}

// Dynamic Filter ຟອມ 2 (ດຶງຈຳນວນຄົງເຫຼືອງຂອງພະແນກ)
function onCategoryChangeForm2(catName) {
    const assetSelect = document.getElementById('selectAssetId');
    assetSelect.innerHTML = '<option value="">-- ເລືອກອຸປະກອນ ທສກ --</option>';

    if (catName && rawAssetsData[catName]) {
        assetSelect.disabled = false;
        assetSelect.classList.remove('bg-slate-100', 'cursor-not-allowed');
        assetSelect.classList.add('bg-slate-50');

        rawAssetsData[catName].forEach(asset => {
            const opt = document.createElement('option');
            opt.value = asset.asset_id;
            const remainingQty = asset.dept_items_qty !== null ? asset.dept_items_qty : 0;
            opt.textContent = `[${asset.asset_code}] ${asset.item_name} (ຄົງເຫຼືອໃນພະແນກ: ${remainingQty} ${asset.unit || ''})`;
            assetSelect.appendChild(opt);
        });
    } else {
        assetSelect.disabled = true;
        assetSelect.classList.add('bg-slate-100', 'cursor-not-allowed');
        assetSelect.classList.remove('bg-slate-50');
    }
}

function handleSelectApproved(selectEl) {
    const opt = selectEl.options[selectEl.selectedIndex];
    document.getElementById('tsg_request_id').value = selectEl.value;
    document.getElementById('tsg_item_id').value = opt.getAttribute('data-item-id') || '';
    document.getElementById('tsg_quantity').value = opt.getAttribute('data-qty') || '';
    
    if (selectEl.value) {
        const reqId = String(selectEl.value).padStart(3, '0');
        document.getElementById('asset_code_input').value = `TSG-${userDept}-${reqId}`;
    } else {
        document.getElementById('asset_code_input').value = '';
    }
}

function validateCreateTsg() {
    const cat = document.getElementById('catSelectForm1').value;
    const reqId = document.getElementById('selectApproved').value;
    const assetCode = document.getElementById('asset_code_input').value.trim();

    if (!cat) {
        Swal.fire({ icon: 'warning', title: 'ແຈ້ງເຕືອນ', text: 'ກະລຸນາເລືອກປະເພດອຸປະກອນກ່ອນ!', confirmButtonColor: '#002B66' });
        return false;
    }
    if (!reqId) {
        Swal.fire({ icon: 'warning', title: 'ແຈ້ງເຕືອນ', text: 'ກະລຸນາເລືອກອຸປະກອນທີ່ຕ້ອງການສ້າງ ທສກ!', confirmButtonColor: '#002B66' });
        return false;
    }
    if (!assetCode) {
        Swal.fire({ icon: 'warning', title: 'ແຈ້ງເຕືອນ', text: 'ກະລຸນາປ້ອນລະຫັດ ທສກ (asset_code)!', confirmButtonColor: '#002B66' });
        return false;
    }
    return true;
}

function validateMovement() {
    const cat = document.getElementById('catSelectForm2').value;
    const assetId = document.getElementById('selectAssetId').value;
    const qty = document.getElementById('moveQuantity').value;
    const takenBy = document.getElementById('takenBy').value.trim();

    if (!cat) {
        Swal.fire({ icon: 'warning', title: 'ແຈ້ງເຕືອນ', text: 'ກະລຸນາເລືອກປະເພດອຸປະກອນກ່ອນ!', confirmButtonColor: '#002B66' });
        return false;
    }
    if (!assetId) {
        Swal.fire({ icon: 'warning', title: 'ແຈ້ງເຕືອນ', text: 'ກະລຸນາເລືອກອຸປະກອນ ທສກ ທີ່ຕ້ອງການດຳເນີນການ!', confirmButtonColor: '#002B66' });
        return false;
    }
    if (!qty || qty <= 0) {
        Swal.fire({ icon: 'warning', title: 'ແຈ້ງເຕືອນ', text: 'ກະລຸນາປ້ອນຈຳນວນໃຫ້ຖືກຕ້ອງ!', confirmButtonColor: '#002B66' });
        return false;
    }
    if (!takenBy) {
        Swal.fire({ icon: 'warning', title: 'ແຈ້ງເຕືອນ', text: 'ກະລຸນາປ້ອນຊື່ຜູ້ເອົາໄປໃຊ້ງານ/ຍົກຍ້າຍ!', confirmButtonColor: '#002B66' });
        return false;
    }
    return true;
}
</script>