<?php
declare(strict_types=1);

function getSCTProcessDetails(PDO $pdo, int $processId): ?array
{
    $stmt=$pdo->prepare("SELECT r.*, ur.fullname AS requester_name, uv.fullname AS receiver_name, ua.fullname AS approved_by_name, ui.fullname AS issued_by_name, d.dept_name AS department_name FROM requests r LEFT JOIN users ur ON ur.id=r.user_id LEFT JOIN users uv ON uv.id=r.receiver_id LEFT JOIN users ua ON ua.id=r.approved_by LEFT JOIN users ui ON ui.id=r.issued_by LEFT JOIN departments d ON d.dept_name=r.department WHERE r.id=? AND r.process_type='sct' LIMIT 1");
    $stmt->execute([$processId]); $process=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$process) return null;
    $stmt=$pdo->prepare("SELECT ri.*, i.item_code, i.name, i.unit AS item_unit, i.quantity AS current_stock, i.usable_quantity, i.damaged_quantity FROM request_items ri JOIN items i ON i.id=ri.item_id WHERE ri.request_id=? ORDER BY ri.id");
    $stmt->execute([$processId]); $process['materials']=$stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt=$pdo->prepare("SELECT sit.*, i.name, i.unit FROM sct_issue_transactions sit JOIN items i ON i.id=sit.item_id WHERE sit.process_id=? ORDER BY sit.id");
    $stmt->execute([$processId]); $process['issue_transactions']=$stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt=$pdo->prepare("SELECT h.*, u.fullname AS changed_by_name FROM sct_approval_history h LEFT JOIN users u ON u.id=h.changed_by WHERE h.process_id=? ORDER BY h.id ASC");
    $stmt->execute([$processId]); $process['history']=$stmt->fetchAll(PDO::FETCH_ASSOC);
    return $process;
}

function logSCTStatusChange(PDO $pdo, int $processId, string $old, string $new, string $action, ?int $userId, ?string $reason=null): void
{
    $pdo->prepare('INSERT INTO sct_approval_history (process_id,old_status,new_status,action,changed_by,reason) VALUES (?,?,?,?,?,?)')->execute([$processId,$old,$new,$action,$userId,$reason]);
}

function createSCTProcess(PDO $pdo, array $data): array
{
    $userId=(int)$data['user_id']; $receiverId=(int)$data['receiver_id']; $department=trim((string)$data['department']);
    $purpose=trim((string)$data['purpose']); $note=trim((string)($data['note']??'')); $materials=$data['materials']??[];
    if($userId<=0||$receiverId<=0||$department===''||$purpose===''||!$materials) return ['success'=>false,'message'=>'Invalid SCT data'];
    try {
        $pdo->beginTransaction();
        $u=$pdo->prepare('SELECT id FROM users WHERE id=? AND is_active=1'); $u->execute([$userId]); if(!$u->fetch()) throw new RuntimeException('Invalid requester');
        $r=$pdo->prepare('SELECT id FROM users WHERE id=? AND is_active=1'); $r->execute([$receiverId]); if(!$r->fetch()) throw new RuntimeException('Invalid receiver');
        $d=$pdo->prepare('SELECT id FROM departments WHERE dept_name=? LIMIT 1'); $d->execute([$department]); if(!$d->fetch()) throw new RuntimeException('Invalid department');
        $processNumber='SCT-'.date('Ym').'-'.strtoupper(bin2hex(random_bytes(4)));
        $stmt=$pdo->prepare("INSERT INTO requests (process_number,user_id,receiver_id,request_date,department,purpose,status,process_type,note,created_at) VALUES (?,?,?,?,?,?, 'pending','sct',?,NOW())");
        $stmt->execute([$processNumber,$userId,$receiverId,date('Y-m-d'),$department,$purpose,$note]); $processId=(int)$pdo->lastInsertId();
        $ins=$pdo->prepare('INSERT INTO request_items (request_id,item_id,quantity,approved_quantity,issued_quantity,unit,note) VALUES (?,?,?,NULL,NULL,?,?)');
        foreach($materials as $m){$itemId=(int)$m['item_id'];$qty=(int)$m['quantity'];if($itemId<=0||$qty<=0)throw new RuntimeException('Invalid material quantity');$q=$pdo->prepare('SELECT unit FROM items WHERE id=? AND is_active=1');$q->execute([$itemId]);$unit=$q->fetchColumn();if($unit===false)throw new RuntimeException('Invalid material');$ins->execute([$processId,$itemId,$qty,$unit,trim((string)($m['note']??''))]);}
        logSCTStatusChange($pdo,$processId,'pending','pending','submit',$userId);
        $pdo->commit(); return ['success'=>true,'message'=>'SCT process created successfully','process_id'=>$processId];
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('SCT create: '.$e->getMessage());return ['success'=>false,'message'=>'Unable to create SCT process'];}
}

