<?php
require_once __DIR__ . '/../includes/config.php';

// ກວດສອບສິດການເຂົ້າເຖິງ
checkRole(['admin']);

// Function ສຳລັບ Redirect ຢ່າງປອດໄພ (ຮອງຮັບທັງ Headers sent ແລະ ບໍ່ sent)
function safeRedirect($url) {
    if (!headers_sent()) {
        header("Location: " . $url);
        exit();
    } else {
        echo '<script>window.location.href = "' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '";</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" /></noscript>';
        exit();
    }
}

$redirect_url = $_SERVER['REQUEST_URI'] ?? $_SERVER['PHP_SELF'];

// ປະມວນຜົນຟອມ (POST Request)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    // ກວດສອບ CSRF Token
    if (!verifyCSRFToken($csrf_token)) {
        showAlert('ຂໍ້ມູນບໍ່ປອດໄພ (CSRF Token Invalid)', 'error');
        safeRedirect($redirect_url);
    }

    // ==========================================
    // 1. ຈັດການຂໍ້ມູນພະແນກ (Departments)
    // ==========================================
    if (isset($_POST['add_department'])) {
        $dept_name = trim($_POST['dept_name'] ?? '');

        if (empty($dept_name)) {
            showAlert('ກະລຸນາປ້ອນຊື່ພະແນກ', 'error');
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO departments (dept_name) VALUES (?)");
                $stmt->execute([$dept_name]);
                
                showAlert('ເພີ່ມພະແນກສຳເລັດ', 'success');
                if (function_exists('logActivity')) {
                    logActivity("ເພີ່ມພະແນກ: {$dept_name}");
                }
            } catch (PDOException $e) {
                error_log("Add Department Error: " . $e->getMessage());
                showAlert('ເກີດຂໍ້ຜິດພາດໃນການເພີ່ມຂໍ້ມູນ', 'error');
            }
        }
        safeRedirect($redirect_url);
    }

    if (isset($_POST['delete_department'])) {
        $id = filter_input(INPUT_POST, 'department_id', FILTER_VALIDATE_INT);
        
        if ($id) {
            try {
                $stmt = $pdo->prepare("DELETE FROM departments WHERE id = ?");
                $stmt->execute([$id]);
                showAlert('ລຶບພະແນກສຳເລັດ', 'success');
            } catch (PDOException $e) {
                error_log("Delete Department Error: " . $e->getMessage());
                showAlert('ບໍ່ສາມາດລຶບໄດ້ ຍ້ອນມີຂໍ້ມູນຖືກນຳໃຊ້ອື່ນຢູ່', 'error');
            }
        } else {
            showAlert('ລະຫັດພະແນກບໍ່ຖືກຕ້ອງ', 'error');
        }
        safeRedirect($redirect_url);
    }

    // ==========================================
    // 2. ຈັດການຂໍ້ມູນໝວດໝູ່ອຸປະກອນ (Category)
    // ==========================================
    if (isset($_POST['add_category'])) {
        $cate_name = trim($_POST['cate_name'] ?? '');

        if (empty($cate_name)) {
            showAlert('ກະລຸນາປ້ອນຊື່ໝວດໝູ່ອຸປະກອນ', 'error');
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO category (cate_name) VALUES (?)");
                $stmt->execute([$cate_name]);
                
                showAlert('ເພີ່ມໝວດໝູ່ອຸປະກອນສຳເລັດ', 'success');
                if (function_exists('logActivity')) {
                    logActivity("ເພີ່ມໝວດໝູ່: {$cate_name}");
                }
            } catch (PDOException $e) {
                error_log("Add Category Error: " . $e->getMessage());
                showAlert('ເກີດຂໍ້ຜິດພາດໃນການເພີ່ມຂໍ້ມູນ', 'error');
            }
        }
        safeRedirect($redirect_url);
    }

    if (isset($_POST['delete_category'])) {
        $cate_id = filter_input(INPUT_POST, 'cate_id', FILTER_VALIDATE_INT);
        
        if ($cate_id) {
            try {
                $stmt = $pdo->prepare("DELETE FROM category WHERE cate_id = ?");
                $stmt->execute([$cate_id]);
                showAlert('ລຶບໝວດໝູ່ສຳເລັດ', 'success');
            } catch (PDOException $e) {
                error_log("Delete Category Error: " . $e->getMessage());
                showAlert('ບໍ່ສາມາດລຶບໄດ້ ຍ້ອນມີຂໍ້ມູນຖືກນຳໃຊ້ອື່ນຢູ່', 'error');
            }
        } else {
            showAlert('ລະຫັດໝວດໝູ່ບໍ່ຖືກຕ້ອງ', 'error');
        }
        safeRedirect($redirect_url);
    }
}

