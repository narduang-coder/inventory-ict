<?php
require_once __DIR__ . '/../includes/config.php';
checkRole(['user']);
$user_id = $_SESSION['user_id'];
$user_department = $_SESSION['department'] ?? '';

// ສະຖິຕິການເບີກ ສະເພາະພະແນກຂອງຜູ້ໃຊ້
$total_requests = $pdo->prepare("SELECT COUNT(*) FROM requests WHERE department = ?");
$total_requests->execute([$user_department]);
$total = $total_requests->fetchColumn();

$pending = $pdo->prepare("SELECT COUNT(*) FROM requests WHERE department = ? AND status = 'pending'");
$pending->execute([$user_department]);
$pending_count = $pending->fetchColumn();

$approved = $pdo->prepare("SELECT COUNT(*) FROM requests WHERE department = ? AND status = 'approved'");
$approved->execute([$user_department]);
$approved_count = $approved->fetchColumn();

$rejected = $pdo->prepare("SELECT COUNT(*) FROM requests WHERE department = ? AND status = 'rejected'");
$rejected->execute([$user_department]);
$rejected_count = $rejected->fetchColumn();

// ຄຳຂໍລ່າສຸດ ສະເພາະພະແນກຂອງຜູ້ໃຊ້
$recent = $pdo->prepare("SELECT * FROM requests WHERE department = ? ORDER BY created_at DESC LIMIT 5");
$recent->execute([$user_department]);
$recent_requests = $recent->fetchAll();
?>

<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-800">ໜ້າຫຼັກ</h1>
    <p class="text-gray-600">
        ສະບາຍດີ, <strong><?php echo htmlspecialchars($_SESSION['fullname'] ?? 'ຜູ້ໃຊ້'); ?></strong>! 
        (ພະແນກ: <span class="text-indigo-600 font-semibold"><?php echo htmlspecialchars($user_department); ?></span>)
    </p>
</div>

<div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
    <div class="stat-card card">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-500">ຄຳຂໍທັງໝົດໃນພະແນກ</p>
                <p class="text-2xl font-bold text-gray-800"><?php echo number_format($total); ?></p>
            </div>
            <div class="stat-icon p-3 rounded-full bg-blue-100 text-blue-600">
                <i class="fas fa-clipboard-list text-xl"></i>
            </div>
        </div>
    </div>
    <div class="stat-card card border-l-4 border-l-amber-500">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-500">ລໍຖ້າອະນຸມັດ</p>
                <p class="text-2xl font-bold text-gray-800"><?php echo number_format($pending_count); ?></p>
            </div>
            <div class="stat-icon p-3 rounded-full bg-yellow-100 text-yellow-600">
                <i class="fas fa-clock text-xl"></i>
            </div>
        </div>
    </div>
    <div class="stat-card card border-l-4 border-l-emerald-500">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-500">ອະນຸມັດແລ້ວ</p>
                <p class="text-2xl font-bold text-gray-800"><?php echo number_format($approved_count); ?></p>
            </div>
            <div class="stat-icon p-3 rounded-full bg-green-100 text-green-600">
                <i class="fas fa-check-circle text-xl"></i>
            </div>
        </div>
    </div>
    <div class="stat-card card border-l-4 border-l-rose-500">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-500">ປະຕິເສດ</p>
                <p class="text-2xl font-bold text-gray-800"><?php echo number_format($rejected_count); ?></p>
            </div>
            <div class="stat-icon p-3 rounded-full bg-red-100 text-red-600">
                <i class="fas fa-times-circle text-xl"></i>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="flex items-center justify-between mb-4">
        <h3 class="text-lg font-bold text-gray-800">
            <i class="fas fa-history text-blue-500 mr-2"></i>
            ຄຳຂໍລ່າສຸດ (ປະຈຳພະແນກ)
        </h3>
        <a href="?user=myrequests" class="text-sm text-blue-600 hover:text-blue-800">ເບິ່ງທັງໝົດ →</a>
    </div>
    <?php if (count($recent_requests) > 0): ?>
        <div class="space-y-2">
            <?php foreach ($recent_requests as $request): 
                $colors = ['pending' => 'bg-yellow-100 text-yellow-800', 'approved' => 'bg-green-100 text-green-800', 'rejected' => 'bg-red-100 text-red-800'];
                $texts = ['pending' => 'ລໍຖ້າອະນຸມັດ', 'approved' => 'ອະນຸມັດແລ້ວ', 'rejected' => 'ປະຕິເສດ'];
            ?>
                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                    <div>
                        <p class="font-medium text-gray-800"><?php echo htmlspecialchars($request['purpose']); ?></p>
                        <p class="text-xs text-gray-500"><?php echo function_exists('formatDate') ? formatDate($request['request_date']) : $request['request_date']; ?></p>
                    </div>
                    <span class="px-2.5 py-1 text-xs rounded-full font-medium <?php echo $colors[$request['status']] ?? 'bg-gray-100'; ?>">
                        <?php echo $texts[$request['status']] ?? $request['status']; ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="text-center py-8 text-gray-500">
            <i class="fas fa-inbox text-3xl mb-2 block"></i>
            <p>ຍັງບໍ່ມີຄຳຂໍເບີກໃນພະແນກນີ້</p>
            <a href="?user=request" class="text-blue-600 hover:text-blue-800 mt-2 inline-block">ສ້າງຄຳຂໍເບີກ</a>
        </div>
    <?php endif; ?>
</div>
