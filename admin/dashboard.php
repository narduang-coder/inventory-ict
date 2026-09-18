<?php
require_once __DIR__ . '/../includes/config.php';
checkRole(['admin']);
// ============================================================
// ດຶງຂໍ້ມູນສຳລັບແດຊບອດ (Optimized Queries)
// ============================================================

// 1. Combine All Summary Stats into Single Query
$summary = $pdo->query("
    SELECT 
        (SELECT COUNT(*) FROM items) as total_items,
        (SELECT COUNT(*) FROM users) as total_users,
        (SELECT COUNT(*) FROM requests) as total_requests,
        (SELECT COUNT(*) FROM requests WHERE status = 'pending') as pending_requests,
        (SELECT COUNT(*) FROM requests WHERE status = 'approved') as approved_requests,
        (SELECT COUNT(*) FROM requests WHERE status = 'rejected') as rejected_requests,
        (SELECT COUNT(*) FROM items WHERE quantity <= min_quantity) as low_stock,
        (SELECT SUM(price_per_unit * quantity) FROM items) as total_value
")->fetch(PDO::FETCH_ASSOC);

$total_items      = $summary['total_items'] ?? 0;
$total_users      = $summary['total_users'] ?? 0;
$total_requests   = $summary['total_requests'] ?? 0;
$pending_requests = $summary['pending_requests'] ?? 0;
$approved_requests= $summary['approved_requests'] ?? 0;
$rejected_requests= $summary['rejected_requests'] ?? 0;
$low_stock        = $summary['low_stock'] ?? 0;
$total_value      = $summary['total_value'] ?? 0;

// 2. ດຶງຂໍ້ມູນອຸປະກອນໃກ້ໝົດ
$stmt = $pdo->prepare("SELECT * FROM items WHERE quantity <= min_quantity ORDER BY quantity ASC LIMIT 5");
$stmt->execute();
$low_stock_items = $stmt->fetchAll();

// 3. ດຶງຄຳຂໍລ່າສຸດ
$stmt = $pdo->prepare("
    SELECT r.*, u.fullname 
    FROM requests r 
    JOIN users u ON r.user_id = u.id 
    ORDER BY r.created_at DESC 
    LIMIT 5
");
$stmt->execute();
$recent_requests = $stmt->fetchAll();

// 4. ດຶງສະຖິຕິຂັ້ນສູງ
$monthly_stats   = function_exists('getMonthlyStats') ? getMonthlyStats($pdo) : [];
$popular_items   = function_exists('getPopularItems') ? getPopularItems($pdo) : [];
$top_users       = function_exists('getTopUsers') ? getTopUsers($pdo) : [];
?>

<!-- Header -->
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
    <div>
        <h1 class="text-2xl font-bold text-slate-800 flex items-center gap-2">
            <i class="fas fa-chart-pie text-sky-600"></i>
            ໜ້າຫຼັກ (Dashboard)
        </h1>
        <p class="text-slate-500 text-sm mt-0.5">ສະຫຼຸບພາບລວມ ແລະ ສະຖິຕິການນຳໃຊ້ລະບົບ</p>
    </div>
    <div class="flex items-center gap-2">
        <button onclick="window.print()" class="inline-flex items-center gap-2 px-4 py-2 bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 font-medium rounded-xl text-sm shadow-sm transition">
            <i class="fas fa-print text-slate-400"></i> ພິມລາຍງານ
        </button>
    </div>
</div>

<!-- ສະຖິຕິ Cards Summary -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <!-- ອຸປະກອນທັງໝົດ -->
    <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-sm flex items-center justify-between transition hover:shadow-md">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">ອຸປະກອນທັງໝົດ</p>
            <h3 class="text-2xl font-bold text-slate-800 mt-1"><?php echo number_format((int)$total_items); ?></h3>
        </div>
        <div class="p-3 bg-blue-50 text-blue-600 rounded-2xl text-xl">
            <i class="fas fa-boxes-stacked"></i>
        </div>
    </div>

    <!-- ລໍຖ້າອະນຸມັດ -->
    <div class="bg-white p-5 rounded-2xl border border-amber-200/80 shadow-sm flex items-center justify-between transition hover:shadow-md border-l-4 border-l-amber-500">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wider text-amber-600">ລໍຖ້າອະນຸມັດ</p>
            <h3 class="text-2xl font-bold text-slate-800 mt-1"><?php echo number_format((int)$pending_requests); ?></h3>
            <p class="text-xs text-slate-400 mt-1">ຄຳຂໍທີ່ລໍຖ້າກວດສອບ</p>
        </div>
        <div class="p-3 bg-amber-50 text-amber-600 rounded-2xl text-xl">
            <i class="fas fa-clock-rotate-left"></i>
        </div>
    </div>

    <!-- ອຸປະກອນໃກ້ໝົດ -->
    <div class="bg-white p-5 rounded-2xl border border-rose-200/80 shadow-sm flex items-center justify-between transition hover:shadow-md border-l-4 border-l-rose-500">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wider text-rose-600">ອຸປະກອນໃກ້ໝົດ</p>
            <h3 class="text-2xl font-bold text-slate-800 mt-1"><?php echo number_format((int)$low_stock); ?></h3>
            <p class="text-xs text-slate-400 mt-1">ຕ່ຳກວ່າເກນຄາດໝາຍ</p>
        </div>
        <div class="p-3 bg-rose-50 text-rose-600 rounded-2xl text-xl">
            <i class="fas fa-triangle-exclamation"></i>
        </div>
    </div>

    <!-- ຜູ້ໃຊ້ທັງໝົດ -->
    <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-sm flex items-center justify-between transition hover:shadow-md">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">ຜູ້ໃຊ້ທັງໝົດ</p>
            <h3 class="text-2xl font-bold text-slate-800 mt-1"><?php echo number_format((int)$total_users); ?></h3>
            <p class="text-xs text-slate-400 mt-1">ບັນຊີໃນລະບົບ</p>
        </div>
        <div class="p-3 bg-sky-50 text-sky-600 rounded-2xl text-xl">
            <i class="fas fa-users"></i>
        </div>
    </div>
</div>

<!-- ການດຳເນີນການດ່ວນ Quick Actions -->
<div class="bg-gradient-to-r from-[#002B66] to-[#0284c7] text-white p-6 rounded-2xl shadow-md mb-8 flex flex-wrap items-center justify-between gap-4">
    <div>
        <h3 class="text-lg font-bold flex items-center gap-2">
            <i class="fas fa-bolt text-yellow-300"></i> ການດຳເນີນການດ່ວນ
        </h3>
        <p class="text-sky-100 text-sm">ເມນູລັດສຳລັບການຈັດການລະບົບ</p>
    </div>
    <div class="flex flex-wrap gap-2.5">
        <a href="?admin=items&action=add" class="inline-flex items-center gap-2 px-4 py-2 bg-white/10 hover:bg-white/20 text-white rounded-xl text-sm font-medium backdrop-blur-sm transition">
            <i class="fas fa-plus"></i> ເພີ່ມອຸປະກອນ
        </a>
        <a href="?admin=users&action=add" class="inline-flex items-center gap-2 px-4 py-2 bg-white/10 hover:bg-white/20 text-white rounded-xl text-sm font-medium backdrop-blur-sm transition">
            <i class="fas fa-user-plus"></i> ເພີ່ມຜູ້ໃຊ້
        </a>
        <a href="?admin=requests" class="inline-flex items-center gap-2 px-4 py-2 bg-white text-sky-700 hover:bg-sky-50 rounded-xl text-sm font-semibold shadow-sm transition">
            <i class="fas fa-clipboard-check"></i> ກວດສອບຄຳຂໍ
        </a>
    </div>
</div>

<!-- ສະຖິຕິລາຍເດືອນ Chart (Full Width) -->
<div class="bg-white p-6 rounded-2xl border border-slate-200/80 shadow-sm mb-8">
    <h3 class="text-base font-bold text-slate-800 mb-4 flex items-center gap-2">
        <i class="fas fa-chart-column text-sky-600"></i> ສະຖິຕິການເບີກລາຍເດືອນ
    </h3>
    <div class="h-80">
        <canvas id="monthlyChart"></canvas>
    </div>
</div>

<!-- ອຸປະກອນຍອດນິຍົມ & ຜູ້ໃຊ້ເບີກຫຼາຍສຸດ -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
    <!-- ອຸປະກອນຍອດນິຍົມ -->
    <div class="bg-white p-6 rounded-2xl border border-slate-200/80 shadow-sm">
        <h3 class="text-base font-bold text-slate-800 mb-4 flex items-center gap-2">
            <i class="fas fa-trophy text-amber-500"></i> ອຸປະກອນຍອດນິຍົມ (Top Items)
        </h3>
        <div class="space-y-3">
            <?php if (!empty($popular_items)): ?>
                <?php foreach ($popular_items as $index => $item): ?>
                    <div class="flex items-center justify-between p-3.5 bg-slate-50/80 rounded-xl border border-slate-100 hover:bg-slate-50 transition">
                        <div class="flex items-center gap-3">
                            <span class="w-7 h-7 rounded-lg flex items-center justify-center text-xs font-bold text-white <?php 
                                echo $index == 0 ? 'bg-amber-500' : ($index == 1 ? 'bg-slate-400' : ($index == 2 ? 'bg-amber-700' : 'bg-slate-300'));
                            ?>">
                                <?php echo $index + 1; ?>
                            </span>
                            <div>
                                <p class="font-semibold text-slate-800 text-sm"><?php echo htmlspecialchars($item['name'] ?? ''); ?></p>
                                <p class="text-xs font-mono text-slate-400"><?php echo htmlspecialchars($item['item_code'] ?? ''); ?></p>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="font-bold text-slate-800 text-sm"><?php echo number_format((int)($item['total_quantity'] ?? 0)); ?></p>
                            <p class="text-xs text-slate-400"><?php echo (int)($item['request_count'] ?? 0); ?> ຄັ້ງ</p>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="text-center py-8 text-slate-400">
                    <i class="fas fa-inbox text-3xl mb-2"></i>
                    <p class="text-sm">ຍັງບໍ່ມີຂໍ້ມູນອຸປະກອນ</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ຜູ້ໃຊ້ທີ່ເບີກຫຼາຍທີ່ສຸດ -->
    <div class="bg-white p-6 rounded-2xl border border-slate-200/80 shadow-sm">
        <h3 class="text-base font-bold text-slate-800 mb-4 flex items-center gap-2">
            <i class="fas fa-crown text-sky-500"></i> ຜູ້ໃຊ້ທີ່ເບີກຫຼາຍທີ່ສຸດ
        </h3>
        <div class="space-y-3">
            <?php if (!empty($top_users)): ?>
                <?php foreach ($top_users as $index => $user): ?>
                    <div class="flex items-center justify-between p-3.5 bg-slate-50/80 rounded-xl border border-slate-100 hover:bg-slate-50 transition">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-full bg-sky-100 text-sky-600 font-bold text-xs flex items-center justify-center">
                                <?php echo mb_substr($user['fullname'] ?? 'U', 0, 1, 'UTF-8'); ?>
                            </div>
                            <div>
                                <p class="font-semibold text-slate-800 text-sm"><?php echo htmlspecialchars($user['fullname'] ?? ''); ?></p>
                                <p class="text-xs text-slate-400">@<?php echo htmlspecialchars($user['username'] ?? ''); ?></p>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="font-bold text-slate-800 text-sm"><?php echo number_format((int)($user['request_count'] ?? 0)); ?> ຄຳຂໍ</p>
                            <p class="text-xs text-slate-400"><?php echo number_format((int)($user['total_items'] ?? 0)); ?> ລາຍການ</p>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="text-center py-8 text-slate-400">
                    <i class="fas fa-inbox text-3xl mb-2"></i>
                    <p class="text-sm">ຍັງບໍ່ມີຂໍ້ມູນຜູ້ໃຊ້</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ອຸປະກອນໃກ້ໝົດ & ຄຳຂໍລ່າສຸດ -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <!-- ອຸປະກອນໃກ້ໝົດ -->
    <div class="bg-white p-6 rounded-2xl border border-slate-200/80 shadow-sm">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-base font-bold text-slate-800 flex items-center gap-2">
                <i class="fas fa-triangle-exclamation text-rose-500"></i> ອຸປະກອນໃກ້ໝົດ
            </h3>
            <a href="?admin=items" class="text-xs font-semibold text-sky-600 hover:text-sky-800">ເບິ່ງທັງໝົດ →</a>
        </div>
        <?php if (!empty($low_stock_items)): ?>
            <div class="space-y-2.5">
                <?php foreach ($low_stock_items as $item): ?>
                    <div class="flex items-center justify-between p-3 bg-rose-50/50 rounded-xl border border-rose-100">
                        <div>
                            <p class="font-semibold text-slate-800 text-sm"><?php echo htmlspecialchars($item['name'] ?? ''); ?></p>
                            <p class="text-xs font-mono text-slate-400">ລະຫັດ: <?php echo htmlspecialchars($item['item_code'] ?? ''); ?></p>
                        </div>
                        <div class="text-right">
                            <span class="text-sm text-rose-600 font-bold"><?php echo (int)($item['quantity'] ?? 0); ?></span>
                            <span class="text-xs text-slate-400">/ <?php echo (int)($item['min_quantity'] ?? 0); ?> <?php echo htmlspecialchars($item['unit'] ?? ''); ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="text-center py-8 text-slate-400">
                <i class="fas fa-circle-check text-emerald-500 text-3xl mb-2"></i>
                <p class="text-sm">ບໍ່ມີອຸປະກອນໃກ້ໝົດ</p>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- ຄຳຂໍລ່າສຸດ -->
    <div class="bg-white p-6 rounded-2xl border border-slate-200/80 shadow-sm">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-base font-bold text-slate-800 flex items-center gap-2">
                <i class="fas fa-receipt text-indigo-600"></i> ຄຳຂໍເບີກລ່າສຸດ
            </h3>
            <a href="?admin=requests" class="text-xs font-semibold text-indigo-600 hover:text-indigo-800">ເບິ່ງທັງໝົດ →</a>
        </div>
        <?php if (!empty($recent_requests)): ?>
            <div class="space-y-2.5">
                <?php foreach ($recent_requests as $request): 
                    $statusBadge = match($request['status'] ?? '') {
                        'approved' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                        'rejected' => 'bg-rose-50 text-rose-700 border-rose-200',
                        default    => 'bg-amber-50 text-amber-700 border-amber-200'
                    };
                    $statusText = match($request['status'] ?? '') {
                        'approved' => 'ອະນຸມັດແລ້ວ',
                        'rejected' => 'ປະຕິເສດ',
                        default    => 'ລໍຖ້າອະນຸມັດ'
                    };
                ?>
                    <div class="flex items-center justify-between p-3 bg-slate-50/80 rounded-xl border border-slate-100 hover:bg-slate-50 transition">
                        <div>
                            <p class="font-semibold text-slate-800 text-sm"><?php echo htmlspecialchars($request['fullname'] ?? ''); ?></p>
                            <p class="text-xs text-slate-500 line-clamp-1"><?php echo htmlspecialchars($request['purpose'] ?? 'ເບີກອຸປະກອນ'); ?></p>
                        </div>
                        <div class="text-right">
                            <span class="inline-block px-2.5 py-0.5 text-xs font-medium border rounded-md <?php echo $statusBadge; ?>">
                                <?php echo $statusText; ?>
                            </span>
                            <p class="text-[11px] text-slate-400 mt-1"><?php echo function_exists('formatDate') ? formatDate($request['created_at'] ?? '') : substr($request['created_at'] ?? '', 0, 10); ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="text-center py-8 text-slate-400">
                <i class="fas fa-inbox text-3xl mb-2"></i>
                <p class="text-sm">ບໍ່ມີຄຳຂໍເບີກ</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
Chart.defaults.font.family = "'Noto Sans Lao', sans-serif";

// Monthly Chart
<?php if (!empty($monthly_stats)): ?>
const monthlyCtx = document.getElementById('monthlyChart')?.getContext('2d');
if(monthlyCtx) {
    new Chart(monthlyCtx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode(array_map(fn($m) => date('M Y', strtotime(($m['month'] ?? date('Y-m')) . '-01')), $monthly_stats)); ?>,
            datasets: [
                {
                    label: 'ອະນຸມັດແລ້ວ',
                    data: <?php echo json_encode(array_column($monthly_stats, 'approved')); ?>,
                    backgroundColor: '#10B981',
                    borderRadius: 6
                },
                {
                    label: 'ລໍຖ້າອະນຸມັດ',
                    data: <?php echo json_encode(array_column($monthly_stats, 'pending')); ?>,
                    backgroundColor: '#F59E0B',
                    borderRadius: 6
                },
                {
                    label: 'ປະຕິເສດ',
                    data: <?php echo json_encode(array_column($monthly_stats, 'rejected')); ?>,
                    backgroundColor: '#EF4444',
                    borderRadius: 6
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom' }
            },
            scales: {
                y: { beginAtZero: true }
            }
        }
    });
}
<?php endif; ?>
</script>
