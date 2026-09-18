<?php
require_once __DIR__ . '/../includes/config.php';
checkRole(['admin']);
// ຈັດການຜູ້ໃຊ້

$current_user_role = $_SESSION['role'] ?? 'user';
$current_user_dept = $_SESSION['department'] ?? '';

// ============================================================
// ດຶງຂໍ້ມູນພະແນກທັງໝົດຈາກຖານຂໍ້ມູນ (Dynamic Departments)
// ============================================================
$all_departments = [];
if (isset($pdo)) {
    try {
        $stmtDept = $pdo->query("SELECT id, dept_name FROM departments ORDER BY dept_name ASC");
        $all_departments = $stmtDept->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Handle error if table departments doesn't exist
    }
}

// ============================================================
// ປະມວນຜົນຟອມ[cite: 5]
// ============================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (function_exists('verifyCSRFToken') && !verifyCSRFToken($csrf_token)) {
        if (function_exists('showAlert')) showAlert('ຂໍ້ມູນບໍ່ປອດໄພ', 'error');
        echo '<script>window.location.href = "?admin=users";</script>';
        exit();
    }
    
    // 1. ເພີ່ມຜູ້ໃຊ້[cite: 5]
    if (isset($_POST['add_user'])) {
        $username = trim($_POST['username'] ?? '');
        $rawPassword = (string)($_POST['password'] ?? '');
        $role = $_POST['role'] ?? 'user';
        if ($username === '' || strlen($username) < 3 || strlen($rawPassword) < 8 || !in_array($role, ['admin', 'user'], true)) {
            showAlert('ຂໍ້ມູນບັນຊີບໍ່ຖືກຕ້ອງ: ລະຫັດຜ່ານຕ້ອງມີຢ່າງໜ້ອຍ 8 ຕົວອັກສອນ', 'error');
            echo '<script>window.location.href = "?admin=users";</script>';
            exit();
        }
        $password = password_hash($rawPassword, PASSWORD_DEFAULT);
        $fullname = trim($_POST['fullname'] ?? '');
        // ຖ້າເປັນ admin ສາມາດເລືອກ department ໄດ້, ຖ້າເປັນ user ໃຫ້ໃຊ້ department ຂອງຕົນເອງ
        $department = ($current_user_role === 'admin') ? ($_POST['department'] ?? '') : $current_user_dept;
        
        try {
            $stmt = $pdo->prepare("INSERT INTO users (username, password, fullname, role, department) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$username, $password, $fullname, $role, $department]);
            if (function_exists('showAlert')) showAlert('ເພີ່ມຜູ້ໃຊ້ສຳເລັດ', 'success');
            if (function_exists('logActivity')) logActivity("ເພີ່ມຜູ້ໃຊ້: {$username} (ພະແນກ: {$department})");
        } catch (PDOException $e) {
            if (function_exists('showAlert')) showAlert('ເກີດຂໍ້ຜິດພາດ ກະລຸນາລອງໃໝ່', 'error');
        }
        echo '<script>window.location.href = "?admin=users";</script>';
        exit();
    }
    
    // 2. ແກ້ໄຂຜູ້ໃຊ້
    if (isset($_POST['edit_user'])) {
        $id       = (int)($_POST['user_id'] ?? 0);
        $username = trim($_POST['username'] ?? '');
        $fullname = trim($_POST['fullname'] ?? '');
        $role     = $_POST['role'] ?? 'user';
        if (!in_array($role, ['admin', 'user'], true) || $username === '' || strlen($username) < 3) {
            showAlert('ຂໍ້ມູນບັນຊີບໍ່ຖືກຕ້ອງ', 'error');
            echo '<script>window.location.href = "?admin=users";</script>';
            exit();
        }
        if ($id === (int)($_SESSION['user_id'] ?? 0) && $role !== 'admin') {
            showAlert('ບໍ່ສາມາດລົດສິດບັນຊີຕົນເອງໄດ້', 'error');
            echo '<script>window.location.href = "?admin=users";</script>';
            exit();
        }
        
        // ກວດສອບສິດການແກ້ໄຂ (User ທົ່ວໄປບໍ່ສາມາດແກ້ໄຂຂ້າມພະແນກໄດ້)
        $check_stmt = $pdo->prepare("SELECT department FROM users WHERE id = ?");
        $check_stmt->execute([$id]);
        $target_user = $check_stmt->fetch();

        if ($current_user_role !== 'admin' && ($target_user['department'] ?? '') !== $current_user_dept) {
            if (function_exists('showAlert')) showAlert('ທ່ານບໍ່ມີສິດແກ້ໄຂຜູ້ໃຊ້ນອກພະແນກຕົນເອງ', 'error');
            echo '<script>window.location.href = "?admin=users";</script>';
            exit();
        }

        $department = ($current_user_role === 'admin') ? ($_POST['department'] ?? '') : $current_user_dept;
        
        try {
            if (!empty($_POST['password'])) {
                if (strlen((string)$_POST['password']) < 8) {
                    showAlert('ລະຫັດຜ່ານໃໝ່ຕ້ອງມີຢ່າງໜ້ອຍ 8 ຕົວອັກສອນ', 'error');
                    echo '<script>window.location.href = "?admin=users";</script>';
                    exit();
                }
                $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET username = ?, password = ?, fullname = ?, role = ?, department = ? WHERE id = ?");
                $stmt->execute([$username, $password, $fullname, $role, $department, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET username = ?, fullname = ?, role = ?, department = ? WHERE id = ?");
                $stmt->execute([$username, $fullname, $role, $department, $id]);
            }
            if (function_exists('showAlert')) showAlert('ແກ້ໄຂຜູ້ໃຊ້ສຳເລັດ', 'success');
            if (function_exists('logActivity')) logActivity("ແກ້ໄຂຜູ້ໃຊ້: {$username}");
        } catch (PDOException $e) {
            if (function_exists('showAlert')) showAlert('ເກີດຂໍ້ຜິດພາດ ກະລຸນາລອງໃໝ່', 'error');
        }
        echo '<script>window.location.href = "?admin=users";</script>';
        exit();
    }
    
    // 3. ລຶບຜູ້ໃຊ້
    if (isset($_POST['delete_user'])) {
        $id = (int)($_POST['user_id'] ?? 0);
        if ($id == ($_SESSION['user_id'] ?? 0)) {
            if (function_exists('showAlert')) showAlert('ບໍ່ສາມາດລຶບຕົວເອງໄດ້', 'error');
        } else {
            try {
                // ກວດສອບສິດການລຶບ[cite: 5]
                $check_stmt = $pdo->prepare("SELECT department FROM users WHERE id = ?");
                $check_stmt->execute([$id]);
                $target_user = $check_stmt->fetch();

                if ($current_user_role !== 'admin' && ($target_user['department'] ?? '') !== $current_user_dept) {
                    if (function_exists('showAlert')) showAlert('ທ່ານບໍ່ມີສິດລຶບຜູ້ໃຊ້ນອກພະແນກຕົນເອງ', 'error');
                } else {
                    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                    $stmt->execute([$id]);
                    if (function_exists('showAlert')) showAlert('ລຶບຜູ້ໃຊ້ສຳເລັດ', 'success');
                    if (function_exists('logActivity')) logActivity("ລຶບຜູ້ໃຊ້ ID: {$id}");
                }
            } catch (PDOException $e) {
                if (function_exists('showAlert')) showAlert('ເກີດຂໍ້ຜິດພາດ ກະລຸນາລອງໃໝ່', 'error');
            }
        }
        echo '<script>window.location.href = "?admin=users";</script>';
        exit();
    }
}

// ============================================================
// ດຶງຂໍ້ມູນຜູ້ໃຊ້ ຕາມສິດພະແນກ (Department Filtering)
// ============================================================
if ($current_user_role === 'admin') {
    // Admin ເຫັນທັງໝົດ
    $stmt = $pdo->prepare("SELECT * FROM users ORDER BY id DESC");
    $stmt->execute();
} else {
    // User ທົ່ວໄປ ເຫັນສະເພາະຄົນໃນພະແນກດຽວກັນ
    $stmt = $pdo->prepare("SELECT * FROM users WHERE department = ? ORDER BY id DESC");
    $stmt->execute([$current_user_dept]);
}
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="mb-6 flex flex-wrap justify-between items-center gap-4">
    <div>
        <h1 class="text-2xl font-bold text-gray-800">ຈັດການຜູ້ໃຊ້</h1>
        <p class="text-gray-600">
            <?php if ($current_user_role === 'admin'): ?>
                ເພີ່ມ, ແກ້ໄຂ ແລະ ລຶບຜູ້ໃຊ້ລະບົບທັງໝົດ
            <?php else: ?>
                ຈັດການຜູ້ໃຊ້ສະເພາະ <strong><?php echo htmlspecialchars($current_user_dept ?: 'ພະແນກຂອງທ່ານ'); ?></strong>[cite: 5]
            <?php endif; ?>
        </p>
    </div>
    <button onclick="openAddUserModal()" class="btn-primary flex items-center gap-2">
        <i class="fas fa-user-plus"></i> ເພີ່ມຜູ້ໃຊ້
    </button>
</div>

<?php if (function_exists('getAlert')): ?>
    <?php $alert = getAlert(); if ($alert): ?>
        <div class="p-4 mb-4 rounded-lg <?php echo $alert['type'] == 'success' ? 'bg-green-100 text-green-700 border border-green-200' : 'bg-red-100 text-red-700 border border-red-200'; ?>">
            <i class="fas <?php echo $alert['type'] == 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?> mr-2"></i>
            <?php echo htmlspecialchars($alert['message']); ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<div class="card bg-white rounded-2xl shadow-sm border border-slate-100 p-4">
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead>
                <tr class="border-b">
                    <th class="text-left py-3 px-4 text-sm font-medium text-gray-500">ID</th>
                    <th class="text-left py-3 px-4 text-sm font-medium text-gray-500">ຊື່ຜູ້ໃຊ້</th>
                    <th class="text-left py-3 px-4 text-sm font-medium text-gray-500">ຊື່ ແລະ ນາມສະກຸນ</th>
                    <th class="text-left py-3 px-4 text-sm font-medium text-gray-500">ພາກສ່ວນ / ພະແນກ</th>
                    <th class="text-left py-3 px-4 text-sm font-medium text-gray-500">ສິດ</th>
                    <th class="text-left py-3 px-4 text-sm font-medium text-gray-500">ວັນທີສ້າງ</th>
                    <th class="text-center py-3 px-4 text-sm font-medium text-gray-500">ຈັດການ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($users) > 0): ?>
                    <?php foreach ($users as $user): ?>
                        <tr class="border-b hover:bg-gray-50">
                            <td class="py-3 px-4 text-sm text-gray-800">#<?php echo $user['id']; ?></td>
                            <td class="py-3 px-4 text-sm font-medium text-gray-800"><?php echo htmlspecialchars($user['username']); ?></td>
                            <td class="py-3 px-4 text-sm text-gray-600"><?php echo htmlspecialchars($user['fullname']); ?></td>
                            <td class="py-3 px-4 text-sm">
                                <span class="bg-gray-100 text-gray-700 px-2.5 py-1 rounded-md text-xs font-semibold">
                                    <?php echo htmlspecialchars($user['department'] ?? 'ບໍ່ທັນກຳນົດ'); ?>
                                </span>
                            </td>
                            <td class="py-3 px-4">
                                <span class="px-2 py-1 text-xs rounded-full font-medium <?php echo $user['role'] == 'admin' ? 'bg-purple-100 text-purple-800' : 'bg-blue-100 text-blue-800'; ?>">
                                    <?php echo $user['role'] == 'admin' ? 'ຜູ້ຄຸ້ມຄອງລະບົບ' : 'ຜູ້ໃຊ້'; ?>
                                </span>
                            </td>
                            <td class="py-3 px-4 text-sm text-gray-500">
                                <?php echo function_exists('formatDate') ? formatDate($user['created_at']) : $user['created_at']; ?>
                            </td>
                            <td class="py-3 px-4 text-sm text-center">
                                <button onclick="editUser(<?php echo htmlspecialchars(json_encode($user)); ?>)" class="text-blue-600 hover:text-blue-800 mr-2 p-1">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <?php if ($user['id'] != ($_SESSION['user_id'] ?? 0)): ?>
                                    <button onclick="deleteUser(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars(addslashes($user['username'])); ?>')" class="text-red-600 hover:text-red-800 p-1">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" class="py-8 text-center text-gray-500">
                            <i class="fas fa-users text-3xl mb-2 block text-gray-300"></i>
                            <p>ຍັງບໍ່ມີຂໍ້ມູນຜູ້ໃຊ້ໃນພະແນກນີ້</p>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal ເພີ່ມ/ແກ້ໄຂຜູ້ໃຊ້ -->
<div id="userModal" class="fixed inset-0 bg-black/50 flex items-center justify-center hidden z-50 p-4">
    <div class="bg-white rounded-2xl max-w-md w-full shadow-xl">
        <div class="p-6">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-bold text-gray-800" id="userModalTitle">ເພີ່ມຜູ້ໃຊ້</h3>
                <button type="button" onclick="closeUserModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <form method="POST" id="userForm">
                <input type="hidden" name="csrf_token" value="<?php echo function_exists('generateCSRFToken') ? generateCSRFToken() : ''; ?>">
                <input type="hidden" name="user_id" id="edit_user_id">
                
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">ຊື່ຜູ້ໃຊ້ <span class="text-red-500">*</span></label>
                        <input type="text" name="username" id="username" required class="form-input border-slate-200 rounded-lg w-full p-2 border">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1"> ລະຫັດຜ່ານ </span></label>
                        <input type="password" name="password" id="password"class="form-input border-slate-200 rounded-lg w-full p-2 border" placeholder="ປ່ອຍວ່າງຖ້າບໍ່ຕ້ອງການປ່ຽນ">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">ຊື່ ແລະ ນາມສະກຸນ <span class="text-red-500">*</span></label>
                        <input type="text" name="fullname" id="fullname" required class="form-input border-slate-200 rounded-lg w-full p-2 border">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">ພາກສ່ວນ / ພະແນກ <span class="text-red-500">*</span></label>
                        <?php if ($current_user_role === 'admin'): ?>
                            <select name="department" id="department" required class="form-input border-slate-200 rounded-lg w-full p-2 border">
                                <option value="">-- ເລືອກພະແນກ --</option>
                                <?php if (!empty($all_departments)): ?>
                                    <?php foreach ($all_departments as $d): ?>
                                        <option value="<?php echo htmlspecialchars($d['dept_name']); ?>">
                                            <?php echo htmlspecialchars($d['dept_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        <?php else: ?>
                            <input type="text" value="<?php echo htmlspecialchars($current_user_dept); ?>" readonly class="form-input bg-gray-100 text-gray-600 cursor-not-allowed border-slate-200 rounded-lg w-full p-2 border">
                            <input type="hidden" name="department" value="<?php echo htmlspecialchars($current_user_dept); ?>">
                        <?php endif; ?>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">ສິດ <span class="text-red-500">*</span></label>
                        <select name="role" id="role" required class="form-input border-slate-200 rounded-lg w-full p-2 border">
                            <option value="user">ຜູ້ໃຊ້</option>
                            <?php if ($current_user_role === 'admin'): ?>
                                <option value="admin">ຜູ້ຄຸ້ມຄອງລະບົບ</option>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>
                
                <div class="flex justify-end gap-3 mt-6 pt-4 border-t">
                    <button type="button" onclick="closeUserModal()" class="px-4 py-2 text-gray-600 border rounded-lg hover:bg-gray-50">
                        ຍົກເລີກ
                    </button>
                    <button type="submit" name="add_user" id="userSubmitBtn" class="btn-primary bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg font-medium">
                        <i class="fas fa-save mr-2"></i>ບັນທຶກ
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Form ລຶບຜູ້ໃຊ້ -->
<form method="POST" id="deleteUserForm" class="hidden">
    <input type="hidden" name="csrf_token" value="<?php echo function_exists('generateCSRFToken') ? generateCSRFToken() : ''; ?>">
    <input type="hidden" name="user_id" id="delete_user_id">
    <input type="hidden" name="delete_user" value="1">
</form>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
function openAddUserModal() {
    document.getElementById('userModalTitle').textContent = 'ເພີ່ມຜູ້ໃຊ້';
    document.getElementById('userForm').reset();
    document.getElementById('edit_user_id').value = '';
    document.getElementById('password').placeholder = 'ປ້ອນລະຫັດຜ່ານ';
    document.getElementById('userSubmitBtn').name = 'add_user';
    
    const deptSelect = document.getElementById('department');
    if (deptSelect && deptSelect.tagName === 'SELECT') {
        deptSelect.value = '';
    }
    
    document.getElementById('userModal').classList.remove('hidden');
}

function editUser(user) {
    document.getElementById('userModalTitle').textContent = 'ແກ້ໄຂຜູ້ໃຊ້';
    document.getElementById('edit_user_id').value = user.id;
    document.getElementById('username').value = user.username;
    document.getElementById('fullname').value = user.fullname;
    document.getElementById('role').value = user.role;
    
    const deptSelect = document.getElementById('department');
    if (deptSelect && deptSelect.tagName === 'SELECT') {
        deptSelect.value = user.department || '';
    }
    
    document.getElementById('password').value = '';
    document.getElementById('password').placeholder = 'ປ່ອຍວ່າງຖ້າບໍ່ຕ້ອງການປ່ຽນ';
    document.getElementById('userSubmitBtn').name = 'edit_user';
    document.getElementById('userModal').classList.remove('hidden');
}

function closeUserModal() {
    document.getElementById('userModal').classList.add('hidden');
}

function deleteUser(id, username) {
    Swal.fire({
        title: 'ຢືນຢັນການລຶບ',
        html: `ທ່ານກຳລັງຈະລຶບຜູ້ໃຊ້ <strong>"${username}"</strong>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#EF4444',
        cancelColor: '#6B7280',
        confirmButtonText: 'ລຶບ',
        cancelButtonText: 'ຍົກເລີກ'
    }).then((result) => {
        if (result.isConfirmed) {
            document.getElementById('delete_user_id').value = id;
            document.getElementById('deleteUserForm').submit();
        }
    });
}
</script>
