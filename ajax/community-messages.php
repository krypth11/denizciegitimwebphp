<?php
header('Content-Type: application/json; charset=utf-8');

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/community_helper.php';

$admin = require_admin();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

function cm_json(bool $success, string $message = '', array $data = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $msg = community_message_schema($pdo);
    $room = community_room_schema($pdo);

    if ($action === 'list') {
        $roomId = trim((string)($_GET['room_id'] ?? ''));
        $userId = trim((string)($_GET['user_id'] ?? ''));

        $profile = community_profile_schema($pdo);
        $fullNameExpr = $profile['full_name'] ? "u.`{$profile['full_name']}`" : "''";

        $sql = "SELECT m.`{$msg['id']}` AS id, m.`{$msg['room_id']}` AS room_id, m.`{$msg['user_id']}` AS user_id, m.`{$msg['message_text']}` AS message_text, "
            . "m.`{$msg['is_deleted']}` AS is_deleted, "
            . ($msg['deleted_at'] ? "m.`{$msg['deleted_at']}` AS deleted_at, " : 'NULL AS deleted_at, ')
            . "m.`{$msg['created_at']}` AS created_at, r.`{$room['name']}` AS room_name, {$fullNameExpr} AS user_full_name, u.`{$profile['email']}` AS user_email "
            . "FROM `{$msg['table']}` m "
            . "LEFT JOIN `{$room['table']}` r ON r.`{$room['id']}` = m.`{$msg['room_id']}` "
            . "LEFT JOIN `{$profile['table']}` u ON u.`{$profile['id']}` = m.`{$msg['user_id']}` WHERE 1=1";

        $params = [];
        if ($roomId !== '') {
            $sql .= " AND m.`{$msg['room_id']}` = ?";
            $params[] = $roomId;
        }
        if ($userId !== '') {
            $sql .= " AND m.`{$msg['user_id']}` = ?";
            $params[] = $userId;
        }

        $sql .= " ORDER BY m.`{$msg['created_at']}` DESC LIMIT 300";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $messages = array_map(static function (array $r): array {
            $text = (string)($r['message_text'] ?? '');
            $preview = mb_strlen($text, 'UTF-8') > 120 ? mb_substr($text, 0, 120, 'UTF-8') . '…' : $text;
            return [
                'id' => (string)$r['id'],
                'room_id' => (string)$r['room_id'],
                'room_name' => (string)($r['room_name'] ?? '-'),
                'user_id' => (string)$r['user_id'],
                'user_name' => (string)($r['user_full_name'] ?: $r['user_email']),
                'message_preview' => $preview,
                'is_deleted' => (int)($r['is_deleted'] ?? 0) === 1 ? 1 : 0,
                'deleted_at' => $r['deleted_at'] ?? null,
                'created_at' => $r['created_at'] ?? null,
            ];
        }, $rows);

        cm_json(true, '', ['messages' => $messages]);
    }

    if ($action === 'delete') {
        $id = trim((string)($_POST['id'] ?? ''));
        if ($id === '') cm_json(false, 'ID gerekli.', [], 422);

        $set = ["`{$msg['is_deleted']}` = 1"];
        $vals = [];
        if ($msg['deleted_at']) {
            $set[] = "`{$msg['deleted_at']}` = ?";
            $vals[] = community_now();
        }
        if ($msg['deleted_by_admin_id']) {
            $set[] = "`{$msg['deleted_by_admin_id']}` = ?";
            $vals[] = (string)($admin['user_id'] ?? '');
        }
        $vals[] = $id;

        $sql = "UPDATE `{$msg['table']}` SET " . implode(', ', $set) . " WHERE `{$msg['id']}` = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($vals);
        cm_json(true, 'Mesaj moderasyon nedeniyle kaldırıldı.');
    }

    cm_json(false, 'Geçersiz işlem.', [], 400);
} catch (Throwable $e) {
    cm_json(false, 'İşlem sırasında bir hata oluştu.', [], 500);
}
