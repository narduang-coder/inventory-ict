<?php
require_once __DIR__ . '/../includes/config.php';
checkRole(['user']);
$user_id = $_SESSION['user_id'];
$user_department = $_SESSION['department'] ?? '';

// ດຶງຂໍ້ມູນປະຫວັດການເບີກ ສະເພາະພະແນກຂອງຜູ້ໃຊ້
$stmt = $pdo->prepare("
    SELECT r.*, u.fullname 
    FROM requests r 
    JOIN users u ON r.user_id = u.id 
    WHERE r.department = ? 
    ORDER BY r.created_at DESC
");
$stmt->execute([$user_department]);
$requests = $stmt->fetchAll();

// ດຶງລາຍການອຸປະກອນຂອງແຕ່ລະ Request ID
$request_details = [];
if (count($requests) > 0) {
    $req_ids = array_column($requests, 'id');
    $in_clause = implode(',', array_fill(0, count($req_ids), '?'));
    
    $stmtItems = $pdo->prepare("
        SELECT ri.*, i.name as item_name, i.unit 
        FROM request_items ri 
        JOIN items i ON ri.item_id = i.id 
        WHERE ri.request_id IN ($in_clause)
    ");
    $stmtItems->execute($req_ids);
    $items_raw = $stmtItems->fetchAll();
    
    foreach ($items_raw as $item) {
        $request_details[$item['request_id']][] = $item;
    }
}
?>

<div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-8">
    <div>
        <h1 class="text-2xl font-bold text-slate-800 flex items-center gap-3">
            <span class="p-2.5 bg-indigo-50 text-indigo-600 rounded-xl text-xl">
                <i class="fas fa-clock-rotate-left"></i>
            </span>
            ປະຫວັດການເບີກອຸປະກອນ (ປະຈຳພະແນກ)
        </h1>
        <p class="text-slate-500 text-sm mt-1">
            ລາຍການຄຳຂໍເບີກອຸປະກອນທັງໝົດ: <span class="font-semibold text-indigo-600"><?php echo htmlspecialchars($user_department); ?></span>
        </p>
    </div>
</div>

<div class="space-y-6">
    <?php if (count($requests) > 0): ?>
        <?php foreach ($requests as $req): 
            $status = $req['status'];
            $status_config = [
                'pending'  => ['bg' => 'bg-amber-50', 'text' => 'text-amber-700', 'border' => 'border-amber-200', 'label' => 'ລໍຖ້າອະນຸມັດ', 'icon' => 'fa-clock'],
                'approved' => ['bg' => 'bg-emerald-50', 'text' => 'text-emerald-700', 'border' => 'border-emerald-200', 'label' => 'ອະນຸມັດແລ້ວ', 'icon' => 'fa-circle-check'],
                'rejected' => ['bg' => 'bg-rose-50', 'text' => 'text-rose-700', 'border' => 'border-rose-200', 'label' => 'ປະຕິເສດ', 'icon' => 'fa-circle-xmark']
            ];
            $currStatus = $status_config[$status] ?? $status_config['pending'];
            $items = $request_details[$req['id']] ?? [];
            
            // ດຶງຂໍ້ມູນໝາຍເຫດຈາກ Admin (ປ່ຽນຊື່ Column ໃຫ້ກົງກັບຖານຂໍ້ມູນຂອງທ່ານ ເຊັ່ນ: admin_note ຫຼື reject_reason)
            $admin_note = $req['admin_note'] ?? $req['reject_reason'] ?? '';
        ?>
            <div class="card border border-slate-200/80 hover:border-indigo-200 transition-all">
                <div class="flex flex-wrap items-center justify-between gap-4 pb-4 border-b border-slate-100">
                    <div class="flex items-center gap-3">
                        <span class="text-xs font-mono font-bold bg-slate-100 text-slate-600 px-3 py-1.5 rounded-lg">
                            #REQ-<?php echo str_pad($req['id'], 5, '0', STR_PAD_LEFT); ?>
                        </span>
                        <div>
                            <span class="text-xs text-slate-400 block">ວັນທີຂໍເບີກ / ຜູ້ຂໍ:</span>
                            <span class="text-sm font-semibold text-slate-700">
                                <?php echo date('d/m/Y', strtotime($req['request_date'])); ?> 
                                (<?php echo htmlspecialchars($req['fullname'] ?? 'ຜູ້ໃຊ້'); ?>)
                            </span>
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold <?php echo $currStatus['bg'] . ' ' . $currStatus['text'] . ' ' . $currStatus['border']; ?> border">
                            <i class="fas <?php echo $currStatus['icon']; ?>"></i>
                            <?php echo $currStatus['label']; ?>
                        </span>

                        <button onclick="printRequest(<?php echo $req['id']; ?>)" class="bg-slate-100 hover:bg-indigo-50 text-slate-600 hover:text-indigo-600 px-3 py-1.5 rounded-xl text-xs font-semibold transition-all flex items-center gap-1.5">
                            <i class="fas fa-print"></i> ປິ້ນໃບເບີກ
                        </button>
                    </div>
                </div>

                <div class="py-3 my-2 grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                    <div>
                        <span class="text-xs text-slate-400 font-medium block">ຈຸດປະສົງ:</span>
                        <p class="font-medium text-slate-800"><?php echo htmlspecialchars($req['purpose']); ?></p>
                    </div>
                    <?php if (!empty($req['note'])): ?>
                        <div>
                            <span class="text-xs text-slate-400 font-medium block">ໝາຍເຫດຜູ້ຂໍ:</span>
                            <p class="text-slate-600"><?php echo htmlspecialchars($req['note']); ?></p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- ສ່ວນທີ່ເພີ່ມ: ສະແດງໝາຍເຫດຈາກ Admin ເມື່ອມີການອະນຸມັດ ຫຼື ປະຕິເສດ -->
                <?php if (($status === 'approved' || $status === 'rejected') && !empty($admin_note)): ?>
                    <div class="mb-3 p-3 rounded-xl border text-sm <?php echo $status === 'rejected' ? 'bg-rose-50/60 border-rose-100 text-rose-800' : 'bg-emerald-50/60 border-emerald-100 text-emerald-800'; ?>">
                        <div class="flex items-start gap-2">
                            <i class="fas <?php echo $status === 'rejected' ? 'fa-triangle-exclamation text-rose-500' : 'fa-comment-dots text-emerald-500'; ?> mt-0.5"></i>
                            <div>
                                <span class="font-bold block text-xs uppercase tracking-wider mb-0.5">
                                    <?php echo $status === 'rejected' ? 'ເຫດຜົນການປະຕິເສດ:' : 'ໝາຍເຫດຈາກ Admin:'; ?>
                                </span>
                                <p class="text-xs md:text-sm"><?php echo htmlspecialchars($admin_note); ?></p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="bg-slate-50/70 rounded-xl p-3 border border-slate-100 mt-2">
                    <h4 class="text-xs font-semibold text-slate-500 uppercase mb-2">ລາຍການອຸປະກອນທີ່ຂໍເບີກ:</h4>
                    <div class="space-y-1.5">
                        <?php foreach ($items as $index => $item): ?>
                            <div class="flex items-center justify-between text-sm py-1 border-b border-slate-100 last:border-0">
                                <span class="text-slate-700 font-medium">
                                    <?php echo ($index + 1) . '. ' . htmlspecialchars($item['item_name']); ?>
                                </span>
                                <span class="font-bold text-slate-800 bg-white px-2.5 py-0.5 rounded-md border border-slate-200 text-xs">
                                    <?php echo number_format($item['quantity']) . ' ' . htmlspecialchars($item['unit']); ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="card text-center py-12 text-slate-400">
            <i class="fas fa-folder-open text-5xl mb-3 block text-slate-300"></i>
            <p class="text-base font-medium">ຍັງບໍ່ມີປະຫວັດການເບີກອຸປະກອນໃນພະແນກນີ້</p>
            <a href="?user=request" class="btn-primary mt-4 inline-flex">
                <i class="fas fa-paper-plane mr-1"></i> ສ້າງຄຳຂໍເບີກໃໝ່
            </a>
        </div>
    <?php endif; ?>
</div>

<script>
function printRequest(reqId) {
    const printWindow = window.open('', '_blank', 'height=800,width=1000');
    
    fetch(`api/get_request_print.php?id=${reqId}`)
        .then(response => {
            if (!response.ok) throw new Error('Network response was not ok');
            return response.text();
        })
        .then(html => {
            printWindow.document.write(html);
            printWindow.document.close();
            printWindow.focus();
            setTimeout(() => {
                printWindow.print();
                printWindow.close();
            }, 500);
        })
        .catch(() => {
            printWindow.close();
            Swal.fire({
                icon: 'error',
                title: 'ເກີດຂໍ້ຜິດພາດ',
                text: 'ບໍ່ສາມາດດຶງຂໍ້ມູນໃບເບີກເພື່ອປິ້ນໄດ້ (ກວດສອບໄຟລ໌ get_request_print.php)',
                confirmColor: '#6366f1'
            });
        });
}
</script>
ທສກ