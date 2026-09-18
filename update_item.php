<?php
// update_item.php
require_once __DIR__ . '/includes/config.php';
checkRole(['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePostCsrf();
    $id = $_POST['id'] ?? null;
    $new_name = $_POST['name'] ?? '';
    $new_cate_id = $_POST['cate_id'] ?? null;
    $new_quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT);

    if ($new_quantity === false || $new_quantity < 0 || !$id || trim($new_name) === '') {
        http_response_code(422);
        exit('Invalid input');
    }

    if ($id && $new_name) {
        try {
            // 1. ດຶງຂໍ້ມູນເກົ່າອອກມາກ່ອນ
            $stmt_old = $pdo->prepare("SELECT * FROM items WHERE id = :id");
            $stmt_old->execute([':id' => $id]);
            $old_item = $stmt_old->fetch(PDO::FETCH_ASSOC);

            if ($old_item) {
                // ເລີ່ມ Transaction
                $pdo->beginTransaction();

                // 2. ບັນທຶກປະວັດການແກ້ໄຂລົງໃນຕາຕະລາງ item_logs
                $stmt_log = $pdo->prepare("
                    INSERT INTO item_logs (item_id, old_name, new_name, old_cate_id, new_cate_id, old_quantity, new_quantity, edited_at)
                    VALUES (:item_id, :old_name, :new_name, :old_cate_id, :new_cate_id, :old_quantity, :new_quantity, NOW())
                ");
                $stmt_log->execute([
                    ':item_id'      => $id,
                    ':old_name'     => $old_item['name'] ?? $old_item['item_name'] ?? '',
                    ':new_name'     => $new_name,
                    ':old_cate_id'  => $old_item['cate_id'],
                    ':new_cate_id'  => $new_cate_id,
                    ':old_quantity' => $old_item['quantity'] ?? 0,
                    ':new_quantity' => $new_quantity
                ]);

                // 3. ອັບເດດຂໍ້ມູນໃໝ່ລົງໃນຕາຕະລາງ items
                $stmt_update = $pdo->prepare("
                    UPDATE items 
                    SET name = :name, 
                        cate_id = :cate_id, 
                        quantity = :quantity, 
                        updated_at = NOW() 
                    WHERE id = :id
                ");
                $stmt_update->execute([
                    ':name'     => $new_name,
                    ':cate_id'  => $new_cate_id,
                    ':quantity' => $new_quantity,
                    ':id'       => $id
                ]);

                // ຢືນຢັນການບັນທຶກ
                $pdo->commit();

                header('Location: admin.php?admin=items&status=success');
                exit;
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log($e->getMessage());
            http_response_code(500);
            exit("Unable to update item");
        }
    }
}
?>
