<?php
require_once __DIR__ . '/../includes/config.php';
checkRole(['user']);
// ============================================================
// 1. SESSION & INITIALIZATION
// ============================================================

$user_id         = $_SESSION['user_id'] ?? null;
$user_department = $_SESSION['department'] ?? '';
$user_dept_id    = $_SESSION['dept_id'] ?? null;

// ============================================================
// 2. FORM PROCESSING (ປະມວນຜົນການສົ່ງຄຳຂໍເບີກ)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_request'])) {
    // CSRF Token Check
    if (function_exists('verifyCSRFToken')) {
        $csrf_token = $_POST['csrf_token'] ?? '';
        if (!verifyCSRFToken($csrf_token)) {
            if (function_exists('showAlert')) showAlert('ຂໍ້ມູນບໍ່ປອດໄພ (CSRF Token Invalid)', 'error');
            echo '<script>window.location.href = "?user=request";</script>';
            exit();
        }
    }
    
    $request_date = $_POST['request_date'] ?? date('Y-m-d');
    $department   = !empty($user_department) ? $user_department : ($_POST['department'] ?? '');
    $purpose      = trim($_POST['purpose'] ?? '');
    $note         = trim($_POST['note'] ?? '');
    $item_ids     = $_POST['item_id'] ?? [];
    $quantities   = $_POST['quantity'] ?? [];
    
    if (empty($item_ids)) {
        if (function_exists('showAlert')) showAlert('ກະລຸນາເລືອກອຸປະກອນຢ່າງໜ້ອຍ 1 ລາຍການ', 'error');
    } else {
        try {
            $pdo->beginTransaction();
            
            // ບັນທຶກຄຳຂໍເບີກຫຼັກ (ກຳນົດ status = 'pending' ຢ່າງຈະແຈ້ງ)
            $stmt = $pdo->prepare("
                INSERT INTO requests (user_id, request_date, department, purpose, note, status, created_at) 
                VALUES (?, ?, ?, ?, ?, 'pending', NOW())
            ");
            $stmt->execute([$user_id, $request_date, $department, $purpose, $note]);
            $request_id = $pdo->lastInsertId();
            
            // ບັນທຶກລາຍການອຸປະກອນທີ່ຂໍເບີກ
            $stmtItem = $pdo->prepare("INSERT INTO request_items (request_id, item_id, quantity) VALUES (?, ?, ?)");
            for ($i = 0; $i < count($item_ids); $i++) {
                $qty = (int)($quantities[$i] ?? 0);
                $itemId = (int)($item_ids[$i] ?? 0);
                if ($itemId > 0 && $qty > 0) {
                    $stmtItem->execute([$request_id, $itemId, $qty]);
                }
            }
            
            $pdo->commit();
            if (function_exists('showAlert')) showAlert('ສົ່ງຄຳຂໍເບີກສຳເລັດ! ລໍຖ້າການອະນຸມັດ', 'success');
            if (function_exists('logActivity')) logActivity("ສົ່ງຄຳຂໍເບີກ ID: {$request_id}");
            echo '<script>window.location.href = "?user=myrequests";</script>';
            exit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if (function_exists('showAlert')) showAlert('ເກີດຂໍ້ຜິດພາດ ກະລຸນາລອງໃໝ່', 'error');
        }
    }
}

