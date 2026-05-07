<?php
header('Content-Type: application/json; charset=utf-8');

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/support_helper.php';
require_once '../api/v1/auth_helper.php';

$admin = require_admin();
$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? ''));

function st_json(bool $success, string $message = '', array $data = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $ticket = support_ticket_schema($pdo);
    $msg = support_ticket_message_schema($pdo);
    $profileSchema = api_get_profile_schema($pdo);

    if ($action === 'list') {
        $status = support_normalize_status((string)($_GET['status'] ?? ''));
        $rawStatus = trim((string)($_GET['status'] ?? ''));
        $q = trim((string)($_GET['q'] ?? ''));

        $nameExpr = $profileSchema['full_name'] ? "u.`{$profileSchema['full_name']}`" : "''";
        $emailExpr = "u.`{$profileSchema['email']}`";

        $sql = "SELECT t.`{$ticket['id']}` AS id, t.`{$ticket['subject']}` AS subject, t.`{$ticket['status']}` AS status, "
            . "t.`{$ticket['created_at']}` AS created_at, t.`{$ticket['user_id']}` AS user_id, "
            . "{$nameExpr} AS user_full_name, {$emailExpr} AS user_email "
            . "FROM `{$ticket['table']}` t "
            . "LEFT JOIN `{$profileSchema['table']}` u ON u.`{$profileSchema['id']}` = t.`{$ticket['user_id']}` "
            . "WHERE 1=1";
        $params = [];

        if ($rawStatus !== '' && in_array($status, support_allowed_statuses(), true)) {
            $sql .= " AND t.`{$ticket['status']}` = ?";
            $params[] = $status;
        }
        if ($q !== '') {
            $sql .= " AND (t.`{$ticket['subject']}` LIKE ? OR {$nameExpr} LIKE ? OR {$emailExpr} LIKE ?)";
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $sql .= " ORDER BY t.`{$ticket['created_at']}` DESC LIMIT 300";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $items = array_map(static function (array $r): array {
            $email = trim((string)($r['user_email'] ?? ''));
            $fullName = trim((string)($r['user_full_name'] ?? ''));
            return [
                'id' => (string)($r['id'] ?? ''),
                'subject' => (string)($r['subject'] ?? ''),
                'status' => support_normalize_status((string)($r['status'] ?? 'submitted')),
                'created_at' => $r['created_at'] ?? null,
                'user_id' => (string)($r['user_id'] ?? ''),
                'user_full_name' => $fullName,
                'user_email' => $email,
                'user_display' => $fullName !== '' ? $fullName : $email,
            ];
        }, $rows);

        st_json(true, '', ['tickets' => $items]);
    }

    if ($action === 'detail') {
        $ticketId = trim((string)($_GET['ticket_id'] ?? ''));
        if ($ticketId === '') {
            st_json(false, 'ticket_id zorunludur.', [], 422);
        }

        $nameExpr = $profileSchema['full_name'] ? "u.`{$profileSchema['full_name']}`" : "''";
        $sql = "SELECT t.*, {$nameExpr} AS user_full_name, u.`{$profileSchema['email']}` AS user_email"
            . " FROM `{$ticket['table']}` t"
            . " LEFT JOIN `{$profileSchema['table']}` u ON u.`{$profileSchema['id']}` = t.`{$ticket['user_id']}`"
            . " WHERE t.`{$ticket['id']}` = ? LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$ticketId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            st_json(false, 'Destek talebi bulunamadı.', [], 404);
        }

        $statusNow = support_normalize_status((string)($row['status'] ?? 'submitted'));
        if ($statusNow === 'submitted') {
            support_update_ticket_status($pdo, $ticketId, 'in_review');
            $row['status'] = 'in_review';
        }

        $messages = support_fetch_ticket_messages($pdo, $ticketId);
        st_json(true, '', [
            'ticket' => [
                'id' => (string)($row['id'] ?? ''),
                'subject' => (string)($row['subject'] ?? ''),
                'status' => support_normalize_status((string)($row['status'] ?? 'submitted')),
                'created_at' => $row['created_at'] ?? null,
                'completed_at' => $row['completed_at'] ?? null,
                'user_followup_count' => (int)($row['user_followup_count'] ?? 0),
                'user_id' => (string)($row['user_id'] ?? ''),
                'user_full_name' => (string)($row['user_full_name'] ?? ''),
                'user_email' => (string)($row['user_email'] ?? ''),
            ],
            'messages' => $messages,
        ]);
    }

    if ($action === 'reply') {
        $ticketId = trim((string)($_POST['ticket_id'] ?? ''));
        $message = trim((string)($_POST['message'] ?? ''));
        if ($ticketId === '' || $message === '') {
            st_json(false, 'ticket_id ve message zorunludur.', [], 422);
        }
        if (mb_strlen($message, 'UTF-8') > 5000) {
            st_json(false, 'Mesaj en fazla 5000 karakter olabilir.', [], 422);
        }

        $stmt = $pdo->prepare("SELECT `{$ticket['id']}` AS id, `{$ticket['status']}` AS status FROM `{$ticket['table']}` WHERE `{$ticket['id']}` = ? LIMIT 1");
        $stmt->execute([$ticketId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            st_json(false, 'Destek talebi bulunamadı.', [], 404);
        }
        if (support_normalize_status((string)($row['status'] ?? 'submitted')) === 'completed') {
            st_json(false, 'Tamamlanmış talebe cevap yazılamaz.', [], 422);
        }

        $adminId = trim((string)($admin['user_id'] ?? ''));
        if ($adminId === '') {
            st_json(false, 'Admin bilgisi doğrulanamadı.', [], 403);
        }

        support_add_message($pdo, $ticketId, $adminId, 'admin', $message);
        support_update_ticket_status($pdo, $ticketId, 'answered');

        st_json(true, 'Admin cevabı kaydedildi.');
    }

    if ($action === 'complete') {
        $ticketId = trim((string)($_POST['ticket_id'] ?? ''));
        if ($ticketId === '') {
            st_json(false, 'ticket_id zorunludur.', [], 422);
        }

        $stmt = $pdo->prepare("SELECT `{$ticket['id']}` AS id FROM `{$ticket['table']}` WHERE `{$ticket['id']}` = ? LIMIT 1");
        $stmt->execute([$ticketId]);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            st_json(false, 'Destek talebi bulunamadı.', [], 404);
        }

        support_update_ticket_status($pdo, $ticketId, 'completed', true);
        st_json(true, 'Destek talebi tamamlandı.');
    }

    st_json(false, 'Geçersiz işlem.', [], 400);
} catch (Throwable $e) {
    st_json(false, 'İşlem sırasında bir hata oluştu.', [], 500);
}
