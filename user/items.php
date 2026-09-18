<?php
require_once __DIR__ . '/../includes/config.php';
checkRole(['user']);

$user_department = $_SESSION['department'] ?? '';
$search_query = trim($_GET['search'] ?? '');
$my_department_items = [];

if (!empty($user_department) && isset($pdo)) {
    // ແຍກ Parameter ສຳລັບແຕ່ລະ Subquery
    $params = [
        'dept1' => $user_department,
        'dept2' => $user_department,
        'dept3' => $user_department
    ];

    $search_condition = "";
    if (!empty($search_query)) {
        // ແຍກ Parameter :search ເປັນ 4 ຕົວ ເພື່ອປ້ອງກັນ Error HY093 ໃນ PDO
        $search_condition = " AND (i.name LIKE :search1 
                                OR i.item_code LIKE :search2 
                                OR c.cate_name LIKE :search3 
                                OR da.asset_code LIKE :search4) ";
        
        $search_term = '%' . $search_query . '%';
        $params['search1'] = $search_term;
        $params['search2'] = $search_term;
        $params['search3'] = $search_term;
        $params['search4'] = $search_term;
    }

    $sql = "
        SELECT 
            i.id AS item_id,
            i.item_code,
            i.name AS item_name,
            c.cate_name AS category_name,
            da.asset_code,
            IFNULL(approved_data.total_approved, 0) AS total_received,
            IFNULL(returned_data.total_returned, 0) AS total_returned,
            (
                IFNULL(approved_data.total_approved, 0) 
                - IFNULL(returned_data.total_returned, 0)
            ) AS current_balance

        FROM items i
        LEFT JOIN category c ON i.cate_id = c.cate_id
        
        INNER JOIN (
            SELECT 
                ri.item_id, 
                SUM(ri.quantity) AS total_approved
            FROM request_items ri
            JOIN requests r ON ri.request_id = r.id
            WHERE r.department = :dept1 
              AND r.status IN ('approved', 'issued', 'completed')
            GROUP BY ri.item_id
        ) approved_data ON i.id = approved_data.item_id
        
        LEFT JOIN (
            SELECT 
                item_id, 
                SUM(quantity) AS total_returned
            FROM return_requests
            WHERE department = :dept2 
              AND status IN ('pending', 'approved', 'disposed', 'completed', 'cleared')
            GROUP BY item_id
        ) returned_data ON i.id = returned_data.item_id

        LEFT JOIN (
            SELECT 
                item_id, 
                GROUP_CONCAT(DISTINCT asset_code SEPARATOR ', ') AS asset_code
            FROM department_assets
            WHERE department = :dept3
            GROUP BY item_id
        ) da ON i.id = da.item_id
        
        WHERE 1=1 {$search_condition}
        HAVING current_balance > 0
        ORDER BY i.name ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $my_department_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<div class="p-6 bg-white rounded-2xl border border-slate-200/80 shadow-sm">
    <!-- Header & Search Form -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-6">
        <h2 class="text-xl font-bold text-slate-800 flex items-center gap-2">
            <i class="fas fa-boxes-stacked text-indigo-600"></i>
            ອຸປະກອນທີ່ພະແນກມີ: (<span class="text-indigo-600"><?php echo htmlspecialchars($user_department); ?></span>)
        </h2>

        <!-- ຟອມຄົ້ນຫາ -->
        <form method="GET" action="" class="flex items-center gap-2 w-full md:w-auto">
            <?php foreach ($_GET as $key => $value): ?>
                <?php if ($key !== 'search'): ?>
                    <input type="hidden" name="<?php echo htmlspecialchars($key); ?>" value="<?php echo htmlspecialchars($value); ?>">
                <?php endif; ?>
            <?php endforeach; ?>

            <div class="relative w-full md:w-72">
                <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-slate-400">
                    <i class="fas fa-search"></i>
                </span>
                <input type="text" 
                       name="search" 
                       value="<?php echo htmlspecialchars($search_query); ?>" 
                       placeholder="ຄົ້ນຫາ ຊື່, ລະຫັດ, ທສກ, ໝວດໝູ່..." 
                       class="w-full pl-9 pr-8 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:bg-white transition-all">
                
                <?php if (!empty($search_query)): ?>
                    <?php 
                        $clear_params = $_GET;
                        unset($clear_params['search']);
                        $clear_url = '?' . http_build_query($clear_params);
                    ?>
                    <a href="<?php echo htmlspecialchars($clear_url); ?>" 
                       class="absolute inset-y-0 right-0 flex items-center pr-3 text-slate-400 hover:text-slate-600" 
                       title="ລ້າງການຄົ້ນຫາ">
                        <i class="fas fa-times-circle"></i>
                    </a>
                <?php endif; ?>
            </div>
            <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-medium rounded-xl text-sm transition-colors shadow-sm flex items-center gap-1.5 shrink-0">
                <i class="fas fa-search text-xs"></i> ຄົ້ນຫາ
            </button>
        </form>
    </div>

    <!-- Table -->
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm text-slate-600">
            <thead class="bg-slate-50 text-slate-500 text-xs uppercase font-semibold border-b border-slate-200">
                <tr>
                    <th class="px-4 py-3">ລະຫັດອຸປະກອນ</th>
                    <th class="px-4 py-3">ລະຫັດ ຊຄທ</th>
                    <th class="px-4 py-3">ຊື່ອຸປະກອນ</th>
                    <th class="px-4 py-3">ໝວດໝູ່</th>
                    <th class="px-4 py-3 text-center">ໄດ້ຮັບທັງໝົດ</th>
                    <th class="px-4 py-3 text-center">ສົ່ງຄືນແລ້ວ</th>
                    <th class="px-4 py-3 text-center">ຄົງເຫຼືອໃນພະແນກ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if (count($my_department_items) > 0): ?>
                    <?php foreach ($my_department_items as $item): ?>
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-4 py-3 font-mono text-xs"><?php echo htmlspecialchars($item['item_code'] ?? '-'); ?></td>
                            <td class="px-4 py-3 font-mono text-xs text-indigo-600 font-semibold">
                                <?php echo htmlspecialchars($item['asset_code'] ?? '-'); ?>
                            </td>
                            <td class="px-4 py-3 font-semibold text-slate-800"><?php echo htmlspecialchars($item['item_name']); ?></td>
                            <td class="px-4 py-3 text-slate-500"><?php echo htmlspecialchars($item['category_name'] ?? '-'); ?></td>
                            <td class="px-4 py-3 text-center font-medium text-slate-600"><?php echo number_format($item['total_received']); ?></td>
                            <td class="px-4 py-3 text-center font-medium text-amber-600"><?php echo number_format($item['total_returned']); ?></td>
                            <td class="px-4 py-3 text-center font-bold text-emerald-600">
                                <?php echo number_format($item['current_balance']) . ' ອັນ'; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center text-slate-400">
                            <?php if (!empty($search_query)): ?>
                                ບໍ່ພົບຂໍ້ມູນທີ່ກົງກັບຄຳຄົ້ນຫາ "<strong><?php echo htmlspecialchars($search_query); ?></strong>"
                            <?php else: ?>
                                ບໍ່ມີອຸປະກອນຢູ່ໃນພະແນກ ຫຼື ອຸປະກອນທີ່ຂໍໄປຍັງບໍ່ໄດ້ຮັບການອະນຸມັດ.
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
