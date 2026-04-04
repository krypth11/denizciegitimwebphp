<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__, 3) . '/includes/community_helper.php';

api_require_method('POST');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)($auth['user']['id'] ?? '');
    $userQualificationId = api_require_current_user_qualification_id($pdo, $auth, 'community.send_message');
    $profile = community_get_profile_by_user_id($pdo, $userId);
    if (!$profile) {
        api_error('Kullanıcı profili bulunamadı.', 404);
    }

    if ((int)($profile['is_guest'] ?? 0) === 1) {
        api_error('Mesaj göndermek için kayıtlı kullanıcı olmalısınız.', 403);
    }

    $payload = api_get_request_data();
    $roomId = trim((string)($payload['room_id'] ?? ''));
    $messageText = trim((string)($payload['message_text'] ?? ''));

    if ($roomId === '') {
        api_error('room_id zorunludur.', 422);
    }
    if ($messageText === '') {
        api_error('Mesaj boş olamaz.', 422);
    }
    if (mb_strlen($messageText, 'UTF-8') > 500) {
        api_error('Mesaj en fazla 500 karakter olabilir.', 422);
    }

    $room = community_room_schema($pdo);
    $msg = community_message_schema($pdo);

    $roomStmt = $pdo->prepare("SELECT `{$room['id']}` AS id, `{$room['is_active']}` AS is_active, `{$room['type']}` AS type"
        . ($room['qualification_id'] ? ", `{$room['qualification_id']}` AS qualification_id" : ', NULL AS qualification_id')
        . " FROM `{$room['table']}` WHERE `{$room['id']}` = ? LIMIT 1");
    $roomStmt->execute([$roomId]);
    $roomRow = $roomStmt->fetch(PDO::FETCH_ASSOC);
    if (!$roomRow) {
        api_error('Oda bulunamadı.', 404);
    }
    if ((int)($roomRow['is_active'] ?? 0) !== 1) {
        api_error('Bu oda pasif olduğu için mesaj gönderemezsiniz.', 422);
    }

    if (!community_user_can_access_room([
        'type' => (string)($roomRow['type'] ?? ''),
        'qualification_id' => (string)(($roomRow['qualification_id'] ?? null) ?: ''),
    ], $userQualificationId)) {
        api_qualification_access_log('qualification access rejected', [
            'context' => 'community.send_message.room',
            'requested_qualification_id' => ($roomRow['qualification_id'] ?? null),
            'current_qualification_id' => $userQualificationId,
            'room_id' => $roomId,
        ]);
        api_error('Bu oda için erişim yetkiniz yok.', 403);
    }

    if ((string)($roomRow['type'] ?? '') === 'qualification') {
        api_qualification_access_log('community qualification room returned', [
            'context' => 'community.send_message',
            'current_qualification_id' => $userQualificationId,
            'community qualification room returned' => (string)(($roomRow['qualification_id'] ?? null) ?: ''),
        ]);
    }

    $mute = community_is_user_muted($pdo, $userId);
    if ($mute) {
        api_error('Susturulduğunuz için şu an mesaj gönderemezsiniz.', 403);
    }

    if (community_message_has_link($messageText)) {
        api_error('Link paylaşımı yasaktır.', 422);
    }

    $blacklisted = community_blacklist_match($pdo, $messageText);
    if ($blacklisted) {
        api_error('Mesajınız topluluk filtrelerine takıldı.', 422);
    }

    if (community_check_flood($pdo, $userId, 10)) {
        api_error('Flood koruması: 10 saniyede 1 mesaj gönderebilirsiniz.', 429);
    }

    $normalized = community_normalize_message($messageText);
    if (community_check_repeat_message($pdo, $userId, $roomId, $normalized, 120)) {
        api_error('Tekrarlı mesaj engeli: Aynı mesajı kısa sürede tekrar gönderemezsiniz.', 429);
    }

    $cols = [$msg['id'], $msg['room_id'], $msg['user_id'], $msg['message_text'], $msg['is_deleted'], $msg['created_at']];
    $vals = [generate_uuid(), $roomId, $userId, $messageText, 0, community_now()];
    if ($msg['normalized_text']) {
        $cols[] = $msg['normalized_text'];
        $vals[] = $normalized;
    }

    $quoted = implode(', ', array_map(static fn($c) => '`' . $c . '`', $cols));
    $holders = implode(', ', array_fill(0, count($cols), '?'));
    $stmt = $pdo->prepare("INSERT INTO `{$msg['table']}` ({$quoted}) VALUES ({$holders})");
    $stmt->execute($vals);

    api_success('Mesaj gönderildi.', [
        'message_id' => $vals[0],
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}
