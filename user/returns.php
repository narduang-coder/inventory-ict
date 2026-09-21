<?php
require_once __DIR__ . '/../includes/config.php';
checkRole(['user']);

$user_id = $_SESSION['user_id'] ?? 0;
$user_department = $_SESSION['department'] ?? '';

$message = '';
$message_type = '';

// 1. ຈັດການການບັນທຶກຟອມສົ່ງຄືນ
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_return') {
    if (function_exists('requirePostCsrf')) {
        requirePostCsrf();
    }
    
    $item_id = $_POST['item_id'] ?? null;
    $asset_code = trim($_POST['asset_code'] ?? '');
    $quantity = (int)($_POST['quantity'] ?? 0);
    $item_condition = $_POST['item_condition'] ?? 'good';
    $reason = trim($_POST['reason'] ?? '');

    if (!empty($item_id) && $quantity > 0) {
        try {
            // ກວດສອບຈຳນວນຄົງເຫຼືອທີ່ພະແນກເບີກຈ່າຍໄປແລ້ວ (ດຶງຈາກ item_issuances)
            $checkSql = "
                SELECT 
                    (
                        IFNULL((
                            SELECT SUM(quantity) 
                            FROM item_issuances 
                            WHERE item_id = ? 
                              AND (department = ? OR user_id = ?)
                              AND (
                                  (asset_code = ?) 
                                  OR (asset_code IS NULL AND ? = '')
                                  OR (asset_code = '' AND ? = '')
                              )
                              AND status = 'issued'
                        ), 0) 
                        - 
                        IFNULL((
                            SELECT SUM(quantity) 
                            FROM return_requests 
                            WHERE item_id = ? 
                              AND department = ? 
                              AND (
                                  (asset_code = ?) 
                                  OR (asset_code IS NULL AND ? = '')
                                  OR (asset_code = '' AND ? = '')
                              )
                              AND status IN ('pending', 'approved', 'disposed', 'completed', 'cleared')
                        ), 0)
                    ) AS current_qty
            ";
            
            $stmtCheck = $pdo->prepare($checkSql);
            $stmtCheck->execute([
                $item_id, $user_department, $user_id, $asset_code, $asset_code, $asset_code,
                $item_id, $user_department, $asset_code, $asset_code, $asset_code
            ]);
            $current_available = (int)$stmtCheck->fetchColumn();

            if ($quantity > $current_available) {
                $message = "ຈຳນວນທີ່ລະບຸເກີນຈຳນວນອຸປະກອນທີ່ມີຢູ່ (ຄົງເຫຼືອທີ່ສາມາດສົ່ງຄືນໄດ້: {$current_available})";
                $message_type = "warning";
            } else {
                $stmtInsert = $pdo->prepare("
                    INSERT INTO return_requests (user_id, department, item_id, asset_code, quantity, item_condition, reason, status, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
                ");
                $stmtInsert->execute([$user_id, $user_department, $item_id, $asset_code, $quantity, $item_condition, $reason]);
                
                $message = "ສົ່ງຄຳຂໍສົ່ງຄືນອຸປະກອນສຳເລັດແລ້ວ!";
                $message_type = "success";
            }
        } catch (PDOException $e) {
            error_log("Return Request Error: " . $e->getMessage());
            $message = "ເກີດຂໍ້ຜິດພາດ ກະລຸນາລອງໃໝ່ (" . $e->getMessage() . ")";
            $message_type = "error";
        }
    } else {
        $message = "ກະລຸນາປ້ອນຂໍ້ມູນໃຫ້ຄົບຖ້ວນ";
        $message_type = "warning";
    }
}

// 2. ດຶງລາຍການອຸປະກອນທີ່ພະແນກ/ຜູ້ໃຊ້ ເບີກໄປແລ້ວ (ອ້າງອີງຈາກ item_issuances)
$available_items = [];
if (isset($pdo)) {
    try {
        $stmtItems = $pdo->prepare("
            SELECT
                i.id AS item_id,
                i.item_code,
                i.name AS item_name,
                i.unit,
                ii.asset_code,
                (
                    COALESCE(SUM(ii.quantity), 0) -
                    COALESCE((
                        SELECT SUM(rr.quantity)
                        FROM return_requests rr
                        WHERE rr.item_id = ii.item_id
                          AND rr.department = ?
                          AND (
                              (rr.asset_code = ii.asset_code)
                              OR (rr.asset_code IS NULL AND ii.asset_code IS NULL)
                              OR (rr.asset_code = '' AND ii.asset_code = '')
                          )
                          AND rr.status IN ('pending','approved','disposed','completed','cleared')
                    ), 0)
                ) AS total_approved_qty
            FROM item_issuances ii
            INNER JOIN items i ON i.id = ii.item_id
            WHERE (ii.department = ? OR ii.user_id = ?)
              AND ii.status = 'issued'
              AND i.is_active = 1
            GROUP BY i.id, i.item_code, i.name, i.unit, ii.asset_code
            HAVING total_approved_qty > 0
            ORDER BY i.name ASC, ii.asset_code ASC
        ");
        $stmtItems->execute([$user_department, $user_department, $user_id]);
        $available_items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('User Return Fetch Error: ' . $e->getMessage());
        $message = 'ບໍ່ສາມາດສະແດງລາຍການສົ່ງຄືນໄດ້. (SQL Error: ' . $e->getMessage() . ')';
        $message_type = 'error';
    }
}

// 3. ດຶງປະຫວັດການສົ່ງຄືນ
$return_history = [];
if (!empty($user_id) && isset($pdo)) {
    try {
        $stmtHistory = $pdo->prepare("
            SELECT rr.*, i.item_code, i.name AS item_name, i.unit
            FROM return_requests rr
            LEFT JOIN items i ON rr.item_id = i.id
            WHERE rr.user_id = ?
            ORDER BY rr.created_at DESC
        ");
        $stmtHistory->execute([$user_id]);
        $return_history = $stmtHistory->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('User Return History Fetch Error: ' . $e->getMessage());
    }
}
?>

<!-- Notification Message -->
<?php if (!empty($message)): ?>
    <div class="mb-6 p-4 rounded-xl border text-sm flex items-center gap-3 <?php 
        echo $message_type === 'success' ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 
            ($message_type === 'warning' ? 'bg-amber-50 border-amber-200 text-amber-800' : 'bg-rose-50 border-rose-200 text-rose-800'); 
    ?>">
        <i class="fas <?php 
            echo $message_type === 'success' ? 'fa-circle-check text-emerald-600' : 
                ($message_type === 'warning' ? 'fa-triangle-exclamation text-amber-600' : 'fa-circle-xmark text-rose-600'); 
        ?> text-lg"></i>
        <span><?php echo htmlspecialchars($message); ?></span>
    </div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
    <!-- ຟອມສົ່ງຄືນ -->
    <div class="lg:col-span-1 bg-white p-6 rounded-2xl border border-slate-200/80 shadow-sm h-fit">
        <h3 class="text-base font-bold text-slate-800 mb-4 flex items-center gap-2 border-b border-slate-100 pb-3">
            <i class="fas fa-paper-plane text-amber-500"></i>
            ຟອມແຈ້ງສົ່ງຄືນອຸປະກອນ
        </h3>

        <form action="" method="POST" class="space-y-4">
            <?php if (function_exists('generateCSRFToken')): ?>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCSRFToken()); ?>">
            <?php endif; ?>
            <input type="hidden" name="action" value="submit_return">

            <div>
                <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase">ເລືອກອຸປະກອນທີ່ຈະສົ່ງຄືນ <span class="text-red-500">*</span></label>
                <select name="item_id" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:border-indigo-500 transition-all">
                    <option value="">-- ເລືອກລາຍການອຸປະກອນ --</option>
                    <?php foreach ($available_items as $item): ?>
                        <option value="<?php echo $item['item_id']; ?>" data-asset="<?php echo htmlspecialchars($item['asset_code'] ?? ''); ?>">
                            [<?php echo htmlspecialchars($item['item_code'] ?? '-'); ?>] <?php echo htmlspecialchars($item['item_name']); ?><?php if (!empty($item['asset_code'])): ?> [Asset: <?php echo htmlspecialchars($item['asset_code']); ?>]<?php endif; ?> 
                            (ເຫຼືອ: <?php echo number_format($item['total_approved_qty']) . ' ' . htmlspecialchars($item['unit'] ?? ''); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase">ລະຫັດ asset / ຊຄທ (Asset Code)</label>
                <input type="text" id="returnAssetCode" name="asset_code" placeholder="ເຊັ່ນ: 65465315" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:border-indigo-500 transition-all font-mono">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase">ຈຳນວນທີ່ສົ່ງຄືນ <span class="text-red-500">*</span></label>
                <input type="number" name="quantity" min="1" value="1" required placeholder="0" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:border-indigo-500 transition-all">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase">ສະພາບຂອງອຸປະກອນ <span class="text-red-500">*</span></label>
                <select name="item_condition" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:border-indigo-500 transition-all">
                    <option value="good">ດີ / ບໍ່ໄດ້ໃຊ້ງານ</option>
                    <option value="damaged">ເປ່ເພ / ຊຳລຸດ</option>
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase">ເຫດຜົນການສົ່ງຄືນ / ໝາຍເຫດ</label>
                <textarea name="reason" rows="3" placeholder="ລະບຸເຫດຜົນ..." class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:border-indigo-500 transition-all"></textarea>
            </div>

            <button type="submit" class="w-full bg-amber-500 hover:bg-amber-600 text-white font-semibold py-2.5 px-4 rounded-xl transition-all shadow-sm flex items-center justify-center gap-2 text-sm">
                <i class="fas fa-arrow-rotate-left"></i> ສົ່ງຄຳຂໍສົ່ງຄືນ
            </button>
        </form>
    </div>

    <!-- ຕາຕະລາງປະຫວັດ -->
    <div class="lg:col-span-2 bg-white rounded-2xl border border-slate-200/80 shadow-sm overflow-hidden">
        <div class="p-5 border-b border-slate-100">
            <h3 class="text-base font-bold text-slate-800 flex items-center gap-2">
                <i class="fas fa-clock-rotate-left text-indigo-600"></i>
                ປະຫວັດການສົ່ງຄືນອຸປະກອນ
            </h3>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-slate-600">
                <thead class="bg-slate-50 text-slate-500 text-xs uppercase font-semibold border-b border-slate-200">
                    <tr>
                        <th class="px-5 py-3.5">ວັນທີສົ່ງຄືນ</th>
                        <th class="px-5 py-3.5">ລະຫັດ asset</th>
                        <th class="px-5 py-3.5">ອຸປະກອນ</th>
                        <th class="px-5 py-3.5 text-center">ຈຳນວນ</th>
                        <th class="px-5 py-3.5 text-center">ສະພາບ</th>
                        <th class="px-5 py-3.5 text-center">ສະຖານະ</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (count($return_history) > 0): ?>
                        <?php foreach ($return_history as $ret): ?>
                            <tr class="hover:bg-slate-50 transition-colors">
                                <td class="px-5 py-4 text-xs font-medium text-slate-500">
                                    <?php echo date('d/m/Y H:i', strtotime($ret['created_at'])); ?>
                                </td>
                                <td class="px-5 py-4">
                                    <?php if (!empty($ret['asset_code'])): ?>
                                        <span class="inline-flex items-center gap-1 bg-emerald-50 text-emerald-700 border border-emerald-200 px-2 py-0.5 rounded font-mono text-xs font-bold">
                                            <i class="fas fa-barcode"></i> <?php echo htmlspecialchars($ret['asset_code']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-slate-400 italic text-xs">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-5 py-4">
                                    <div class="font-semibold text-slate-800"><?php echo htmlspecialchars($ret['item_name'] ?? 'ລຶບແລ້ວ'); ?></div>
                                    <span class="text-xs font-mono text-slate-400"><?php echo htmlspecialchars($ret['item_code'] ?? '-'); ?></span>
                                </td>
                                <td class="px-5 py-4 text-center font-bold text-slate-800">
                                    <?php echo number_format($ret['quantity']) . ' ' . htmlspecialchars($ret['unit'] ?? ''); ?>
                                </td>
                                <td class="px-5 py-4 text-center">
                                    <?php 
                                        $is_damaged = ($ret['item_condition'] === 'damaged' || $ret['item_condition'] === 'broken');
                                        $bg_class = $is_damaged ? 'bg-amber-50 text-amber-700 border-amber-200' : 'bg-emerald-50 text-emerald-700 border-emerald-200';
                                        $status_text = $is_damaged ? 'ເປ່ເພ / ຊຳລຸດ' : 'ດີ / ປົກກະຕິ';
                                    ?>
                                    <span class="px-2.5 py-0.5 text-xs font-medium border rounded-md <?php echo $bg_class; ?>">
                                        <?php echo $status_text; ?>
                                    </span>
                                </td>
                                <td class="px-5 py-4 text-center">
                                    <?php if (in_array($ret['status'], ['approved', 'disposed', 'completed', 'cleared'])): ?>
                                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-700 border border-emerald-200 shadow-sm">
                                            <i class="fas fa-check-circle text-xs"></i> ກວດເຊັກສຳເລັດ
                                        </span>
                                    <?php elseif ($ret['status'] === 'rejected'): ?>
                                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-rose-100 text-rose-700 border border-rose-200">
                                            <i class="fas fa-times-circle text-xs"></i> ຖືກປະຕິເສດ
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-amber-100 text-amber-700 border border-amber-200 animate-pulse">
                                            <i class="fas fa-clock text-xs"></i> ລໍຖ້າກວດເຊັກ
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="px-5 py-12 text-center text-slate-400">ບໍ່ມີປະຫວັດການສົ່ງຄືນ</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const itemSelect = document.querySelector('select[name="item_id"]');
    const assetInput = document.getElementById('returnAssetCode');
    if (!itemSelect || !assetInput) return;

    function syncAssetCode() {
        const option = itemSelect.options[itemSelect.selectedIndex];
        assetInput.value = (option && option.dataset && option.dataset.asset) ? option.dataset.asset : '';
    }

    itemSelect.addEventListener('change', syncAssetCode);
    syncAssetCode();
});
</script>
