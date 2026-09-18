<?php
/**
 * Inventory domain rules.
 * Canonical meaning:
 *   items.quantity         = usable stock in the central warehouse
 *   items.damaged_quantity = damaged stock in the central warehouse
 *   item_department_stock = stock currently held by each department
 * Every stock-changing operation must use these functions inside a DB transaction.
 */

function inventoryDepartmentId(PDO $pdo, string $departmentName): int
{
    $departmentName = trim($departmentName);
    if ($departmentName === '') {
        throw new InvalidArgumentException('ບໍ່ລະບຸພະແນກ');
    }
    $stmt = $pdo->prepare('SELECT id FROM departments WHERE dept_name = ? LIMIT 1');
    $stmt->execute([$departmentName]);
    $id = (int)$stmt->fetchColumn();
    if ($id <= 0) {
        throw new RuntimeException('ບໍ່ພົບພະແນກ: ' . $departmentName);
    }
    return $id;
}

function inventoryEnsureDepartmentRow(PDO $pdo, int $itemId, int $departmentId): void
{
    $stmt = $pdo->prepare('INSERT INTO item_department_stock (item_id, department_id, quantity, damaged_quantity) VALUES (?, ?, 0, 0) ON DUPLICATE KEY UPDATE item_id = VALUES(item_id)');
    $stmt->execute([$itemId, $departmentId]);
}

function inventoryGetDepartmentStock(PDO $pdo, int $itemId, int $departmentId, bool $forUpdate = true): array
{
    inventoryEnsureDepartmentRow($pdo, $itemId, $departmentId);
    $sql = 'SELECT * FROM item_department_stock WHERE item_id = ? AND department_id = ?';
    if ($forUpdate) $sql .= ' FOR UPDATE';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$itemId, $departmentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('ບໍ່ພົບຍອດ Stock ຂອງພະແນກ');
    return $row;
}

function inventoryGetItem(PDO $pdo, int $itemId, bool $forUpdate = true): array
{
    $sql = 'SELECT * FROM items WHERE id = ?';
    if ($forUpdate) $sql .= ' FOR UPDATE';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$itemId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$item) throw new RuntimeException('ບໍ່ພົບອຸປະກອນ');
    return $item;
}

function inventoryMovement(PDO $pdo, int $itemId, string $type, int $qty, int $oldQty, int $newQty, array $extra = []): void
{
    $sql = 'INSERT INTO stock_movements (item_id, movement_type, quantity, old_quantity, new_quantity, reference_no, from_department, to_department, from_location_id, to_location_id, serial_number, note, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())';
    $params = [
        $itemId, $type, $qty, $oldQty, $newQty,
        $extra['reference_no'] ?? null,
        $extra['from_department'] ?? null,
        $extra['to_department'] ?? null,
        $extra['from_location_id'] ?? null,
        $extra['to_location_id'] ?? null,
        $extra['serial_number'] ?? null,
        $extra['note'] ?? null,
        $extra['created_by'] ?? null,
    ];
    $pdo->prepare($sql)->execute($params);
}

function inventoryReceive(PDO $pdo, int $itemId, int $qty, string $reason, int $userId, ?string $referenceNo = null): void
{
    if ($qty <= 0) throw new InvalidArgumentException('ຈຳນວນຮັບເຂົ້າຕ້ອງຫຼາຍກວ່າ 0');
    $item = inventoryGetItem($pdo, $itemId, true);
    $old = (int)$item['quantity'];
    $new = $old + $qty;
    $pdo->prepare('UPDATE items SET quantity = quantity + ? WHERE id = ?')->execute([$qty, $itemId]);
    inventoryMovement($pdo, $itemId, 'receive', $qty, $old, $new, [
        'reference_no' => $referenceNo,
        'note' => $reason,
        'created_by' => $userId,
    ]);
}

function inventoryIssue(PDO $pdo, int $itemId, int $qty, string $departmentName, int $userId, string $referenceNo, string $note = ''): void
{
    if ($qty <= 0) throw new InvalidArgumentException('ຈຳນວນເບີກຕ້ອງຫຼາຍກວ່າ 0');
    $deptId = inventoryDepartmentId($pdo, $departmentName);
    $item = inventoryGetItem($pdo, $itemId, true);
    $old = (int)$item['quantity'];
    if ($old < $qty) throw new RuntimeException("Stock ກາງບໍ່ພໍ: ມີ {$old}, ຕ້ອງການ {$qty}");

    $st = $pdo->prepare('UPDATE items SET quantity = quantity - ? WHERE id = ? AND quantity >= ?');
    $st->execute([$qty, $itemId, $qty]);
    if ($st->rowCount() !== 1) throw new RuntimeException('ບໍ່ສາມາດຫັກ Stock ໄດ້');

    inventoryEnsureDepartmentRow($pdo, $itemId, $deptId);
    $pdo->prepare('UPDATE item_department_stock SET quantity = quantity + ? WHERE item_id = ? AND department_id = ?')->execute([$qty, $itemId, $deptId]);

    inventoryMovement($pdo, $itemId, 'issue', $qty, $old, $old - $qty, [
        'reference_no' => $referenceNo,
        'to_department' => $departmentName,
        'note' => $note,
        'created_by' => $userId,
        'serial_number' => $item['serial_number'] ?? null,
    ]);
}

