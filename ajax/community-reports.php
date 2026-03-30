<?php
header('Content-Type: application/json; charset=utf-8');

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/community_helper.php';

$admin = require_admin();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

function crp_json(bool $success, string $message = '', array $data = [], int $status = 200): void
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
    $report = community_report_schema($pdo);
    $msg = community_message_schema($pdo);
    $room = community_room_schema($pdo);
    $profile = community_profile_schema($pdo);
    $reportedByCol = $report['reported_by_user_id'] ?? $report['reporter_user_id'] ?? null;

    if ($action === 'list') {
        if (!$reportedByCol) {
            error_log('community-reports list schema error: reported_by_user_id kolonu bulunamadı.');
            crp_json(false, 'Rapor listesi için şema uygun değil.', [], 500);
        }

        $reporterNameExpr = $profile['full_name'] ? "ru.`{$profile['full_name']}`" : "''";
        $ownerNameExpr = $profile['full_name'] ? "mu.`{$profile['full_name']}`" : "''";

        try {
            $sql = "SELECT rp.`{$report['id']}` AS id, rp.`{$report['reason']}` AS reason, rp.`{$report['created_at']}` AS created_at, "
                . "m.`{$msg['id']}` AS message_id, m.`{$msg['message_text']}` AS message_text, m.`{$msg['user_id']}` AS message_owner_id, "
                . "r.`{$room['name']}` AS room_name, "
                . "ru.`{$profile['email']}` AS reporter_email, {$reporterNameExpr} AS reporter_name, "
                . "mu.`{$profile['email']}` AS owner_email, {$ownerNameExpr} AS owner_name "
                . "FROM `{$report['table']}` rp "
                . "LEFT JOIN `{$msg['table']}` m ON m.`{$msg['id']}` = rp.`{$report['message_id']}` "
                . "LEFT JOIN `{$room['table']}` r ON r.`{$room['id']}` = m.`{$msg['room_id']}` "
                . "LEFT JOIN `{$profile['table']}` ru ON ru.`{$profile['id']}` = rp.`{$reportedByCol}` "
                . "LEFT JOIN `{$profile['table']}` mu ON mu.`{$profile['id']}` = m.`{$msg['user_id']}` ";

            if ($report['status']) {
                $sql .= " WHERE (rp.`{$report['status']}` IS NULL OR rp.`{$report['status']}` = 'pending')";
            }

            $sql .= " ORDER BY rp.`{$report['created_at']}` DESC LIMIT 300";
            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $listError) {
            error_log('community-reports list error: ' . $listError->getMessage());
            crp_json(false, 'Rapor listesi alınırken bir hata oluştu.', [], 500);
        }

        $reports = array_map(static function (array $r): array {
            $text = (string)($r['message_text'] ?? '');
            $preview = mb_strlen($text, 'UTF-8') > 120 ? mb_substr($text, 0, 120, 'UTF-8') . '…' : $text;
            return [
                'id' => (string)$r['id'],
                'reason' => (string)($r['reason'] ?? ''),
                'message_id' => (string)($r['message_id'] ?? ''),
                'message_preview' => $preview,
                'room_name' => (string)($r['room_name'] ?? '-'),
                'reporter_name' => (string)(($r['reporter_name'] ?: $r['reporter_email']) ?? '-'),
                'owner_name' => (string)(($r['owner_name'] ?: $r['owner_email']) ?? '-'),
                'owner_id' => (string)($r['message_owner_id'] ?? ''),
                'created_at' => $r['created_at'] ?? null,
            ];
        }, $rows);

        crp_json(true, '', ['reports' => $reports]);
    }

    if ($action === 'delete_message') {
        $messageId = trim((string)($_POST['message_id'] ?? ''));
        if ($messageId === '') crp_json(false, 'message_id gerekli.', [], 422);

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
        $vals[] = $messageId;

        $sql = "UPDATE `{$msg['table']}` SET " . implode(', ', $set) . " WHERE `{$msg['id']}` = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($vals);

        crp_json(true, 'Mesaj moderasyon nedeniyle kaldırıldı.');
    }

    if ($action === 'ignore_report') {
        $reportId = trim((string)($_POST['report_id'] ?? ''));
        if ($reportId === '') crp_json(false, 'report_id gerekli.', [], 422);

        if ($report['status']) {
            $stmt = $pdo->prepare("UPDATE `{$report['table']}` SET `{$report['status']}` = ? WHERE `{$report['id']}` = ?");
            $stmt->execute(['ignored', $reportId]);
        } else {
            $stmt = $pdo->prepare("DELETE FROM `{$report['table']}` WHERE `{$report['id']}` = ?");
            $stmt->execute([$reportId]);
        }

        crp_json(true, 'Rapor yok sayıldı.');
    }

    if ($action === 'mute_user') {
        $userId = trim((string)($_POST['user_id'] ?? ''));
        $minutes = (int)($_POST['minutes'] ?? 60);
        if ($userId === '') crp_json(false, 'user_id gerekli.', [], 422);
        if ($minutes < 1) $minutes = 60;

        $mute = community_mute_schema($pdo);
        $cols = [$mute['id'], $mute['user_id'], $mute['muted_until']];
        $vals = [generate_uuid(), $userId, date('Y-m-d H:i:s', time() + ($minutes * 60))];

        if ($mute['reason']) {
            $cols[] = $mute['reason'];
            $vals[] = 'Topluluk moderasyonu';
        }
        if ($mute['is_active']) {
            $cols[] = $mute['is_active'];
            $vals[] = 1;
        }
        if ($mute['muted_by_admin_id']) {
            $cols[] = $mute['muted_by_admin_id'];
            $vals[] = (string)($admin['user_id'] ?? '');
        }
        if ($mute['created_at']) {
            $cols[] = $mute['created_at'];
            $vals[] = community_now();
        }

        $quoted = implode(', ', array_map(static fn($c) => '`' . $c . '`', $cols));
        $holders = implode(', ', array_fill(0, count($cols), '?'));
        $stmt = $pdo->prepare("INSERT INTO `{$mute['table']}` ({$quoted}) VALUES ({$holders})");
        $stmt->execute($vals);

        crp_json(true, 'Kullanıcı susturuldu.');
    }

    crp_json(false, 'Geçersiz işlem.', [], 400);
} catch (Throwable $e) {
    error_log('community-reports general error: ' . $e->getMessage());
    crp_json(false, 'İşlem sırasında bir hata oluştu.', [], 500);
}