// ດຶງຂໍ້ມູນພະແນກ ແລະ ໝວດໝູ່ (SELECT)
$departments = [];
$categories  = [];

try {
    $departments = $pdo->query("SELECT * FROM departments ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    $categories  = $pdo->query("SELECT * FROM category ORDER BY cate_id DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Fetch Data Error: " . $e->getMessage());
    showAlert('ບໍ່ສາມາດດຶງຂໍ້ມູນໄດ້', 'error');
}
?>

<!-- SweetAlert2 CDN ສຳລັບ Modal ຢືນຢັນລຶບຂໍ້ມູນ -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<!-- Header Banner -->
<div class="bg-gradient-to-r from-indigo-600 to-blue-500 rounded-2xl p-6 text-white shadow-lg mb-8 flex flex-col md:flex-row justify-between items-center gap-4 w-full">
    <div>
        <h1 class="text-2xl font-extrabold flex items-center gap-3">
            <i class="fas fa-layer-group text-3xl"></i> ຈັດການຂໍ້ມູນພະແນກ ແລະ ໝວດໝູ່ອຸປະກອນ
        </h1>
        <p class="text-indigo-100 text-sm mt-1">ເລືອກປະເພດຂໍ້ມູນດ້ານລຸ່ມເພື່ອເພີ່ມຂໍ້ມູນ</p>
    </div>
    <div class="flex gap-3">
        <span class="bg-white/20 backdrop-blur-md px-4 py-2 rounded-xl text-sm font-semibold border border-white/30">
             <?php echo count($departments); ?> ພະແນກ
        </span>
        <span class="bg-white/20 backdrop-blur-md px-4 py-2 rounded-xl text-sm font-semibold border border-white/30">
             <?php echo count($categories); ?> ໝວດໝູ່
        </span>
    </div>
</div>

<?php
$alert = getAlert();
if ($alert): ?>
    <div class="p-4 mb-6 rounded-xl shadow-sm flex items-center justify-between w-full <?php echo $alert['type'] === 'success' ? 'bg-emerald-50 text-emerald-800 border border-emerald-200' : 'bg-rose-50 text-rose-800 border border-rose-200'; ?>">
        <div class="flex items-center gap-3">
            <i class="fas <?php echo $alert['type'] === 'success' ? 'fa-check-circle text-emerald-500 text-xl' : 'fa-exclamation-circle text-rose-500 text-xl'; ?>"></i>
            <span class="font-medium"><?php echo htmlspecialchars($alert['message'], ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
        <button onclick="this.parentElement.remove()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
    </div>
<?php endif; ?>

<!-- 🎯 Dropdown Selection -->
<div class="bg-white p-8 rounded-2xl border border-gray-100 shadow-sm mb-8 w-full text-center">
    <label for="entryTypeSelect" class="block text-lg font-bold text-gray-800 mb-2">
        <i class="fas fa-hand-point-down text-indigo-600 mr-2"></i>ເລືອກປະເພດຂໍ້ມູນທີ່ຕ້ອງການຈັດການ
    </label>
    <p class="text-xs text-gray-500 mb-5">ກະລຸນາເລືອກປະເພດຂໍ້ມູນ</p>
    
    <div class="w-full">
        <select id="entryTypeSelect" onchange="toggleAddForm()" class="w-full border-2 border-indigo-200 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-100 rounded-xl p-3.5 text-base font-semibold bg-gray-50 focus:bg-white transition cursor-pointer text-gray-700 text-center">
            <option value="">-- ກະລຸນາເລືອກປະເພດຂໍ້ມູນ --</option>
            <option value="department"> ຈັດການຂໍ້ມູນພະແນກ (Department)</option>
            <option value="category"> ຈັດການໝວດໝູ່ອຸປະກອນ (Category)</option>
        </select>
    </div>
</div>

<!-- ============================================================ -->
<!-- 🏢 SECTION 1: ຂໍ້ມູນພະແນກ (DEPARTMENTS) -->
<!-- ============================================================ -->
<div id="deptContainer" class="hidden space-y-8 w-full transition-all duration-300">
    <!-- ຟອມເພີ່ມພະແນກ -->
    <div class="bg-white rounded-2xl border-2 border-indigo-100 shadow-lg overflow-hidden w-full">
        <div class="bg-indigo-50 px-6 py-4 border-b border-indigo-100 flex items-center justify-between">
            <h3 class="font-bold text-indigo-900 flex items-center gap-2 text-base">
                <i class="fas fa-plus-circle text-indigo-600"></i> ຟອມເພີ່ມຂໍ້ມູນພະແນກໃໝ່
            </h3>
            <button type="button" onclick="closeForms()" class="text-gray-400 hover:text-rose-500 text-sm font-bold transition">
                <i class="fas fa-times"></i> ປິດ
            </button>
        </div>
        <form method="POST" class="p-6 space-y-4">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">ຊື່ພະແນກ <span class="text-rose-500">*</span></label>
                <input type="text" name="dept_name" required class="w-full border border-gray-300 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 rounded-xl p-3 text-sm transition" placeholder="ຕົວຢ່າງ: ພະແນກບໍລິຫານ-ສັງລວມ">
            </div>
            <div class="flex items-center justify-end gap-3 pt-2">
                <button type="button" onclick="closeForms()" class="px-5 py-2.5 rounded-xl text-sm font-semibold text-gray-600 bg-gray-100 hover:bg-gray-200 transition">
                    ຍົກເລີກ
                </button>
                <button type="submit" name="add_department" class="px-6 py-2.5 rounded-xl text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 shadow-md transition flex items-center gap-2">
                    <i class="fas fa-save"></i> ບັນທຶກພະແນກ
                </button>
            </div>
        </form>
    </div>

    <!-- ລາຍຊື່ພະແນກ -->
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden w-full">
        <div class="p-5 border-b border-gray-100 flex items-center justify-between bg-gray-50/50">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-indigo-100 text-indigo-600 flex items-center justify-center font-bold">
                    <i class="fas fa-building"></i>
                </div>
                <div>
                    <h3 class="font-bold text-gray-800">ລາຍຊື່ພະແນກທັງໝົດ</h3>
                    <p class="text-xs text-gray-500">ຂໍ້ມູນພະແນກ</p>
                </div>
            </div>
            <span class="text-xs font-bold bg-indigo-50 text-indigo-700 border border-indigo-100 px-3 py-1 rounded-full">
                <?php echo count($departments); ?> ລາຍການ
            </span>
        </div>
        <div class="p-4 space-y-2 max-h-[500px] overflow-y-auto">
            <?php 
            $i = 1;
            foreach ($departments as $dept): 
                $safe_dept_name = htmlspecialchars($dept['dept_name'], ENT_QUOTES, 'UTF-8');
                $js_safe_name   = addslashes($safe_dept_name);
            ?>
                <div class="flex items-center justify-between p-3.5 rounded-xl bg-gray-50 hover:bg-indigo-50/40 border border-gray-100 hover:border-indigo-100 transition group">
                    <div class="flex items-center gap-3">
                        <span class="text-xs font-bold w-8 h-8 rounded-lg bg-indigo-100 text-indigo-700 flex items-center justify-center">
                            <?php echo $i++; ?>
                        </span>
                        <div>
                            <p class="font-bold text-gray-800 text-sm group-hover:text-indigo-900"><?php echo $safe_dept_name; ?></p>
                        </div>
                    </div>
                    <form method="POST" id="del-dept-form-<?php echo $dept['id']; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <input type="hidden" name="department_id" value="<?php echo $dept['id']; ?>">
                        <input type="hidden" name="delete_department" value="1">
                        <button type="button" onclick="confirmDelete('del-dept-form-<?php echo $dept['id']; ?>', '<?php echo $js_safe_name; ?>')" class="w-8 h-8 rounded-lg text-gray-400 hover:text-rose-600 hover:bg-rose-50 transition flex items-center justify-center" title="ລຶບ">
                            <i class="fas fa-trash-alt text-xs"></i>
                        </button>
                    </form>
                </div>
            <?php endforeach; ?>
            <?php if (empty($departments)): ?>
                <div class="text-center py-10 text-gray-400">
                    <i class="fas fa-folder-open text-3xl mb-2"></i>
                    <p class="text-sm">ຍັງບໍ່ມີຂໍ້ມູນພະແນກ</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- 📦 SECTION 2: ຂໍ້ມູນໝວດໝູ່ (CATEGORY) -->
<!-- ============================================================ -->
<div id="catContainer" class="hidden space-y-8 w-full transition-all duration-300">
    <!-- ຟອມເພີ່ມໝວດໝູ່ -->
    <div class="bg-white rounded-2xl border-2 border-emerald-100 shadow-lg overflow-hidden w-full">
        <div class="bg-emerald-50 px-6 py-4 border-b border-emerald-100 flex items-center justify-between">
            <h3 class="font-bold text-emerald-900 flex items-center gap-2 text-base">
                <i class="fas fa-plus-circle text-emerald-600"></i> ຟອມເພີ່ມໝວດໝູ່ອຸປະກອນໃໝ່
            </h3>
            <button type="button" onclick="closeForms()" class="text-gray-400 hover:text-rose-500 text-sm font-bold transition">
                <i class="fas fa-times"></i> ປິດ
            </button>
        </div>
        <form method="POST" class="p-6 space-y-4">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">ຊື່ໝວດໝູ່ <span class="text-rose-500">*</span></label>
                <input type="text" name="cate_name" required class="w-full border border-gray-300 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200 rounded-xl p-3 text-sm transition" placeholder="ຕົວຢ່າງ: ອຸປະກອນໄອທີ, ສາຍໄຟ">
            </div>
            <div class="flex items-center justify-end gap-3 pt-2">
                <button type="button" onclick="closeForms()" class="px-5 py-2.5 rounded-xl text-sm font-semibold text-gray-600 bg-gray-100 hover:bg-gray-200 transition">
                    ຍົກເລີກ
                </button>
                <button type="submit" name="add_category" class="px-6 py-2.5 rounded-xl text-sm font-semibold text-white bg-emerald-600 hover:bg-emerald-700 shadow-md transition flex items-center gap-2">
                    <i class="fas fa-save"></i> ບັນທຶກໝວດໝູ່
                </button>
            </div>
        </form>
    </div>

    <!-- ລາຍຊື່ໝວດໝູ່ -->
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden w-full">
        <div class="p-5 border-b border-gray-100 flex items-center justify-between bg-gray-50/50">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-emerald-100 text-emerald-600 flex items-center justify-center font-bold">
                    <i class="fas fa-boxes"></i>
                </div>
                <div>
                    <h3 class="font-bold text-gray-800">ລາຍຊື່ໝວດໝູ່ອຸປະກອນທັງໝົດ</h3>
                    <p class="text-xs text-gray-500">ໝວດໝູ່ສິນຄ້າ ແລະ ອຸປະກອນ</p>
                </div>
            </div>
            <span class="text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-100 px-3 py-1 rounded-full">
                <?php echo count($categories); ?> ລາຍການ
            </span>
        </div>
        <div class="p-4 space-y-2 max-h-[500px] overflow-y-auto">
            <?php 
            $j = 1;
            foreach ($categories as $cat): 
                $safe_cate_name = htmlspecialchars($cat['cate_name'], ENT_QUOTES, 'UTF-8');
                $js_safe_cate   = addslashes($safe_cate_name);
            ?>
                <div class="flex items-center justify-between p-3.5 rounded-xl bg-gray-50 hover:bg-emerald-50/40 border border-gray-100 hover:border-emerald-100 transition group">
                    <div class="flex items-center gap-3">
                        <span class="text-xs font-bold w-8 h-8 rounded-lg bg-emerald-100 text-emerald-700 flex items-center justify-center">
                            <?php echo $j++; ?>
                        </span>
                        <div>
                            <p class="font-bold text-gray-800 text-sm group-hover:text-emerald-900"><?php echo $safe_cate_name; ?></p>
                        </div>
                    </div>
                    <form method="POST" id="del-cat-form-<?php echo $cat['cate_id']; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <input type="hidden" name="cate_id" value="<?php echo $cat['cate_id']; ?>">
                        <input type="hidden" name="delete_category" value="1">
                        <button type="button" onclick="confirmDelete('del-cat-form-<?php echo $cat['cate_id']; ?>', '<?php echo $js_safe_cate; ?>')" class="text-gray-400 hover:text-rose-600 hover:bg-rose-50 w-8 h-8 rounded-lg transition flex items-center justify-center" title="ລຶບ">
                            <i class="fas fa-trash-alt text-xs"></i>
                        </button>
                    </form>
                </div>
            <?php endforeach; ?>
            <?php if (empty($categories)): ?>
                <div class="text-center py-10 text-gray-400">
                    <i class="fas fa-folder-open text-3xl mb-2"></i>
                    <p class="text-sm">ຍັງບໍ່ມີຂໍ້ມູນໝວດໝູ່ອຸປະກອນ</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function toggleAddForm() {
    const selectValue = document.getElementById('entryTypeSelect').value;
    const deptContainer = document.getElementById('deptContainer');
    const catContainer = document.getElementById('catContainer');

    deptContainer.classList.add('hidden');
    catContainer.classList.add('hidden');

    if (selectValue === 'department') {
        deptContainer.classList.remove('hidden');
    } else if (selectValue === 'category') {
        catContainer.classList.remove('hidden');
    }
}

function closeForms() {
    document.getElementById('entryTypeSelect').value = '';
    toggleAddForm();
}

// Modal SweetAlert2
function confirmDelete(formId, itemName) {
    Swal.fire({
        title: 'ຢືນຢັນການລຶບຂໍ້ມູນ?',
        html: `ທ່ານຕ້ອງການລຶບ <b class="text-rose-600">"${itemName}"</b> ແທ້ ຫຼື ບໍ່?<br><span class="text-xs text-gray-400">ເມື່ອລຶບແລ້ວ ຂໍ້ມູນນີ້ຈະບໍ່ສາມາດກູ້ຄືນໄດ້!</span>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#e11d48',
        cancelButtonColor: '#6b7280',
        confirmButtonText: '<i class="fas fa-trash-alt mr-1"></i> ຢືນຢັນລຶບ',
        cancelButtonText: 'ຍົກເລີກ',
        customClass: {
            popup: 'rounded-2xl shadow-xl border border-gray-100',
            confirmButton: 'px-5 py-2.5 rounded-xl font-semibold text-sm',
            cancelButton: 'px-5 py-2.5 rounded-xl font-semibold text-sm'
        }
    }).then((result) => {
        if (result.isConfirmed) {
            document.getElementById(formId).submit();
        }
    });
}
</script>
