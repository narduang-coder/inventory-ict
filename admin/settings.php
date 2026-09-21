<?php
require_once __DIR__ . '/../includes/config.php';
checkRole(['admin']);
// ຕັ້ງຄ່າລະບົບ

// ============================================================
// ປະມວນຜົນຟອມ
// ============================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($csrf_token)) {
        showAlert('ຂໍ້ມູນບໍ່ປອດໄພ', 'error');
         echo '<script>window.location.href = "?admin=settings";</script>';
        exit();
    }
    
    if (isset($_POST['update_settings'])) {
        $school_name = $_POST['school_name'];
        $logo_path = normalizeLogoAssetPath($_POST['current_logo'] ?? '');

        // ອັບໂຫຼດໂລໂກ້
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK && is_uploaded_file($_FILES['logo']['tmp_name'])) {
            $upload_dir = UPLOAD_PATH . 'logos' . DIRECTORY_SEPARATOR;
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0750, true);
            }
            $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $_FILES['logo']['tmp_name']) ?: '';
            finfo_close($finfo);
            $allowedMime = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            if (in_array($ext, $allowed, true) && isset($allowedMime[$ext]) && $mime === $allowedMime[$ext] && $_FILES['logo']['size'] <= 2 * 1024 * 1024) {
                // ລຶບໂລໂກ້ເກົ່າ
                if (!empty($logo_path)) {
                    $oldLogoPath = assetPath($logo_path);
                    if ($oldLogoPath !== '' && file_exists($oldLogoPath)) {
                        unlink($oldLogoPath);
                    }
                }
                $filename = 'logo_' . bin2hex(random_bytes(12)) . '.' . $ext;
                $target = $upload_dir . $filename;
                if (move_uploaded_file($_FILES['logo']['tmp_name'], $target)) {
                    $logo_path = normalizeLogoAssetPath('assets/uploads/logos/' . $filename);
                }
            }
        }

        try {
            $stmt = $pdo->prepare("UPDATE settings SET school_name = ?, logo_path = ?");
            $stmt->execute([$school_name, $logo_path]);
            showAlert('ບັນທຶກການຕັ້ງຄ່າສຳເລັດ', 'success');
            logActivity("ອັບເດດການຕັ້ງຄ່າລະບົບ");
        } catch (PDOException $e) {
            error_log($e->getMessage());
            showAlert('ເກີດຂໍ້ຜິດພາດ ກະລຸນາລອງໃໝ່', 'error');
        }
         echo '<script>window.location.href = "?admin=settings";</script>';
        exit();
    }
    }


// ຈັດການການສຳຮອງຂໍ້ມູນ
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'backup') {
    requirePostCsrf();
    header('Content-Type: application/json');
    try {
        $backup_file = backupDatabase($pdo);
        echo json_encode([
            'success' => true,
            'filename' => basename($backup_file),
            'message' => 'ສຳຮອງຂໍ້ມູນສຳເລັດ'
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Backup failed'
        ]);
    }
    exit();
}

// ============================================================
// ດຶງຂໍ້ມູນ
// ============================================================
$stmt = $pdo->query("SELECT * FROM settings LIMIT 1");
$settings = $stmt->fetch();
if (!$settings) {
    $pdo->query("INSERT INTO settings (school_name) VALUES ('ຝ່າຍ ICT')");
    $settings = $pdo->query("SELECT * FROM settings LIMIT 1")->fetch();
}

