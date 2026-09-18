<?php
require_once __DIR__ . '/../includes/config.php';
requireAuthenticated();
checkApiAuth();

if (isset($_GET['id'])) {
    $request_id = (int)$_GET['id'];

    // ດຶງຂໍ້ມູນຄຳຂໍ ແລະ ຜູ້ຂໍ
    $stmt = $pdo->prepare("SELECT r.*, u.fullname FROM requests r JOIN users u ON r.user_id = u.id WHERE r.id = ? AND (r.user_id = ? OR ? = 'admin')");
    $stmt->execute([$request_id, getCurrentUserId(), getCurrentUserRole()]);
    $request = $stmt->fetch();

    if ($request) {
        // ດຶງລາຍການອຸປະກອນ
        $stmtItems = $pdo->prepare("SELECT ri.*, i.name, i.unit FROM request_items ri JOIN items i ON ri.item_id = i.id WHERE ri.request_id = ?");
        $stmtItems->execute([$request_id]);
        $items = $stmtItems->fetchAll();

        // ກຳນົດ Badge ສະຖານະ
        $statusBadge = '';
        if ($request['status'] == 'pending') {
            $statusBadge = '<span class="text-xs px-2 py-1 bg-yellow-100 text-yellow-800 rounded-full">ລໍຖ້າອະນຸມັດ</span>';
        } elseif ($request['status'] == 'approved') {
            $statusBadge = '<span class="text-xs px-2 py-1 bg-green-100 text-green-800 rounded-full">ອະນຸມັດແລ້ວ</span>';
        } else {
            $statusBadge = '<span class="text-xs px-2 py-1 bg-red-100 text-red-800 rounded-full">ປະຕິເສດ</span>';
        }
        ?>
        <div class="space-y-4 text-left">
            <div class="flex justify-between items-start border-b pb-3">
                <div>
                    <div class="flex items-center gap-2 mb-1">
                        <span class="text-sm font-semibold text-gray-500">#<?= $request['id'] ?></span>
                        <?= $statusBadge ?>
                    </div>
                    <h4 class="text-lg font-bold text-gray-800"><?= htmlspecialchars($request['fullname']) ?></h4>
                </div>
                <p class="text-xs text-gray-400">ວັນທີ: <?= formatDate($request['request_date']) ?></p>
            </div>

            <div>
                <p class="text-xs font-semibold text-gray-500 uppercase">ຈຸດປະສົງ:</p>
                <p class="text-sm text-gray-800 bg-gray-50 p-2.5 rounded-lg border mt-1"><?= htmlspecialchars($request['purpose']) ?></p>
            </div>

            <?php if (!empty($request['admin_note'])): ?>
            <div>
                <p class="text-xs font-semibold text-gray-500 uppercase">ໝາຍເຫດຈາກແອັດມິນ:</p>
                <p class="text-sm text-gray-800 bg-amber-50 p-2.5 rounded-lg border border-amber-200 mt-1"><?= htmlspecialchars($request['admin_note']) ?></p>
            </div>
            <?php endif; ?>

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
                        <tbody class="divide-y">
                            <?php foreach ($items as $idx => $item): ?>
                                <tr>
                                    <td class="py-2 px-3 text-gray-400"><?= $idx + 1 ?></td>
                                    <td class="py-2 px-3 font-medium"><?= htmlspecialchars($item['name']) ?></td>
                                    <td class="py-2 px-3 text-right"><?= $item['quantity'] ?> <?= htmlspecialchars($item['unit']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    } else {
        echo '<p class="text-red-500 text-center py-4">ບໍ່ພົບຂໍ້ມູນຄຳຂໍນີ້</p>';
    }
}
?>