function inventoryReturn(PDO $pdo, int $itemId, int $qty, string $departmentName, string $condition, int $userId, string $referenceNo, string $note = ''): void
{
    if ($qty <= 0) throw new InvalidArgumentException('ຈຳນວນຮັບຄືນຕ້ອງຫຼາຍກວ່າ 0');
    $deptId = inventoryDepartmentId($pdo, $departmentName);
    $condition = in_array($condition, ['good', 'damaged', 'broken'], true) ? $condition : 'good';
    // Lock order is item -> department everywhere to reduce deadlock risk.
    $item = inventoryGetItem($pdo, $itemId, true);
    $dept = inventoryGetDepartmentStock($pdo, $itemId, $deptId, true);
    if ((int)$dept['quantity'] < $qty) {
        throw new RuntimeException('ພະແນກມີອຸປະກອນດີບໍ່ພໍສຳລັບຮັບຄືນ');
    }

    $stDept = $pdo->prepare('UPDATE item_department_stock SET quantity = quantity - ? WHERE item_id = ? AND department_id = ? AND quantity >= ?');
    $stDept->execute([$qty, $itemId, $deptId, $qty]);
    if ($stDept->rowCount() !== 1) throw new RuntimeException('ບໍ່ສາມາດຫັກ Stock ພະແນກໄດ້');
    $old = (int)$item['quantity'];

    if ($condition === 'good') {
        $new = $old + $qty;
        $pdo->prepare('UPDATE items SET quantity = quantity + ? WHERE id = ?')->execute([$qty, $itemId]);
    } else {
        $new = $old;
        $pdo->prepare('UPDATE items SET damaged_quantity = COALESCE(damaged_quantity,0) + ? WHERE id = ?')->execute([$qty, $itemId]);
    }

    inventoryMovement($pdo, $itemId, 'return', $qty, $old, $new, [
        'reference_no' => $referenceNo,
        'from_department' => $departmentName,
        'note' => ($condition === 'good' ? 'ຮັບຄືນສະພາບດີ: ' : 'ຮັບຄືນສະພາບຊຳລຸດ: ') . $note,
        'created_by' => $userId,
        'serial_number' => $item['serial_number'] ?? null,
    ]);
}

function inventoryDisposeDamaged(PDO $pdo, int $itemId, int $qty, int $userId, string $reason, string $referenceNo): void
{
    if ($qty <= 0) throw new InvalidArgumentException('ຈຳນວນສະສາງຕ້ອງຫຼາຍກວ່າ 0');
    $item = inventoryGetItem($pdo, $itemId, true);
    $damaged = (int)($item['damaged_quantity'] ?? 0);
    if ($damaged < $qty) throw new RuntimeException("ອຸປະກອນຊຳລຸດບໍ່ພໍ: ມີ {$damaged}, ຕ້ອງການ {$qty}");
    $pdo->prepare('UPDATE items SET damaged_quantity = damaged_quantity - ? WHERE id = ? AND damaged_quantity >= ?')->execute([$qty, $itemId, $qty]);
    inventoryMovement($pdo, $itemId, 'dispose', $qty, (int)$item['quantity'], (int)$item['quantity'], [
        'reference_no' => $referenceNo,
        'note' => $reason,
        'created_by' => $userId,
    ]);
}

function inventoryAdjust(PDO $pdo, int $itemId, string $adjustmentType, int $qty, int $userId, string $reason): void
{
    if ($qty <= 0) throw new InvalidArgumentException('ຈຳນວນຕ້ອງຫຼາຍກວ່າ 0');
    if (mb_strlen(trim($reason)) < 5) throw new InvalidArgumentException('ກະລຸນາລະບຸເຫດຜົນການປັບ Stock ຢ່າງໜ້ອຍ 5 ຕົວອັກສອນ');
    $item = inventoryGetItem($pdo, $itemId, true);
    $old = (int)$item['quantity'];
    
    if ($adjustmentType === 'add') {
        $newQty = $old + $qty;
        $type = 'count';
    } elseif ($adjustmentType === 'sub') {
        $newQty = $old - $qty;
        if ($newQty < 0) throw new RuntimeException("Stock ບໍ່ພໍໃຫ້ຫັກ (ມີ: {$old}, ຕ້ອງການຫັກ: {$qty})");
        $type = 'count';
    } else {
        throw new InvalidArgumentException('ປະເພດການປັບປຸງບໍ່ຖືກຕ້ອງ');
    }
    
    $pdo->prepare('UPDATE items SET quantity = ? WHERE id = ?')->execute([$newQty, $itemId]);
    
    inventoryMovement($pdo, $itemId, $type, $qty, $old, $newQty, [
        'note' => "ປັບປຸງ Stock: {$reason}",
        'created_by' => $userId,
    ]);
    
    // The existing production DB uses columns: item_id, quantity, type, reason, created_by.
    $pdo->prepare('INSERT INTO stock_adjustments (item_id, quantity, type, reason, created_by) VALUES (?,?,?,?,?)')
        ->execute([$itemId, $qty, $adjustmentType === 'add' ? 'add' : 'subtract', $reason, $userId]);
}