// ============================================================
// ຟັງຊັນສຳຮອງຂໍ້ມູນ
// ============================================================
function backupDatabase($pdo) {
    $backup_dir = BACKUP_PATH;
    if (!is_dir($backup_dir)) {
        mkdir($backup_dir, 0750, true);
    }
    
    $filename = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
    $filepath = $backup_dir . $filename;
    
    // ດຶງຂໍ້ມູນຕາຕະລາງທັງໝົດ
    $tables = [];
    $stmt = $pdo->query("SHOW TABLES");
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $tables[] = $row[0];
    }
    
    $output = "-- ສຳຮອງຂໍ້ມູນ " . date('Y-m-d H:i:s') . "\n\n";
    $output .= "SET FOREIGN_KEY_CHECKS=0;\n\n";
    
    foreach ($tables as $table) {
        // ໂຄງສ້າງຕາຕະລາງ
        $stmt = $pdo->query("SHOW CREATE TABLE `$table`");
        $row = $stmt->fetch();
        $output .= "DROP TABLE IF EXISTS `$table`;\n";
        $output .= $row[1] . ";\n\n";
        
        // ຂໍ້ມູນ
        $stmt = $pdo->query("SELECT * FROM `$table`");
        $rows = $stmt->fetchAll();
        if (count($rows) > 0) {
            $columns = array_keys($rows[0]);
            $output .= "INSERT INTO `$table` (`" . implode("`, `", $columns) . "`) VALUES \n";
            $values = [];
            foreach ($rows as $row) {
                $row_values = [];
                foreach ($row as $value) {
                    if ($value === null) {
                        $row_values[] = 'NULL';
                    } else {
                        $row_values[] = "'" . addslashes($value) . "'";
                    }
                }
                $values[] = "(" . implode(", ", $row_values) . ")";
            }
            $output .= implode(",\n", $values) . ";\n\n";
        }
    }
    
    $output .= "SET FOREIGN_KEY_CHECKS=1;\n";
    
    file_put_contents($filepath, $output);
    
    // ລຶບໄຟລ໌ສຳຮອງເກົ່າ (ເກັບໄວ້ 7 ວັນ)
    $files = glob($backup_dir . 'backup_*.sql');
    foreach ($files as $file) {
        if (filemtime($file) < strtotime('-7 days')) {
            unlink($file);
        }
    }
    
    return $filepath;
}
?>

<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-800">ຕັ້ງຄ່າລະບົບ</h1>
    <p class="text-gray-600">ຈັດການຂໍ້ມູນຝ່າຍ ແລະ ໂລໂກ້</p>
</div>

<?php
$alert = getAlert();
if ($alert): ?>
    <div class="p-4 mb-4 rounded-lg <?php echo $alert['type'] == 'success' ? 'bg-green-100 text-green-700 border border-green-200' : 'bg-red-100 text-red-700 border border-red-200'; ?>">
        <i class="fas <?php echo $alert['type'] == 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?> mr-2"></i>
        <?php echo $alert['message']; ?>
    </div>
<?php endif; ?>

<div class="card max-w-2xl mb-8">
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
        <input type="hidden" name="current_logo" value="<?php echo $settings['logo_path'] ?? ''; ?>">
        
        <div class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">ຊື່ຝ່າຍ</label>
                <input type="text" name="school_name" value="<?php echo htmlspecialchars($settings['school_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required class="form-input">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">ໂລໂກ້</label>
                <div class="flex items-center gap-4">
                    <?php if (!empty($settings['logo_path']) && file_exists(assetPath($settings['logo_path']))): ?>
                        <img id="logoPreview" src="<?php echo htmlspecialchars(assetUrl($settings['logo_path'])); ?>" class="h-16 w-16 object-cover rounded-lg border">
                    <?php else: ?>
                        <div id="logoPlaceholder" class="h-16 w-16 bg-gray-200 rounded-lg flex items-center justify-center">
                            <i class="fas fa-image text-gray-400 text-2xl"></i>
                        </div>
                    <?php endif; ?>
                    <input id="logoInput" type="file" name="logo" accept="image/*" class="form-input">
                </div>
                <p class="text-xs text-gray-500 mt-1">ຮອງຮັບ: JPG, PNG, GIF (ຂະໜາດສູງສຸດ 2MB)</p>
            </div>
            
            <div class="pt-4 border-t">
                <button type="submit" name="update_settings" class="btn-primary w-full">
                    <i class="fas fa-save mr-2"></i>ບັນທຶກການຕັ້ງຄ່າ
                </button>
            </div>
        </div>
    </form>
</div> 
<script>
    document.getElementById('logoInput').addEventListener('change', function (event) {
        const file = event.target.files[0];
        if (!file || !file.type.startsWith('image/')) {
            return;
        }

        const reader = new FileReader();
        reader.onload = function (loadEvent) {
            let preview = document.getElementById('logoPreview');
            const placeholder = document.getElementById('logoPlaceholder');

            if (!preview) {
                preview = document.createElement('img');
                preview.id = 'logoPreview';
                preview.className = 'h-16 w-16 object-cover rounded-lg border';
                placeholder.parentNode.replaceChild(preview, placeholder);
            }

            preview.src = loadEvent.target.result;
        };
        reader.readAsDataURL(file);
    });
</script>
