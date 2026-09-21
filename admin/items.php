<?php
require_once __DIR__ . '/../includes/config.php';
checkRole(['admin']);

// ============================================================
// 1. HELPER FUNCTIONS FALLBACK & SESSION CHECK
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();

if (!function_exists('verifyCSRFToken')) {
    function verifyCSRFToken($token) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
}

if (!function_exists('generateCSRFToken')) {
    function generateCSRFToken() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('showAlert')) {
    function showAlert($message, $type = 'info') {
        $_SESSION['flash_alert'] = ['message' => $message, 'type' => $type];
    }
}

if (!function_exists('getAlert')) {
    function getAlert() {
        if (isset($_SESSION['flash_alert'])) {
            $alert = $_SESSION['flash_alert'];
            unset($_SESSION['flash_alert']);
            return $alert;
        }
        return null;
    }
}

if (!function_exists('logActivity')) {
    function logActivity($action) {}
}

if (!function_exists('rollbackItemTransaction')) {
    function rollbackItemTransaction($pdo) {
        try {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (Throwable $rollbackError) {
            error_log('Admin Items rollback failed: ' . $rollbackError->getMessage());
        }
    }
}

// ຟັງຊັນສ້າງ Barcode Auto Running
function generateBarcode($pdo, $import_date = null) {
    $year = !empty($import_date) ? date('Y', strtotime($import_date)) : date('Y');
    
    $stmt = $pdo->prepare("SELECT barcode FROM items WHERE barcode LIKE ? AND LENGTH(barcode) = 8 ORDER BY barcode DESC LIMIT 1");
    $stmt->execute([$year . '%']);
    $lastBarcode = $stmt->fetchColumn();

    if ($lastBarcode) {
        $lastNum = (int)substr($lastBarcode, 4);
        $nextNum = $lastNum + 1;
    } else {
        $nextNum = 1;
    }

    return $year . sprintf("%04d", $nextNum);
}

// ຈຳກັດຂະໜາດໄຟລ໌ສຳລັບລະບົບ (100 MB)
// ໝາຍເຫດ: PHP upload_max_filesize/post_max_size ແລະ MySQL max_allowed_packet
// ຕ້ອງຕັ້ງໃຫ້ສູງກວ່າຄ່ານີ້ຢູ່ server/container ດ້ວຍ.
const MAX_ITEM_UPLOAD_BYTES = 100 * 1024 * 1024;

// ຟັງຊັນອ່ານ Binary Data ຂອງຟາຍ ເພື່ອເກັບລົງ Database (BLOB)
function getFileDataForDB($file, $allowedTypes) {
    if (!isset($file['error'], $file['tmp_name'], $file['name'], $file['size']) ||
        $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        return null;
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $mimeByExt = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif', 'webp' => 'image/webp',
        'pdf' => 'application/pdf', 
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'txt' => 'text/plain'
    ];

    if (!in_array($ext, $allowedTypes, true) || !isset($mimeByExt[$ext]) || (int)$file['size'] > MAX_ITEM_UPLOAD_BYTES) {
        return null;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']) ?: '';
    finfo_close($finfo);

    if ($mime !== $mimeByExt[$ext]) {
        return null;
    }

    return [
        'data' => file_get_contents($file['tmp_name']),
        'mime' => $mime,
        'name' => $file['name'],
        'size' => (int)$file['size']
    ];
}

function getOptionalUploadData(array $files, string $field, array $allowedTypes, string $label): ?array {
    if (!isset($files[$field]) || (int)($files[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $file = $files[$field];
    if ((int)($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $errorNames = [
            UPLOAD_ERR_INI_SIZE => 'ໄຟລ໌ໃຫຍ່ເກີນ upload_max_filesize ຂອງ PHP (ກະລຸນາຕັ້ງໃຫ້ > 100MB)',
            UPLOAD_ERR_FORM_SIZE => 'ໄຟລ໌ໃຫຍ່ເກີນຂະໜາດທີ່ຟອມກຳນົດ (ສູງສຸດ 100MB)',
            UPLOAD_ERR_PARTIAL => 'ອັບໂຫຼດໄຟລ໌ບໍ່ຄົບ',
            UPLOAD_ERR_NO_TMP_DIR => 'ບໍ່ພົບ temporary directory ຂອງ PHP',
            UPLOAD_ERR_CANT_WRITE => 'PHP ບໍ່ສາມາດຂຽນໄຟລ໌ຊົ່ວຄາວໄດ້',
        ];
        throw new RuntimeException($label . ': ' . ($errorNames[(int)$file['error']] ?? 'ການອັບໂຫຼດລົ້ມເຫຼວ'));
    }

    $result = getFileDataForDB($file, $allowedTypes);
    if ($result === null) {
        throw new RuntimeException($label . ': ປະເພດໄຟລ໌ບໍ່ຖືກຕ້ອງ ຫຼື ໄຟລ໌ໃຫຍ່ເກີນ 100MB');
    }
    return $result;
}

// ຈັດການ endpoint ສຳລັບ stream/download ຟາຍ Binary ຈາກ Database
if (isset($_GET['get_blob'])) {
    $id = (int)$_GET['get_blob'];
    $type = $_GET['type'] ?? 'image'; // 'image' ຫຼື 'doc'

    $stmt = $pdo->prepare("SELECT image_data, image_mime, doc_data, doc_mime, doc_name FROM items WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $itemBlob = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($itemBlob && in_array($type, ['image', 'doc'], true)) {
        streamItemBlob($itemBlob, $type, isset($_GET['download']) && $_GET['download'] === '1');
    }
    http_response_code(404);
    exit('File Not Found');
}

// ກຳນົດ Role, User ID ແລະ Username
$current_user_role = $_SESSION['role'] ?? ($_SESSION['is_admin'] ?? false ? 'admin' : 'user');
$is_admin          = ($current_user_role === 'admin' || !empty($_SESSION['is_admin']));
$user_id           = $_SESSION['user_id'] ?? 1;
$current_username  = $_SESSION['username'] ?? ($_SESSION['user_name'] ?? ($_SESSION['name'] ?? 'System User'));

// ຫາ Base URL ສຳລັບ QR Code
$baseUrl = appUrl('');

// ============================================================
// 2. FORM PROCESSING (ປະມວນຜົນຟອມ)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($csrf_token)) {
        showAlert('ຂໍ້ມູນບໍ່ປອດໄພ (CSRF Token invalid)', 'error');
        echo '<script>window.location.href = "?admin=items";</script>';
        exit();
    }
    
    // ----------------------------------------------------
    // 1. ເພີ່ມອຸປະກອນໃໝ່ (ADD ITEM)
    // ----------------------------------------------------
    if (isset($_POST['add_item'])) {
        $item_code          = trim($_POST['item_code'] ?? '');
        $sn_raw             = trim($_POST['serial_number'] ?? '');
        $serial_number      = !empty($sn_raw) ? $sn_raw : null;
        $name               = trim($_POST['name'] ?? ($_POST['item_name'] ?? ''));
        $brand              = trim($_POST['brand'] ?? '');
        $model              = trim($_POST['model'] ?? '');
        $unit               = trim($_POST['unit'] ?? '');
        $quantity           = (int)($_POST['quantity'] ?? 0);
        $service_tax        = floatval($_POST['service_tax'] ?? 0);
        $manufacturing_year = !empty($_POST['manufacturing_year']) ? (int)$_POST['manufacturing_year'] : null;
        $min_quantity       = isset($_POST['min_quantity']) && $_POST['min_quantity'] !== '' ? (int)$_POST['min_quantity'] : 1;
        $category           = isset($_POST['category']) ? (int)$_POST['category'] : 1;
        $is_active          = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
        $import_date        = !empty($_POST['import_date']) ? $_POST['import_date'] : date('Y-m-d');
        $origin             = trim($_POST['origin'] ?? '');
        $warranty_years     = (int)($_POST['warranty_years'] ?? 0);
        $remark             = trim($_POST['remark'] ?? '');
        
        if ($service_tax < 0) {
            showAlert('Service Tax ບໍ່ສາມາດເປັນຄ່າລົບໄດ້!', 'error');
            echo '<script>window.location.href = "?admin=items";</script>';
            exit();
        }
        
        if ($manufacturing_year !== null) {
            $current_year = (int)date('Y');
            if ($manufacturing_year < 1900 || $manufacturing_year > $current_year + 1) {
                showAlert('Manufacturing Year ບໍ່ຖືກຕ້ອງ!', 'error');
                echo '<script>window.location.href = "?admin=items";</script>';
                exit();
            }
        }
        
        try {
            if (isset($pdo)) {
                $pdo->beginTransaction();

                if (empty($item_code)) {
                    $stmtMax = $pdo->query("SELECT MAX(id) FROM items");
                    $nextId = ((int)$stmtMax->fetchColumn()) + 1;
                    $item_code = "ITM-" . sprintf("%04d", $nextId);
                }

                if (!empty($serial_number)) {
                    $checkSN = $pdo->prepare("SELECT COUNT(*) FROM items WHERE serial_number = ? AND is_active = 1");
                    $checkSN->execute([$serial_number]);
                    if ($checkSN->fetchColumn() > 0) {
                        rollbackItemTransaction($pdo);
                        showAlert("Serial Number '{$serial_number}' ມີໃນລະບົບແລ້ວ!", 'error');
                        echo '<script>window.location.href = "?admin=items";</script>';
                        exit();
                    }
                }

                // ເກັບຟາຍຮູບລົງ Database (Binary Data)
                $imageData = null; $imageMime = null;
                $imgFile = getOptionalUploadData($_FILES, 'image', ['jpg', 'jpeg', 'png', 'gif', 'webp'], 'ຮູບພາບ');
                if ($imgFile) {
                    $imageData = $imgFile['data'];
                    $imageMime = $imgFile['mime'];
                }

                // ເກັບຟາຍເອກະສານລົງ Database (Binary Data)
                $docData = null; $docMime = null; $docName = null;
                $docFile = getOptionalUploadData($_FILES, 'document', ['pdf', 'docx', 'xlsx', 'pptx', 'txt'], 'ເອກະສານ');
                if ($docFile) {
                    $docData = $docFile['data'];
                    $docMime = $docFile['mime'];
                    $docName = $docFile['name'];
                }
                
                $barcode = generateBarcode($pdo, $import_date);

                $stmt = $pdo->prepare("
                    INSERT INTO items (item_code, serial_number, name, brand, model, cate_id, unit, quantity, min_quantity, barcode, image_data, image_mime, doc_data, doc_mime, doc_name, is_active, import_date, origin, warranty_years, service_tax, manufacturing_year, remark) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $item_code, $serial_number, $name, $brand, $model, $category, $unit, $quantity, $min_quantity, $barcode, 
                    $imageData, $imageMime, $docData, $docMime, $docName, 
                    $is_active, $import_date, $origin, $warranty_years, $service_tax, $manufacturing_year, $remark
                ]);
                
                $newItemId = $pdo->lastInsertId();

                $stmtMovement = $pdo->prepare("
                    INSERT INTO stock_movements 
                    (item_id, movement_type, quantity, old_quantity, new_quantity, note, created_by, created_at)
                    VALUES (?, 'receive', ?, 0, ?, 'ເພີ່ມອຸປະກອນໃໝ່ເຂົ້າຄັງ', ?, NOW())
                ");
                $stmtMovement->execute([$newItemId, $quantity, $quantity, $user_id]);

                $pdo->commit();

                showAlert('ເພີ່ມອຸປະກອນສຳເລັດ (Barcode: ' . $barcode . ')', 'success');
                logActivity("ເພີ່ມອຸປະກອນ: {$name}");
            }
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                rollbackItemTransaction($pdo);
            }
            error_log('Admin Items Error [' . get_class($e) . ']: ' . $e->getMessage());
            $userMessage = ($e instanceof RuntimeException)
                ? $e->getMessage()
                : 'ເກີດຂໍ້ຜິດພາດ ກະລຸນາລອງໃໝ່';
            showAlert($userMessage, 'error');
        }
        echo '<script>window.location.href = "?admin=items";</script>';
        exit();
    }

    // ----------------------------------------------------
    // 2. ຮັບເຂົ້າອຸປະກອນເກົ່າທີ່ມີໃນລະບົບ (ADD EXISTING ITEM)
    // ----------------------------------------------------
    if (isset($_POST['add_existing_item'])) {
        $existing_item_code = trim($_POST['existing_item_code'] ?? '');
        $sn_raw              = trim($_POST['serial_number'] ?? '');
        $serial_number      = !empty($sn_raw) ? $sn_raw : null;
        $add_quantity       = (int)($_POST['quantity'] ?? 1);
        $import_date        = !empty($_POST['import_date']) ? $_POST['import_date'] : date('Y-m-d');
        $remark             = trim($_POST['remark'] ?? '');

        try {
            if (isset($pdo)) {
                $pdo->beginTransaction();

                $stmtGet = $pdo->prepare("SELECT * FROM items WHERE item_code = ? AND is_active = 1 LIMIT 1");
                $stmtGet->execute([$existing_item_code]);
                $baseItem = $stmtGet->fetch(PDO::FETCH_ASSOC);

                if (!$baseItem) {
                    rollbackItemTransaction($pdo);
                    showAlert("ບໍ່ພົບລະຫັດອຸປະກອນ '{$existing_item_code}' ໃນລະບົບ!", 'error');
                    echo '<script>window.location.href = "?admin=items";</script>';
                    exit();
                }

                if (!empty($serial_number)) {
                    $checkSN = $pdo->prepare("SELECT COUNT(*) FROM items WHERE serial_number = ? AND is_active = 1");
                    $checkSN->execute([$serial_number]);
                    if ($checkSN->fetchColumn() > 0) {
                        rollbackItemTransaction($pdo);
                        showAlert("Serial Number '{$serial_number}' ມີໃນລະບົບແລ້ວ!", 'error');
                        echo '<script>window.location.href = "?admin=items";</script>';
                        exit();
                    }
                }

                $old_quantity = (int)$baseItem['quantity'];
                $new_quantity = $old_quantity + $add_quantity;

                if (!empty($serial_number)) {
                    $barcode = generateBarcode($pdo, $import_date);
                    $stmt = $pdo->prepare("
                        INSERT INTO items (item_code, serial_number, name, brand, model, cate_id, unit, quantity, min_quantity, barcode, image_data, image_mime, doc_data, doc_mime, doc_name, is_active, import_date, origin, warranty_years, service_tax, manufacturing_year, remark)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $baseItem['item_code'], $serial_number, $baseItem['name'], $baseItem['brand'],
                        $baseItem['model'], $baseItem['cate_id'], $baseItem['unit'], $add_quantity,
                        $baseItem['min_quantity'], $barcode, $baseItem['image_data'], $baseItem['image_mime'],
                        $baseItem['doc_data'], $baseItem['doc_mime'], $baseItem['doc_name'],
                        1, $import_date, $baseItem['origin'], $baseItem['warranty_years'],
                        $baseItem['service_tax'] ?? 0, $baseItem['manufacturing_year'] ?? null, $remark
                    ]);
                    $movementItemId = (int)$pdo->lastInsertId();
                    $movementNewQty = $add_quantity;
                    $successText = "ຮັບອຸປະກອນເຂົ້າສຳເລັດ ({$baseItem['name']})";
                } else {
                    $stmt = $pdo->prepare("UPDATE items SET quantity = quantity + ?, updated_at = NOW() WHERE id = ? AND is_active = 1");
                    $stmt->execute([$add_quantity, $baseItem['id']]);
                    if ($stmt->rowCount() !== 1) {
                        throw new RuntimeException('Failed to update existing item stock');
                    }
                    $movementItemId = (int)$baseItem['id'];
                    $movementNewQty = $new_quantity;
                    $barcode = $baseItem['barcode'] ?? '';
                    $successText = "ຮັບອຸປະກອນເຂົ້າສຳເລັດ ({$baseItem['name']}) (+{$add_quantity})";
                }

                $stmtMovement = $pdo->prepare("
                    INSERT INTO stock_movements
                    (item_id, movement_type, quantity, old_quantity, new_quantity, note, created_by, created_at)
                    VALUES (?, 'receive', ?, ?, ?, ?, ?, NOW())
                ");
                $noteText = 'ຮັບອຸປະກອນເຂົ້າ' . (!empty($serial_number) ? " (S/N: {$serial_number})" : '');
                $stmtMovement->execute([$movementItemId, $add_quantity, !empty($serial_number) ? 0 : $old_quantity, $movementNewQty, $noteText, $user_id]);

                $pdo->commit();

                showAlert($successText, 'success');
                logActivity("ຮັບອຸປະກອນເຂົ້າ: {$baseItem['name']}");
            }
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                rollbackItemTransaction($pdo);
            }
            error_log('Admin Items Error [' . get_class($e) . ']: ' . $e->getMessage());
            $userMessage = ($e instanceof RuntimeException)
                ? $e->getMessage()
                : 'ເກີດຂໍ້ຜິດພາດ ກະລຸນາລອງໃໝ່';
            showAlert($userMessage, 'error');
        }
        echo '<script>window.location.href = "?admin=items";</script>';
        exit();
    }
    
    // ----------------------------------------------------
    // 3. ແກ້ໄຂອຸປະກອນ (EDIT ITEM)
    // ----------------------------------------------------
    if (isset($_POST['edit_item'])) {
        $id                 = (int)$_POST['item_id'];
        $item_code          = trim($_POST['item_code'] ?? '');
        $sn_raw             = trim($_POST['serial_number'] ?? '');
        $serial_number      = !empty($sn_raw) ? $sn_raw : null;
        $name               = trim($_POST['name'] ?? ($_POST['item_name'] ?? ''));
        $brand              = trim($_POST['brand'] ?? '');
        $model              = trim($_POST['model'] ?? '');
        $unit               = trim($_POST['unit'] ?? '');
        $new_quantity       = (int)($_POST['quantity'] ?? 0);
        $service_tax        = floatval($_POST['service_tax'] ?? 0);
        $manufacturing_year = !empty($_POST['manufacturing_year']) ? (int)$_POST['manufacturing_year'] : null;
        $min_quantity       = isset($_POST['min_quantity']) && $_POST['min_quantity'] !== '' ? (int)$_POST['min_quantity'] : 1;
        $category           = isset($_POST['category']) ? (int)$_POST['category'] : 1;
        $is_active          = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
        $import_date        = $_POST['import_date'] ?? date('Y-m-d');
        $origin             = trim($_POST['origin'] ?? '');
        $warranty_years     = (int)($_POST['warranty_years'] ?? 0);
        $remark             = trim($_POST['remark'] ?? '');
        
        if ($service_tax < 0) {
            showAlert('Service Tax ບໍ່ສາມາດເປັນຄ່າລົບໄດ້!', 'error');
            echo '<script>window.location.href = "?admin=items";</script>';
            exit();
        }
        
        try {
            if (isset($pdo)) {
                $pdo->beginTransaction();

                if (!empty($serial_number)) {
                    $checkSN = $pdo->prepare("SELECT COUNT(*) FROM items WHERE serial_number = ? AND id != ? AND is_active = 1");
                    $checkSN->execute([$serial_number, $id]);
                    if ($checkSN->fetchColumn() > 0) {
                        rollbackItemTransaction($pdo);
                        showAlert("Serial Number '{$serial_number}' ນີ້ຖືກໃຊ້ໂດຍອຸປະກອນອື່ນແລ້ວ!", 'error');
                        echo '<script>window.location.href = "?admin=items";</script>';
                        exit();
                    }
                }

                $stmtOld = $pdo->prepare("SELECT i.*, c.cate_name FROM items i LEFT JOIN category c ON i.cate_id = c.cate_id WHERE i.id = ?");
                $stmtOld->execute([$id]);
                $oldData = $stmtOld->fetch(PDO::FETCH_ASSOC);

                $old_quantity = (int)($oldData['quantity'] ?? 0);

                // ກວດສອບ ແລະ ອັບເດດ ຮູບພາບ binary BLOB
                $imageData = $oldData['image_data'];
                $imageMime = $oldData['image_mime'];
                
                $imgFile = getOptionalUploadData($_FILES, 'image', ['jpg', 'jpeg', 'png', 'gif', 'webp'], 'ຮູບພາບ');
                if ($imgFile) {
                    $imageData = $imgFile['data'];
                    $imageMime = $imgFile['mime'];
                }

                // ກວດສອບ ແລະ ອັບເດດ ເອກະສານ binary BLOB
                $docData = $oldData['doc_data'];
                $docMime = $oldData['doc_mime'];
                $docName = $oldData['doc_name'];
                $docFile = getOptionalUploadData($_FILES, 'document', ['pdf', 'docx', 'xlsx', 'pptx', 'txt'], 'ເອກະສານ');
                if ($docFile) {
                    $docData = $docFile['data'];
                    $docMime = $docFile['mime'];
                    $docName = $docFile['name'];
                }

                $stmt = $pdo->prepare("
                    UPDATE items SET 
                        item_code = ?, serial_number = ?, name = ?, brand = ?, model = ?, cate_id = ?, unit = ?, 
                        quantity = ?, min_quantity = ?, 
                        image_data = ?, image_mime = ?, doc_data = ?, doc_mime = ?, doc_name = ?,
                        is_active = ?, import_date = ?, origin = ?, warranty_years = ?, service_tax = ?, manufacturing_year = ?, remark = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $item_code, $serial_number, $name, $brand, $model, $category, $unit, $new_quantity, $min_quantity, 
                    $imageData, $imageMime, $docData, $docMime, $docName, 
                    $is_active, $import_date, $origin, $warranty_years, $service_tax, $manufacturing_year, $remark, $id
                ]);

                $qty_diff = abs($new_quantity - $old_quantity);
                $note_text = "ແກ້ໄຂຂໍ້ມູນອຸປະກອນ";
                if ($old_quantity !== $new_quantity) {
                    $note_text .= " (ປັບຈຳນວນ: {$old_quantity} ➔ {$new_quantity})";
                }

                $stmtMovement = $pdo->prepare("
                    INSERT INTO stock_movements 
                    (item_id, movement_type, quantity, old_quantity, new_quantity, note, created_by, created_at)
                    VALUES (?, 'count', ?, ?, ?, ?, ?, NOW())
                ");
                $stmtMovement->execute([$id, $qty_diff, $old_quantity, $new_quantity, $note_text, $user_id]);

                $pdo->commit();
                showAlert('ແກ້ໄຂອຸປະກອນສຳເລັດ', 'success');
            }
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                rollbackItemTransaction($pdo);
            }
            error_log('Admin Items Error [' . get_class($e) . ']: ' . $e->getMessage());
            $userMessage = ($e instanceof RuntimeException)
                ? $e->getMessage()
                : 'ເກີດຂໍ້ຜິດພາດ ກະລຸນາລອງໃໝ່';
            showAlert($userMessage, 'error');
        }
        echo '<script>window.location.href = "?admin=items";</script>';
        exit();
    }

    // ----------------------------------------------------
    // 4. RESTOCK
    // ----------------------------------------------------
    if (isset($_POST['restock_item'])) {
        $id = (int)$_POST['item_id'];
        $add_qty = (int)$_POST['add_quantity'];
        $new_unit = trim($_POST['unit'] ?? '');
        
        if ($add_qty > 0) {
            try {
                if (isset($pdo)) {
                    $pdo->beginTransaction();

                    $stmtOld = $pdo->prepare("SELECT quantity FROM items WHERE id = ?");
                    $stmtOld->execute([$id]);
                    $old_quantity = (int)$stmtOld->fetchColumn();
                    $new_quantity = $old_quantity + $add_qty;

                    $stmt = $pdo->prepare("UPDATE items SET quantity = quantity + ?, unit = ? WHERE id = ?");
                    $stmt->execute([$add_qty, $new_unit, $id]);

                    $stmtMovement = $pdo->prepare("
                        INSERT INTO stock_movements 
                        (item_id, movement_type, quantity, old_quantity, new_quantity, note, created_by, created_at)
                        VALUES (?, 'receive', ?, ?, ?, 'ຊື້ເພີ່ມ / Restock ສະຕັອກ', ?, NOW())
                    ");
                    $stmtMovement->execute([$id, $add_qty, $old_quantity, $new_quantity, $user_id]);

                    $pdo->commit();
                    showAlert("ເພີ່ມສະຕັອກອຸປະກອນສຳເລັດ (+{$add_qty})", 'success');
                }
            } catch (Throwable $e) {
                if (isset($pdo) && $pdo->inTransaction()) {
                    rollbackItemTransaction($pdo);
                }
                error_log('Admin Items PDO Error: ' . $e->getMessage());
                showAlert('ເກີດຂໍ້ຜິດພາດ ກະລຸນາລອງໃໝ່', 'error');
            }
        }
        echo '<script>window.location.href = "?admin=items";</script>';
        exit();
    }

    // ----------------------------------------------------
    // 5. ບັນທຶກການຖ່າຍໂອນ/ຈຳໜ່າຍອອກ (DISPOSE / DELETE ITEM)
    // ----------------------------------------------------
    if (isset($_POST['delete_item'])) {
        $id = (int)$_POST['item_id'];
        
        try {
            if (isset($pdo)) {
                $pdo->beginTransaction();

                $stmtGet = $pdo->prepare("SELECT * FROM items WHERE id = ? AND is_active = 1 LIMIT 1");
                $stmtGet->execute([$id]);
                $item = $stmtGet->fetch(PDO::FETCH_ASSOC);

                if ($item) {
                    $old_qty = (int)$item['quantity'];

                    $stmtDel = $pdo->prepare("UPDATE items SET is_active = 0 WHERE id = ?");
                    $stmtDel->execute([$id]);

                    $stmtMovement = $pdo->prepare("
                        INSERT INTO stock_movements 
                        (item_id, movement_type, quantity, old_quantity, new_quantity, note, created_by, created_at)
                        VALUES (?, 'dispose', ?, ?, 0, 'ລຶບອຸປະກອນອອກ', ?, NOW())
                    ");
                    $stmtMovement->execute([$id, $old_qty, $old_qty, $user_id]);

                    $pdo->commit();
                    showAlert("ລຶບອຸປະກອນ '{$item['name']}' ອອກຈາກລະບົບ (Dispose) ສຳເລັດ", 'success');
                    logActivity("ລຶບອຸປະກອນ (Dispose): {$item['name']}");
                } else {
                    rollbackItemTransaction($pdo);
                    showAlert('ບໍ່ພົບຂໍ້ມູນອຸປະກອນທີ່ຈະຈຳໜ່າຍ', 'error');
                }
            }
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                rollbackItemTransaction($pdo);
            }
            error_log('Admin Items Error [' . get_class($e) . ']: ' . $e->getMessage());
            $userMessage = ($e instanceof RuntimeException)
                ? $e->getMessage()
                : 'ເກີດຂໍ້ຜິດພາດ ກະລຸນາລອງໃໝ່';
            showAlert($userMessage, 'error');
        }
        echo '<script>window.location.href = "?admin=items";</script>';
        exit();
    }
}

// ============================================================
// 3. FETCH DATA & STATS
// ============================================================
$items = [];
$categories = [];
$total_usable_stock = 0;

if (isset($pdo)) {
    try {
        $categories = $pdo->query("SELECT * FROM category ORDER BY cate_name")->fetchAll(PDO::FETCH_ASSOC);

        $sql = "SELECT i.id, i.item_code, i.serial_number, i.name, i.brand, i.model, i.cate_id, i.unit, 
                       i.quantity, i.min_quantity, i.barcode, i.is_active, i.import_date, i.origin, 
                       i.warranty_years, i.service_tax, i.manufacturing_year, i.remark, i.edit_history,
                       (COALESCE(OCTET_LENGTH(i.image_data), 0) > 0) AS has_image,
                       (COALESCE(OCTET_LENGTH(i.doc_data), 0) > 0) AS has_doc, i.doc_name, c.cate_name
                FROM items i 
                LEFT JOIN category c ON i.cate_id = c.cate_id 
                WHERE i.is_active = 1 
                ORDER BY i.id DESC";

        $items = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($items as $itm) {
            $qty = (int)($itm['quantity'] ?? 0);
            $total_usable_stock += $qty;
        }
    } catch (Throwable $e) {
        error_log('Admin Items PDO Error: ' . $e->getMessage());
        showAlert('ເກີດຂໍ້ຜິດພາດ ກະລຸນາລອງໃໝ່', 'error');
    }
}
?>

<!-- ============================================================ -->
<!-- 4. UI HTML VIEW -->
<!-- ============================================================ -->
<div class="mb-6 flex flex-wrap justify-between items-center gap-4">
    <div>
        <h1 class="text-2xl font-bold text-gray-800">📦 ຈັດການອຸປະກອນທັງໝົດ</h1>
        <p class="text-gray-600">ສະແດງຂໍ້ມູນອຸປະກອນທັງໝົດໃນຄັງ</p>
    </div>
    <div class="flex flex-wrap gap-3">
        <button onclick="openAddModal()" class="btn-primary flex items-center shadow-md">
            <i class="fas fa-plus mr-2"></i>ເພີ່ມອຸປະກອນໃໝ່
        </button>
        <button onclick="openReceiveExistingModal()" class="bg-amber-500 hover:bg-amber-600 text-white px-4 py-2 rounded-lg font-medium transition shadow-md flex items-center">
            <i class="fas fa-boxes mr-2"></i>ຮັບອຸປະກອນເຂົ້າ
        </button>
        <button onclick="exportItems()" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg font-medium transition shadow-md">
            <i class="fas fa-file-export mr-2"></i>ສົ່ງອອກ
        </button>
    </div>
</div>

<!-- 📊 SUMMARY CARDS STATS -->
<div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
    <div class="card flex items-center p-4 bg-white rounded-xl shadow-sm border border-gray-100">
        <div class="w-12 h-12 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center text-xl mr-4">
            <i class="fas fa-boxes"></i>
        </div>
        <div>
            <p class="text-sm text-gray-500 font-medium">ລາຍການອຸປະກອນ</p>
            <h3 class="text-2xl font-bold text-gray-800"><?php echo count($items); ?> <span class="text-xs font-normal text-gray-500">ລາຍການ</span></h3>
        </div>
    </div>

    <div class="card flex items-center p-4 bg-white rounded-xl shadow-sm border border-gray-100">
        <div class="w-12 h-12 rounded-full bg-green-100 text-green-600 flex items-center justify-center text-xl mr-4">
            <i class="fas fa-check-circle"></i>
        </div>
        <div>
            <p class="text-sm text-gray-500 font-medium">ຈຳນວນອຸປະກອນທັງໝົດໃນຄັງ</p>
            <h3 class="text-2xl font-bold text-green-600"><?php echo number_format($total_usable_stock); ?></h3>
        </div>
    </div>
</div>

<?php
$alert = getAlert();
if ($alert): ?>
    <div class="p-4 mb-4 rounded-lg <?php echo $alert['type'] === 'success' ? 'bg-green-100 text-green-700 border border-green-200' : 'bg-red-100 text-red-700 border border-red-200'; ?>">
        <i class="fas <?php echo $alert['type'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?> mr-2"></i>
        <?php echo htmlspecialchars($alert['message']); ?>
    </div>
<?php endif; ?>

<!-- ການຄົ້ນຫາ ແລະ Filter -->
<div class="card mb-6">
    <div class="flex flex-wrap gap-4 items-center justify-between">
        <div class="flex-1 min-w-[200px] flex flex-wrap gap-3">
            <div class="relative flex-1 min-w-[180px]">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none"></i>
                <input type="text" id="searchInput" onkeyup="filterData()" placeholder="ຄົ້ນຫາອຸປະກອນ, Serial No (S/N), Barcode, ຍີ່ຫໍ້..." class="form-input !pl-10 w-full">
            </div>

            <select id="categoryFilter" onchange="filterData()" class="form-input w-48">
                <option value="all">-- ທຸກໝວດໝູ່ --</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo $cat['cate_id']; ?>"><?php echo htmlspecialchars($cat['cate_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="flex flex-wrap gap-2">
            <button onclick="setStockFilter('all', this)" class="filter-btn active-filter px-3 py-1 text-sm rounded-full bg-blue-500 text-white ring-2 ring-blue-300">ທັງໝົດ</button>
            <button onclick="setStockFilter('low', this)" class="filter-btn px-3 py-1 text-sm rounded-full bg-yellow-500 text-white">ໃກ້ໝົດ</button>
            <button onclick="setStockFilter('out', this)" class="filter-btn px-3 py-1 text-sm rounded-full bg-red-500 text-white">ໝົດສະຕັອກ</button>
        </div>
    </div>
</div>

<div class="card">
    <div class="overflow-x-auto">
        <table class="w-full" id="itemsTable">
            <thead>
                <tr class="border-b bg-gray-50">
                    <th class="text-left py-3 px-4 text-sm font-medium text-gray-500">ຮູບ</th>
                    <th class="text-left py-3 px-4 text-sm font-medium text-gray-500">ເອກະສານ</th>
                    <th class="text-left py-3 px-4 text-sm font-medium text-gray-500">ລະຫັດອຸປະກອນ</th> 
                    <th class="text-left py-3 px-4 text-sm font-medium text-gray-500">Serial Number</th> 
                    <th class="text-left py-3 px-4 text-sm font-medium text-gray-500">ຊື່ / Brand & Model</th>
                    <th class="text-left py-3 px-4 text-sm font-medium text-gray-500">ປະເພດ</th>
                    <th class="text-left py-3 px-4 text-sm font-medium text-gray-500">ປີຜະລິດ</th>
                    <th class="text-left py-3 px-4 text-sm font-medium text-gray-500">Service Tax</th>
                    <th class="text-left py-3 px-4 text-sm font-medium text-gray-500">ວັນທີນຳເຂົ້າ / ທີ່ມາ</th>
                    <th class="text-center py-3 px-4 text-sm font-medium text-gray-500">ຈຳນວນລວມ</th>
                    <th class="text-left py-3 px-4 text-sm font-medium text-gray-500">📝 ໝາຍເຫດ</th>
                    <th class="text-center py-3 px-4 text-sm font-medium text-gray-500">📝 ປະຫວັດແກ້ໄຂ</th>
                    <th class="text-center py-3 px-4 text-sm font-medium text-gray-500">QR Code</th>
                    <th class="text-center py-3 px-4 text-sm font-medium text-gray-500">ຈັດການ</th>
                </tr>
            </thead>
            <tbody id="tableBody">
                <?php if (!empty($items) && count($items) > 0): ?>
                    <?php foreach ($items as $item): 
                        $total_qty = (int)($item['quantity'] ?? 0);
                        $min_qty   = (int)($item['min_quantity'] ?? 1);

                        $stockStatus = '';
                        $isOut = false;
                        
                        if ($total_qty <= 0) {
                            $stockStatus = 'ໝົດສະຕັອກ';
                            $isOut = true;
                        } elseif ($total_qty <= $min_qty) {
                            $stockStatus = 'ໃກ້ໝົດ';
                        }
                        
                        $sn = $item['serial_number'] ?? ''; 
                        $origin = $item['origin'] ?? '';
                        $warranty = isset($item['warranty_years']) ? (int)$item['warranty_years'] : 0;
                        $remark = $item['remark'] ?? '';
                        $brand = $item['brand'] ?? '';
                        $model = $item['model'] ?? '';
                        $mfg_year = !empty($item['manufacturing_year']) ? $item['manufacturing_year'] : '-';
                        $service_tax_val = isset($item['service_tax']) && $item['service_tax'] !== null ? number_format($item['service_tax'], 2) . ' %' : '-';
                        $import_date = !empty($item['import_date']) ? date('d/m/Y', strtotime($item['import_date'])) : '-';
                        
                        $editHistory = !empty($item['edit_history']) ? json_decode($item['edit_history'], true) : [];
                        $detailUrl = rtrim($baseUrl, "/") . "/qr_detail.php?id=" . urlencode((string)$item['id']);
                        
                        // Link Stream DB BLOB
                        $blobImgUrl = appUrl('admin.php') . '?admin=items&get_blob=' . (int)$item['id'] . '&type=image';
                        $blobDocUrl = appUrl('admin.php') . '?admin=items&get_blob=' . (int)$item['id'] . '&type=doc';
                    ?>
                        <tr class="border-b hover:bg-gray-50 transition item-row" 
                            data-name="<?php echo htmlspecialchars(mb_strtolower($item['name'])); ?>"
                            data-code="<?php echo htmlspecialchars(mb_strtolower($item['item_code'])); ?>"
                            data-sn="<?php echo htmlspecialchars(mb_strtolower($sn)); ?>"
                            data-barcode="<?php echo htmlspecialchars(mb_strtolower($item['barcode'])); ?>"
                            data-brand="<?php echo htmlspecialchars(mb_strtolower($brand)); ?>"
                            data-model="<?php echo htmlspecialchars(mb_strtolower($model)); ?>"
                            data-origin="<?php echo htmlspecialchars(mb_strtolower($origin)); ?>"
                            data-remark="<?php echo htmlspecialchars(mb_strtolower($remark)); ?>"
                            data-category="<?php echo $item['cate_id']; ?>"
                            data-stock="<?php echo $total_qty <= 0 ? 'out' : ($total_qty <= $min_qty ? 'low' : 'ok'); ?>">
                            
                            <td class="py-3 px-4">
                                <div class="flex items-center gap-2">
                                    <!-- ຟັງຊັ່ນຮູບ (ດຶງຈາກ DATABASE BLOB) -->
                                    <?php if ($item['has_image']): ?>
                                        <button type="button" onclick="viewImageModal('<?php echo htmlspecialchars($blobImgUrl, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($item['name'], ENT_QUOTES); ?>')" title="ກົດເພື່ອເບິ່ງຮູບພາບ">
                                            <img src="<?php echo htmlspecialchars($blobImgUrl); ?>" class="w-10 h-10 rounded object-cover shadow-sm hover:scale-105 hover:shadow-md transition cursor-pointer border">
                                        </button>
                                    <?php else: ?>
                                        <div class="w-10 h-10 rounded bg-gray-100 border flex items-center justify-center text-gray-400 cursor-not-allowed" title="ບໍ່ມີຮູບພາບ">
                                            <i class="fas fa-image text-xs"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>

                            <td class="py-3 px-4">
                                <div class="flex items-center gap-2">
                                    <!-- ຟັງຊັ່ນເອກະສານ (ດຶງຈາກ DATABASE BLOB) -->
                                    <?php if ($item['has_doc']): 
                                        $docName = $item['doc_name'] ?? 'document';
                                        $docExt = strtolower(pathinfo($docName, PATHINFO_EXTENSION));
                                    ?>
                                        <div class="flex flex-col gap-1">
                                            <?php if ($docExt === 'pdf'): ?>
                                                <button type="button" onclick="viewDocumentModal('<?php echo htmlspecialchars($blobDocUrl, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($blobDocUrl . '&download=1', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($item['name'], ENT_QUOTES); ?>')" class="px-2 py-0.5 bg-blue-50 text-blue-600 hover:bg-blue-100 rounded text-[10px] font-bold transition flex items-center justify-center gap-1 border border-blue-200" title="ເບິ່ງ PDF">
                                                    <i class="fas fa-file-pdf"></i> PDF
                                                </button>
                                            <?php else: ?>
                                                <a href="<?php echo htmlspecialchars($blobDocUrl . '&download=1'); ?>" class="px-2 py-0.5 bg-blue-50 text-blue-600 hover:bg-blue-100 rounded text-[10px] font-bold transition flex items-center justify-center gap-1 border border-blue-200">
                                                    <i class="fas fa-file-alt"></i> <?php echo strtoupper($docExt); ?>
                                                </a>
                                            <?php endif; ?>
                                            <a href="<?php echo htmlspecialchars($blobDocUrl . '&download=1'); ?>" class="text-[10px] text-gray-500 hover:text-gray-700 underline text-center">
                                                ດາວໂຫຼດ
                                            </a>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-gray-400 text-xs">-</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            
                            <td class="py-3 px-4">
                                <a href="<?php echo htmlspecialchars($detailUrl); ?>" class="text-xs text-blue-600 hover:underline font-mono font-semibold" title="ເບິ່ງລາຍລະອຽດ">
                                    <?php echo htmlspecialchars($item['item_code']); ?>
                                </a>
                            </td>

                            <td class="py-3 px-4">
                                <?php if (!empty($sn)): ?>
                                    <div class="text-xs font-mono font-semibold text-blue-600 bg-blue-50 px-1.5 py-0.5 rounded inline-block">
                                        S/N: <?php echo htmlspecialchars($sn); ?>
                                    </div>
                                <?php else: ?>
                                    <span class="text-gray-400 text-xs">-</span>
                                <?php endif; ?>
                            </td>

                            <td class="py-3 px-4">
                                <a href="<?php echo htmlspecialchars($detailUrl); ?>" class="text-sm font-medium text-gray-800 hover:text-blue-600 transition">
                                    <?php echo htmlspecialchars($item['name']); ?>
                                </a>
                                <?php if (!empty($brand) || !empty($model)): ?>
                                    <div class="text-xs text-gray-500">🏷️ <?php echo htmlspecialchars($brand); ?> <?php echo !empty($model) ? "({$model})" : ''; ?></div>
                                <?php endif; ?>
                                <?php if ($stockStatus): ?>
                                    <span class="text-[10px] px-2 py-0.5 rounded-full mt-0.5 inline-block <?php echo $isOut ? 'bg-red-100 text-red-600' : 'bg-yellow-100 text-yellow-600'; ?>">
                                        <?php echo $stockStatus; ?>
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td class="py-3 px-4 text-sm text-gray-600">
                                <div class="font-medium text-gray-800"><?php echo htmlspecialchars($item['cate_name'] ?? 'ບໍ່ມີໝວດໝູ່'); ?></div>
                            </td>

                            <td class="py-3 px-4 text-sm text-gray-700 font-medium">
                                <?php echo htmlspecialchars($mfg_year); ?>
                            </td>

                            <td class="py-3 px-4 text-sm font-semibold text-emerald-600">
                                <?php echo htmlspecialchars($service_tax_val); ?>
                            </td>

                            <td class="py-3 px-4 text-xs">
                                <div class="text-gray-700 font-medium">📅 ນຳເຂົ້າ: <?php echo $import_date; ?></div>
                                <div class="text-gray-500 mt-0.5">📍 <?php echo !empty($origin) ? htmlspecialchars($origin) : 'ບໍ່ລະບຸ'; ?></div>
                                <div class="text-gray-400">🛡️ <?php echo $warranty > 0 ? $warranty . ' ປີ' : 'ບໍ່ມີປະກັນ'; ?></div>
                            </td>

                            <td class="py-3 px-4 text-sm text-center font-bold text-gray-700">
                                <?php echo number_format($total_qty); ?> <span class="text-xs text-gray-400 font-normal"><?php echo htmlspecialchars($item['unit']); ?></span>
                            </td>

                            <td class="py-3 px-4 text-xs text-gray-600 max-w-[150px]">
                                <?php if (!empty($remark)): ?>
                                    <span class="bg-gray-100 text-gray-700 px-2 py-1 rounded border block truncate">
                                        <?php echo htmlspecialchars($remark); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-gray-400 font-italic">-</span>
                                <?php endif; ?>
                            </td>

                            <td class="py-3 px-4 text-center">
                                <?php if (!empty($editHistory)): ?>
                                    <button onclick="viewEditHistory(<?php echo htmlspecialchars(json_encode($editHistory), ENT_QUOTES, 'UTF-8'); ?>)" class="text-xs bg-amber-100 text-amber-800 hover:bg-amber-200 px-2.5 py-1 rounded-lg transition font-medium">
                                        ✏️ <?php echo count($editHistory); ?> ຄັ້ງ
                                    </button>
                                <?php else: ?>
                                    <span class="text-gray-400 text-xs">-</span>
                                <?php endif; ?>
                            </td>

                            <td class="py-3 px-4 text-center">
                                <?php if (!empty($item['barcode'])): ?>
                                    <button onclick="showQRCode('<?php echo htmlspecialchars($detailUrl, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($item['barcode']); ?>', '<?php echo htmlspecialchars($item['name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($remark, ENT_QUOTES); ?>')" class="text-blue-600 hover:text-blue-800 transition p-1.5 rounded-lg hover:bg-blue-50" title="ສະແກນເບິ່ງຂໍ້ມູນຜ່ານມືຖື">
                                        <i class="fas fa-qrcode text-xl"></i>
                                    </button>
                                <?php else: ?>
                                    <span class="text-gray-400 text-xs">ບໍ່ມີ</span>
                                <?php endif; ?>
                            </td>

                            <td class="py-3 px-4 text-sm text-center">
                                <div class="flex items-center justify-center gap-1.5">
                                    <?php if ($total_qty <= $min_qty): ?>
                                        <button type="button" onclick="openRestockModal(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['name'], ENT_QUOTES); ?>', <?php echo $total_qty; ?>, '<?php echo htmlspecialchars($item['unit'], ENT_QUOTES); ?>')" class="bg-orange-500 hover:bg-orange-600 text-white p-1.5 rounded-lg transition text-xs font-medium flex items-center gap-1 shadow-sm">
                                            <i class="fas fa-shopping-cart"></i><span class="hidden md:inline">ຊື້ເພີ່ມ</span>
                                        </button>
                                    <?php endif; ?>

                                    <button onclick="editItem(<?php echo htmlspecialchars(json_encode($item), ENT_QUOTES, 'UTF-8'); ?>)" class="text-blue-600 hover:text-blue-800 p-1.5 rounded-lg hover:bg-blue-50 transition" title="ແກ້ໄຂ">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button onclick="printBarcode('<?php echo htmlspecialchars($item['barcode'] ?? ''); ?>', '<?php echo htmlspecialchars($item['name'], ENT_QUOTES); ?>')" class="text-green-600 hover:text-green-800 p-1.5 rounded-lg hover:bg-green-50 transition" title="ພິມ Barcode">
                                        <i class="fas fa-print"></i>
                                    </button>
                                    <button onclick="openDeleteModal(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['name'], ENT_QUOTES); ?>')" class="text-red-600 hover:text-red-800 p-1.5 rounded-lg hover:bg-red-50 transition" title="ລຶບ (ຊຳລະ)">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="14" class="py-8 text-center text-gray-500">
                            <i class="fas fa-box-open text-4xl mb-3 block"></i>
                            <p>ຍັງບໍ່ມີຂໍ້ມູນອຸປະກອນໃນຄັງ</p>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- 📌 PAGINATION CONTROLS (ສະແດງ 10 ລາຍການຕໍ່ໜ້າ) -->
    <div class="flex flex-wrap justify-between items-center mt-4 pt-4 border-t border-gray-100 gap-4">
        <div class="text-xs text-gray-500" id="paginationInfo">
            ສະແດງ 0 ຫາ 0 ຈາກທັງໝົດ 0 ລາຍການ
        </div>
        <div class="flex items-center gap-1" id="paginationButtons">
            <!-- ປຸ່ມແບ່ງໜ້າຈະຖືກສ້າງຜ່ານ JavaScript -->
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- MODAL: LIGHTBOX & VIEWERS -->
<!-- ============================================================ -->
<div id="imageViewerModal" class="fixed inset-0 bg-black/70 flex items-center justify-center hidden z-50 p-4">
    <div class="bg-white rounded-2xl max-w-2xl w-full p-4 shadow-2xl overflow-hidden relative">
        <div class="flex justify-between items-center pb-2 border-b mb-3">
            <h3 class="text-lg font-bold text-gray-800 truncate" id="imageViewerTitle">🖼️ ຮູບພາບອຸປະກອນ</h3>
            <button onclick="closeImageViewerModal()" class="text-gray-400 hover:text-gray-600 transition">
                <i class="fas fa-times text-2xl"></i>
            </button>
        </div>
        <div class="flex justify-center items-center bg-gray-50 rounded-xl p-2 min-h-[300px] max-h-[70vh] overflow-hidden">
            <img id="imageViewerSrc" src="" alt="Full Image" class="max-h-[65vh] w-auto object-contain rounded-lg shadow-md">
        </div>
        <div class="mt-4 flex justify-between items-center">
            <a id="imageViewerDownload" href="" download class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-xl text-sm font-medium transition flex items-center gap-2">
                <i class="fas fa-download"></i> ດາວໂຫຼດຮູບ
            </a>
            <button onclick="closeImageViewerModal()" class="px-5 py-2 text-gray-600 border rounded-xl hover:bg-gray-50 transition font-medium text-sm">
                ປິດ
            </button>
        </div>
    </div>
</div>

<div id="documentViewerModal" class="fixed inset-0 bg-black/70 flex items-center justify-center hidden z-50 p-4">
    <div class="bg-white rounded-2xl max-w-5xl w-full p-4 shadow-2xl overflow-hidden relative">
        <div class="flex justify-between items-center pb-2 border-b mb-3">
            <h3 class="text-lg font-bold text-gray-800 truncate" id="documentViewerTitle">📄 ເອກະສານ</h3>
            <button onclick="closeDocumentViewerModal()" class="text-gray-400 hover:text-gray-600 transition">
                <i class="fas fa-times text-2xl"></i>
            </button>
        </div>
        <div class="flex justify-center items-center bg-gray-50 rounded-xl p-2 min-h-[300px] max-h-[75vh] overflow-hidden">
            <iframe id="documentViewerFrame" src="" class="w-full min-h-[70vh] rounded-lg border border-gray-200 bg-white" title="Document Preview"></iframe>
        </div>
        <div class="mt-4 flex justify-between items-center">
            <a id="documentViewerDownload" href="" download class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-xl text-sm font-medium transition flex items-center gap-2">
                <i class="fas fa-download"></i> ດາວໂຫຼດເອກະສານ
            </a>
            <button onclick="closeDocumentViewerModal()" class="px-5 py-2 text-gray-600 border rounded-xl hover:bg-gray-50 transition font-medium text-sm">
                ປິດ
            </button>
        </div>
    </div>
</div>

<!-- MODAL: ເພີ່ມ/ແກ້ໄຂ ອຸປະກອນ -->
<div id="itemModal" class="fixed inset-0 bg-black/50 flex items-center justify-center hidden z-50 p-4">
    <div class="bg-white rounded-2xl max-w-3xl w-full max-h-[92vh] overflow-y-auto shadow-2xl border border-gray-100">
        <div class="p-6">
            <div class="flex justify-between items-center mb-5 border-b pb-3">
                <h3 class="text-xl font-bold text-gray-800" id="modalTitle">
                    <i class="fas fa-plus-circle text-blue-500 mr-2"></i>ເພີ່ມອຸປະກອນ
                </h3>
                <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600 transition">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <form method="POST" enctype="multipart/form-data" id="itemForm">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="item_id" id="edit_id">
                
                <!-- 📌 Section 1: ຂໍ້ມູນຫຼັກ -->
                <div class="bg-blue-50/50 p-4 rounded-xl border border-blue-100 mb-5">
                    <h4 class="text-xs font-bold text-blue-800 uppercase tracking-wider mb-3"><i class="fas fa-info-circle mr-1"></i> ຂໍ້ມູນຫຼັກອຸປະກອນ</h4>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">ຊື່ອຸປະກອນ <span class="text-red-500">*</span></label>
                            <input type="text" name="name" id="name" required class="form-input" placeholder="ຕົວຢ່າງ: ຄອມພິວເຕີໂນດບຸກ, ໂຕະເຮັດວຽກ...">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                ລະຫັດອຸປະກອນ (Item Code)
                            </label>
                            <input type="text" name="item_code" id="item_code" class="form-input uppercase" placeholder="ຕົວຢ່າງ: ITM-0001 (Auto)">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">ໝວດໝູ່ <span class="text-red-500">*</span></label>
                            <?php if (!empty($categories)): ?>
                                <div class="flex flex-wrap gap-1.5 mb-2">
                                    <?php foreach (array_slice($categories, 0, 5) as $cat): ?>
                                        <button type="button" onclick="quickSelectCat(<?php echo $cat['cate_id']; ?>)" class="text-xs bg-white border border-gray-300 hover:border-blue-500 hover:bg-blue-50 px-2.5 py-1 rounded-full text-gray-700 transition">
                                            + <?php echo htmlspecialchars($cat['cate_name']); ?>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <select name="category" id="category" required class="form-input">
                                <option value="">-- ເລືອກໝວດໝູ່ --</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo $cat['cate_id']; ?>"><?php echo htmlspecialchars($cat['cate_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-blue-900 mb-1">📦 ຈຳນວນທັງໝົດ <span class="text-red-500">*</span></label>
                            <input type="number" min="0" name="quantity" id="quantity" required class="form-input border-blue-300 font-bold text-blue-700" placeholder="0" value="1">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">ຫົວໜ່ວຍ <span class="text-red-500">*</span></label>
                            <input type="text" name="unit" id="unit" required class="form-input" placeholder="ອັນ, ຊຸດ, ເຄື່ອງ">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">ຈຳນວນຕ່ຳສຸດ (ເຕືອນ) <span class="text-red-500">*</span></label>
                            <input type="number" min="0" name="min_quantity" id="min_quantity" required class="form-input" placeholder="1" value="1">
                        </div>
                    </div>
                </div>

                <!-- 📌 Section 2: ຂໍ້ມູນເພີ່ມເຕີມ -->
                <div class="bg-gray-50 p-4 rounded-xl border border-gray-200 mb-5">
                    <h4 class="text-xs font-bold text-gray-600 uppercase tracking-wider mb-3"><i class="fas fa-sliders-h mr-1"></i> ຂໍ້ມູນເພີ່ມເຕີມ (ຖ້າມີ)</h4>
                    
                    <div id="barcode_display_container" class="hidden mb-4">
                        <label class="block text-sm font-medium text-blue-700 mb-1">🏷️ Barcode </label>
                        <input type="text" id="display_barcode" class="form-input bg-gray-100 font-mono font-bold text-blue-600 cursor-not-allowed" readonly>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Serial Number (S/N)</label>
                            <input type="text" name="serial_number" id="serial_number" class="form-input font-mono text-sm" placeholder="SN12345678">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">ຍີ່ຫໍ້ (Brand)</label>
                            <input type="text" name="brand" id="brand" class="form-input text-sm" placeholder="Dell, HP, Apple...">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">ລຸ້ນ (Model)</label>
                            <input type="text" name="model" id="model" class="form-input text-sm" placeholder="Core i5, 16GB RAM...">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">📅 ວັນທີນຳເຂົ້າ</label>
                            <input type="date" name="import_date" id="import_date" class="form-input text-sm" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">🏭 ປີຜະລິດ (Mfg Year)</label>
                            <input type="number" min="1900" max="<?php echo date('Y') + 1; ?>" name="manufacturing_year" id="manufacturing_year" class="form-input text-sm" placeholder="2024">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">💰 Service Tax (%)</label>
                            <input type="number" step="0.01" min="0" name="service_tax" id="service_tax" class="form-input text-sm" placeholder="0.00" value="0.00">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">ທີ່ມາ/ຮ້ານຄ້າ (Origin)</label>
                            <input type="text" name="origin" id="origin" class="form-input text-sm" placeholder="ບໍລິສັດ ຜູ້ສະໜອງ...">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">ຮັບປະກັນ (ປີ)</label>
                            <input type="number" min="0" name="warranty_years" id="warranty_years" class="form-input text-sm" placeholder="0" value="0">
                        </div>
                    </div>

                    <!-- Input ແນບຮູບພາບ ແລະ ເອກະສານ (ເກັບໃນ DB) -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4 p-3 bg-white rounded-xl border border-gray-200">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1"><i class="fas fa-image text-emerald-600 mr-1"></i> ແນບຮູບພາບ (ເກັບໃນ Database)</label>
                            <input type="file" name="image" id="image" accept="image/png, image/jpeg, image/webp, image/gif" class="form-input text-xs" onchange="previewImageFile(this)">
                            <div id="imagePreview" class="mt-2 hidden">
                                <div id="imagePreviewContainer" class="flex items-center gap-2 p-2 bg-slate-50 border rounded-lg shadow-sm"></div>
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1"><i class="fas fa-file-pdf text-red-500 mr-1"></i> ແນບເອກະສານ (ເກັບໃນ Database)</label>
                            <input type="file" name="document" id="document" accept=".pdf,.docx,.xlsx,.pptx,.txt" class="form-input text-xs" onchange="previewDocFile(this)">
                            <div id="docPreview" class="mt-2 hidden">
                                <div id="docPreviewContainer" class="flex items-center gap-2 p-2 bg-slate-50 border rounded-lg shadow-sm"></div>
                            </div>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">ໝາຍເຫດ (Remark)</label>
                        <textarea name="remark" id="remark" rows="2" class="form-input text-sm" placeholder="ເພີ່ມໝາຍເຫດ ຫຼື ລາຍລະອຽດເພີ່ມເຕີມ..."></textarea>
                    </div>
                </div>
                
                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" onclick="closeModal()" class="px-5 py-2.5 text-gray-600 border rounded-xl hover:bg-gray-50 transition font-medium">
                        ຍົກເລີກ
                    </button>
                    <button type="submit" name="add_item" id="submitBtn" class="btn-primary px-6 py-2.5 rounded-xl shadow-lg transition">
                        <i class="fas fa-save mr-2"></i>ບັນທຶກ
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL: RECEIVE EXISTING -->
<div id="receiveExistingModal" class="fixed inset-0 bg-black/50 flex items-center justify-center hidden z-50 p-4">
    <div class="bg-white rounded-2xl max-w-xl w-full p-6 shadow-2xl border border-gray-100">
        <div class="flex justify-between items-center mb-5 border-b pb-3">
            <h3 class="text-xl font-bold text-gray-800">
                <i class="fas fa-boxes text-amber-500 mr-2"></i>ຮັບອຸປະກອນເຂົ້າ
            </h3>
            <button onclick="closeReceiveExistingModal()" class="text-gray-400 hover:text-gray-600 transition">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">1. ເລືອກປະເພດ/ໝວດໝູ່ອຸປະກອນ <span class="text-red-500">*</span></label>
                <select id="select_category_for_existing" onchange="filterExistingItemsByCategory()" class="form-input bg-amber-50/40 border-amber-300">
                    <option value="">-- ເລືອກໝວດໝູ່ກ່ອນ --</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo $cat['cate_id']; ?>"><?php echo htmlspecialchars($cat['cate_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">2. ເລືອກອຸປະກອນທີ່ມີຢູ່ແລ້ວ <span class="text-red-500">*</span></label>
                <select name="existing_item_code" id="existing_item_code" required class="form-input" onchange="autoFillExistingInfo(this)">
                    <option value="">-- ເລືອກລາຍການອຸປະກອນ --</option>
                    <?php 
                    $unique_items = [];
                    foreach ($items as $it) {
                        if (!isset($unique_items[$it['item_code']])) {
                            $unique_items[$it['item_code']] = $it;
                        }
                    }
                    foreach ($unique_items as $u_item): 
                    ?>
                        <option value="<?php echo htmlspecialchars($u_item['item_code']); ?>" 
                                data-category="<?php echo $u_item['cate_id']; ?>"
                                data-name="<?php echo htmlspecialchars($u_item['name']); ?>"
                                data-brand="<?php echo htmlspecialchars($u_item['brand']); ?>"
                                data-unit="<?php echo htmlspecialchars($u_item['unit']); ?>">
                            [<?php echo htmlspecialchars($u_item['item_code']); ?>] <?php echo htmlspecialchars($u_item['name']); ?> <?php echo !empty($u_item['brand']) ? "({$u_item['brand']})" : ''; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div id="existing_info_preview" class="hidden bg-amber-50 p-3 rounded-lg mb-4 text-xs text-amber-900 border border-amber-200">
                <p><strong>ຊື່ອຸປະກອນ:</strong> <span id="prev_name">-</span></p>
                <p><strong>ຍີ່ຫໍ້:</strong> <span id="prev_brand">-</span></p>
                <p><strong>ຫົວໜ່ວຍ:</strong> <span id="prev_unit">-</span></p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Serial Number (S/N) ໃໝ່</label>
                    <input type="text" name="serial_number" class="form-input font-mono text-sm" placeholder="SN12345678 (ຖ້າມີ)">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">ຈຳນວນຮັບເຂົ້າ <span class="text-red-500">*</span></label>
                    <input type="number" min="1" name="quantity" required class="form-input font-bold text-blue-600" value="1">
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">📅 ວັນທີນຳເຂົ້າ</label>
                    <input type="date" name="import_date" class="form-input text-sm" value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">ໝາຍເຫດ</label>
                    <input type="text" name="remark" class="form-input text-sm" placeholder="ໝາຍເຫດການຮັບເຂົ້າ...">
                </div>
            </div>

            <div class="flex justify-end gap-3 pt-3 border-t">
                <button type="button" onclick="closeReceiveExistingModal()" class="px-5 py-2.5 text-gray-600 border rounded-xl hover:bg-gray-50 transition font-medium">
                    ຍົກເລີກ
                </button>
                <button type="submit" name="add_existing_item" class="bg-amber-500 hover:bg-amber-600 text-white px-6 py-2.5 rounded-xl shadow-lg transition">
                    <i class="fas fa-check mr-2"></i>ບັນທຶກຮັບເຂົ້າ
                </button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: DELETE ITEM -->
<div id="deleteModal" class="fixed inset-0 bg-black/50 flex items-center justify-center hidden z-50 p-4">
    <div class="bg-white rounded-2xl max-w-md w-full p-6 shadow-2xl border border-gray-100">
        <div class="text-center mb-4">
            <div class="w-16 h-16 bg-red-100 text-red-500 rounded-full flex items-center justify-center mx-auto mb-3 text-2xl">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <h3 class="text-lg font-bold text-gray-800">ຢືນຢັນການລຶບອຸປະກອນ</h3>
            <p class="text-sm text-gray-500 mt-1">ທ່ານຕ້ອງການລຶບ <span id="delete_item_name" class="font-bold text-gray-800"></span> ແທ້ຫຼືບໍ່?</p>
            <p class="text-xs text-amber-600 bg-amber-50 p-2 rounded-lg mt-2 border border-amber-200">
                <i class="fas fa-info-circle mr-1"></i> ຂໍ້ມູນການລຶບຈະຖືກບັນທຶກເປັນ <b>"ການຊຳລະ"</b> ໃນປະຫວັດ
            </p>
        </div>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <input type="hidden" name="item_id" id="delete_item_id">

            <div class="flex justify-center gap-3 pt-3">
                <button type="button" onclick="closeDeleteModal()" class="px-5 py-2 text-gray-600 border rounded-xl hover:bg-gray-50 transition font-medium">
                    ຍົກເລີກ
                </button>
                <button type="submit" name="delete_item" class="bg-red-600 hover:bg-red-700 text-white px-6 py-2 rounded-xl shadow-md transition font-medium">
                    <i class="fas fa-trash mr-1"></i> ຢືນຢັນການລຶບ
                </button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: EDIT HISTORY -->
<div id="editHistoryModal" class="fixed inset-0 bg-black/50 flex items-center justify-center hidden z-50 p-4">
    <div class="bg-white rounded-2xl max-w-lg w-full p-6 shadow-2xl">
        <div class="flex justify-between items-center mb-4 border-b pb-3">
            <h3 class="text-lg font-bold text-gray-800">
                <i class="fas fa-edit text-amber-600 mr-2"></i>ປະຫວັດການແກ້ໄຂຂໍ້ມູນ
            </h3>
            <button onclick="closeEditHistoryModal()" class="text-gray-400 hover:text-gray-600 transition"><i class="fas fa-times text-xl"></i></button>
        </div>
        <div id="editHistoryList" class="space-y-3 max-h-80 overflow-y-auto"></div>
        <div class="mt-5 text-right">
            <button onclick="closeEditHistoryModal()" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm font-medium">ປິດໜ້ານີ້</button>
        </div>
    </div>
</div>

<!-- MODAL: RESTOCK ITEM -->
<div id="restockModal" class="fixed inset-0 bg-black/50 flex items-center justify-center hidden z-50 p-4">
    <div class="bg-white rounded-2xl max-w-md w-full p-6 shadow-2xl">
        <div class="flex justify-between items-center mb-4 border-b pb-3">
            <h3 class="text-lg font-bold text-gray-800"><i class="fas fa-cart-plus text-orange-500 mr-2"></i>ຊື້ອຸປະກອນເພີ່ມ (Restock)</h3>
            <button onclick="closeRestockModal()" class="text-gray-400 hover:text-gray-600 transition"><i class="fas fa-times text-xl"></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <input type="hidden" name="item_id" id="restock_item_id">
            
            <div class="mb-3">
                <label class="block text-sm font-medium text-gray-700 mb-1">ຊື່ອຸປະກອນ</label>
                <input type="text" id="restock_item_name" class="form-input bg-gray-100 cursor-not-allowed" readonly>
            </div>
            
            <div class="mb-3">
                <label class="block text-sm font-medium text-gray-700 mb-1">ຈຳນວນປັດຈຸບັນໃນສະຕັອກ</label>
                <input type="text" id="restock_current_qty" class="form-input bg-gray-100 cursor-not-allowed" readonly>
            </div>

            <div class="mb-3">
                <label class="block text-sm font-medium text-gray-700 mb-1">ຈຳນວນທີ່ຕ້ອງການຊື້ເພີ່ມ <span class="text-red-500">*</span></label>
                <input type="number" name="add_quantity" id="add_quantity" min="1" required class="form-input text-lg font-bold text-blue-600">
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">ຫົວໜ່ວຍ <span class="text-red-500">*</span></label>
                <input type="text" name="unit" id="restock_unit" required class="form-input">
            </div>

            <div class="flex justify-end gap-3 pt-3 border-t">
                <button type="button" onclick="closeRestockModal()" class="px-4 py-2 text-gray-600 border rounded-lg hover:bg-gray-50 transition">ຍົກເລີກ</button>
                <button type="submit" name="restock_item" class="bg-orange-500 hover:bg-orange-600 text-white px-4 py-2 rounded-lg font-medium transition flex items-center gap-2"><i class="fas fa-check"></i>ບັນທຶກເພີ່ມສະຕັອກ</button>
            </div>
        </form>
    </div>
</div>

<!-- 📌 MODAL: QR CODE (ເພີ່ມ ຕົວເລືອກຂະໜາດ) -->
<div id="qrModal" class="fixed inset-0 bg-black/50 flex items-center justify-center hidden z-50 p-4">
    <div class="bg-white rounded-2xl max-w-sm w-full p-6 shadow-2xl text-center">
        <div class="flex justify-between items-center mb-3">
            <h3 class="text-lg font-bold text-gray-800"><i class="fas fa-qrcode text-blue-500 mr-2"></i>QR Code</h3>
            <button onclick="closeQRModal()" class="text-gray-400 hover:text-gray-600 transition"><i class="fas fa-times text-xl"></i></button>
        </div>
        
        <!-- ຕົວເລືອກຂະໜາດ QR Code -->
        <div class="mb-4 bg-gray-50 p-2 rounded-xl border flex items-center justify-between">
            <label for="qrSizeSelect" class="text-xs font-semibold text-gray-600 flex items-center gap-1">
                <i class="fas fa-expand text-blue-500"></i> ຂະໜາດ QR:
            </label>
            <select id="qrSizeSelect" onchange="changeQRSize()" class="form-input !py-1 !px-2 text-xs w-36 font-medium border-gray-300">
                <option value="150">Small (150x150)</option>
                <option value="300" selected>Medium (300x300)</option>
                <option value="500">Large (500x500)</option>
                <option value="800">HD (800x800)</option>
            </select>
        </div>

        <div class="bg-gray-50 p-3 rounded-xl border flex justify-center items-center mb-3 min-h-[200px]">
            <img id="qrImage" src="" alt="QR Code" class="mx-auto border rounded-lg p-2 bg-white shadow w-48 h-48 object-contain">
        </div>

        <p id="qrBarcode" class="text-sm font-mono bg-gray-100 px-4 py-1 rounded-lg inline-block text-gray-700 font-bold"></p>
        <p id="qrName" class="text-sm text-gray-600 mt-2 font-medium"></p>
        <p id="qrRemark" class="text-xs text-gray-500 mt-1 italic"></p>
        
        <div class="mt-5 flex justify-center gap-3">
            <button onclick="downloadQR()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition text-sm font-medium flex items-center gap-2"><i class="fas fa-download"></i>ດາວໂຫຼດ</button>
            <button onclick="printQR()" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg transition text-sm font-medium flex items-center gap-2"><i class="fas fa-print"></i>ພິມ</button>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- 5. JAVASCRIPT LOGIC -->
<!-- ============================================================ -->
<script>
let activeStockFilter = 'all';

// Variables ສຳລັບ Pagination 10 ລາຍການ/ໜ້າ
let currentPage = 1;
const rowsPerPage = 10;
let visibleRows = [];

// ຟັງຊັ່ນສະແດງ Modal ຮູບພາບຂະໜາດໃຫຍ່
function viewImageModal(src, title) {
    document.getElementById('imageViewerSrc').src = src;
    document.getElementById('imageViewerTitle').textContent = '🖼️ ຮູບພາບ: ' + title;
    document.getElementById('imageViewerDownload').href = src;
    document.getElementById('imageViewerModal').classList.remove('hidden');
}

function closeImageViewerModal() {
    document.getElementById('imageViewerModal').classList.add('hidden');
    document.getElementById('imageViewerSrc').src = '';
}

function viewDocumentModal(previewUrl, downloadUrl, title) {
    document.getElementById('documentViewerFrame').src = previewUrl;
    document.getElementById('documentViewerTitle').textContent = '📄 ' + title;
    document.getElementById('documentViewerDownload').href = downloadUrl;
    document.getElementById('documentViewerModal').classList.remove('hidden');
}

function closeDocumentViewerModal() {
    document.getElementById('documentViewerModal').classList.add('hidden');
    document.getElementById('documentViewerFrame').src = '';
}

function quickSelectCat(catId) {
    document.getElementById('category').value = catId;
}

function setStockFilter(type, btn) {
    activeStockFilter = type;
    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('ring-2', 'ring-blue-300'));
    if (btn) btn.classList.add('ring-2', 'ring-blue-300');
    filterData();
}

function filterData() {
    const searchTerm = document.getElementById('searchInput').value.toLowerCase();
    const selectedCategory = document.getElementById('categoryFilter').value;
    const rows = document.querySelectorAll('#tableBody .item-row');

    visibleRows = [];
    rows.forEach(row => {
        const name = row.dataset.name || '';
        const code = row.dataset.code || '';
        const sn = row.dataset.sn || '';
        const barcode = row.dataset.barcode || '';
        const brand = row.dataset.brand || '';
        const model = row.dataset.model || '';
        const origin = row.dataset.origin || '';
        const remark = row.dataset.remark || '';
        const category = row.dataset.category || '';
        const stock = row.dataset.stock || 'ok';

        const matchSearch = name.includes(searchTerm) || code.includes(searchTerm) || sn.includes(searchTerm) || barcode.includes(searchTerm) ||
                            brand.includes(searchTerm) || model.includes(searchTerm) ||
                            origin.includes(searchTerm) || remark.includes(searchTerm);

        const matchCategory = (selectedCategory === 'all' || category === selectedCategory);

        let matchStock = true;
        if (activeStockFilter !== 'all') {
            matchStock = (stock === activeStockFilter);
        }

        if (matchSearch && matchCategory && matchStock) {
            visibleRows.push(row);
        } else {
            row.style.display = 'none';
        }
    });

    currentPage = 1;
    renderPagination();
}

// 📌 ຟັງຊັ່ນ Render Pagination (10 ລາຍການ/ໜ້າ)
function renderPagination() {
    const totalItems = visibleRows.length;
    const totalPages = Math.ceil(totalItems / rowsPerPage) || 1;
    
    if (currentPage > totalPages) currentPage = totalPages;
    if (currentPage < 1) currentPage = 1;

    const startIdx = (currentPage - 1) * rowsPerPage;
    const endIdx = startIdx + rowsPerPage;

    // ເຊື່ອງ/ສະແດງ ແຖວຕາມ Pagination
    const allRows = document.querySelectorAll('#tableBody .item-row');
    allRows.forEach(row => row.style.display = 'none');

    visibleRows.slice(startIdx, endIdx).forEach(row => {
        row.style.display = '';
    });

    // ອັບເດດ Pagination Info Text
    const infoText = document.getElementById('paginationInfo');
    if (totalItems > 0) {
        const showingFrom = startIdx + 1;
        const showingTo = Math.min(endIdx, totalItems);
        infoText.textContent = `ສະແດງ ${showingFrom} ຫາ ${showingTo} ຈາກທັງໝົດ ${totalItems} ລາຍການ (ໜ້າ ${currentPage}/${totalPages})`;
    } else {
        infoText.textContent = `ສະແດງ 0 ຫາ 0 ຈາກທັງໝົດ 0 ລາຍການ`;
    }

    // ສ້າງ ປຸ່ມ Pagination
    const btnContainer = document.getElementById('paginationButtons');
    btnContainer.innerHTML = '';

    if (totalPages <= 1) return;

    // ປຸ່ມ ກ່ອນໜ້າ
    const prevBtn = document.createElement('button');
    prevBtn.className = `px-3 py-1 rounded-lg text-xs font-medium border transition ${currentPage === 1 ? 'bg-gray-100 text-gray-400 cursor-not-allowed' : 'bg-white hover:bg-gray-100 text-gray-700'}`;
    prevBtn.innerHTML = '<i class="fas fa-chevron-left mr-1"></i> ກ່ອນໜ້າ';
    prevBtn.disabled = currentPage === 1;
    prevBtn.onclick = () => { currentPage--; renderPagination(); };
    btnContainer.appendChild(prevBtn);

    // ປຸ່ມ ຕົວເລກໜ້າ
    for (let i = 1; i <= totalPages; i++) {
        if (i === 1 || i === totalPages || (i >= currentPage - 1 && i <= currentPage + 1)) {
            const pageBtn = document.createElement('button');
            pageBtn.className = `px-3 py-1 rounded-lg text-xs font-bold transition ${i === currentPage ? 'bg-blue-600 text-white' : 'bg-white border text-gray-700 hover:bg-gray-100'}`;
            pageBtn.textContent = i;
            pageBtn.onclick = () => { currentPage = i; renderPagination(); };
            btnContainer.appendChild(pageBtn);
        } else if (i === currentPage - 2 || i === currentPage + 2) {
            const dots = document.createElement('span');
            dots.className = 'px-1 text-xs text-gray-400';
            dots.textContent = '...';
            btnContainer.appendChild(dots);
        }
    }

    // ປຸ່ມ ໜ້າຖັດໄປ
    const nextBtn = document.createElement('button');
    nextBtn.className = `px-3 py-1 rounded-lg text-xs font-medium border transition ${currentPage === totalPages ? 'bg-gray-100 text-gray-400 cursor-not-allowed' : 'bg-white hover:bg-gray-100 text-gray-700'}`;
    nextBtn.innerHTML = 'ໜ້າຖັດໄປ <i class="fas fa-chevron-right ml-1"></i>';
    nextBtn.disabled = currentPage === totalPages;
    nextBtn.onclick = () => { currentPage++; renderPagination(); };
    btnContainer.appendChild(nextBtn);
}

function openAddModal() {
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus-circle text-blue-500 mr-2"></i>ເພີ່ມອຸປະກອນ';
    document.getElementById('itemForm').reset();
    document.getElementById('edit_id').value = '';
    document.getElementById('item_code').value = '';
    document.getElementById('serial_number').value = '';
    document.getElementById('quantity').value = '1';
    document.getElementById('min_quantity').value = '1';
    document.getElementById('unit').value = '';
    document.getElementById('import_date').value = '<?php echo date('Y-m-d'); ?>';
    document.getElementById('warranty_years').value = '0';
    document.getElementById('service_tax').value = '0.00';
    document.getElementById('manufacturing_year').value = '';
    
    document.getElementById('barcode_display_container').classList.add('hidden');
    document.getElementById('imagePreview').classList.add('hidden');
    document.getElementById('docPreview').classList.add('hidden');
    document.getElementById('submitBtn').name = 'add_item';
    document.getElementById('itemModal').classList.remove('hidden');
}

function openReceiveExistingModal() {
    document.getElementById('select_category_for_existing').value = '';
    document.getElementById('existing_item_code').value = '';
    document.getElementById('existing_info_preview').classList.add('hidden');
    
    filterExistingItemsByCategory();
    
    document.getElementById('receiveExistingModal').classList.remove('hidden');
}

function closeReceiveExistingModal() {
    document.getElementById('receiveExistingModal').classList.add('hidden');
}

function filterExistingItemsByCategory() {
    const selectedCategory = document.getElementById('select_category_for_existing').value;
    const itemSelect = document.getElementById('existing_item_code');
    const options = itemSelect.querySelectorAll('option');

    itemSelect.value = '';
    document.getElementById('existing_info_preview').classList.add('hidden');

    options.forEach(opt => {
        if (!opt.value) {
            opt.style.display = '';
            return;
        }

        const optCategory = opt.dataset.category || '';
        if (!selectedCategory || optCategory === selectedCategory) {
            opt.style.display = '';
        } else {
            opt.style.display = 'none';
        }
    });
}

function autoFillExistingInfo(selectElem) {
    const selectedOption = selectElem.options[selectElem.selectedIndex];
    const previewBox = document.getElementById('existing_info_preview');
    
    if (selectElem.value) {
        document.getElementById('prev_name').textContent = selectedOption.dataset.name || '-';
        document.getElementById('prev_brand').textContent = selectedOption.dataset.brand || '-';
        document.getElementById('prev_unit').textContent = selectedOption.dataset.unit || '-';
        previewBox.classList.remove('hidden');
    } else {
        previewBox.classList.add('hidden');
    }
}

function editItem(item) {
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit text-yellow-500 mr-2"></i>ແກ້ໄຂອຸປະກອນ';
    document.getElementById('edit_id').value = item.id;
    document.getElementById('item_code').value = item.item_code || '';
    document.getElementById('serial_number').value = item.serial_number || '';
    
    document.getElementById('display_barcode').value = item.barcode || '';
    document.getElementById('barcode_display_container').classList.remove('hidden');

    document.getElementById('name').value = item.name || item.item_name || '';
    document.getElementById('brand').value = item.brand || '';
    document.getElementById('model').value = item.model || '';
    document.getElementById('category').value = item.cate_id || '';
    document.getElementById('unit').value = item.unit || '';
    
    document.getElementById('quantity').value = item.quantity || 0;
    document.getElementById('min_quantity').value = item.min_quantity || 1;
    document.getElementById('import_date').value = item.import_date || '<?php echo date('Y-m-d'); ?>';
    document.getElementById('manufacturing_year').value = item.manufacturing_year || '';
    document.getElementById('service_tax').value = item.service_tax || '0.00';
    document.getElementById('origin').value = item.origin || '';
    document.getElementById('warranty_years').value = item.warranty_years || 0;
    document.getElementById('remark').value = item.remark || '';
    
    document.getElementById('submitBtn').name = 'edit_item';
    
    // Preview ຮູບພາບ ຈາກ Database BLOB Stream
    const imgPreview = document.getElementById('imagePreview');
    const imgContainer = document.getElementById('imagePreviewContainer');
    if (item.has_image == 1) {
        const blobUrl = `<?php echo htmlspecialchars(appUrl('admin.php'), ENT_QUOTES); ?>?admin=items&get_blob=${item.id}&type=image`;
        imgContainer.innerHTML = `<img src="${blobUrl}" class="w-10 h-10 object-cover rounded border"> <span class="text-xs text-emerald-600 font-semibold">ມີຮູບພາບໃນ Database ແລ້ວ</span>`;
        imgPreview.classList.remove('hidden');
    } else {
        imgPreview.classList.add('hidden');
    }

    // Preview ເອກະສານ ຈາກ Database BLOB Stream
    const docPreview = document.getElementById('docPreview');
    const docContainer = document.getElementById('docPreviewContainer');
    if (item.has_doc == 1) {
        const ext = (item.doc_name || 'DOC').split('.').pop().toUpperCase();
        docContainer.innerHTML = `<div class="px-2 py-1 bg-blue-100 text-blue-600 rounded text-xs font-bold">${ext}</div> <span class="text-xs text-gray-700 font-medium truncate">${item.doc_name || 'ຟາຍໃນ Database'}</span>`;
        docPreview.classList.remove('hidden');
    } else {
        docPreview.classList.add('hidden');
    }
    
    document.getElementById('itemModal').classList.remove('hidden');
}

function openDeleteModal(id, name) {
    document.getElementById('delete_item_id').value = id;
    document.getElementById('delete_item_name').textContent = name;
    document.getElementById('deleteModal').classList.remove('hidden');
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.add('hidden');
}

function viewEditHistory(editHistoryArr) {
    const list = document.getElementById('editHistoryList');
    list.innerHTML = '';
    if (editHistoryArr && editHistoryArr.length > 0) {
        const reversedArr = [...editHistoryArr].reverse();
        reversedArr.forEach(h => {
            let changesHtml = '';
            if (h.changes && h.changes.length > 0) {
                changesHtml = '<ul class="mt-2 space-y-1 pl-1 border-t border-amber-200/60 pt-2 text-[11px]">';
                h.changes.forEach(c => {
                    changesHtml += `
                        <li class="flex flex-wrap items-center gap-1 text-gray-700">
                            <span class="font-semibold text-amber-900">• ${c.field}:</span>
                            <span class="text-red-500 line-through bg-red-50 px-1 rounded">${c.old}</span>
                            <span class="text-gray-400">➔</span>
                            <span class="text-green-600 font-semibold bg-green-50 px-1 rounded">${c.new}</span>
                        </li>
                    `;
                });
                changesHtml += '</ul>';
            } else {
                changesHtml = '<p class="text-[11px] text-gray-500 italic mt-1">(ບໍ່ມີຂໍ້ມູນທີ່ຈະສະແດງ)</p>';
            }

            list.innerHTML += `
                <div class="p-3 bg-amber-50/70 rounded-xl border border-amber-200/80 text-xs shadow-sm">
                    <div class="flex justify-between items-center text-amber-900 font-semibold border-b border-amber-200/40 pb-1.5">
                        <span><i class="far fa-user mr-1 text-amber-700"></i> ${h.updated_by}</span>
                        <span class="text-[11px] font-normal text-amber-800"><i class="far fa-clock mr-1"></i> ${h.updated_at}</span>
                    </div>
                    ${changesHtml}
                </div>
            `;
        });
    } else {
        list.innerHTML = '<p class="text-center text-gray-500 py-4">ບໍ່ມີປະຫວັດການແກ້ໄຂ</p>';
    }
    document.getElementById('editHistoryModal').classList.remove('hidden');
}

function closeEditHistoryModal() { document.getElementById('editHistoryModal').classList.add('hidden'); }
function closeModal() { document.getElementById('itemModal').classList.add('hidden'); }

function openRestockModal(id, name, currentQty, currentUnit = '') {
    document.getElementById('restock_item_id').value = id;
    document.getElementById('restock_item_name').value = name;
    document.getElementById('restock_current_qty').value = currentQty + ' ' + currentUnit;
    document.getElementById('restock_unit').value = currentUnit;
    document.getElementById('restockModal').classList.remove('hidden');
}

function closeRestockModal() { document.getElementById('restockModal').classList.add('hidden'); }

// 📌 LOGIC ປັບ QR CODE ຂະໜາດ
let currentQRDetailUrl = '';
let currentQRBarcode = '';

function showQRCode(detailUrl, barcode, name, remark = '') {
    currentQRDetailUrl = detailUrl;
    currentQRBarcode = barcode;
    
    document.getElementById('qrBarcode').textContent = 'Barcode: ' + barcode;
    document.getElementById('qrName').textContent = name;
    document.getElementById('qrRemark').textContent = remark ? 'ໝາຍເຫດ: ' + remark : '';
    
    // ເອີ້ນຟັງຊັ່ນອັບເດດຮູບ QR ຕາມຂະໜາດທີ່ເລືອກ
    changeQRSize();
    
    document.getElementById('qrModal').classList.remove('hidden');
}

function changeQRSize() {
    const selectedSize = document.getElementById('qrSizeSelect').value;
    const qrImage = document.getElementById('qrImage');
    qrImage.src = `https://api.qrserver.com/v1/create-qr-code/?size=${selectedSize}x${selectedSize}&data=` + encodeURIComponent(currentQRDetailUrl);
}

function closeQRModal() { document.getElementById('qrModal').classList.add('hidden'); }

async function downloadQR() {
    const qrUrl = document.getElementById('qrImage').src;
    const fileName = 'qrcode_' + (currentQRBarcode || 'download') + '.png';
    try {
        const response = await fetch(qrUrl);
        const blob = await response.blob();
        const blobUrl = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = blobUrl; link.download = fileName;
        document.body.appendChild(link); link.click(); document.body.removeChild(link);
        URL.revokeObjectURL(blobUrl);
    } catch (e) {
        window.open(qrUrl, '_blank');
    }
}

function printQR() {
    const printWindow = window.open('', '_blank', 'width=500,height=600');
    printWindow.document.write(`
        <!DOCTYPE html><html><head><title>QR Code</title><style>body{font-family:sans-serif;text-align:center;padding:20px;}</style></head>
        <body>
            <img src="${document.getElementById('qrImage').src}" style="max-width:300px;" />
            <p><strong>${document.getElementById('qrBarcode').textContent}</strong></p>
            <p>${document.getElementById('qrName').textContent}</p>
            <script>window.onload=function(){window.print();window.close();}<\/script>
        </body></html>
    `);
    printWindow.document.close();
}

function printBarcode(barcode, name) {
    if (!barcode) return alert('ບໍ່ມີ Barcode');
    const printWindow = window.open('', '_blank', 'width=400,height=400');
    printWindow.document.write(`
        <!DOCTYPE html><html><head><title>Barcode</title><style>body{font-family:sans-serif;text-align:center;padding:20px;}</style></head>
        <body><img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(barcode)}" /><p>${name}</p><script>window.onload=function(){window.print();window.close();}<\/script></body></html>
    `);
    printWindow.document.close();
}

function previewImageFile(input) {
    const preview = document.getElementById('imagePreview');
    const container = document.getElementById('imagePreviewContainer');
    
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            container.innerHTML = `<img src="${e.target.result}" class="w-10 h-10 object-cover rounded border"> <span class="text-xs text-gray-600 truncate">${input.files[0].name}</span>`;
            preview.classList.remove('hidden');
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function previewDocFile(input) {
    const preview = document.getElementById('docPreview');
    const container = document.getElementById('docPreviewContainer');
    
    if (input.files && input.files[0]) {
        const ext = input.files[0].name.split('.').pop().toUpperCase();
        container.innerHTML = `<div class="px-2 py-1 bg-red-100 text-red-600 rounded text-xs font-bold">${ext}</div> <span class="text-xs text-gray-700 font-medium truncate">${input.files[0].name}</span>`;
        preview.classList.remove('hidden');
    }
}

function exportItems() {
    const type = prompt("ກະລຸນາພິມປະເພດ 'csv' ເພື່ອສົ່ງອອກຂໍ້ມູນ:", "csv");
    if (type) window.location.href = '?admin=items&export=' + type;
}

// ໂຫຼດ Pagination ເມື່ອເລີ່ມຕົ້ນ
document.addEventListener('DOMContentLoaded', () => {
    filterData();
});

document.getElementById('imageViewerModal').addEventListener('click', function(e) { if (e.target === this) closeImageViewerModal(); });
document.getElementById('documentViewerModal').addEventListener('click', function(e) { if (e.target === this) closeDocumentViewerModal(); });
document.getElementById('itemModal').addEventListener('click', function(e) { if (e.target === this) closeModal(); });
document.getElementById('receiveExistingModal').addEventListener('click', function(e) { if (e.target === this) closeReceiveExistingModal(); });
document.getElementById('restockModal').addEventListener('click', function(e) { if (e.target === this) closeRestockModal(); });
document.getElementById('deleteModal').addEventListener('click', function(e) { if (e.target === this) closeDeleteModal(); });
document.getElementById('qrModal').addEventListener('click', function(e) { if (e.target === this) closeQRModal(); });
document.getElementById('editHistoryModal').addEventListener('click', function(e) { if (e.target === this) closeEditHistoryModal(); });
</script>

<style>
.form-input { width: 100%; padding: 0.5rem 0.75rem; border-radius: 0.5rem; border: 1px solid #D1D5DB; outline: none; background-color: #ffffff; }
.form-input:focus { border-color: #3B82F6; box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2); }
.btn-primary { background-color: #2563EB; color: white; padding: 0.5rem 1rem; border-radius: 0.5rem; font-weight: 500; }
.btn-primary:hover { background-color: #1D4ED8; }
.card { background-color: white; border-radius: 1rem; padding: 1.5rem; box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1); }
</style>