<?php

require_once __DIR__ . '/../includes/config.php';
checkRole(['user']);

$user_id = $_SESSION['user_id'] ?? 0;
$user_department = $_SESSION['department'] ?? '';

$message = '';
$message_type = '';

// ============================================================
// 1. PROCESS: ສ້າງຂໍ້ມູນ ຊຄທ (department_assets)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_tsg') {
    requirePostCsrf();
    $request_id = (int)($_POST['request_id'] ?? 0);
    $item_id    = (int)($_POST['item_id'] ?? 0);
    $asset_code = trim($_POST['asset_code'] ?? '');
    $create_qty = (int)($_POST['quantity'] ?? 1);

    if ($request_id <= 0 || $item_id <= 0) {
        $message = "ກະລຸນາເລືອກປະເພດອຸປະກອນ ແລະ ລາຍການອຸປະກອນໃຫ້ຄົບຖ້ວນ!";
        $message_type = "warning";
    } elseif (empty($asset_code) || $create_qty <= 0) {
        $message = "ກະລຸນາປ້ອນລະຫັດ ຊຄທ ແລະ ຈຳນວນໃຫ້ຄົບຖ້ວນ!";
        $message_type = "warning";
    } else {
        try {
            $pdo->beginTransaction();

            $reqStmt = $pdo->prepare("
                SELECT r.id
                FROM requests r
                INNER JOIN request_items ri ON ri.request_id = r.id
                WHERE r.id = ?
                  AND ri.item_id = ?
                  AND r.department = ?
                  AND r.status = 'approved'
                LIMIT 1
                FOR UPDATE
            ");
            $reqStmt->execute([$request_id, $item_id, $user_department]);
            
            if (!$reqStmt->fetch()) {
                $pdo->rollBack();
                $message = "ບໍ່ສາມາດສ້າງ ຊຄທ ຈາກລາຍການຮ້ອງຂໍນີ້ໄດ້ (ຂໍ້ມູນບໍ່ຖືກຕ້ອງ ຫຼື ຂ້າມຂັ້ນຕອນ)";
                $message_type = "warning";
            } else {
                $availStmt = $pdo->prepare("
                    SELECT 
                        (
                            IFNULL((
                                SELECT SUM(ri.quantity) 
                                FROM request_items ri 
                                JOIN requests r ON ri.request_id = r.id 
                                WHERE ri.item_id = :item1 
                                  AND r.department = :dept1 
                                  AND r.status = 'approved'
                            ), 0)
                            -
                            IFNULL((
                                SELECT SUM(da.total_qty) 
                                FROM department_assets da 
                                WHERE da.item_id = :item2 
                                  AND da.department = :dept2
                            ), 0)
                            -
                            IFNULL((
                                SELECT SUM(quantity) 
                                FROM return_requests 
                                WHERE item_id = :item3 
                                  AND department = :dept3 
                                  AND status IN ('pending', 'approved', 'disposed', 'completed', 'cleared')
                            ), 0)
                        ) AS available_for_asset
                ");
                $availStmt->execute([
                    'item1' => $item_id, 'dept1' => $user_department,
                    'item2' => $item_id, 'dept2' => $user_department,
                    'item3' => $item_id, 'dept3' => $user_department
                ]);
                
                $available_for_asset = (int)$availStmt->fetchColumn();

                if ($available_for_asset <= 0) {
                    $pdo->rollBack();
                    $message = "ອຸປະກອນນີ້ຖືກສ້າງ ຊຄທ ຄົບຕາມຈຳນວນທີ່ມີໃນພະແນກແລ້ວ! (ບໍ່ມີຈຳນວນເຫຼືອໃຫ້ສ້າງ)";
                    $message_type = "warning";
                } elseif ($create_qty > $available_for_asset) {
                    $pdo->rollBack();
                    $message = "ຈຳນວນທີ່ຕ້ອງການສ້າງ ({$create_qty}) ເກີນຈຳນວນຄົງເຫຼືອທີ່ສາມາດສ້າງ ຊຄທ ໄດ້ (ເຫຼືອສ້າງໄດ້: {$available_for_asset})";
                    $message_type = "warning";
                } else {
                    $chkCode = $pdo->prepare("SELECT COUNT(*) FROM department_assets WHERE asset_code = ?");
                    $chkCode->execute([$asset_code]);
                    
                    if ($chkCode->fetchColumn() > 0) {
                        $pdo->rollBack();
                        $message = "ລະຫັດ ຊຄທ ({$asset_code}) ນີ້ມີໃນລະບົບແລ້ວ!";
                        $message_type = "warning";
                    } else {
                        $stmt = $pdo->prepare("
                            INSERT INTO department_assets (request_id, item_id, department, asset_code, total_qty, current_qty, created_at) 
                            VALUES (?, ?, ?, ?, ?, ?, NOW())
                        ");
                        $stmt->execute([$request_id, $item_id, $user_department, $asset_code, $create_qty, $create_qty]);
                        
                        $updIssuance = $pdo->prepare("
                            UPDATE item_issuances 
                            SET asset_code = ? 
                            WHERE item_id = ? AND department = ? AND (asset_code IS NULL OR asset_code = '')
                            ORDER BY id DESC LIMIT 1
                        ");
                        $updIssuance->execute([$asset_code, $item_id, $user_department]);

                        $pdo->commit();
                        $message = "ເພີ່ມຂໍ້ມູນ ຊຄທ ສຳເລັດແລ້ວ! (ລະຫັດ: {$asset_code})";
                        $message_type = "success";
                    }
                }
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $message = "ເກີດຂໍ້ຜິດພາດໃນການບັນທຶກ ກະລຸນາລອງໃໝ່";
            error_log('User TSG Save Error: ' . $e->getMessage() . ' | department=' . $user_department . ' | user_id=' . $user_id);
            $message_type = "error";
        }
    }
}

// ============================================================
// 2. PROCESS: ບັນທຶກ Movement & ຕັດ current_qty (ປັບແກ້ strict validation)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'record_movement') {
    requirePostCsrf();
    $asset_id    = (int)($_POST['asset_id'] ?? 0);
    $action_type = $_POST['action_type'] ?? 'use';
    $quantity    = (int)($_POST['quantity'] ?? 0);
    $taken_by    = trim($_POST['taken_by'] ?? '');
    $destination = trim($_POST['destination'] ?? '');
    $note        = trim($_POST['note'] ?? '');

    if ($asset_id <= 0) {
        $message = "ກະລຸນາເລືອກປະເພດ ແລະ ອຸປະກອນ ຊຄທ ທີ່ຕ້ອງການດໍາເນີນການ!";
        $message_type = "warning";
    } elseif ($quantity <= 0) {
        $message = "ກະລຸນາປ້ອນຈຳນວນທີ່ຕ້ອງການນຳໃຊ້/ຍົກຍ້າຍ ໃຫ້ຖືກຕ້ອງ!";
        $message_type = "warning";
    } elseif (empty($taken_by)) {
        $message = "ກະລຸນາປ້ອນຊື່ຜູ້ເອົາໄປໃຊ້ງານ/ຍົກຍ້າຍ!";
        $message_type = "warning";
    } else {
        try {
            $pdo->beginTransaction();

            // 1. Lock Row ຂອງ department_assets ເພື່ອກວດສອບ current_qty ທີ່ແທ້ຈິງ
            $assetStmt = $pdo->prepare("
                SELECT da.id, da.item_id, da.asset_code, da.current_qty 
                FROM department_assets da 
                WHERE da.id = ? AND da.department = ? 
                FOR UPDATE
            ");
            $assetStmt->execute([$asset_id, $user_department]);
            $assetData = $assetStmt->fetch(PDO::FETCH_ASSOC);

            if (!$assetData) {
                $pdo->rollBack();
                $message = "ບໍ່ພົບຂໍ້ມູນ ຊຄທ ນີ້ໃນພະແນກ!";
                $message_type = "error";
            } else {
                $asset_current_qty = (int)$assetData['current_qty'];

                // 2. ກວດສອບຍອດ ຄົົງເຫຼືອຂອງ asset_code / item ນີ້
                if ($asset_current_qty <= 0) {
                    $pdo->rollBack();
                    $message = "ອຸປະກອນ ຊຄທ ລະຫັດ ({$assetData['asset_code']}) ນີ້ໝົດແລ້ວ! ບໍ່ສາມາດເຄື່ອນຍ້າຍ/ນຳໃຊ້ໄດ້";
                    $message_type = "warning";
                } elseif ($quantity > $asset_current_qty) {
                    $pdo->rollBack();
                    $message = "ຈຳນວນທີ່ລະບຸ ({$quantity}) ເກີນຈຳນວນຄົງເຫຼືອຂອງ ຊຄທ ນີ້ (ເຫຼືອຢູ່: {$asset_current_qty})";
                    $message_type = "warning";
                } else {
                    // 3. ບັນທຶກປະຫວັດການເຄື່ອນຍ້າຍ/ນຳໃຊ້
                    $insStmt = $pdo->prepare("
                        INSERT INTO asset_movements (asset_id, action_type, quantity, taken_by, destination, note, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $insStmt->execute([$asset_id, $action_type, $quantity, $taken_by, $destination, $note]);

                    // 4. ຕັດຍອດ current_qty ໃນ department_assets
                    $updAsset = $pdo->prepare("UPDATE department_assets SET current_qty = current_qty - ? WHERE id = ?");
                    $updAsset->execute([$quantity, $asset_id]);

                    $pdo->commit();
                    $message = "ບັນທຶກ " . ($action_type === 'transfer' ? 'ການຍົກຍ້າຍ' : 'ການນຳເອົາໄປໃຊ້ງານ') . " ສຳເລັດແລ້ວ!";
                    $message_type = "success";
                }
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $message = "ເກີດຂໍ້ຜິດພາດໃນການບັນທຶກ ກະລຸນາລອງໃໝ່";
            $message_type = "error";
        }
    }
}

// ============================================================
// 3. FETCH DATA
// ============================================================

try {
    $approved_items = [];
    if (!empty($user_department)) {
        $stmtApproved = $pdo->prepare("
            SELECT 
                r.id AS request_id, 
                ri.item_id, 
                i.name AS item_name, 
                i.item_code AS sys_item_code,
                c.cate_name AS category_name,
                COALESCE(ri.approved_quantity, ri.quantity) AS original_qty,
                (
                    COALESCE(ri.approved_quantity, ri.quantity)
                    - IFNULL(created_assets.total_created, 0)
                    - IFNULL(returned_assets.total_returned, 0)
                ) AS available_qty,
                r.request_date
            FROM requests r
            JOIN request_items ri ON r.id = ri.request_id
            JOIN items i ON ri.item_id = i.id
            LEFT JOIN category c ON i.cate_id = c.cate_id
            LEFT JOIN (
                SELECT request_id, item_id, SUM(total_qty) AS total_created
                FROM department_assets
                WHERE department = :dept1
                GROUP BY request_id, item_id
            ) created_assets ON r.id = created_assets.request_id AND ri.item_id = created_assets.item_id
            LEFT JOIN (
                SELECT item_id, SUM(quantity) AS total_returned
                FROM return_requests
                WHERE department = :dept2 AND status IN ('pending', 'approved', 'disposed', 'completed', 'cleared')
                GROUP BY item_id
            ) returned_assets ON ri.item_id = returned_assets.item_id
            WHERE r.department = :dept3 
              AND r.status = 'approved'
              AND (COALESCE(ri.approved_quantity, ri.quantity) - IFNULL(created_assets.total_created, 0) - IFNULL(returned_assets.total_returned, 0)) > 0
            ORDER BY c.cate_name ASC, i.name ASC
        ");
        $stmtApproved->execute([
            'dept1' => $user_department,
            'dept2' => $user_department,
            'dept3' => $user_department
        ]);
        $approved_items = $stmtApproved->fetchAll(PDO::FETCH_ASSOC);
    }

    // ດຶງຂໍ້ມູນ department_assets ພ້ອມ Filter ໃຫ້ດຶງສະເພາະອຸປະກອນທີ່ມີ current_qty > 0 ມາສະແດງໃນ Selector Form 2
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
                da.created_at,
                i.name AS item_name, 
                i.item_code AS sys_item_code, 
                i.unit,
                c.cate_name AS category_name
            FROM department_assets da
            JOIN items i ON da.item_id = i.id
            LEFT JOIN category c ON i.cate_id = c.cate_id
            WHERE da.department = :dept
            ORDER BY c.cate_name ASC, da.id DESC
        ");
        $stmtAssets->execute(['dept' => $user_department]);
        $my_assets = $stmtAssets->fetchAll(PDO::FETCH_ASSOC);
    }

    // ຈັດກຸ່ມ Category ໃຫ້ Form 1
    $categories_approved = [];
    foreach ($approved_items as $item) {
        $cat = $item['category_name'] ?: 'ອື່ນໆ';
        $categories_approved[$cat][] = $item;
    }

    // ຈັດກຸ່ມ Category ໃຫ້ Form 2 (ສະແດງສະເພາະທີ່ຄົ່ງເຫຼືອ > 0 ເພື່ອນຳໃຊ້/ຍົກຍ້າຍ)
    $categories_assets = [];
    foreach ($my_assets as $asset) {
        if ($asset['asset_current_qty'] > 0) {
            $cat = $asset['category_name'] ?: 'ອື່ນໆ';
            $categories_assets[$cat][] = $asset;
        }
    }

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
} catch (PDOException $e) {
    error_log('User TSG Fetch Error: ' . $e->getMessage() . ' | department=' . $user_department . ' | user_id=' . $user_id);
    $approved_items = [];
    $my_assets = [];
    $movements_history = [];
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
                ຄຸ້ມຄອງ ຊຄທ ປະຈຳພະແນກ 
                <span class="text-sky-600 font-semibold">(<?php echo htmlspecialchars($user_department); ?>)</span>
            </h1>
            <p class="text-slate-500 text-xs mt-0.5">ເພີ່ມຂໍ້ມູນ ຊຄທ, ລາຍງານ Admin ພ້ອມບັນທຶກ Process ຍົກຍ້າຍ/ນຳໃຊ້</p>
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
                <h3 class="text-base font-bold text-slate-800">1. ສ້າງຂໍ້ມູນ ຊຄທ</h3>
            </div>

            <form action="" method="POST" onsubmit="return validateCreateTsg()" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCSRFToken()); ?>">
                <input type="hidden" name="action" value="create_tsg">

                <div>
                    <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase">1. ເລືອກປະເພດອຸປະກອນ *</label>
                    <select id="catSelectForm1" onchange="onCategoryChangeForm1(this.value)" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:border-sky-500 transition-all">
                        <option value="">-- ເລືອກປະເພດອຸປະກອນ --</option>
                        <?php foreach (array_keys($categories_approved) as $catName): ?>
                            <option value="<?php echo htmlspecialchars($catName); ?>"><?php echo htmlspecialchars($catName); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase">2. ເລືອກອຸປະກອນທີ່ອະນຸມັດເບີກ *</label>
                    <select id="selectApproved" disabled onchange="handleSelectApproved(this)" class="w-full bg-slate-100 border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:border-sky-500 transition-all disabled:opacity-60 disabled:cursor-not-allowed">
                        <option value="">-- ກະລຸນາເລືອກປະເພດອຸປະກອນກ່ອນ --</option>
                    </select>
                    <input type="hidden" name="request_id" id="tsg_request_id">
                    <input type="hidden" name="item_id" id="tsg_item_id">
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase">ຈຳນວນທີ່ສ້າງ ຊຄທ ຄັ້ງນີ້ *</label>
                        <input type="number" id="tsg_quantity" name="quantity" min="1" value="1" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:border-sky-500 transition-all font-semibold text-slate-800">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase">ກຳນົດ(ລະຫັດ ຊຄທ) *</label>
                        <input type="text" id="asset_code_input" name="asset_code" placeholder="ເຊັ່ນ: TSG-<?php echo htmlspecialchars(strtoupper($user_department ?: 'EDL')); ?>-001" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:border-sky-500 transition-all font-mono">
                    </div>
                </div>

                <button type="submit" class="w-full bg-gradient-to-r from-[#002B66] to-[#004B99] hover:from-[#001d45] hover:to-[#003875] text-white font-semibold py-2.5 px-4 rounded-xl transition-all shadow-md flex items-center justify-center gap-2 text-sm mt-2">
                    <i class="fas fa-plus-circle"></i> ບັນທຶກສ້າງ ຊຄທ
                </button>
            </form>
        </div>
    </div>

    <!-- ຟອມ 2: ບັນທຶກ Process ຍົກຍ້າຍ/ນຳໃຊ້ (ປັບແກ້ປ້ອງກັນເລືອກເກີນ) -->
    <div class="bg-white p-6 rounded-2xl border border-slate-200/80 shadow-sm flex flex-col justify-between">
        <div>
            <div class="flex items-center gap-2 pb-3 mb-4 border-b border-slate-100">
                <i class="fas fa-people-carry-box text-amber-500 text-lg"></i>
                <h3 class="text-base font-bold text-slate-800">2.ຍົກຍ້າຍ / ນຳໃຊ້ ຊຄທ </h3>
            </div>

            <form action="" method="POST" onsubmit="return validateMovement()" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCSRFToken()); ?>">
                <input type="hidden" name="action" value="record_movement">

                <div>
                    <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase">1. ເລືອກປະເພດອຸປະກອນ *</label>
                    <select id="catSelectForm2" onchange="onCategoryChangeForm2(this.value)" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:border-amber-500 transition-all">
                        <option value="">-- ເລືອກປະເພດອຸປະກອນ --</option>
                        <?php foreach (array_keys($categories_assets) as $catName): ?>
                            <option value="<?php echo htmlspecialchars($catName); ?>"><?php echo htmlspecialchars($catName); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase">2. ເລືອກອຸປະກອນ ຊຄທ ທີ່ພະແນກມີ *</label>
                    <select id="selectAssetId" name="asset_id" disabled onchange="handleSelectAsset(this)" class="w-full bg-slate-100 border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:border-amber-500 transition-all disabled:opacity-60 disabled:cursor-not-allowed">
                        <option value="">-- ກະລຸນາເລືອກປະເພດອຸປະກອນກ່ອນ --</option>
                    </select>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase">ປະເພດ *</label>
                        <select name="action_type" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:border-amber-500 transition-all">
                            <option value="use">ນຳໄປໃຊ້ງານ (use)</option>
                            <option value="transfer">ຍົກຍ້າຍ (transfer)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase">quantity (ຈຳນວນ) *</label>
                        <input type="number" id="moveQuantity" name="quantity" min="1" value="1" placeholder="1" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:border-amber-500 transition-all font-semibold text-slate-800">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase">ຜູ້ນຳໃຊ້ *</label>
                    <input type="text" id="takenBy" name="taken_by" placeholder="ລະບຸຊື່ຜູ້ເອົາໄປໃຊ້ງານ/ຍົກຍ້າຍ" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:border-amber-500 transition-all">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase">ສະຖານທີ່ *</label>
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
    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm overflow-hidden">
        <div class="p-5 border-b border-slate-100 flex items-center justify-between">
            <h3 class="text-base font-bold text-slate-800 flex items-center gap-2">
                <i class="fas fa-table text-[#002B66]"></i>
                ຕາຕະລາງ ຈຳນວນຄົງເຫຼືອໃນພະແນກ
            </h3>
            <span class="text-xs font-semibold bg-sky-50 text-sky-700 px-3 py-1 rounded-full border border-sky-200">
                <?php echo count($my_assets); ?> ລາຍການ
            </span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-slate-600">
                <thead class="bg-slate-50 text-slate-500 text-xs uppercase font-semibold border-b border-slate-200">
                    <tr>
                        <th class="px-5 py-3.5">ລະຫັດ</th>
                        <th class="px-5 py-3.5">ປະເພດ / ລາຍການອຸປະກອນ</th>
                        <th class="px-5 py-3.5 text-center">ຈຳນວນ</th>
                        <th class="px-5 py-3.5 text-center">ຍັງເຫຼືອ</th>
                        <th class="px-5 py-3.5 text-center">ວັນທີສ້າງ</th>
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
                                <td class="px-5 py-4 text-center font-bold <?php echo ($asset['asset_current_qty'] ?? 0) > 0 ? 'text-emerald-600' : 'text-rose-500'; ?>">
                                    <?php echo number_format($asset['asset_current_qty'] ?? 0) . ' ' . htmlspecialchars($asset['unit'] ?? ''); ?>
                                </td>
                                <td class="px-5 py-4 text-center text-xs text-slate-400">
                                    <?php echo date('d/m/Y H:i', strtotime($asset['created_at'])); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="px-5 py-8 text-center text-slate-400">ບໍ່ມີຂໍ້ມູນ</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm overflow-hidden">
        <div class="p-5 border-b border-slate-100 flex items-center justify-between">
            <h3 class="text-base font-bold text-slate-800 flex items-center gap-2">
                <i class="fas fa-history text-amber-500"></i>
                ຕາຕະລາງປະຫວັດການເບີກຈ່າຍ ຊຄທ
            </h3>
            <span class="text-xs font-semibold bg-amber-50 text-amber-700 px-3 py-1 rounded-full border border-amber-200">
                <?php echo count($movements_history); ?> ປະຫວັດ
            </span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-slate-600">
                <thead class="bg-slate-50 text-slate-500 text-xs uppercase font-semibold border-b border-slate-200">
                    <tr>
                        <th class="px-5 py-3.5">ວັນທີ</th>
                        <th class="px-5 py-3.5">ລະຫັດ / ຊື່ສິ້ນຄ້າ</th>
                        <th class="px-5 py-3.5 text-center">ປະເພດ</th>
                        <th class="px-5 py-3.5 text-center">ຈຳນວນ</th>
                        <th class="px-5 py-3.5">ຜູ້ນຳໃຊ້</th>
                        <th class="px-5 py-3.5">ສະຖານທີ່</th>
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
                                            <i class="fas fa-right-left mr-1"></i>ຍົກຍ້າຍ
                                        </span>
                                    <?php else: ?>
                                        <span class="px-2.5 py-1 text-xs font-semibold bg-amber-50 text-amber-700 border border-amber-200 rounded-full">
                                            <i class="fas fa-hand-holding mr-1"></i> ນຳໃຊ້
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
                            <td colspan="6" class="px-5 py-8 text-center text-slate-400">ບໍ່ມີຂໍ້ມູນ</td>
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

function onCategoryChangeForm1(catName) {
    const itemSelect = document.getElementById('selectApproved');
    itemSelect.innerHTML = '<option value="">-- ເລືອກອຸປະກອນ --</option>';
    
    document.getElementById('tsg_request_id').value = '';
    document.getElementById('tsg_item_id').value = '';
    document.getElementById('tsg_quantity').value = '1';
    document.getElementById('asset_code_input').value = '';

    if (catName && rawApprovedData[catName]) {
        itemSelect.disabled = false;
        itemSelect.classList.remove('bg-slate-100', 'cursor-not-allowed');
        itemSelect.classList.add('bg-slate-50');

        rawApprovedData[catName].forEach(item => {
            const opt = document.createElement('option');
            opt.value = item.request_id;
            opt.setAttribute('data-item-id', item.item_id);
            opt.setAttribute('data-avail-qty', item.available_qty);
            opt.setAttribute('data-code', item.sys_item_code);
            opt.textContent = `#REQ-${String(item.request_id).padStart(5, '0')} | ${item.item_name} (ຍັງເຫຼືອ: ${item.available_qty} ລາຍການ)`;
            itemSelect.appendChild(opt);
        });
    } else {
        itemSelect.disabled = true;
        itemSelect.classList.add('bg-slate-100', 'cursor-not-allowed');
        itemSelect.classList.remove('bg-slate-50');
    }
}

function onCategoryChangeForm2(catName) {
    const assetSelect = document.getElementById('selectAssetId');
    assetSelect.innerHTML = '<option value="">-- ເລືອກອຸປະກອນ ຊຄທ --</option>';

    document.getElementById('moveQuantity').value = '1';

    if (catName && rawAssetsData[catName]) {
        assetSelect.disabled = false;
        assetSelect.classList.remove('bg-slate-100', 'cursor-not-allowed');
        assetSelect.classList.add('bg-slate-50');

        rawAssetsData[catName].forEach(asset => {
            const opt = document.createElement('option');
            opt.value = asset.asset_id;
            opt.setAttribute('data-max-qty', asset.asset_current_qty);
            opt.textContent = `[${asset.asset_code}] ${asset.item_name} (ຄົງເຫຼືອ: ${asset.asset_current_qty} ${asset.unit || ''})`;
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
    
    const availQty = parseInt(opt.getAttribute('data-avail-qty') || '1');
    const inputQty = document.getElementById('tsg_quantity');
    inputQty.max = availQty;
    if (parseInt(inputQty.value) > availQty) {
        inputQty.value = 1;
    }
    
    if (selectEl.value) {
        const reqId = String(selectEl.value).padStart(3, '0');
        const randomNum = Math.floor(100 + Math.random() * 900);
        document.getElementById('asset_code_input').value = `TSG-${userDept}-${reqId}-${randomNum}`;
    } else {
        document.getElementById('asset_code_input').value = '';
    }
}

function handleSelectAsset(selectEl) {
    const opt = selectEl.options[selectEl.selectedIndex];
    const maxQty = parseInt(opt ? opt.getAttribute('data-max-qty') || '0' : '0');
    const moveInput = document.getElementById('moveQuantity');
    
    moveInput.max = maxQty;
    if (parseInt(moveInput.value) > maxQty && maxQty > 0) {
        moveInput.value = 1;
    }
}

function validateCreateTsg() {
    const cat = document.getElementById('catSelectForm1').value;
    const reqId = document.getElementById('selectApproved').value;
    const itemId = document.getElementById('tsg_item_id').value;
    const createQty = parseInt(document.getElementById('tsg_quantity').value || '0');
    const assetCode = document.getElementById('asset_code_input').value.trim();

    const selectEl = document.getElementById('selectApproved');
    const opt = selectEl.options[selectEl.selectedIndex];
    const maxAvail = parseInt(opt ? opt.getAttribute('data-avail-qty') || '0' : '0');

    if (!cat) {
        Swal.fire({ icon: 'warning', title: 'ແຈ້ງເຕືອນ', text: 'ກະລຸນາເລືອກປະເພດອຸປະກອນກ່ອນ!', confirmButtonColor: '#002B66' });
        return false;
    }
    if (!reqId || !itemId) {
        Swal.fire({ icon: 'warning', title: 'ແຈ້ງເຕືອນ', text: 'ກະລຸນາເລືອກອຸປະກອນທີ່ຕ້ອງການສ້າງ ຊຄທ!', confirmButtonColor: '#002B66' });
        return false;
    }
    if (createQty <= 0) {
        Swal.fire({ icon: 'warning', title: 'ແຈ້ງເຕືອນ', text: 'ກະລຸນາປ້ອນຈຳນວນທີ່ຕ້ອງການສ້າງໃຫ້ຖືກຕ້ອງ!', confirmButtonColor: '#002B66' });
        return false;
    }
    if (createQty > maxAvail) {
        Swal.fire({ icon: 'warning', title: 'ແຈ້ງເຕືອນ', text: `ຈຳນວນທີ່ຕ້ອງການສ້າງເກີນຈຳນວນທີ່ມີໃນພະແນກ! (ສາມາດສ້າງໄດ້ອີກພຽງ ${maxAvail})`, confirmButtonColor: '#002B66' });
        return false;
    }
    if (!assetCode) {
        Swal.fire({ icon: 'warning', title: 'ແຈ້ງເຕືອນ', text: 'ກະລຸນາປ້ອນລະຫັດ ຊຄທ (asset_code)!', confirmButtonColor: '#002B66' });
        return false;
    }
    return true;
}

function validateMovement() {
    const cat = document.getElementById('catSelectForm2').value;
    const assetId = document.getElementById('selectAssetId').value;
    const qty = parseInt(document.getElementById('moveQuantity').value || '0');
    const takenBy = document.getElementById('takenBy').value.trim();

    const selectEl = document.getElementById('selectAssetId');
    const opt = selectEl.options[selectEl.selectedIndex];
    const maxQty = parseInt(opt ? opt.getAttribute('data-max-qty') || '0' : '0');

    if (!cat) {
        Swal.fire({ icon: 'warning', title: 'ແຈ້ງເຕືອນ', text: 'ກະລຸນາເລືອກປະເພດອຸປະກອນກ່ອນ!', confirmButtonColor: '#002B66' });
        return false;
    }
    if (!assetId) {
        Swal.fire({ icon: 'warning', title: 'ແຈ້ງເຕືອນ', text: 'ກະລຸນາເລືອກອຸປະກອນ ຊຄທ ທີ່ຕ້ອງການດຳເນີນການ!', confirmButtonColor: '#002B66' });
        return false;
    }
    if (qty <= 0) {
        Swal.fire({ icon: 'warning', title: 'ແຈ້ງເຕືອນ', text: 'ກະລຸນາປ້ອນຈຳນວນໃຫ້ຖືກຕ້ອງ!', confirmButtonColor: '#002B66' });
        return false;
    }
    if (qty > maxQty) {
        Swal.fire({ icon: 'warning', title: 'ແຈ້ງເຕືອນ', text: `ຈຳນວນທີ່ຕ້ອງການນຳໃຊ້/ຍົກຍ້າຍ เกີນຈຳນວນຄົງເຫຼືອຂອງ ຊຄທ ນີ້! (ຄົງເຫຼືອ: ${maxQty})`, confirmButtonColor: '#002B66' });
        return false;
    }
    if (!takenBy) {
        Swal.fire({ icon: 'warning', title: 'ແຈ້ງເຕືອນ', text: 'ກະລຸນາປ້ອນຊື່ຜູ້ເອົາໄປໃຊ້ງານ/ຍົກຍ້າຍ!', confirmButtonColor: '#002B66' });
        return false;
    }
    return true;
}
</script>