// ============================================================
// 3. FETCH DATA (ດຶງຂໍ້ມູນອຸປະກອນທັງໝົດໃນລະບົບ - ປ້ອງກັນ SQL Error)
// ============================================================
$items = [];
$categories = [];
if (isset($pdo)) {
    try {
        $categories = $pdo->query("SELECT * FROM category ORDER BY cate_name ASC")->fetchAll(PDO::FETCH_ASSOC);
        $stmt = $pdo->prepare("SELECT i.*, c.cate_name
                               FROM items i
                               LEFT JOIN category c ON i.cate_id = c.cate_id
                               WHERE i.quantity > 0 AND (i.is_active = 1 OR i.is_active IS NULL)
                               ORDER BY i.name ASC");
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        if (function_exists('showAlert')) showAlert('ເກີດຂໍ້ຜິດພາດ ກະລຸນາລອງໃໝ່', 'error');
    }
}
?>

<div class="mb-8">
    <h1 class="text-2xl font-bold text-slate-800 flex items-center gap-3">
        <span class="p-2.5 bg-indigo-50 text-indigo-600 rounded-xl text-xl">
            <i class="fas fa-file-signature"></i>
        </span>
        ຟອມຂໍເບີກອຸປະກອນ
    </h1>
    <p class="text-slate-500 text-sm mt-1">
        ສະແດງອຸປະກອນທັງໝົດ | ຜູ້ຂໍເບີກຈາກ: <span class="font-bold text-indigo-600"><?php echo htmlspecialchars($user_department ?: 'ບໍ່ລະບຸ'); ?></span> 
    </p>
</div>

<?php if (function_exists('getAlert')): ?>
    <?php $alert = getAlert(); if ($alert): ?>
        <div class="p-4 mb-6 rounded-2xl flex items-center gap-3 <?php echo $alert['type'] == 'success' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-rose-50 text-rose-700 border border-rose-200'; ?>">
            <i class="fas <?php echo $alert['type'] == 'success' ? 'fa-circle-check text-xl' : 'fa-triangle-exclamation text-xl'; ?>"></i>
            <div class="text-sm font-medium"><?php echo htmlspecialchars($alert['message']); ?></div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<form method="POST" id="requestForm" class="grid grid-cols-1 lg:grid-cols-12 gap-8">
    <input type="hidden" name="csrf_token" value="<?php echo function_exists('generateCSRFToken') ? generateCSRFToken() : ''; ?>">
    
    <!-- ສ່ວນ Card ສະແດງອຸປະກອນ -->
    <div class="lg:col-span-7 space-y-4">
        <div class="card bg-white p-4 rounded-2xl border border-slate-100 shadow-sm">
            <div class="flex flex-col sm:flex-row gap-3">
                <div class="relative flex-1">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none"></i>
                    <input type="text" id="requestSearchInput" oninput="filterRequestItems()"
                           placeholder="ຄົ້ນຫາຊື່, ລະຫັດ, Serial No, Barcode, ຍີ່ຫໍ້..."
                           class="form-input !pl-10 w-full border border-slate-200 rounded-xl">
                </div>
                <select id="requestCategoryFilter" onchange="filterRequestItems()" class="form-input sm:w-52 border border-slate-200 rounded-xl">
                    <option value="all">-- ທຸກໝວດໝູ່ --</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?php echo (int)$category['cate_id']; ?>"><?php echo htmlspecialchars($category['cate_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <p id="requestFilterEmpty" class="hidden text-center text-slate-400 text-sm pt-3">ບໍ່ພົບອຸປະກອນທີ່ກົງກັບການຄົ້ນຫາ</p>
        </div>

        <div class="card bg-white p-6 rounded-2xl border border-slate-100 shadow-sm">
            <div class="flex items-center justify-between mb-4 pb-3 border-b border-slate-100">
                <h3 class="font-bold text-slate-800 text-lg flex items-center gap-2">
                    <i class="fas fa-boxes-packing text-indigo-500"></i>
                    ເລືອກອຸປະກອນທີ່ຕ້ອງການເບີກ
                </h3>
                <span class="text-xs bg-indigo-50 text-indigo-600 px-3 py-1 rounded-full font-medium">
                    ມີທັງໝົດ <?php echo count($items); ?> ລາຍການ
                </span>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 max-h-[550px] overflow-y-auto pr-1">
                <?php if (!empty($items) && count($items) > 0): ?>
                    <?php foreach ($items as $item): 
                        // ກວດສອບ Column ຮູບພາບ (ຮອງຮັບ image, image_path, img)
                        $img_src = '';
                        if (!empty($item['image'])) $img_src = $item['image'];
                        elseif (!empty($item['image_path'])) $img_src = $item['image_path'];
                        elseif (!empty($item['img'])) $img_src = $item['img'];
                        $img_path = assetPath($img_src);
                    ?>
                            <div class="item-card border border-slate-200 rounded-2xl p-4 transition-all duration-200 hover:border-indigo-300 hover:shadow-md bg-white relative"
                                data-name="<?php echo htmlspecialchars(mb_strtolower((string)$item['name']), ENT_QUOTES); ?>"
                                data-code="<?php echo htmlspecialchars(mb_strtolower((string)($item['item_code'] ?? '')), ENT_QUOTES); ?>"
                                data-sn="<?php echo htmlspecialchars(mb_strtolower((string)($item['serial_number'] ?? '')), ENT_QUOTES); ?>"
                                data-barcode="<?php echo htmlspecialchars(mb_strtolower((string)($item['barcode'] ?? '')), ENT_QUOTES); ?>"
                                data-brand="<?php echo htmlspecialchars(mb_strtolower((string)($item['brand'] ?? '')), ENT_QUOTES); ?>"
                                data-model="<?php echo htmlspecialchars(mb_strtolower((string)($item['model'] ?? '')), ENT_QUOTES); ?>"
                                data-origin="<?php echo htmlspecialchars(mb_strtolower((string)($item['origin'] ?? '')), ENT_QUOTES); ?>"
                                data-remark="<?php echo htmlspecialchars(mb_strtolower((string)($item['remark'] ?? '')), ENT_QUOTES); ?>"
                                data-category="<?php echo (int)($item['cate_id'] ?? 0); ?>">
                            <div class="flex gap-3">
                                <div class="w-14 h-14 bg-slate-100 rounded-xl flex-shrink-0 flex items-center justify-center text-slate-400 font-bold text-lg overflow-hidden border border-slate-100">
                                    <?php if (!empty($img_src) && file_exists($img_path)): ?>
                                        <img src="<?php echo htmlspecialchars(assetUrl($img_src)); ?>" class="w-full h-full object-cover">
                                    <?php else: ?>
                                        <i class="fas fa-box text-2xl text-indigo-300"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <h4 class="font-bold text-slate-800 text-sm truncate" title="<?php echo htmlspecialchars($item['name']); ?>">
                                        <?php echo htmlspecialchars($item['name']); ?>
                                    </h4>
                                    <p class="text-xs text-slate-400 mt-0.5">ລະຫັດ: <?php echo htmlspecialchars($item['item_code'] ?? '#'.str_pad($item['id'], 4, '0', STR_PAD_LEFT)); ?></p>
                                    <span class="inline-block text-[11px] font-semibold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded-md mt-2">
                                        ຄົງເຫຼືອ: <?php echo number_format($item['quantity']); ?> <?php echo htmlspecialchars($item['unit'] ?? 'ອັນ'); ?>
                                    </span>
                                </div>
                            </div>

                            <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between">
                                <label class="text-xs text-slate-500 font-medium">ຈຳນວນເບີກ:</label>
                                <div class="flex items-center gap-2">
                                    <input type="number" 
                                           name="qty_input_<?php echo $item['id']; ?>" 
                                           id="qty_<?php echo $item['id']; ?>"
                                           data-id="<?php echo $item['id']; ?>"
                                           data-name="<?php echo htmlspecialchars($item['name'], ENT_QUOTES); ?>"
                                           data-max="<?php echo (int)$item['quantity']; ?>"
                                           data-unit="<?php echo htmlspecialchars($item['unit'] ?? 'ອັນ', ENT_QUOTES); ?>"
                                           min="0" 
                                           max="<?php echo (int)$item['quantity']; ?>" 
                                           value="0"
                                           class="w-20 form-input text-center py-1 font-bold text-slate-700 border border-slate-200 rounded-lg" 
                                           oninput="handleQtyChange(this)">
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="col-span-2 text-center py-12 text-slate-400">
                        <i class="fas fa-box-open text-4xl mb-3 block"></i>
                        ບໍ່ພົບອຸປະກອນໃນຄັງ
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ສ່ວນ ຂໍ້ມູນຄຳຂໍເບີກ -->
    <div class="lg:col-span-5 space-y-6">
        <div class="card bg-white p-6 rounded-2xl border border-slate-100 shadow-sm">
            <h3 class="font-bold text-slate-800 text-lg mb-4 pb-3 border-b border-slate-100 flex items-center gap-2">
                <i class="fas fa-paper-plane text-indigo-500"></i>
                ຂໍ້ມູນຄຳຂໍເບີກ
            </h3>

            <div class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-600 uppercase mb-1">ວັນທີຂໍເບີກ *</label>
                    <input type="date" name="request_date" value="<?php echo date('Y-m-d'); ?>" required class="form-input font-medium text-slate-700 border border-slate-200 rounded-lg w-full p-2">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-600 uppercase mb-1">ພາກສ່ວນ / ພະແນກ</label>
                    <input type="text" name="department_display" value="<?php echo htmlspecialchars($user_department); ?>" readonly class="form-input bg-slate-100 font-bold text-indigo-600 cursor-not-allowed border border-slate-200 rounded-lg w-full p-2">
                    <input type="hidden" name="department" value="<?php echo htmlspecialchars($user_department); ?>">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-600 uppercase mb-1">ຜູ້ຂໍເບີກ</label>
                    <input type="text" value="<?php echo htmlspecialchars($_SESSION['fullname'] ?? ($_SESSION['username'] ?? 'User')); ?>" readonly class="form-input bg-slate-100 font-medium text-slate-500 cursor-not-allowed border border-slate-200 rounded-lg w-full p-2">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-600 uppercase mb-1">ຈຸດປະສົງ <span class="text-red-500">*</span></label>
                    <textarea name="purpose" rows="2" required class="form-input border border-slate-200 rounded-lg w-full p-2" placeholder="ລະບຸຈຸດປະສົງໃນການນຳໃຊ້..."></textarea>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-600 uppercase mb-1">ໝາຍເຫດ</label>
                    <textarea name="note" rows="2" class="form-input border border-slate-200 rounded-lg w-full p-2" placeholder="ໝາຍເຫດເພີ່ມເຕີມ (ຖ້າມີ)..."></textarea>
                </div>
            </div>

            <div class="mt-6 pt-4 border-t border-slate-100">
                <h4 class="text-xs font-semibold text-slate-600 uppercase mb-3 flex items-center justify-between">
                    <span>ລາຍການທີ່ຈະເບີກ</span>
                    <span id="selectedCount" class="bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded-full text-[11px]">0 ລາຍການ</span>
                </h4>
                
                <div id="selectedItemsContainer" class="space-y-2 mb-4 max-h-48 overflow-y-auto">
                    <p id="emptyCartText" class="text-center text-slate-400 py-6 text-sm italic border-2 border-dashed border-slate-100 rounded-xl">
                        ຍັງບໍ່ທັນມີລາຍການຖືກເລືອກ
                    </p>
                </div>

                <div id="hiddenInputs"></div>

                <button type="submit" name="submit_request" class="btn-primary w-full py-3 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-semibold shadow-lg transition duration-200">
                    <i class="fas fa-paper-plane mr-1"></i> ຢືນຢັນການສົ່ງຄຳຂໍເບີກ
                </button>
            </div>
        </div>
    </div>
</form>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
let selectedCart = {};

function handleQtyChange(inputEl) {
    const id = inputEl.getAttribute('data-id');
    const name = inputEl.getAttribute('data-name');
    const maxStock = parseInt(inputEl.getAttribute('data-max')) || 0;
    const unit = inputEl.getAttribute('data-unit');
    let qty = parseInt(inputEl.value) || 0;

    if (qty > maxStock) {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon: 'warning',
                title: 'ຈຳນວນເກີນສິນຄ້າທີ່ມີ',
                text: `ອຸປະກອນນີ້ມີຄົງເຫຼືອງພຽງ ${maxStock} ${unit}`,
                confirmColor: '#6366f1'
            });
        } else {
            alert(`ອຸປະກອນນີ້ມີຄົງເຫຼືອງພຽງ ${maxStock} ${unit}`);
        }
        inputEl.value = maxStock;
        qty = maxStock;
    }

    if (qty < 0) {
        inputEl.value = 0;
        qty = 0;
    }

    if (qty > 0) {
        selectedCart[id] = { name: name, qty: qty, unit: unit };
    } else {
        delete selectedCart[id];
    }
    
    renderSelectedList();
}