function issueSCTMaterials(PDO $pdo,int $processId,int $userId,array $requested): array
{
    if(!$requested) return ['success'=>false,'message'=>'No quantities supplied'];
    try { $pdo->beginTransaction();
        $p=$pdo->prepare("SELECT * FROM requests WHERE id=? AND process_type='sct' FOR UPDATE");$p->execute([$processId]);$process=$p->fetch();if(!$process||$process['status']!=='approved')throw new RuntimeException('Process is not ready for issue');
        $seen=[];
        foreach($requested as $row){$riId=(int)$row['request_item_id'];$qty=(int)$row['issued_qty'];if(isset($seen[$riId]))throw new RuntimeException('Duplicate request item');$seen[$riId]=1;if($qty<0)throw new RuntimeException('Invalid issue quantity');
            $q=$pdo->prepare('SELECT ri.*,i.name,i.quantity,i.quantity AS usable_quantity FROM request_items ri JOIN items i ON i.id=ri.item_id WHERE ri.id=? AND ri.request_id=? FOR UPDATE');$q->execute([$riId,$processId]);$ri=$q->fetch();if(!$ri)throw new RuntimeException('Invalid request item');$max=(int)$ri['quantity'];if($qty>$max)throw new RuntimeException('Issued quantity exceeds requested quantity');if($qty===0)continue;
            $stock=(int)$ri['usable_quantity'];if($stock<$qty)throw new RuntimeException('Insufficient stock');
            $old=(int)$ri['quantity'];
            $dept=$process['department']; inventoryIssue($pdo,(int)$ri['item_id'],$qty,$dept,$userId,(string)$process['process_number'],'SCT '.$process['process_number']);
            $pdo->prepare('UPDATE request_items SET approved_quantity=?, issued_quantity=? WHERE id=?')->execute([$qty,$qty,$riId]);
            $pdo->prepare('INSERT INTO sct_issue_transactions (process_id,request_item_id,item_id,stock_before,issued_quantity,stock_after,issued_by) VALUES (?,?,?,?,?,?,?)')->execute([$processId,$riId,(int)$ri['item_id'],$stock,$qty,$stock-$qty,$userId]);
        }
        $check=$pdo->prepare('SELECT COUNT(*) FROM request_items WHERE request_id=? AND COALESCE(issued_quantity,0)>0');$check->execute([$processId]);if((int)$check->fetchColumn()===0)throw new RuntimeException('Nothing issued');
        $pdo->prepare("UPDATE requests SET status='issued', issued_by=?, issued_at=NOW() WHERE id=? AND status='approved'")->execute([$userId,$processId]);
        logSCTStatusChange($pdo,$processId,'approved','issued','issue',$userId);$pdo->commit();return ['success'=>true,'message'=>'Materials issued successfully'];
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('SCT issue: '.$e->getMessage());return ['success'=>false,'message'=>$e instanceof RuntimeException?$e->getMessage():'Unable to issue materials'];}
}

function cancelSCTProcess(PDO $pdo,int $processId,int $userId,string $reason): array
{
    try{$pdo->beginTransaction();$p=$pdo->prepare("SELECT * FROM requests WHERE id=? AND process_type='sct' FOR UPDATE");$p->execute([$processId]);$process=$p->fetch();if(!$process)throw new RuntimeException('Process not found');if(!in_array($process['status'],['pending','approved','issued'],true))throw new RuntimeException('Process cannot be cancelled');
        if($process['status']==='issued'){ $q=$pdo->prepare('SELECT sit.*, i.unit FROM sct_issue_transactions sit JOIN items i ON i.id=sit.item_id WHERE sit.process_id=? FOR UPDATE');$q->execute([$processId]);foreach($q->fetchAll() as $tx){inventoryReturn($pdo,(int)$tx['item_id'],(int)$tx['issued_quantity'],$process['department'],'good',$userId,(string)$process['process_number'],'SCT cancellation');$pdo->prepare('INSERT INTO sct_return_transactions (process_id,issue_transaction_id,item_id,stock_before,returned_quantity,stock_after,return_reason,returned_by) VALUES (?,?,?,?,?,?,?,?)')->execute([$processId,(int)$tx['id'],(int)$tx['item_id'],(int)$tx['stock_after'],(int)$tx['issued_quantity'],(int)$tx['stock_after']+(int)$tx['issued_quantity'],$reason,$userId]);}}
        $pdo->prepare("UPDATE requests SET status='cancelled', admin_note=? WHERE id=?")->execute([$reason,$processId]);logSCTStatusChange($pdo,$processId,$process['status'],'cancelled','cancel',$userId,$reason);$pdo->commit();return ['success'=>true,'message'=>'SCT process cancelled'];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('SCT cancel: '.$e->getMessage());return ['success'=>false,'message'=>$e->getMessage()];}
}

function listSCTProcesses(PDO $pdo,array $filters,int $limit,int $offset):array
{
    $where=["r.process_type='sct'"]; $params=[]; if(!empty($filters['status'])){$where[]='r.status=?';$params[]=$filters['status'];} if(!empty($filters['search'])){$where[]='(r.process_number LIKE ? OR r.purpose LIKE ? OR u.fullname LIKE ?)';$v='%'.$filters['search'].'%';array_push($params,$v,$v,$v);}
    $limit=max(1,min(100,$limit));$offset=max(0,$offset);$sql="SELECT r.*,u.fullname AS requester_name,rv.fullname AS receiver_name,d.dept_name AS department_name,(SELECT COUNT(*) FROM request_items ri WHERE ri.request_id=r.id) item_count FROM requests r LEFT JOIN users u ON u.id=r.user_id LEFT JOIN users rv ON rv.id=r.receiver_id LEFT JOIN departments d ON d.dept_name=r.department WHERE ".implode(' AND ',$where).' ORDER BY r.created_at DESC LIMIT '.(int)$limit.' OFFSET '.(int)$offset; $st=$pdo->prepare($sql);$st->execute($params);return $st->fetchAll(PDO::FETCH_ASSOC);
}
function countSCTProcesses(PDO $pdo,array $filters):int{$where=["r.process_type='sct'"]; $params=[];if(!empty($filters['status'])){$where[]='r.status=?';$params[]=$filters['status'];}if(!empty($filters['search'])){$where[]='(r.process_number LIKE ? OR r.purpose LIKE ? OR u.fullname LIKE ?)';$v='%'.$filters['search'].'%';array_push($params,$v,$v,$v);} $st=$pdo->prepare('SELECT COUNT(*) FROM requests r LEFT JOIN users u ON u.id=r.user_id WHERE '.implode(' AND ',$where));$st->execute($params);return (int)$st->fetchColumn();}
