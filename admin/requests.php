<?php
require_once __DIR__ . '/../includes/config.php';
checkRole(['admin']);
// admin_request.php - ຈັດການຄຳຂໍເບີກ ແລະ ຄຳຂໍສົ່ງຄືນອຸປະກອນ

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================
// 1. ປະມວນຜົນຟອມ (Update Status & Stock Management)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // ----------------------------------------------------
    // A. ຈັດການຄຳຂໍເບີກອຸປະກອນ (Requests Approval/Rejection)
    // ----------------------------------------------------
    if (isset($_POST['update_status'])) {
        $csrf_token = $_POST['csrf_token'] ?? '';
        if (function_exists('verifyCSRFToken') && !verifyCSRFToken($csrf_token)) {
            if (function_exists('showAlert')) showAlert('ຂໍ້ມູນບໍ່ປອດໄພ (CSRF Token Invalid)', 'error');
            echo '<script>window.location.href = "?admin=requests";</script>';
            exit();
        }

        $request_id = (int)($_POST['request_id'] ?? 0);
        $status     = $_POST['status'] ?? null;
        $admin_note = trim($_POST['admin_note'] ?? '');
        
        try {
            $pdo->beginTransaction();

            if ($status !== null && $request_id > 0) {
                $stmt = $pdo->prepare("UPDATE requests SET status = ?, admin_note = ? WHERE id = ?");
                $stmt->execute([$status, $admin_note, $request_id]);
            } else {
                throw new Exception("ຂໍ້ມູນຄຳຂໍບໍ່ຖືກຕ້ອງ!");
            }

            if ($status == 'approved') {
                $reqStmt = $pdo->prepare("SELECT r.*, u.fullname FROM requests r JOIN users u ON r.user_id = u.id WHERE r.id = ?");
                $reqStmt->execute([$request_id]);
                $requestData = $reqStmt->fetch(PDO::FETCH_ASSOC);

                if (!$requestData) {
                    throw new Exception("ບໍ່ພົບຂໍ້ມູນຄຳຂໍເບີກ #{$request_id}");
                }

                $itemsStmt = $pdo->prepare("SELECT item_id, quantity FROM request_items WHERE request_id = ?");
                $itemsStmt->execute([$request_id]);
                $requestedItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
                
                $checkStock = $pdo->prepare("SELECT quantity, name, serial_number FROM items WHERE id = ? FOR UPDATE");
                $stmtUpdateStock = $pdo->prepare("UPDATE items SET quantity = quantity - ? WHERE id = ?");

                $stmtMovement = $pdo->prepare("
                    INSERT INTO stock_movements (
                        item_id, movement_type, quantity, old_quantity, new_quantity, 
                        to_department, serial_number, note, created_by, created_at
                    ) VALUES (?, 'issue', ?, ?, ?, ?, ?, ?, ?, NOW())
                ");

                $stmtIssuance = $pdo->prepare("
                    INSERT INTO item_issuances (
                        issue_code, item_id, issued_to, department, quantity, issue_date, status, remark, created_by, created_at
                    ) VALUES (?, ?, ?, ?, ?, NOW(), 'issued', ?, ?, NOW())
                ");

                foreach ($requestedItems as $item) {
                    $checkStock->execute([$item['item_id']]);
                    $stock = $checkStock->fetch(PDO::FETCH_ASSOC);

                    $requested_qty = (int)$item['quantity'];
                    $current_qty   = $stock ? (int)$stock['quantity'] : 0;

                    if (!$stock || $current_qty < $requested_qty) {
                        $itemName = $stock['name'] ?? 'ID: '.$item['item_id'];
                        throw new Exception("ບໍ່ສາມາດອະນຸມັດໄດ້ ເນື່ອງຈາກອຸປະກອນ {$itemName} ມີບໍ່ພໍໃນສາງ (ມີຢູ່: {$current_qty} ອັນ)");
                    }

                    $old_qty = $current_qty;
                    $new_qty = $old_qty - $requested_qty;

                    $stmtUpdateStock->execute([$requested_qty, $item['item_id']]);

                    $dept = !empty($requestData['department']) ? $requestData['department'] : 'ບໍ່ລະບຸພະແນກ';
                    $note = "ເບີກຈ່າຍຕາມຄຳຂໍ ID: #{$request_id}" . ($admin_note ? " ({$admin_note})" : "");
                    
                    $stmtMovement->execute([
                        $item['item_id'],
                        $requested_qty,
                        $old_qty,
                        $new_qty,
                        $dept,
                        $stock['serial_number'] ?? null,
                        $note,
                        $_SESSION['user_id'] ?? null
                    ]);

                    $issue_code = 'ISS-' . date('Ym') . '-' . sprintf("%04d", rand(1, 9999));

                    $stmtIssuance->execute([
                        $issue_code,
                        $item['item_id'],
                        $requestData['fullname'],
                        $dept,
                        $requested_qty,
                        $note,
                        $_SESSION['user_id'] ?? null
                    ]);
                }
            }
           
            $pdo->commit();

            if (function_exists('showAlert')) showAlert('ອັບເດດສະຖານະສຳເລັດ', 'success');
            if (function_exists('logActivity')) logActivity("ອັບເດດສະຖານະຄຳຂໍ ID: {$request_id} ເປັນ: {$status}");
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (function_exists('showAlert')) showAlert('ເກີດຂໍ້ຜິດພາດ ກະລຸນາລອງໃໝ່', 'error');
        }

        $current_tab = $_GET['tab'] ?? 'pending';
        echo "<script>window.location.href = '?admin=requests&tab={$current_tab}';</script>";
        exit();
    }

    // ----------------------------------------------------
    // B. ຈັດການການອະນຸມັດຮັບຄືນອຸປະກອນ (Return Requests Approval)
    // ----------------------------------------------------
    if (isset($_POST['action_approve_return'])) {
        $csrf_token = $_POST['csrf_token'] ?? '';
        if (function_exists('verifyCSRFToken') && !verifyCSRFToken($csrf_token)) {
            if (function_exists('showAlert')) showAlert('ຂໍ້ມູນບໍ່ປອດໄພ (CSRF Token Invalid)', 'error');
            echo '<script>window.location.href = "?admin=requests&tab=returns";</script>';
            exit();
        }

        $return_id  = (int)($_POST['return_id'] ?? 0);
        $admin_note = trim($_POST['admin_note'] ?? '');

        try {
            $pdo->beginTransaction();

            // 1. ດຶງຂໍ້ມູນຄຳຂໍສົ່ງຄືນ
            $stFetch = $pdo->prepare("
                SELECT r.*, i.item_code, i.serial_number as item_serial 
                FROM return_requests r 
                LEFT JOIN items i ON r.item_id = i.id 
                WHERE r.id = ? AND r.status = 'pending'
                FOR UPDATE
            ");
            $stFetch->execute([$return_id]);
            $returnReq = $stFetch->fetch(PDO::FETCH_ASSOC);

            if ($returnReq) {
                // 2. ອັບເດດສະຖານະຄຳຂໍສົ່ງຄືນເປັນ 'approved'
                $stApprove = $pdo->prepare("
                    UPDATE return_requests 
                    SET status = 'approved', reason = CONCAT(IFNULL(reason, ''), ' [ໝາຍເຫດ Admin: ', ?, ']')
                    WHERE id = ?
                ");
                $stApprove->execute([$admin_note, $return_id]);

                // 3. ຄົ້ນຫາ item_id ເກົ່າ ໂດຍໃຊ້ item_code (ເຊັ່ນ: 002) ຫຼື item_id ທີ່ສົ່ງມາ
                $target_item_id = $returnReq['item_id'];
                if (!empty($returnReq['item_code'])) {
                    $stCheckCode = $pdo->prepare("SELECT id FROM items WHERE item_code = ? LIMIT 1");
                    $stCheckCode->execute([$returnReq['item_code']]);
                    $foundItem = $stCheckCode->fetch(PDO::FETCH_ASSOC);
                    if ($foundItem) {
                        $target_item_id = $foundItem['id'];
                    }
                }

                // 4. ດຶງຈຳນວນເດີມທີ່ມີຢູ່ໃນ items
                $stGetStock = $pdo->prepare("SELECT quantity, serial_number FROM items WHERE id = ? FOR UPDATE");
                $stGetStock->execute([$target_item_id]);
                $itemStock = $stGetStock->fetch(PDO::FETCH_ASSOC);

                if (!$itemStock) {
                    throw new Exception("ບໍ່ພົບຂໍ້ມູນອຸປະກອນໃນສາງທີ່ລະຫັດກົງກັນ!");
                }

                // 5. ບວກຈຳນວນອຸປະກອນຮັບຄືນ ເຂົ້າໃນ quantity (ສະຕັອກເກົ່າ) ທຸກກໍລະນີ
                $return_qty = (int)$returnReq['quantity'];
                $old_qty    = (int)($itemStock['quantity'] ?? 0);
                $new_qty    = $old_qty + $return_qty;

                $stStock = $pdo->prepare("UPDATE items SET quantity = quantity + ? WHERE id = ?");
                $stStock->execute([$return_qty, $target_item_id]);
                
                $msg_status = "ອະນຸມັດຮັບຄືນ ແລະ ບວກເຂົ້າສະຕັອກເກົ່າສຳເລັດ";

                // 6. ກຳນົດ Serial Number
                $serial_number = $returnReq['serial_number'] ?? $returnReq['item_serial'] ?? ($itemStock['serial_number'] ?? null);

                // 7. ບັນທຶກປະຫວັດການເຄື່ອນໄຫວລົງ stock_movements
                $note = "ຮັບຄືນອຸປະກອນລະຫັດ: {$returnReq['item_code']} ຈາກພະແນກ: {$returnReq['department']} (ສະພາບ: {$returnReq['item_condition']})" . ($admin_note ? " - {$admin_note}" : "");
                $stMovement = $pdo->prepare("
                    INSERT INTO stock_movements (
                        item_id, movement_type, quantity, old_quantity, new_quantity, 
                        from_department, serial_number, note, created_by, created_at
                    ) VALUES (?, 'return', ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stMovement->execute([
                    $target_item_id,
                    $return_qty,
                    $old_qty,
                    $new_qty,
                    $returnReq['department'],
                    $serial_number,
                    $note,
                    $_SESSION['user_id'] ?? null
                ]);

                // 8. ອັບເດດສະຖານະໃນ item_issuances ເປັນ 'returned'
                if (!empty($returnReq['issuance_id'])) {
                    $stIssuance = $pdo->prepare("UPDATE item_issuances SET status = 'returned' WHERE id = ?");
                    $stIssuance->execute([$returnReq['issuance_id']]);
                } else {
                    $stIssuance = $pdo->prepare("
                        UPDATE item_issuances 
                        SET status = 'returned' 
                        WHERE item_id = ? AND status = 'issued' 
                        ORDER BY id DESC LIMIT 1
                    ");
                    $stIssuance->execute([$target_item_id]);
                }

                $pdo->commit();
                if (function_exists('showAlert')) showAlert($msg_status, 'success');
            } else {
                $pdo->rollBack();
                if (function_exists('showAlert')) showAlert('ບໍ່ພົບຂໍ້ມູນ ຫຼື ຄຳຂໍຖືກດຳເນີນການໄປແລ້ວ', 'error');
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if (function_exists('showAlert')) showAlert('ເກີດຂໍ້ຜິດພາດ ກະລຸນາລອງໃໝ່', 'error');
        }

        echo "<script>window.location.href = '?admin=requests&tab=returns';</script>";
        exit();
    }
}

// ============================================================
// 2. ດຶງຂໍ້ມູນຄຳຂໍ
// ============================================================
$pending  = $pdo->query("SELECT r.*, u.fullname FROM requests r JOIN users u ON r.user_id = u.id WHERE r.status = 'pending' ORDER BY r.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$approved = $pdo->query("SELECT r.*, u.fullname FROM requests r JOIN users u ON r.user_id = u.id WHERE r.status = 'approved' ORDER BY r.created_at DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
$rejected = $pdo->query("SELECT r.*, u.fullname FROM requests r JOIN users u ON r.user_id = u.id WHERE r.status = 'rejected' ORDER BY r.created_at DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);

$sqlReturns = "
    SELECT 
        rr.*,
        i.name as item_name,
        i.item_code,
        i.serial_number,
        i.unit,
        u.fullname as user_name
    FROM return_requests rr
    LEFT JOIN items i ON rr.item_id = i.id
    LEFT JOIN users u ON rr.user_id = u.id
    WHERE rr.status = 'pending'
    ORDER BY rr.created_at DESC
";
$return_requests = $pdo->query($sqlReturns)->fetchAll(PDO::FETCH_ASSOC);

function getRequestItems($pdo, $request_id) {
    $stmt = $pdo->prepare("SELECT ri.*, i.name, i.unit, i.serial_number FROM request_items ri JOIN items i ON ri.item_id = i.id WHERE ri.request_id = ?");
    $stmt->execute([$request_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$all_requests_detail = [];
$all_list = array_merge($pending, $approved, $rejected);
foreach ($all_list as $req) {
    $items = getRequestItems($pdo, $req['id']);
    $formatted_date = function_exists('formatDate') ? formatDate($req['request_date']) : $req['request_date'];
    
    $all_requests_detail[$req['id']] = [
        'id'           => $req['id'],
        'fullname'     => $req['fullname'],
        'purpose'      => $req['purpose'],
        'status'       => $req['status'],
        'request_date' => $formatted_date,
        'admin_note'   => $req['admin_note'] ?? '',
        'items'        => $items
    ];
}

$tab = isset($_GET['tab']) ? $_GET['tab'] : 'pending';
?>

<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-800">ຈັດການຄຳຂໍເບີກ ແລະ ສົ່ງຄືນອຸປະກອນ</h1>
    <p class="text-gray-600">ອະນຸມັດ ຫຼື ປະຕິເສດຄຳຂໍເບີກ ແລະ ອະນຸມັດຮັບຄືນອຸປະກອນ</p>
</div>

<?php if (function_exists('getAlert')): ?>
    <?php $alert = getAlert(); if ($alert): ?>
        <div class="p-4 mb-4 rounded-lg <?php echo $alert['type'] == 'success' ? 'bg-green-100 text-green-700 border border-green-200' : 'bg-red-100 text-red-700 border border-red-200'; ?>">
            <i class="fas <?php echo $alert['type'] == 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?> mr-2"></i>
            <?php echo htmlspecialchars($alert['message']); ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<!-- Tabs -->
<div class="flex border-b mb-6 overflow-x-auto">
    <button onclick="switchTab('pending')" class="px-4 py-2 font-medium whitespace-nowrap <?php echo $tab == 'pending' ? 'text-blue-600 border-b-2 border-blue-600' : 'text-gray-500 hover:text-gray-700'; ?>">
        ລໍຖ້າອະນຸມັດເບີກ (<?php echo count($pending); ?>)
    </button>
    <button onclick="switchTab('returns')" class="px-4 py-2 font-medium whitespace-nowrap <?php echo $tab == 'returns' ? 'text-blue-600 border-b-2 border-blue-600' : 'text-gray-500 hover:text-gray-700'; ?>">
        <i class="fas fa-rotate-left mr-1"></i>ຄຳຂໍສົ່ງຄືນ (<?php echo count($return_requests); ?>)
    </button>
    <button onclick="switchTab('approved')" class="px-4 py-2 font-medium whitespace-nowrap <?php echo $tab == 'approved' ? 'text-blue-600 border-b-2 border-blue-600' : 'text-gray-500 hover:text-gray-700'; ?>">
        ອະນຸມັດເບີກແລ້ວ (<?php echo count($approved); ?>)
    </button>
    <button onclick="switchTab('rejected')" class="px-4 py-2 font-medium whitespace-nowrap <?php echo $tab == 'rejected' ? 'text-blue-600 border-b-2 border-blue-600' : 'text-gray-500 hover:text-gray-700'; ?>">
        ປະຕິເສດເບີກ (<?php echo count($rejected); ?>)
    </button>
</div>

<!-- 1. ລໍຖ້າອະນຸມັດເບີກ -->
<div id="tab-pending" class="<?php echo $tab != 'pending' ? 'hidden' : ''; ?>">
    <?php if (count($pending) > 0): ?>
        <?php foreach ($pending as $request): 
            $request_items = $all_requests_detail[$request['id']]['items'] ?? [];
            $display_date  = function_exists('formatDate') ? formatDate($request['request_date']) : $request['request_date'];
        ?>
            <div class="card mb-4 p-4 bg-white rounded-xl shadow-sm border">
                <div class="flex flex-wrap justify-between items-start gap-4">
                    <div>
                        <div class="flex items-center gap-2 mb-1">
                            <span class="text-sm font-medium text-gray-500">#<?php echo $request['id']; ?></span>
                            <span class="text-xs px-2 py-1 bg-yellow-100 text-yellow-800 rounded-full">ລໍຖ້າອະນຸມັດ</span>
                        </div>
                        <h3 class="text-lg font-bold text-gray-800"><?php echo htmlspecialchars($request['fullname']); ?></h3>
                        <p class="text-sm text-gray-600"><?php echo htmlspecialchars($request['purpose']); ?></p>
                        <p class="text-xs text-gray-400 mt-1">ວັນທີ: <?php echo htmlspecialchars($display_date); ?></p>
                    </div>
                    <div class="flex gap-2">
                        <button onclick="showRequestDetail(<?php echo $request['id']; ?>)" class="text-blue-600 hover:text-blue-800 flex items-center gap-1 text-sm font-medium">
                            <i class="fas fa-eye"></i> ເບິ່ງລາຍລະອຽດ
                        </button>
                    </div>
                </div>
                
                <div class="mt-3 pt-3 border-t">
                    <div class="flex flex-wrap gap-2">
                        <?php foreach ($request_items as $item): ?>
                            <span class="text-xs bg-gray-100 px-2 py-1 rounded">
                                <?php echo htmlspecialchars($item['name']); ?>
                                <?php if (!empty($item['serial_number'])): ?>
                                    <span class="font-mono text-gray-500">(SN: <?php echo htmlspecialchars($item['serial_number']); ?>)</span>
                                <?php endif; ?>
                                - <?php echo (int)$item['quantity']; ?> <?php echo htmlspecialchars($item['unit']); ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="mt-3 flex gap-2">
                        <button onclick="updateStatus(<?php echo $request['id']; ?>, 'approved')" class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 text-sm">
                            <i class="fas fa-check mr-1"></i>ອະນຸມັດ
                        </button>
                        <button onclick="updateStatus(<?php echo $request['id']; ?>, 'rejected')" class="px-4 py-2 bg-red-500 text-white rounded-lg hover:bg-red-600 text-sm">
                            <i class="fas fa-times mr-1"></i>ປະຕິເສດ
                        </button>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="text-center py-12 text-gray-500">
            <i class="fas fa-check-circle text-4xl text-green-500 mb-3 block"></i>
            <p>ບໍ່ມີຄຳຂໍເບີກທີ່ລໍຖ້າອະນຸມັດ</p>
        </div>
    <?php endif; ?>
</div>

<!-- 2. ຄຳຂໍສົ່ງຄືນອຸປະກອນ -->
<div id="tab-returns" class="<?php echo $tab != 'returns' ? 'hidden' : ''; ?>">
    <div class="card overflow-hidden bg-white rounded-xl shadow-sm border border-amber-200">
        <div class="p-4 bg-amber-50/50 border-b border-amber-100 flex items-center justify-between">
            <h3 class="font-bold text-amber-900 flex items-center gap-2">
                <i class="fas fa-rotate-left text-amber-600"></i>
                ລາຍການຄຳຂໍສົ່ງຄືນອຸປະກອນ (ລໍຖ້າອະນຸມັດ)
            </h3>
            <span class="bg-amber-200 text-amber-800 text-xs px-2.5 py-0.5 rounded-full font-bold">
                <?php echo count($return_requests); ?> ລາຍການ
            </span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-gray-50 text-xs text-gray-500 uppercase font-semibold border-b">
                        <th class="p-4">ວັນທີ / ຜູ້ສົ່ງຄືນ</th>
                        <th class="p-4">ພະແນກ</th>
                        <th class="p-4">ອຸປະກອນ</th>
                        <th class="p-4">Serial Number</th>
                        <th class="p-4 text-center">ຈຳນວນ</th>
                        <th class="p-4 text-center">ສະພາບ</th>
                        <th class="p-4 text-center">ສະຖານະ</th>
                        <th class="p-4 text-center">ຈັດການ</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 text-sm">
                    <?php if (!empty($return_requests)): ?>
                        <?php foreach ($return_requests as $ret): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="p-4">
                                    <span class="font-bold text-gray-800 block"><?php echo htmlspecialchars($ret['user_name'] ?? 'ຜູ້ໃຊ້'); ?></span>
                                    <span class="text-xs text-gray-400">📅 <?php echo date('d/m/Y H:i', strtotime($ret['created_at'])); ?></span>
                                </td>
                                <td class="p-4">
                                    <span class="font-semibold text-indigo-700 bg-indigo-50 px-2.5 py-1 rounded-lg text-xs">
                                        <i class="fas fa-building text-indigo-500 mr-1"></i><?php echo htmlspecialchars($ret['department']); ?>
                                    </span>
                                </td>
                                <td class="p-4">
                                    <div class="font-bold text-gray-800"><?php echo htmlspecialchars($ret['item_name']); ?></div>
                                    <div class="text-xs text-gray-400 font-mono"><?php echo htmlspecialchars($ret['item_code']); ?></div>
                                    <?php if (!empty($ret['reason'])): ?>
                                        <p class="text-xs text-gray-500 italic mt-0.5">"<?php echo htmlspecialchars($ret['reason']); ?>"</p>
                                    <?php endif; ?>
                                </td>
                                <td class="p-4 font-mono text-xs text-gray-700">
                                    <?php if (!empty($ret['serial_number'])): ?>
                                        <span class="bg-gray-100 px-2 py-1 rounded border text-gray-800 font-semibold">
                                            SN: <?php echo htmlspecialchars($ret['serial_number']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-gray-400 italic">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-4 text-center font-bold text-gray-800">
                                    <?php echo number_format($ret['quantity']); ?> <?php echo htmlspecialchars($ret['unit']); ?>
                                </td>
                                <td class="p-4 text-center">
                                    <?php 
                                    $cond_badges = [
                                        'good' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                                        'fair' => 'bg-amber-50 text-amber-700 border-amber-200',
                                        'damaged' => 'bg-orange-50 text-orange-700 border-orange-200',
                                        'broken' => 'bg-rose-50 text-rose-700 border-rose-200'
                                    ];
                                    $cond_labels = [
                                        'good' => 'ດີ',
                                        'fair' => 'ປານກາງ',
                                        'damaged' => 'ເປ່ເພ',
                                        'broken' => 'ເພ/ຊຳລຸດໜັກ'
                                    ];
                                    $ckey = $ret['item_condition'] ?? 'good';
                                    ?>
                                    <span class="px-2.5 py-0.5 text-xs font-medium border rounded-md <?php echo $cond_badges[$ckey] ?? $cond_badges['good']; ?>">
                                        <?php echo $cond_labels[$ckey] ?? 'ດີ'; ?>
                                    </span>
                                </td>
                                <td class="p-4 text-center">
                                    <span class="px-2.5 py-1 text-xs rounded-full bg-amber-100 text-amber-700 font-semibold animate-pulse">⏳ ລໍຖ້າອະນຸມັດ</span>
                                </td>
                                <td class="p-4 text-center">
                                    <button onclick="openApproveReturnModal(<?php echo $ret['id']; ?>, '<?php echo htmlspecialchars($ret['item_name'], ENT_QUOTES); ?>', <?php echo (int)$ret['quantity']; ?>, '<?php echo $ret['item_condition']; ?>')" class="bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-1.5 rounded-lg text-xs font-medium transition shadow-sm flex items-center gap-1 mx-auto">
                                        <i class="fas fa-check-circle"></i> ອະນຸມັດຮັບຄືນ
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="p-8 text-center text-gray-400">ບໍ່ມີຄຳຂໍສົ່ງຄືນອຸປະກອນທີ່ລໍຖ້າອະນຸມັດ</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- 3. ອະນຸມັດເບີກແລ້ວ -->
<div id="tab-approved" class="<?php echo $tab != 'approved' ? 'hidden' : ''; ?>">
    <?php if (count($approved) > 0): ?>
        <div class="card bg-white p-4 rounded-xl shadow-sm border">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b">
                            <th class="text-left py-2 px-3 text-sm font-medium text-gray-500">ID</th>
                            <th class="text-left py-2 px-3 text-sm font-medium text-gray-500">ຜູ້ຂໍ</th>
                            <th class="text-left py-2 px-3 text-sm font-medium text-gray-500">ຈຸດປະສົງ</th>
                            <th class="text-left py-2 px-3 text-sm font-medium text-gray-500">ວັນທີ</th>
                            <th class="text-center py-2 px-3 text-sm font-medium text-gray-500">ຈັດການ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($approved as $request): 
                            $display_date = function_exists('formatDate') ? formatDate($request['request_date']) : $request['request_date'];
                        ?>
                            <tr class="border-b hover:bg-gray-50">
                                <td class="py-2 px-3 text-sm">#<?php echo $request['id']; ?></td>
                                <td class="py-2 px-3 text-sm font-medium"><?php echo htmlspecialchars($request['fullname']); ?></td>
                                <td class="py-2 px-3 text-sm text-gray-600"><?php echo htmlspecialchars($request['purpose']); ?></td>
                                <td class="py-2 px-3 text-sm text-gray-500"><?php echo htmlspecialchars($display_date); ?></td>
                                <td class="py-2 px-3 text-sm text-center">
                                    <button onclick="showRequestDetail(<?php echo $request['id']; ?>)" class="text-blue-600 hover:text-blue-800 font-medium">
                                        <i class="fas fa-eye"></i> ເບິ່ງລາຍລະອຽດ
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php else: ?>
        <div class="text-center py-12 text-gray-500">
            <i class="fas fa-inbox text-4xl mb-3 block"></i>
            <p>ບໍ່ມີຄຳຂໍເບີກທີ່ອະນຸມັດ</p>
        </div>
    <?php endif; ?>
</div>

<!-- 4. ປະຕິເສດເບີກ -->
<div id="tab-rejected" class="<?php echo $tab != 'rejected' ? 'hidden' : ''; ?>">
    <?php if (count($rejected) > 0): ?>
        <div class="card bg-white p-4 rounded-xl shadow-sm border">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b">
                            <th class="text-left py-2 px-3 text-sm font-medium text-gray-500">ID</th>
                            <th class="text-left py-2 px-3 text-sm font-medium text-gray-500">ຜູ້ຂໍ</th>
                            <th class="text-left py-2 px-3 text-sm font-medium text-gray-500">ຈຸດປະສົງ</th>
                            <th class="text-left py-2 px-3 text-sm font-medium text-gray-500">ໝາຍເຫດ</th>
                            <th class="text-center py-2 px-3 text-sm font-medium text-gray-500">ຈັດການ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rejected as $request): ?>
                            <tr class="border-b hover:bg-gray-50">
                                <td class="py-2 px-3 text-sm">#<?php echo $request['id']; ?></td>
                                <td class="py-2 px-3 text-sm font-medium"><?php echo htmlspecialchars($request['fullname']); ?></td>
                                <td class="py-2 px-3 text-sm text-gray-600"><?php echo htmlspecialchars($request['purpose']); ?></td>
                                <td class="py-2 px-3 text-sm text-gray-500"><?php echo !empty($request['admin_note']) ? htmlspecialchars($request['admin_note']) : '-'; ?></td>
                                <td class="py-2 px-3 text-sm text-center">
                                    <button onclick="showRequestDetail(<?php echo $request['id']; ?>)" class="text-blue-600 hover:text-blue-800 font-medium">
                                        <i class="fas fa-eye"></i> ເບິ່ງລາຍລະອຽດ
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php else: ?>
        <div class="text-center py-12 text-gray-500">
            <i class="fas fa-inbox text-4xl mb-3 block"></i>
            <p>ບໍ່ມີຄຳຂໍເບີກທີ່ປະຕິເສດ</p>
        </div>
    <?php endif; ?>
</div>

<!-- Modal ສະແດງລາຍລະອຽດຄຳຂໍເບີກ -->
<div id="detailModal" class="fixed inset-0 bg-black/50 flex items-center justify-center hidden z-50 p-4">
    <div class="bg-white rounded-2xl max-w-lg w-full overflow-hidden shadow-xl">
        <div class="p-6">
            <div class="flex justify-between items-center mb-4 pb-2 border-b">
                <h3 class="text-xl font-bold text-gray-800">ລາຍລະອຽດຄຳຂໍເບີກ</h3>
                <button onclick="closeDetailModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <div id="detailModalContent" class="py-2"></div>
            
            <div class="flex justify-end mt-6 pt-3 border-t">
                <button type="button" onclick="closeDetailModal()" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 text-sm font-medium">
                    ປິດ
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal ອັບເດດສະຖານະຄຳຂໍເບີກ -->
<div id="statusModal" class="fixed inset-0 bg-black/50 flex items-center justify-center hidden z-50 p-4">
    <div class="bg-white rounded-2xl max-w-md w-full">
        <div class="p-6">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-bold">ອັບເດດສະຖານະ</h3>
                <button onclick="closeStatusModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo function_exists('generateCSRFToken') ? generateCSRFToken() : ''; ?>">
                <input type="hidden" name="request_id" id="status_request_id">
                <input type="hidden" name="status" id="status_value">
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">ໝາຍເຫດ</label>
                    <textarea name="admin_note" id="status_note" rows="3" class="w-full p-2 border rounded-lg focus:ring-2 focus:ring-blue-500" placeholder="ລະບຸເຫດຜົນ..."></textarea>
                </div>
                
                <div class="flex justify-end gap-3">
                    <button type="button" onclick="closeStatusModal()" class="px-4 py-2 text-gray-600 border rounded-lg hover:bg-gray-50">
                        ຍົກເລີກ
                    </button>
                    <button type="submit" name="update_status" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                        <i class="fas fa-save mr-2"></i>ບັນທຶກ
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal ອະນຸມັດຮັບຄືນອຸປະກອນ -->
<div id="modalApproveReturn" class="fixed inset-0 bg-black/50 hidden flex items-center justify-center p-4 z-50">
    <div class="bg-white rounded-xl max-w-sm w-full p-6 space-y-4 shadow-xl">
        <h3 class="text-lg font-bold text-gray-800 border-b pb-2 flex items-center gap-2">
            <i class="fas fa-box-open text-emerald-600"></i>
            ຢືນຢັນການອະນຸມັດຮັບຄືນ
        </h3>
        <form method="POST" class="space-y-3">
            <input type="hidden" name="csrf_token" value="<?php echo function_exists('generateCSRFToken') ? generateCSRFToken() : ''; ?>">
            <input type="hidden" name="action_approve_return" value="1">
            <input type="hidden" name="return_id" id="approve_return_id">

            <p class="text-sm text-gray-600">ອຸປະກອນ: <span id="approve_item_name" class="font-bold text-gray-800"></span></p>
            <p class="text-sm text-gray-600">ຈຳນວນທີ່ຈະບວກເຂົ້າ: <span id="approve_item_qty" class="font-bold text-emerald-600"></span></p>
            <p class="text-sm text-gray-600">ປາຍທາງການບວກສະຕັອກ: <span id="approve_item_target" class="font-bold text-indigo-600"></span></p>

            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">ໝາຍເຫດ Admin (ຖ້າມີ)</label>
                <textarea name="admin_note" rows="2" class="w-full p-2 border text-sm rounded-lg" placeholder="ກວດເຊັກສະພາບຮຽບຮ້ອຍແລ້ວ..."></textarea>
            </div>

            <div class="flex justify-end gap-2 pt-2 border-t">
                <button type="button" onclick="document.getElementById('modalApproveReturn').classList.add('hidden')" class="px-4 py-2 border rounded-lg text-sm text-gray-600">ຍົກເລີກ</button>
                <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg text-sm font-medium shadow-sm">
                    <i class="fas fa-check"></i> ຢືນຢັນອະນຸມັດ
                </button>
            </div>
        </form>
    </div>
</div>

<script>
const requestsData = <?php echo json_encode($all_requests_detail, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

function switchTab(tab) {
    window.location.href = '?admin=requests&tab=' + tab;
}

function updateStatus(id, status) {
    document.getElementById('status_request_id').value = id;
    document.getElementById('status_value').value = status;
    document.getElementById('statusModal').classList.remove('hidden');
}

function closeStatusModal() {
    document.getElementById('statusModal').classList.add('hidden');
}

function openApproveReturnModal(id, itemName, qty, condition) {
    document.getElementById('approve_return_id').value = id;
    document.getElementById('approve_item_name').textContent = itemName;
    document.getElementById('approve_item_qty').textContent = qty;
    
    // ບວກເຂົ້າ quantity ເກົ່າສະເໝີ ທຸກສະພາບ
    document.getElementById('approve_item_target').textContent = 'ສະຕັອກເກົ່າ (quantity)';
    document.getElementById('modalApproveReturn').classList.remove('hidden');
}

function showRequestDetail(id) {
    const data = requestsData[id];
    if (!data) return;

    let statusBadge = '';
    if (data.status === 'pending') {
        statusBadge = '<span class="text-xs px-2 py-1 bg-yellow-100 text-yellow-800 rounded-full">ລໍຖ້າອະນຸມັດ</span>';
    } else if (data.status === 'approved') {
        statusBadge = '<span class="text-xs px-2 py-1 bg-green-100 text-green-800 rounded-full">ອະນຸມັດແລ້ວ</span>';
    } else {
        statusBadge = '<span class="text-xs px-2 py-1 bg-red-100 text-red-800 rounded-full">ປະຕິເສດ</span>';
    }

    let itemsHtml = '';
    if (data.items && data.items.length > 0) {
        data.items.forEach((item, index) => {
            const snText = item.serial_number ? ` <span class="text-xs text-gray-500 font-mono">(SN: ${escapeHtml(item.serial_number)})</span>` : '';
            itemsHtml += `
                <tr>
                    <td class="py-2 px-3 text-gray-400 border-b">${index + 1}</td>
                    <td class="py-2 px-3 font-medium border-b">${escapeHtml(item.name)}${snText}</td>
                    <td class="py-2 px-3 text-right border-b">${item.quantity} ${escapeHtml(item.unit)}</td>
                </tr>
            `;
        });
    } else {
        itemsHtml = '<tr><td colspan="3" class="text-center py-2 text-gray-400">ບໍ່ມີລາຍການອຸປະກອນ</td></tr>';
    }

    let adminNoteHtml = '';
    if (data.admin_note) {
        adminNoteHtml = `
            <div>
                <p class="text-xs font-semibold text-gray-500 uppercase">ໝາຍເຫດຈາກແອັດມິນ:</p>
                <p class="text-sm text-gray-800 bg-amber-50 p-2.5 rounded-lg border border-amber-200 mt-1">${escapeHtml(data.admin_note)}</p>
            </div>
        `;
    }

    const html = `
        <div class="space-y-4 text-left">
            <div class="flex justify-between items-start border-b pb-3">
                <div>
                    <div class="flex items-center gap-2 mb-1">
                        <span class="text-sm font-semibold text-gray-500">#${data.id}</span>
                        ${statusBadge}
                    </div>
                    <h4 class="text-lg font-bold text-gray-800">${escapeHtml(data.fullname)}</h4>
                </div>
                <p class="text-xs text-gray-400">ວັນທີ: ${escapeHtml(data.request_date)}</p>
            </div>

            <div>
                <p class="text-xs font-semibold text-gray-500 uppercase">ຈຸດປະສົງ:</p>
                <p class="text-sm text-gray-800 bg-gray-50 p-2.5 rounded-lg border mt-1">${escapeHtml(data.purpose)}</p>
            </div>

            ${adminNoteHtml}

            <div>
                <p class="text-xs font-semibold text-gray-500 uppercase mb-2">ລາຍການອຸປະກອນທີ່ຂໍເບີກ:</p>
                <div class="border rounded-lg overflow-hidden">
                    <table class="w-full text-sm text-left">
                        <thead class="bg-gray-100 text-gray-600 border-b">
                            <tr>
                                <th class="py-2 px-3">#</th>
                                <th class="py-2 px-3">ລາຍການ</th>
                                <th class="py-2 px-3 text-right">ຈຳນວນ</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${itemsHtml}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    `;

    document.getElementById('detailModalContent').innerHTML = html;
    document.getElementById('detailModal').classList.remove('hidden');
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

function closeDetailModal() {
    document.getElementById('detailModal').classList.add('hidden');
}
</script>