function renderSelectedList() {
    const container = document.getElementById('selectedItemsContainer');
    const hiddenInputs = document.getElementById('hiddenInputs');
    const countBadge = document.getElementById('selectedCount');
    
    container.innerHTML = '';
    hiddenInputs.innerHTML = '';
    
    const keys = Object.keys(selectedCart);
    countBadge.textContent = `${keys.length} ລາຍການ`;
    
    if (keys.length === 0) {
        container.innerHTML = `<p class="text-center text-slate-400 py-6 text-sm italic border-2 border-dashed border-slate-100 rounded-xl">ຍັງບໍ່ທັນມີລາຍການຖືກເລືອກ</p>`;
        return;
    }
    
    keys.forEach(id => {
        const item = selectedCart[id];
        
        const itemEl = document.createElement('div');
        itemEl.className = 'flex items-center justify-between p-2.5 bg-slate-50 border border-slate-200/80 rounded-xl text-sm';
        itemEl.innerHTML = `
            <div class="truncate pr-2">
                <span class="font-semibold text-slate-700">${item.name}</span>
            </div>
            <div class="flex items-center gap-2 flex-shrink-0">
                <span class="bg-indigo-50 text-indigo-700 font-bold px-2 py-0.5 rounded-lg text-xs">
                    ${item.qty} ${item.unit}
                </span>
                <button type="button" onclick="removeItem('${id}')" class="text-slate-400 hover:text-rose-500 p-1">
                    <i class="fas fa-xmark"></i>
                </button>
            </div>
        `;
        container.appendChild(itemEl);

        hiddenInputs.innerHTML += `
            <input type="hidden" name="item_id[]" value="${id}">
            <input type="hidden" name="quantity[]" value="${item.qty}">
        `;
    });
}

