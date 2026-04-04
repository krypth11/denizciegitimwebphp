<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__, 3) . '/includes/community_helper.php';

api_require_method('POST');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)($auth['user']['id'] ?? '');
    $userQualificationId = api_get_current_user_qualification_id($pdo, $auth);
    $profile = community_get_profile_by_user_id($pdo, $userId);
    if (!$profile) {
        api_error('Kullanıcı profili bulunamadı.', 404);
    }

    if ((int)($profile['is_guest'] ?? 0) === 1) {
        api_error('Mesaj raporlamak için kayıtlı kullanıcı olmalısınız.', 403);
    }

    $payload = api_get_request_data();
    $messageId = trim((string)($payload['message_id'] ?? ''));
    $reason = trim((string)($payload['reason'] ?? ''));

    if ($messageId === '') {
        api_error('message_id zorunludur.', 422);
    }
    if ($reason === '') {
        api_error('reason zorunludur.', 422);
    }

    $allowedReasons = community_report_reasons();
    if (!in_array($reason, $allowedReasons, true)) {
        api_error('Geçersiz rapor nedeni.', 422);
    }

    $msg = community_message_schema($pdo);
    $report = community_report_schema($pdo);
    $room = community_room_schema($pdo);

    $reportedByCol = $report['reported_by_user_id'] ?? $report['reporter_user_id'] ?? null;
    if (!$reportedByCol) {
        api_error('Raporlama şeması uygun değil.', 500);
    }

    $msgStmt = $pdo->prepare("SELECT m.`{$msg['id']}` AS id, m.`{$msg['user_id']}` AS user_id, m.`{$msg['room_id']}` AS room_id, r.`{$room['type']}` AS room_type"
        . ($room['qualification_id'] ? ", r.`{$room['qualification_id']}` AS room_qualification_id" : ', NULL AS room_qualification_id')
        . " FROM `{$msg['table']}` m"
        . " LEFT JOIN `{$room['table']}` r ON r.`{$room['id']}` = m.`{$msg['room_id']}`"
        . " WHERE m.`{$msg['id']}` = ? LIMIT 1");
    $msgStmt->execute([$messageId]);
    $message = $msgStmt->fetch(PDO::FETCH_ASSOC);
    if (!$message) {
        api_error('Mesaj bulunamadı.', 404);
    }

    if (!community_user_can_access_room([
        'type' => (string)($message['room_type'] ?? ''),
        'qualification_id' => (string)(($message['room_qualification_id'] ?? null) ?: ''),
    ], $userQualificationId)) {
        api_qualification_access_log('qualification access rejected', [
            'context' => 'community.report_message.room',
            'requested_qualification_id' => ($message['room_qualification_id'] ?? null),
            'current_qualification_id' => $userQualificationId,
            'message_id' => $messageId,
        ]);
        api_error('Bu oda için erişim yetkiniz yok.', 403);
    }

    $dupSql = "SELECT COUNT(*) FROM `{$report['table']}` WHERE `{$report['message_id']}` = ? AND `{$reportedByCol}` = ?";
    $dupStmt = $pdo->prepare($dupSql);
    $dupStmt->execute([$messageId, $userId]);
    if ((int)$dupStmt->fetchColumn() > 0) {
        api_error('Bu mesajı zaten raporladınız.', 409);
    }

    $cols = [$report['id'], $report['message_id'], $reportedByCol, $report['reason'], $report['created_at']];
    $vals = [generate_uuid(), $messageId, $userId, $reason, community_now()];
    if ($report['message_owner_user_id']) {
        $cols[] = $report['message_owner_user_id'];
        $vals[] = (string)($message['user_id'] ?? '');
    }
    if ($report['status']) {
        $cols[] = $report['status'];
        $vals[] = 'pending';
    }

    $quoted = implode(', ', array_map(static fn($c) => '`' . $c . '`', $cols));
    $holders = implode(', ', array_fill(0, count($cols), '?'));
    $stmt = $pdo->prepare("INSERT INTO `{$report['table']}` ({$quoted}) VALUES ({$holders})");
    $stmt->execute($vals);

    api_success('Mesaj raporlandı.');
} catch (Throwable $e) {
    error_log('community/report_message.php error: ' . $e->getMessage());
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}
