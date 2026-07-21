<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/admin_notification_helper.php';

header('Content-Type: application/json; charset=utf-8');
$admin = require_auth(true);
$adminId = (string)($admin['id'] ?? '');
$action = trim((string)($_REQUEST['action'] ?? 'list'));

function admin_inbox_json(bool $success, array $data = [], string $message = '', int $status = 200): void {
    http_response_code($status);
    echo json_encode(['success'=>$success,'message'=>$message,'data'=>$data], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if ($action === 'unread_count') {
        admin_inbox_json(true, ['count'=>admin_notification_unread_count($pdo, $adminId)]);
    }
    if ($action === 'mark_read') {
        $id=trim((string)($_POST['id'] ?? '')); if($id==='') admin_inbox_json(false,[],'id zorunludur.',422);
        admin_notification_mark_read($pdo,$id,$adminId); admin_inbox_json(true);
    }
    if ($action === 'mark_all_read') {
        $pdo->prepare("INSERT INTO admin_notification_reads(notification_id,admin_user_id,read_at)
            SELECT id,?,NOW() FROM admin_notifications WHERE status='open'
            ON DUPLICATE KEY UPDATE read_at=NOW()")->execute([$adminId]);
        admin_inbox_json(true);
    }
    if ($action === 'set_status') {
        $id=trim((string)($_POST['id'] ?? '')); $status=trim((string)($_POST['status'] ?? ''));
        if($id==='' || !in_array($status,['open','resolved','archived'],true)) admin_inbox_json(false,[],'Geçersiz işlem.',422);
        $pdo->prepare("UPDATE admin_notifications SET status=?,resolved_at=IF(?='resolved',NOW(),NULL),updated_at=NOW() WHERE id=?")
            ->execute([$status,$status,$id]);
        admin_notification_mark_read($pdo,$id,$adminId); admin_inbox_json(true);
    }

    $where=['n.status <> \'archived\'']; $params=[$adminId];
    $filter=trim((string)($_GET['filter'] ?? 'all'));
    if($filter==='unread') $where[]='r.read_at IS NULL';
    elseif(in_array($filter,['critical','high','normal','low'],true)){ $where[]='n.severity=?';$params[]=$filter; }
    elseif($filter!=='all'){ $where[]='n.source_type=?';$params[]=$filter; }
    $search=trim((string)($_GET['search'] ?? '')); if($search!==''){ $where[]='(n.title LIKE ? OR n.message LIKE ?)';$params[]="%$search%";$params[]="%$search%"; }
    $sql="SELECT n.*,IF(r.read_at IS NULL,0,1) is_read FROM admin_notifications n
          LEFT JOIN admin_notification_reads r ON r.notification_id=n.id AND r.admin_user_id=?
          WHERE ".implode(' AND ',$where)." ORDER BY FIELD(n.severity,'critical','high','normal','low'),n.created_at DESC LIMIT 300";
    $stmt=$pdo->prepare($sql);$stmt->execute($params);$items=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
    admin_inbox_json(true,['items'=>$items,'unread_count'=>admin_notification_unread_count($pdo,$adminId)]);
} catch(Throwable $e){ error_log('[admin-notifications] '.$e->getMessage()); admin_inbox_json(false,[],'Bildirimler alınamadı.',500); }