function removeItem(id) {
    delete selectedCart[id];
    const qtyInput = document.getElementById(`qty_${id}`);
    if (qtyInput) qtyInput.value = 0;
    renderSelectedList();
}

function filterRequestItems() {
    const searchTerm = document.getElementById('requestSearchInput').value.toLowerCase().trim();
    const selectedCategory = document.getElementById('requestCategoryFilter').value;
    const cards = document.querySelectorAll('.item-card');
    let visibleCount = 0;

    cards.forEach(card => {
        const searchableText = [
            card.dataset.name, card.dataset.code, card.dataset.sn, card.dataset.barcode,
            card.dataset.brand, card.dataset.model, card.dataset.origin, card.dataset.remark
        ].join(' ');
        const isVisible = searchableText.includes(searchTerm) &&
            (selectedCategory === 'all' || card.dataset.category === selectedCategory);

        card.classList.toggle('hidden', !isVisible);
        if (isVisible) visibleCount++;
    });

    document.getElementById('requestFilterEmpty').classList.toggle('hidden', visibleCount !== 0);
}

document.getElementById('requestForm').addEventListener('submit', function(e) {
    if (Object.keys(selectedCart).length === 0) {
        e.preventDefault();
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon: 'error',
                title: 'ກະລຸນາເລືອກອຸປະກອນ',
                text: 'ທ່ານຕ້ອງເລືອກຢ່າງໜ້ອຍ 1 ລາຍການກ່ອນສົ່ງຄຳຂໍ',
                confirmColor: '#6366f1'
            });
        } else {
            alert('ທ່ານຕ້ອງເລືອກຢ່າງໜ້ອຍ 1 ລາຍການກ່ອນສົ່ງຄຳຂໍ');
        }
    }
});
</script>
