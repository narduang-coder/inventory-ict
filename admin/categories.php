<?php
require_once __DIR__ . '/../includes/config.php';
checkRole(['admin']);
// ============================================================
// 1. SESSION & HELPER FUNCTIONS
// ============================================================

$current_username  = $_SESSION['username'] ?? $_SESSION['user_name'] ?? $_SESSION['name'] ?? 'admin';
$current_user_role = $_SESSION['role'] ?? ($_SESSION['is_admin'] ?? false ? 'admin' : 'user');
$is_admin          = ($current_user_role === 'admin' || !empty($_SESSION['is_admin']));
$current_fullname  = $_SESSION['fullname'] ?? $_SESSION['name'] ?? '';

if (!function_exists('verifyCSRFToken')) {
    function verifyCSRFToken($token) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
}

if (!function_exists('generateCSRFToken')) {
    function generateCSRFToken() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('showAlert')) {
    function showAlert($message, $type = 'info') {
        $_SESSION['flash_alert'] = ['message' => $message, 'type' => $type];
    }
}

if (!function_exists('getAlert')) {
    function getAlert() {
        if (isset($_SESSION['flash_alert'])) {
            $alert = $_SESSION['flash_alert'];
            unset($_SESSION['flash_alert']);
            return $alert;
        }
        return null;
    }
}

function recordLog($pdo, $item_id, $action_type, $details, $username) {
    try {
        $stmt = $pdo->prepare("INSERT INTO item_logs (item_id, action_type, details, created_by, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$item_id, $action_type, $details, $username]);
    } catch (Exception $e) {
        // ຂ້າມຖ້າຍັງບໍ່ມີ table item_logs
    }
}

// ============================================================
// 2. FORM PROCESSING (ປະມວນຜົນການກົດຊຳລະ & ຕັດສະຕັອກທັງໝົດ)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dispose_item'])) {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($csrf_token)) {
        showAlert('ຂໍ້ມູນບໍ່ປອດໄພ (CSRF Token invalid)', 'error');
        echo '<script>window.location.href = "?admin=categories";</script>';
        exit();
    }

    $id             = (int)$_POST['item_id'];
    $request_id     = (int)($_POST['request_id'] ?? 0);
    $disposed_qty   = (int)($_POST['disposed_qty'] ?? 0);
    $note           = trim($_POST['note'] ?? '');
    
    if (!$is_admin) {
        showAlert('ທ່ານບໍ່ມີສິດປະຕິບັດການນີ້', 'error');
        echo '<script>window.location.href = "?admin=categories";</script>';
        exit();
    }
    
    try {
        if (!isset($pdo)) {
            throw new Exception('ບໍ່ສາມາດເຊື່ອມຕໍ່ຖານຂໍ້ມູນ');
        }

        $pdo->beginTransaction();

        $stmtSel = $pdo->prepare("SELECT id, name, quantity, damaged_quantity, item_code, (SELECT item_condition FROM return_requests WHERE id = ?) AS return_condition FROM items WHERE id = ? FOR UPDATE");
        $stmtSel->execute([$request_id, $id]);
        $item = $stmtSel->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            throw new Exception('ບໍ່ພົບອຸປະກອນ');
        }

        if ($disposed_qty <= 0) {
            showAlert('ຈຳນວນທີ່ຕ້ອງສະສາງບໍ່ຖືກຕ້ອງ', 'warning');
            echo '<script>window.location.href = "?admin=categories";</script>';
            exit();
        }

        $current_qty = (int)$item['quantity'];

        // ກວດສອບວ່າຈຳນວນໃນສະຕັອກພໍໃຫ້ລົບອອກຫຼືບໍ່
        if ($current_qty < $disposed_qty) {
            throw new Exception("ຈຳນວນອຸປະກອນໃນສະຕັອກເກົ່າບໍ່ພໍໃຫ້ລົບອອກ! (ມີຢູ່: {$current_qty} ອັນ)");
        }
        
        // ຕັດອອກຈາກຈຳນວນທັງໝົດທີ່ມີ (quantity)
        $new_qty     = max(0, $current_qty - $disposed_qty); 
        $new_damaged = max(0, (int)($item['damaged_quantity'] ?? 0) - $disposed_qty);
        $now         = date('Y-m-d H:i:s');
        $disposed_by_user     = $_SESSION['username'] ?? 'admin';
        $disposed_by_fullname = !empty($_SESSION['fullname']) ? $_SESSION['fullname'] : $disposed_by_user;

        // 1. ອັບເດດ items (ຕັດ quantity ທັງໝົດ ແລະ damaged_quantity ອອກ)
        $stmtUpd = $pdo->prepare("
            UPDATE items 
            SET quantity = ?,
                damaged_quantity = ?,
                last_disposed_at = ?,
                last_disposed_by = ? 
            WHERE id = ?
        ");
        $stmtUpd->execute([$new_qty, $new_damaged, $now, $disposed_by_fullname, $id]);

        // 2. ອັບເດດສະຖານະໃນ return_requests ເປັນ 'disposed' 
        if ($request_id > 0) {
            $stmtUpdReq = $pdo->prepare("UPDATE return_requests SET status = 'disposed' WHERE id = ?");
            $stmtUpdReq->execute([$request_id]);
        } else {
            $stmtUpdReq = $pdo->prepare("UPDATE return_requests SET status = 'disposed' WHERE item_id = ? AND item_condition IN ('damaged', 'broken') AND status = 'approved'");
            $stmtUpdReq->execute([$id]);
        }

        // 3. ບັນທຶກ disposal_history
        $stmtHist = $pdo->prepare("
            INSERT INTO disposal_history (
                item_id, item_code, item_name, 
                disposed_quantity, previous_quantity, new_quantity,
                disposed_by, disposed_by_fullname, note, disposed_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtHist->execute([
            $id,
            $item['item_code'] ?? '',
            $item['name'] ?? '',
            $disposed_qty,
            $current_qty,
            $new_qty,
            $disposed_by_user,
            $disposed_by_fullname,
            $note,
            $now
        ]);

        // 4. ບັນທຶກ stock_movements
        $user_id_for_log = $_SESSION['user_id'] ?? 1;
        $remark_text     = !empty($note) ? "ຊຳລະ: " . $note : 'ຊຳລະ/ສະສາງອຸປະກອນຊຳຣຸດ';
        
        $stmtStockMove = $pdo->prepare("
            INSERT INTO stock_movements (
                item_id, movement_type, quantity, old_quantity, new_quantity, note, created_by, created_at
            ) VALUES (?, 'dispose', ?, ?, ?, ?, ?, ?)
        ");
        $stmtStockMove->execute([
            $id, $disposed_qty, $current_qty, $new_qty, $remark_text, $user_id_for_log, $now
        ]);

        recordLog($pdo, $id, 'DISPOSE', "ຊຳລະ/ສະສາງອຸປະກອນ '{$item['name']}' ຈຳນວນ {$disposed_qty} ", $disposed_by_fullname);

        $pdo->commit();

        showAlert("ຊຳລະ/ສະສາງອຸປະກອນຊຳລຸດ ແລະ ຕັດອອກຈາກຈຳນວນທັງໝົດທີ່ມີສຳເລັດ!", 'success');

    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        showAlert('ເກີດຂໍ້ຜິດພາດ ກະລຸນາລອງໃໝ່', 'error');
    }
    echo '<script>window.location.href = "?admin=categories";</script>';
    exit();
}

// ============================================================
// 3. FETCH ONLY RETURN REQUEST ALERTS (DAMAGED/BROKEN & APPROVED)
// ============================================================
$pending_damaged_requests = [];

if (isset($pdo)) {
    try {
        $stmtDamagedReq = $pdo->query("
            SELECT rr.*, i.name as item_name, i.item_code, u.fullname as requester_name 
            FROM return_requests rr
            LEFT JOIN items i ON rr.item_id = i.id
            LEFT JOIN users u ON rr.user_id = u.id
            WHERE rr.item_condition IN ('damaged', 'broken')
              AND rr.status = 'approved'
            ORDER BY rr.id DESC
        ");
        $pending_damaged_requests = $stmtDamagedReq->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        showAlert('ເກີດຂໍ້ຜິດພາດ ກະລຸນາລອງໃໝ່', 'error');
    }
}
?>

<!-- Header Section -->
<div class="mb-6 flex flex-wrap justify-between items-center gap-4">
    <div>
        <h1 class="text-2xl font-bold text-gray-800">📦 ອຸປະກອນທັງໝົດ / ສະສາງອຸປະກອນຊຳຣຸດ</h1>
        <p class="text-gray-600 text-sm mt-1">
            ຜູ້ດຳເນີນການ: <span class="font-bold text-indigo-600"><?php echo htmlspecialchars($current_fullname); ?></span>
            (<span class="text-gray-500"><?php echo htmlspecialchars($current_username); ?></span>)
        </p>
    </div>
</div>

<!-- Flash Alert Notification -->
<?php
$alert = getAlert();
if ($alert): ?>
    <div class="p-4 mb-4 rounded-lg <?php echo $alert['type'] === 'success' ? 'bg-green-100 text-green-700 border border-green-200' : 'bg-red-100 text-red-700 border border-red-200'; ?>">
        <i class="fas <?php echo $alert['type'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?> mr-2"></i>
        <?php echo htmlspecialchars($alert['message']); ?>
    </div>
<?php endif; ?>

<!-- 🔔 ກ່ອງແຈ້ງເຕືອນຄຳຂໍສົ່ງຄືນອຸປະກອນຊຳຣຸດ/ເປ່ເພ -->
<div class="mb-6 bg-amber-50 border-2 border-amber-300 rounded-xl p-4 shadow-sm">
    <div class="flex items-center gap-2 text-amber-800 font-bold text-base mb-3">
        <i class="fas fa-bell text-amber-600 text-xl animate-bounce"></i>
        <span>ແຈ້ງເຕືອນຄຳຂໍສົ່ງຄືນອຸປະກອນຊຳຣຸດ/ເປ່ເພ (ລໍຖ້າກົດຊຳລະ & ຕັດສະຕັອກ)</span>
        <span class="bg-amber-200 text-amber-900 text-xs px-2.5 py-0.5 rounded-full font-extrabold">
            <?php echo count($pending_damaged_requests); ?> ລາຍການ
        </span>
    </div>

    <?php if (!empty($pending_damaged_requests)): ?>
        <div class="space-y-2">
            <?php foreach ($pending_damaged_requests as $req): ?>
                <div class="bg-white p-3 rounded-lg border border-amber-200 flex flex-wrap items-center justify-between gap-3 shadow-xs">
                    <div class="text-sm">
                        <span class="font-bold text-gray-800"><?php echo htmlspecialchars($req['item_name']); ?></span>
                        <span class="text-xs font-mono text-gray-400">(<?php echo htmlspecialchars($req['item_code'] ?? '-'); ?>)</span>
                        <div class="text-xs text-gray-500 mt-0.5">
                            👤 ຜູ້ສົ່ງຄືນ: <b><?php echo htmlspecialchars($req['requester_name'] ?? 'ບໍ່ລະບຸ'); ?></b> 
                            | 🏢 ພະແນກ: <b><?php echo htmlspecialchars($req['department'] ?? '-'); ?></b>
                            | ⚠️ ສະຖານະ: <span class="text-red-600 font-bold">ຊຳຣຸດ/ເປ່ເພ (<?php echo (int)$req['quantity']; ?> )</span>
                        </div>
                    </div>
                    <div>
                        <button onclick="openDisposeModal(<?php echo $req['item_id']; ?>, <?php echo $req['id']; ?>, '<?php echo htmlspecialchars($req['item_name'] ?? '', ENT_QUOTES); ?>', <?php echo (int)$req['quantity']; ?>)" 
                                class="bg-emerald-600 hover:bg-emerald-700 text-white text-xs px-3.5 py-2 rounded-lg transition font-bold shadow flex items-center gap-1.5">
                            <i class="fas fa-file-invoice-dollar"></i> ກົດຊຳລະ / ຕັດສະຕັອກ
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="p-4 text-center text-sm text-gray-500 bg-white rounded-lg border border-dashed border-amber-200">
            ✅ ບໍ່ມີຄຳຂໍສົ່ງຄືນອຸປະກອນຊຳຣຸດ/ເປ່ເພ ທີ່ລໍຖ້າການຊຳລະ
        </div>
    <?php endif; ?>
</div>

<!-- Modal Dispose -->
<div id="disposeModal" class="fixed inset-0 bg-black/50 flex items-center justify-center hidden z-50 p-4">
    <div class="bg-white rounded-2xl max-w-md w-full p-6 shadow-2xl">
        <div class="w-16 h-16 bg-emerald-100 text-emerald-600 rounded-full flex items-center justify-center text-3xl mx-auto mb-4">
            <i class="fas fa-file-invoice-dollar"></i>
        </div>
        <h3 class="text-xl font-bold text-gray-800 text-center mb-2">ຢືນຢັນການຊຳລະ / ຕັດສະຕັອກ?</h3>
        <p class="text-gray-600 text-sm text-center mb-4">
            ທ່ານກຳລັງຈະດຳເນີນການຊຳລະ/ສະສາງອຸປະກອນຊຳຣຸດຂອງ <br>
            <span id="disposeItemName" class="font-bold text-gray-900"></span> 
            ຈຳນວນ <span id="disposeQty" class="font-bold text-red-600"></span>
        </p>

        <div class="bg-gray-50 p-3 rounded-xl text-xs text-left mb-4 text-gray-600 border space-y-1">
            <p>• ຜູ້ດຳເນີນການ: <b class="text-indigo-600"><?php echo htmlspecialchars($current_fullname); ?></b></p>
            <p>• ຊື່ຜູ້ໃຊ້: <b class="text-gray-600">@<?php echo htmlspecialchars($current_username); ?></b></p>
            <p>• ວັນທີ/ເວລາ: <b><?php echo date('d/m/Y H:i:s'); ?></b></p>
            <p class="text-red-500">• ຫຼັງຈາກກົດຢືນຢັນ ຈຳນວນສະຕັອກທັງໝົດໃນຄັງຈະຖືກຕັດອອກ.</p>
        </div>

        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 mb-1">ໝາຍເຫດ (ຖ້າມີ)</label>
            <textarea id="disposeNote" rows="2" class="form-input w-full p-2 border rounded-lg" placeholder="ບັນທຶກເຫດຜົນການສະສາງ..."></textarea>
        </div>

        <form method="POST" id="disposeForm">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <input type="hidden" name="item_id" id="dispose_id">
            <input type="hidden" name="request_id" id="dispose_request_id">
            <input type="hidden" name="disposed_qty" id="dispose_qty_hidden">
            <input type="hidden" name="dispose_item" value="1">
            <input type="hidden" name="note" id="dispose_note_hidden">
            
            <div class="flex justify-center gap-3">
                <button type="button" onclick="closeDisposeModal()" class="px-4 py-2 text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-xl text-sm font-medium transition">ຍົກເລີກ</button>
                <button type="button" onclick="submitDispose()" class="px-5 py-2 text-white bg-emerald-600 hover:bg-emerald-700 rounded-xl text-sm font-medium shadow-md transition flex items-center gap-2">
                    <i class="fas fa-check"></i> ຢືນຢັນຊຳລະ / ຕັດສະຕັອກ
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openDisposeModal(itemId, requestId, name, qty) {
    document.getElementById('dispose_id').value = itemId;
    document.getElementById('dispose_request_id').value = requestId;
    document.getElementById('dispose_qty_hidden').value = qty;
    document.getElementById('disposeItemName').textContent = '"' + name + '"';
    document.getElementById('disposeQty').textContent = qty;
    document.getElementById('disposeNote').value = '';
    document.getElementById('disposeModal').classList.remove('hidden');
}

function closeDisposeModal() {
    document.getElementById('disposeModal').classList.add('hidden');
}

function submitDispose() {
    const note = document.getElementById('disposeNote').value;
    document.getElementById('dispose_note_hidden').value = note;
    document.getElementById('disposeForm').submit();
}

document.getElementById('disposeModal').addEventListener('click', function(e) { 
    if (e.target === this) closeDisposeModal(); 
});
</script>
